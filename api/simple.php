<?php
/*********************************************************************
    simple.php

    Simple REST API for local development - NO AUTHENTICATION
    
    WARNING: This API has NO authentication and should ONLY be used
    for local development. DO NOT use in production!

    Endpoints:
    Tickets:
    - POST /api/simple.php/tickets - Create a new ticket
    - GET /api/simple.php/tickets - List all tickets
    - POST /api/simple.php/tickets/{id}/reply - Reply to a ticket (as user)
    - POST /api/simple.php/tickets/{id}/staff-reply - Reply to a ticket (as staff)
    - GET /api/simple.php/tickets/{id} - Get ticket details
    
    Departments:
    - POST /api/simple.php/departments - Create a new department
    - GET /api/simple.php/departments - List all departments
    - GET /api/simple.php/departments/{id} - Get department details

    Staff:
    - POST /api/simple.php/staff - Create a new staff member
    - GET /api/simple.php/staff - List all staff
    - GET /api/simple.php/staff/{id} - Get staff details

    Topics:
    - POST /api/simple.php/topics - Create a new help topic
    - GET /api/simple.php/topics - List all topics
    - GET /api/simple.php/topics/{id} - Get topic details

    Users:
    - POST /api/simple.php/users - Create a new user
    - GET /api/simple.php/users - List all users
    - GET /api/simple.php/users/{id} - Get user details

    Organizations:
    - POST /api/simple.php/organizations - Create a new organization
    - GET /api/simple.php/organizations - List all organizations
    - GET /api/simple.php/organizations/{id} - Get organization details

**********************************************************************/

require 'api.inc.php';
require_once INCLUDE_DIR.'class.ticket.php';
require_once INCLUDE_DIR.'class.json.php';
require_once INCLUDE_DIR.'class.dept.php';
require_once INCLUDE_DIR.'class.staff.php';
require_once INCLUDE_DIR.'class.topic.php';
require_once INCLUDE_DIR.'class.user.php';
require_once INCLUDE_DIR.'class.organization.php';
require_once INCLUDE_DIR.'class.faq.php';
require_once INCLUDE_DIR.'class.task.php';
require_once INCLUDE_DIR.'class.canned.php';

header('Content-Type: application/json');

class SimpleApiController {
    
    // Helper function to safely get organization field values
    private function getOrgField($org, $fieldName, $default = '') {
        try {
            foreach ($org->getDynamicData() as $entry) {
                if ($answer = $entry->getAnswer($fieldName)) {
                    return (string) $answer;
                }
            }
        } catch (Exception $e) {
            error_log("Error getting org field '$fieldName': " . $e->getMessage());
        }
        return $default;
    }
    
    private function getRequestBody() {
        $body = file_get_contents('php://input');
        if (!$body) {
            return array();
        }
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error(400, 'Invalid JSON: ' . json_last_error_msg());
        }
        return $data ?: array();
    }
    
    private function error($code, $message) {
        http_response_code($code);
        echo json_encode(array(
            'success' => false,
            'error' => $message
        ));
        exit;
    }
    
    private function success($data, $code = 200) {
        http_response_code($code);
        echo json_encode(array(
            'success' => true,
            'data' => $data
        ));
        exit;
    }
    
    public function createTicket() {
        $data = $this->getRequestBody();
        
        // Validate required fields
        if (empty($data['name'])) {
            $this->error(400, 'Name is required');
        }
        if (empty($data['email'])) {
            $this->error(400, 'Email is required');
        }
        if (empty($data['subject'])) {
            $this->error(400, 'Subject is required');
        }
        if (empty($data['message'])) {
            $this->error(400, 'Message is required');
        }
        
        // Prepare ticket data
        $vars = array(
            'name' => $data['name'],
            'email' => $data['email'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'topicId' => $data['topicId'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'priorityId' => $data['priorityId'] ?? null,
            'source' => 'API',  // Required for API origin tickets
        );
        
        // Add optional phone if provided
        if (!empty($data['phone'])) {
            $vars['phone'] = $data['phone'];
        }
        
        // Create the ticket
        $errors = array();
        $ticket = Ticket::create($vars, $errors, 'API', false, false);
        
        if (!$ticket) {
            $this->error(500, 'Failed to create ticket: ' . implode(', ', $errors));
        }
        
        $this->success(array(
            'ticket_id' => $ticket->getId(),
            'ticket_number' => $ticket->getNumber(),
            'subject' => $ticket->getSubject(),
            'status' => $ticket->getStatus() ? $ticket->getStatus()->getName() : 'Open',
            'created' => $ticket->getCreateDate()
        ), 201);
    }
    
    public function replyToTicket($ticketId) {
        // Look up the ticket
        $ticket = Ticket::lookup($ticketId);
        
        if (!$ticket) {
            $this->error(404, 'Ticket not found');
        }
        
        $data = $this->getRequestBody();
        
        if (empty($data['message'])) {
            $this->error(400, 'Message is required');
        }
        
        // Get the ticket owner to post as
        $user = $ticket->getOwner();
        
        if (!$user) {
            $this->error(500, 'Unable to get ticket owner');
        }
        
        // Prepare reply data
        $vars = array(
            'userId' => $user->getUserId(),
            'poster' => $user->getName()->getFull(),
            'message' => $data['message']
        );
        
        // Post the message
        $msgId = $ticket->postMessage($vars, 'API', false);
        
        if (!$msgId) {
            $this->error(500, 'Failed to post reply');
        }
        
        $this->success(array(
            'message_id' => $msgId,
            'ticket_id' => $ticket->getId(),
            'ticket_number' => $ticket->getNumber(),
            'posted' => true
        ));
    }
    
    public function replyToTicketAsStaff($ticketId) {
        // Look up the ticket
        $ticket = Ticket::lookup($ticketId);
        
        if (!$ticket) {
            $this->error(404, 'Ticket not found');
        }
        
        $data = $this->getRequestBody();
        
        if (empty($data['message'])) {
            $this->error(400, 'Message is required');
        }
        
        if (empty($data['staff_id'])) {
            $this->error(400, 'Staff ID is required');
        }
        
        // Look up the staff member
        $staff = Staff::lookup($data['staff_id']);
        
        if (!$staff) {
            $this->error(404, 'Staff member not found');
        }
        
        // Prepare reply data
        $vars = array(
            'response' => $data['message'],
            'poster' => $staff->getName()->getFull(),
            'staffId' => $staff->getId(),
        );
        
        // Post the reply as staff
        $msgId = $ticket->postReply($vars, 'API', false);
        
        if (!$msgId) {
            $this->error(500, 'Failed to post staff reply');
        }
        
        $this->success(array(
            'message_id' => $msgId,
            'ticket_id' => $ticket->getId(),
            'ticket_number' => $ticket->getNumber(),
            'staff' => array(
                'id' => $staff->getId(),
                'name' => $staff->getName()->getFull(),
                'email' => (string) $staff->getEmail()
            ),
            'posted' => true
        ));
    }
    
    public function getTicket($ticketId) {
        try {
            error_log("getTicket: Looking up ticket ID: $ticketId");
            
            // Look up the ticket
            $ticket = Ticket::lookup($ticketId);
            
            if (!$ticket) {
                error_log("getTicket: Ticket not found with ID: $ticketId");
                $this->error(404, 'Ticket not found');
            }
            
            error_log("getTicket: Successfully loaded ticket #" . $ticket->getNumber());
            
            // Get thread entries (full conversation)
            $entries = array();
            try {
                $thread = $ticket->getThread();
                
                if (!$thread) {
                    error_log("getTicket: Warning - No thread found for ticket ID: $ticketId");
                } else {
                    foreach ($thread->getEntries() as $entry) {
                        try {
                            $type = $entry->getType();
                            
                            // M = Message from user, R = Response from agent, N = Internal note
                            if ($type == 'M' || $type == 'R') {
                                $entryData = array(
                                    'id' => $entry->getId(),
                                    'type' => $type == 'M' ? 'user_message' : 'agent_response',
                                    'poster' => $entry->getName()->getFull(),
                                    'message' => $entry->getBody()->getClean(),
                                    'created' => $entry->getCreateDate(),
                                );
                                
                                // Add staff info if it's an agent response
                                if ($type == 'R' && ($staff = $entry->getStaff())) {
                                    $entryData['staff'] = array(
                                        'id' => $staff->getId(),
                                        'name' => $staff->getName()->getFull(),
                                        'email' => (string) $staff->getEmail()
                                    );
                                }
                                
                                $entries[] = $entryData;
                            }
                        } catch (Exception $e) {
                            error_log("getTicket: Error processing thread entry: " . $e->getMessage());
                            continue;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("getTicket: Error retrieving thread entries: " . $e->getMessage());
            }
            
            // Get status
            $status = null;
            $statusName = 'Open';
            $isClosed = false;
            try {
                $status = $ticket->getStatus();
                $statusName = $status ? $status->getName() : 'Open';
                $isClosed = $ticket->isClosed();
            } catch (Exception $e) {
                error_log("getTicket: Error retrieving status: " . $e->getMessage());
            }
            
            // Get priority
            $priorityData = null;
            try {
                $priority = $ticket->getPriority();
                if ($priority) {
                    $priorityData = array(
                        'id' => $priority->getId(),
                        'name' => $priority->getDesc(),
                        'priority' => $priority->getTag(),
                        'urgency' => $priority->getUrgency(),
                        'color' => $priority->getColor()
                    );
                }
            } catch (Exception $e) {
                error_log("getTicket: Error retrieving priority: " . $e->getMessage());
            }
            
            // Get department
            $deptData = null;
            try {
                $dept = $ticket->getDept();
                if ($dept) {
                    $deptData = array(
                        'id' => $dept->getId(),
                        'name' => $dept->getName()
                    );
                }
            } catch (Exception $e) {
                error_log("getTicket: Error retrieving department: " . $e->getMessage());
            }
            
            // Get assigned staff/team
            $assignee = null;
            try {
                if ($staff = $ticket->getStaff()) {
                    $assignee = array(
                        'type' => 'staff',
                        'id' => $staff->getId(),
                        'name' => $staff->getName()->getFull(),
                        'email' => (string) $staff->getEmail()
                    );
                } elseif ($team = $ticket->getTeam()) {
                    $assignee = array(
                        'type' => 'team',
                        'id' => $team->getId(),
                        'name' => $team->getName()
                    );
                }
            } catch (Exception $e) {
                error_log("getTicket: Error retrieving assignee: " . $e->getMessage());
            }
            
            // Get topic
            $topicData = null;
            try {
                $topic = $ticket->getTopic();
                if ($topic) {
                    $topicData = array(
                        'id' => $topic->getId(),
                        'name' => $topic->getName()
                    );
                }
            } catch (Exception $e) {
                error_log("getTicket: Error retrieving topic: " . $e->getMessage());
            }
            
            // Get collaborators
            $collaborators = array();
            try {
                foreach ($ticket->getCollaborators() as $collab) {
                    try {
                        $collaborators[] = array(
                            'id' => $collab->getUserId(),
                            'name' => $collab->getName()->getFull(),
                            'email' => (string) $collab->getEmail()
                        );
                    } catch (Exception $e) {
                        error_log("getTicket: Error processing collaborator: " . $e->getMessage());
                        continue;
                    }
                }
            } catch (Exception $e) {
                error_log("getTicket: Error retrieving collaborators: " . $e->getMessage());
            }
            
            // Get attachments
            $attachments = array();
            try {
                if (isset($thread) && $thread) {
                    foreach ($thread->getEntries() as $entry) {
                        try {
                            foreach ($entry->getAttachments() as $att) {
                                try {
                                    $attachments[] = array(
                                        'id' => $att->getId(),
                                        'filename' => $att->getFilename(),
                                        'size' => $att->getSize(),
                                        'type' => $att->getType()
                                    );
                                } catch (Exception $e) {
                                    error_log("getTicket: Error processing attachment: " . $e->getMessage());
                                    continue;
                                }
                            }
                        } catch (Exception $e) {
                            error_log("getTicket: Error processing entry attachments: " . $e->getMessage());
                            continue;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("getTicket: Error retrieving attachments: " . $e->getMessage());
            }
            
            // Get linked tickets
            // Note: Linked tickets feature not available in standard osTicket
            $linkedTickets = array();
            
            // Check if ticket is merged
            $mergedInfo = null;
            try {
                if ($ticket->getMergeType()) {
                    $parentId = $ticket->getPid();
                    if ($parentId && ($parentTicket = Ticket::lookup($parentId))) {
                        $mergedInfo = array(
                            'is_merged' => true,
                            'merged_into' => array(
                                'ticket_id' => $parentTicket->getId(),
                                'ticket_number' => $parentTicket->getNumber(),
                                'subject' => $parentTicket->getSubject()
                            )
                        );
                    }
                } else {
                    // Check if other tickets are merged into this one
                    $sql = 'SELECT ticket_id FROM '.TICKET_TABLE.' WHERE pid='.db_input($ticketId);
                    $result = db_query($sql);
                    $childTickets = array();
                    while ($row = db_fetch_row($result)) {
                        try {
                            if ($childTicket = Ticket::lookup($row[0])) {
                                $childTickets[] = array(
                                    'ticket_id' => $childTicket->getId(),
                                    'ticket_number' => $childTicket->getNumber(),
                                    'subject' => $childTicket->getSubject()
                                );
                            }
                        } catch (Exception $e) {
                            error_log("getTicket: Error processing child ticket: " . $e->getMessage());
                            continue;
                        }
                    }
                    if (count($childTickets) > 0) {
                        $mergedInfo = array(
                            'is_parent' => true,
                            'merged_tickets' => $childTickets
                        );
                    }
                }
            } catch (Exception $e) {
                error_log("getTicket: Error retrieving merge info: " . $e->getMessage());
            }
            
            error_log("getTicket: Successfully retrieved all ticket data for ticket ID: $ticketId");
            
            // Try to get reopen count safely
            $reopenCount = 0;
            try {
                // Try to access the reopen_count field
                if (isset($ticket->reopen_count)) {
                    $reopenCount = $ticket->reopen_count;
                } elseif (method_exists($ticket, 'getField')) {
                    $field = $ticket->getField('reopen_count');
                    if ($field) {
                        $reopenCount = $field->getValue();
                    }
                }
            } catch (Exception $e) {
                error_log("getTicket: Could not retrieve reopen count: " . $e->getMessage());
                $reopenCount = $ticket->isReopened() ? 1 : 0;
            }
            
            $this->success(array(
                'ticket_id' => $ticket->getId(),
                'ticket_number' => $ticket->getNumber(),
                'subject' => $ticket->getSubject(),
                'status' => array(
                    'name' => $statusName,
                    'id' => $status ? $status->getId() : null,
                    'is_closed' => $isClosed
                ),
                'priority' => $priorityData,
                'department' => $deptData,
                'topic' => $topicData,
                'assignee' => $assignee,
                'source' => $ticket->getSource(),
                'ip_address' => $ticket->getIP(),
                'is_overdue' => $ticket->isOverdue(),
                'is_answered' => $ticket->isAnswered(),
                'is_reopened' => $ticket->isReopened(),
                'reopen_count' => $reopenCount,
                'created' => $ticket->getCreateDate(),
                'updated' => $ticket->getUpdateDate(),
                'closed' => $isClosed ? $ticket->getCloseDate() : null,
                'due_date' => $ticket->getDueDate(),
                'user' => array(
                    'id' => $ticket->getUserId(),
                    'name' => $ticket->getName()->getFull(),
                    'email' => (string) $ticket->getEmail(),
                    'phone' => $ticket->getPhoneNumber()
                ),
                'collaborators' => array(
                    'count' => count($collaborators),
                    'collaborators' => $collaborators
                ),
                'attachments' => array(
                    'count' => count($attachments),
                    'attachments' => $attachments
                ),
                'linked_tickets' => array(
                    'count' => count($linkedTickets),
                    'tickets' => $linkedTickets
                ),
                'merge_info' => $mergedInfo,
                'thread' => array(
                    'total_entries' => count($entries),
                    'entries' => $entries
                )
            ));
        } catch (Exception $e) {
            error_log("getTicket: Fatal error processing ticket ID $ticketId: " . $e->getMessage());
            error_log("getTicket: Stack trace: " . $e->getTraceAsString());
            $this->error(500, 'Error retrieving ticket: ' . $e->getMessage());
        }
    }
    
    public function getTickets() {
        // Build WHERE clause based on query parameters
        $where = array();
        $params = array();
        
        // Filter by status name (e.g., ?status=Open)
        if (isset($_GET['status']) && $_GET['status']) {
            $where[] = 'status.name='.db_input($_GET['status']);
        }
        
        // Filter by status state (e.g., ?state=open or ?state=closed)
        if (isset($_GET['state']) && $_GET['state']) {
            if (strtolower($_GET['state']) === 'open') {
                $where[] = 'status.state="open"';
            } elseif (strtolower($_GET['state']) === 'closed') {
                $where[] = 'status.state="closed"';
            }
        }
        
        // Filter by department ID (e.g., ?dept_id=1)
        if (isset($_GET['dept_id']) && $_GET['dept_id']) {
            $where[] = 't.dept_id='.db_input($_GET['dept_id']);
        }
        
        // Filter by assigned staff ID (e.g., ?staff_id=2)
        if (isset($_GET['staff_id']) && $_GET['staff_id']) {
            $where[] = 't.staff_id='.db_input($_GET['staff_id']);
        }
        
        // Filter by assigned team ID (e.g., ?team_id=3)
        if (isset($_GET['team_id']) && $_GET['team_id']) {
            $where[] = 't.team_id='.db_input($_GET['team_id']);
        }
        
        // Filter by topic ID (e.g., ?topic_id=4)
        if (isset($_GET['topic_id']) && $_GET['topic_id']) {
            $where[] = 't.topic_id='.db_input($_GET['topic_id']);
        }
        
        // Filter by user ID (e.g., ?user_id=5)
        if (isset($_GET['user_id']) && $_GET['user_id']) {
            $where[] = 't.user_id='.db_input($_GET['user_id']);
        }
        
        // Filter by answered status (e.g., ?answered=1 or ?answered=0)
        if (isset($_GET['answered'])) {
            if ($_GET['answered'] == '1') {
                $where[] = 't.isanswered=1';
            } elseif ($_GET['answered'] == '0') {
                $where[] = 't.isanswered=0';
            }
        }
        
        // Filter by overdue status (e.g., ?overdue=1)
        if (isset($_GET['overdue']) && $_GET['overdue'] == '1') {
            $where[] = 't.isoverdue=1';
        }
        
        // Filter by created date range (e.g., ?created_after=2025-11-01)
        if (isset($_GET['created_after']) && $_GET['created_after']) {
            $where[] = 't.created>='.db_input($_GET['created_after']);
        }
        if (isset($_GET['created_before']) && $_GET['created_before']) {
            $where[] = 't.created<='.db_input($_GET['created_before']);
        }
        
        // Filter by updated date range
        if (isset($_GET['updated_after']) && $_GET['updated_after']) {
            $where[] = 't.updated>='.db_input($_GET['updated_after']);
        }
        if (isset($_GET['updated_before']) && $_GET['updated_before']) {
            $where[] = 't.updated<='.db_input($_GET['updated_before']);
        }
        
        // Filter by due date range
        if (isset($_GET['due_after']) && $_GET['due_after']) {
            $where[] = 't.duedate>='.db_input($_GET['due_after']);
        }
        if (isset($_GET['due_before']) && $_GET['due_before']) {
            $where[] = 't.duedate<='.db_input($_GET['due_before']);
        }
        
        // Filter by keyword in subject (e.g., ?subject=login)
        if (isset($_GET['subject']) && $_GET['subject']) {
            $where[] = 't.subject LIKE "%'.db_input($_GET['subject'], false).'%"';
        }
        
        // Build SQL query
        $sql = 'SELECT t.ticket_id FROM '.TICKET_TABLE.' t 
                LEFT JOIN '.TICKET_STATUS_TABLE.' status ON t.status_id=status.id';
        
        if (count($where) > 0) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        
        // Limit results (e.g., ?limit=10)
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? intval($_GET['limit']) : 100;
        $sql .= ' ORDER BY t.created DESC LIMIT ' . $limit;
        
        $result = db_query($sql);
        
        $tickets = array();
        while ($row = db_fetch_row($result)) {
            $ticket = Ticket::lookup($row[0]);
            if ($ticket) {
                // Get status
                $status = $ticket->getStatus();
                $statusName = $status ? $status->getName() : 'Open';
                $isClosed = $ticket->isClosed();
                
                // Get priority
                $priority = $ticket->getPriority();
                $priorityData = null;
                if ($priority) {
                    $priorityData = array(
                        'id' => $priority->getId(),
                        'name' => $priority->getDesc()
                    );
                }
                
                // Get department
                $dept = $ticket->getDept();
                $deptData = null;
                if ($dept) {
                    $deptData = array(
                        'id' => $dept->getId(),
                        'name' => $dept->getName()
                    );
                }
                
                // Get assigned staff/team
                $assignee = null;
                if ($staff = $ticket->getStaff()) {
                    $assignee = array(
                        'type' => 'staff',
                        'id' => $staff->getId(),
                        'name' => $staff->getName()->getFull()
                    );
                } elseif ($team = $ticket->getTeam()) {
                    $assignee = array(
                        'type' => 'team',
                        'id' => $team->getId(),
                        'name' => $team->getName()
                    );
                }
                
                $tickets[] = array(
                    'ticket_id' => $ticket->getId(),
                    'ticket_number' => $ticket->getNumber(),
                    'subject' => $ticket->getSubject(),
                    'status' => array(
                        'name' => $statusName,
                        'id' => $status ? $status->getId() : null,
                        'is_closed' => $isClosed
                    ),
                    'priority' => $priorityData,
                    'department' => $deptData,
                    'assignee' => $assignee,
                    'is_overdue' => $ticket->isOverdue(),
                    'is_answered' => $ticket->isAnswered(),
                    'user' => array(
                        'id' => $ticket->getUserId(),
                        'name' => $ticket->getName()->getFull(),
                        'email' => (string) $ticket->getEmail()
                    ),
                    'created' => $ticket->getCreateDate(),
                    'updated' => $ticket->getUpdateDate()
                );
            }
        }
        
        $this->success(array(
            'count' => count($tickets),
            'filters_applied' => $_GET,
            'tickets' => $tickets
        ));
    }
    
    // ========== DEPARTMENT ENDPOINTS ==========
    
    public function createDepartment() {
        $data = $this->getRequestBody();
        
        // Validate required fields
        if (empty($data['name'])) {
            $this->error(400, 'Department name is required');
        }
        
        // Create department
        $dept = Dept::create();
        $errors = array();
        
        $vars = array(
            'name' => $data['name'],
            'ispublic' => isset($data['ispublic']) ? $data['ispublic'] : 1,
            'group_membership' => isset($data['group_membership']) ? $data['group_membership'] : Dept::ALERTS_DEPT_AND_EXTENDED,
            'status' => 'active',
        );
        
        // Add optional fields
        if (isset($data['email_id'])) {
            $vars['email_id'] = $data['email_id'];
        }
        if (isset($data['manager_id'])) {
            $vars['manager_id'] = $data['manager_id'];
        }
        if (isset($data['signature'])) {
            $vars['signature'] = $data['signature'];
        }
        
        if (!$dept->update($vars, $errors)) {
            $this->error(500, 'Failed to create department: ' . implode(', ', $errors));
        }
        
        // Reload the department from database to get all populated fields
        $deptId = $dept->getId();
        $dept = Dept::lookup($deptId);
        
        if (!$dept) {
            $this->error(500, 'Department created but failed to reload');
        }
        
        $this->success(array(
            'id' => $dept->getId(),
            'name' => $dept->getName(),
            'ispublic' => $dept->isPublic(),
            'created' => $dept->created  // Direct field access instead of getCreateDate()
        ), 201);
    }
    
    public function getDepartments() {
        try {
            $depts = Dept::getDepartments();
            $result = array();
            
            foreach ($depts as $id => $name) {
                try {
                    $dept = Dept::lookup($id);
                    if ($dept) {
                        $result[] = array(
                            'id' => $dept->getId(),
                            'name' => $dept->getName(),
                            'ispublic' => $dept->isPublic(),
                            'status' => $dept->isActive() ? 'active' : 'disabled'
                        );
                    }
                } catch (Exception $e) {
                    error_log('Error processing department ID ' . $id . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            $this->success(array(
                'count' => count($result),
                'departments' => $result
            ));
        } catch (Exception $e) {
            $this->error(500, 'Error fetching departments: ' . $e->getMessage());
        }
    }
    
    public function getDepartment($deptId) {
        $dept = Dept::lookup($deptId);
        
        if (!$dept) {
            $this->error(404, 'Department not found');
        }
        
        // Get manager info
        $manager = null;
        if ($dept->getManagerId()) {
            if ($mgr = Staff::lookup($dept->getManagerId())) {
                $manager = array(
                    'id' => $mgr->getId(),
                    'name' => $mgr->getName()->getFull(),
                    'email' => (string) $mgr->getEmail()
                );
            }
        }
        
        $this->success(array(
            'id' => $dept->getId(),
            'name' => $dept->getName(),
            'ispublic' => $dept->isPublic(),
            'status' => $dept->isActive() ? 'active' : 'disabled',
            'manager' => $manager,
            'signature' => $dept->getSignature(),
            'created' => $dept->created,
            'updated' => $dept->updated
        ));
    }
    
    // ========== STAFF ENDPOINTS ==========
    
    public function createStaff() {
        $data = $this->getRequestBody();
        
        // Validate required fields
        if (empty($data['firstname'])) {
            $this->error(400, 'First name is required');
        }
        if (empty($data['lastname'])) {
            $this->error(400, 'Last name is required');
        }
        if (empty($data['email'])) {
            $this->error(400, 'Email is required');
        }
        if (empty($data['username'])) {
            $this->error(400, 'Username is required');
        }
        
        // Check if username already exists
        if (Staff::lookup(array('username' => $data['username']))) {
            $this->error(400, 'Username already exists');
        }
        
        // Check if email already exists
        if (Staff::lookup(array('email' => $data['email']))) {
            $this->error(400, 'Email already exists');
        }
        
        // Get default department if not provided
        $dept_id = isset($data['dept_id']) ? $data['dept_id'] : 0;
        if (!$dept_id) {
            // Try to get the first available department
            $depts = Dept::getDepartments();
            if ($depts) {
                $dept_ids = array_keys($depts);
                $dept_id = $dept_ids[0];
            }
        }
        
        if (!$dept_id) {
            $this->error(400, 'Department is required. Please create a department first or specify dept_id.');
        }
        
        // Get default role if not provided (1 = All Access, 3 = Limited Access)
        $role_id = isset($data['role_id']) ? $data['role_id'] : 3; // Default to Limited Access
        
        // Debug: log the values being used
        error_log("Creating staff with dept_id: $dept_id, role_id: $role_id");
        
        // Create staff
        $staff = Staff::create();
        $errors = array();
        
        $vars = array(
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'],
            'email' => $data['email'],
            'username' => $data['username'],
            'isactive' => isset($data['isactive']) ? $data['isactive'] : 1,
            'isadmin' => isset($data['isadmin']) ? $data['isadmin'] : 0,
            'dept_id' => $dept_id,
            'role_id' => $role_id,
            'assign_use_pri_role' => true,
        );
        
        // Debug: log the vars array
        error_log("Vars array: " . json_encode($vars));
        
        // Add optional fields
        if (isset($data['phone'])) {
            $vars['phone'] = $data['phone'];
        }
        if (isset($data['mobile'])) {
            $vars['mobile'] = $data['mobile'];
        }
        if (isset($data['passwd1']) && isset($data['passwd2'])) {
            $vars['passwd1'] = $data['passwd1'];
            $vars['passwd2'] = $data['passwd2'];
        }
        
        // Set default permissions
        $vars['perms'] = array(
            User::PERM_CREATE,
            User::PERM_EDIT,
            User::PERM_DELETE,
            User::PERM_MANAGE,
            User::PERM_DIRECTORY,
            Organization::PERM_CREATE,
            Organization::PERM_EDIT,
            Organization::PERM_DELETE,
            FAQ::PERM_MANAGE,
            Dept::PERM_DEPT,
            Staff::PERM_STAFF,
        );
        
        if (!$staff->update($vars, $errors)) {
            $this->error(500, 'Failed to create staff: ' . implode(', ', $errors));
        }
        
        $this->success(array(
            'id' => $staff->getId(),
            'username' => $staff->getUsername(),
            'name' => $staff->getName()->getFull(),
            'email' => (string) $staff->getEmail(),
            'created' => $staff->created  // Direct field access
        ), 201);
    }
    
    public function getStaffList() {
        try {
            $sql = 'SELECT staff_id FROM '.STAFF_TABLE.' ORDER BY lastname, firstname';
            $result = db_query($sql);
            
            if (!$result) {
                $this->error(500, 'Database query failed');
            }
            
            $staffList = array();
            while ($row = db_fetch_row($result)) {
                try {
                    $staff = Staff::lookup($row[0]);
                    if ($staff) {
                        $dept = null;
                        if ($staff->getDeptId()) {
                            try {
                                if ($d = Dept::lookup($staff->getDeptId())) {
                                    $dept = array(
                                        'id' => $d->getId(),
                                        'name' => $d->getName()
                                    );
                                }
                            } catch (Exception $e) {
                                error_log('Error looking up department: ' . $e->getMessage());
                            }
                        }
                        
                        $staffList[] = array(
                            'id' => $staff->getId(),
                            'username' => $staff->getUsername(),
                            'firstname' => $staff->getFirstName(),
                            'lastname' => $staff->getLastName(),
                            'name' => $staff->getName()->getFull(),
                            'email' => (string) $staff->getEmail(),
                            'department' => $dept,
                            'isactive' => $staff->isActive(),
                            'isadmin' => $staff->isAdmin()
                        );
                    }
                } catch (Exception $e) {
                    error_log('Error processing staff ID ' . $row[0] . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            $this->success(array(
                'count' => count($staffList),
                'staff' => $staffList
            ));
        } catch (Exception $e) {
            $this->error(500, 'Error fetching staff: ' . $e->getMessage());
        }
    }
    
    public function getStaff($staffId) {
        $staff = Staff::lookup($staffId);
        
        if (!$staff) {
            $this->error(404, 'Staff member not found');
        }
        
        // Get department info
        $dept = null;
        try {
            if ($staff->getDeptId()) {
                if ($d = Dept::lookup($staff->getDeptId())) {
                    $dept = array(
                        'id' => $d->getId(),
                        'name' => $d->getName()
                    );
                }
            }
        } catch (Exception $e) {
            error_log("getStaff: Error retrieving department: " . $e->getMessage());
        }
        
        // Get phone numbers - these are direct properties on Staff
        $phone = isset($staff->phone) ? $staff->phone : '';
        $mobile = isset($staff->mobile) ? $staff->mobile : '';
        
        $this->success(array(
            'id' => $staff->getId(),
            'username' => $staff->getUsername(),
            'firstname' => $staff->getFirstName(),
            'lastname' => $staff->getLastName(),
            'name' => $staff->getName()->getFull(),
            'email' => (string) $staff->getEmail(),
            'phone' => $phone,
            'mobile' => $mobile,
            'department' => $dept,
            'isactive' => $staff->isActive(),
            'isadmin' => $staff->isAdmin(),
            'created' => $staff->created,
            'updated' => $staff->updated
        ));
    }
    
    // ========== TOPIC ENDPOINTS ==========
    
    public function createTopic() {
        $data = $this->getRequestBody();
        
        // Validate required fields
        if (empty($data['topic'])) {
            $this->error(400, 'Topic name is required');
        }
        
        // Create topic
        $topic = Topic::create();
        $errors = array();
        
        $vars = array(
            'topic' => $data['topic'],
            'topic_pid' => isset($data['topic_pid']) ? $data['topic_pid'] : 0,
            'ispublic' => isset($data['ispublic']) ? $data['ispublic'] : 1,
            'isactive' => isset($data['isactive']) ? $data['isactive'] : 1,
            'dept_id' => isset($data['dept_id']) ? $data['dept_id'] : 0,
            'id' => 0, // For new topics
        );
        
        // Add optional fields
        if (isset($data['priority_id'])) {
            $vars['priority_id'] = $data['priority_id'];
        }
        if (isset($data['sla_id'])) {
            $vars['sla_id'] = $data['sla_id'];
        }
        if (isset($data['auto_assign'])) {
            $vars['auto_assign'] = $data['auto_assign'];
        }
        if (isset($data['notes'])) {
            $vars['notes'] = $data['notes'];
        }
        
        if (!$topic->update($vars, $errors)) {
            $this->error(500, 'Failed to create topic: ' . implode(', ', $errors));
        }
        
        $this->success(array(
            'id' => $topic->getId(),
            'topic' => $topic->getName(),
            'ispublic' => $topic->isPublic(),
            'isactive' => $topic->isActive(),
            'created' => $topic->created  // Direct field access
        ), 201);
    }
    
    public function getTopics() {
        try {
            $topics = Topic::getHelpTopics(false, true, false);
            $result = array();
            
            foreach ($topics as $id => $name) {
                try {
                    $topic = Topic::lookup($id);
                    if ($topic) {
                        $dept = null;
                        if ($topic->getDeptId()) {
                            try {
                                if ($d = Dept::lookup($topic->getDeptId())) {
                                    $dept = array(
                                        'id' => $d->getId(),
                                        'name' => $d->getName()
                                    );
                                }
                            } catch (Exception $e) {
                                error_log('Error looking up department: ' . $e->getMessage());
                            }
                        }
                        
                        $result[] = array(
                            'id' => $topic->getId(),
                            'topic' => $topic->getName(),
                            'department' => $dept,
                            'ispublic' => $topic->isPublic(),
                            'isactive' => $topic->isActive()
                        );
                    }
                } catch (Exception $e) {
                    error_log('Error processing topic ID ' . $id . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            $this->success(array(
                'count' => count($result),
                'topics' => $result
            ));
        } catch (Exception $e) {
            $this->error(500, 'Error fetching topics: ' . $e->getMessage());
        }
    }
    
    public function getTopic($topicId) {
        $topic = Topic::lookup($topicId);
        
        if (!$topic) {
            $this->error(404, 'Topic not found');
        }
        
        // Get department info
        $dept = null;
        if ($topic->getDeptId()) {
            if ($d = Dept::lookup($topic->getDeptId())) {
                $dept = array(
                    'id' => $d->getId(),
                    'name' => $d->getName()
                );
            }
        }
        
        // Get priority info
        $priority = null;
        if ($topic->getPriorityId()) {
            if ($p = Priority::lookup($topic->getPriorityId())) {
                $priority = array(
                    'id' => $p->getId(),
                    'name' => $p->getDesc()
                );
            }
        }
        
        $this->success(array(
            'id' => $topic->getId(),
            'topic' => $topic->getName(),
            'department' => $dept,
            'priority' => $priority,
            'ispublic' => $topic->isPublic(),
            'isactive' => $topic->isActive(),
            'notes' => $topic->getNotes(),
            'created' => $topic->created,
            'updated' => $topic->updated
        ));
    }
    
    // ========== ORGANIZATION ENDPOINTS ==========
    
    public function createOrganization() {
        $data = $this->getRequestBody();
        
        // Validate required fields
        if (empty($data['name'])) {
            $this->error(400, 'Organization name is required');
        }
        
        // Create organization
        $errors = array();
        
        $vars = array(
            'name' => $data['name'],
        );
        
        // Add optional fields
        if (isset($data['website'])) {
            $vars['website'] = $data['website'];
        }
        if (isset($data['phone'])) {
            $vars['phone'] = $data['phone'];
        }
        if (isset($data['address'])) {
            $vars['address'] = $data['address'];
        }
        if (isset($data['notes'])) {
            $vars['notes'] = $data['notes'];
        }
        
        $org = Organization::create($vars, $errors);
        
        if (!$org) {
            $this->error(500, 'Failed to create organization: ' . implode(', ', $errors));
        }
        
        $this->success(array(
            'id' => $org->getId(),
            'name' => $org->getName(),
            'website' => $this->getOrgField($org, 'website'),
            'phone' => $this->getOrgField($org, 'phone'),
            'created' => $org->created
        ), 201);
    }
    
    public function getOrganizations() {
        try {
            $sql = 'SELECT id FROM '.ORGANIZATION_TABLE.' ORDER BY name';
            $result = db_query($sql);
            
            if (!$result) {
                $this->error(500, 'Database query failed');
            }
            
            $organizations = array();
            while ($row = db_fetch_row($result)) {
                try {
                    $org = Organization::lookup($row[0]);
                    if ($org) {
                        // Get user count for the organization using SQL query
                        $userCount = 0;
                        $countSql = 'SELECT COUNT(*) FROM '.USER_TABLE.' WHERE org_id='.db_input($org->getId());
                        $countResult = db_query($countSql);
                        if ($countResult && ($countRow = db_fetch_row($countResult))) {
                            $userCount = (int)$countRow[0];
                        }
                        
                        $organizations[] = array(
                            'id' => $org->getId(),
                            'name' => $org->getName(),
                            'website' => $this->getOrgField($org, 'website'),
                            'phone' => $this->getOrgField($org, 'phone'),
                            'user_count' => $userCount,
                            'created' => $org->getCreateDate(),
                            'updated' => $org->getUpdateDate()
                        );
                    }
                } catch (Exception $e) {
                    // Log error but continue with other organizations
                    error_log('getOrganizations: Error processing organization ID ' . $row[0] . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            $this->success(array(
                'count' => count($organizations),
                'organizations' => $organizations
            ));
        } catch (Exception $e) {
            $this->error(500, 'Error fetching organizations: ' . $e->getMessage());
        }
    }
    
    public function getOrganization($orgId) {
        $org = Organization::lookup($orgId);
        
        if (!$org) {
            $this->error(404, 'Organization not found');
        }
        
        // Get organization's users
        $sql = 'SELECT id FROM '.USER_TABLE.' WHERE org_id='.db_input($orgId).' ORDER BY created DESC';
        $result = db_query($sql);
        
        $users = array();
        while ($row = db_fetch_row($result)) {
            try {
                $user = User::lookup($row[0]);
                if ($user) {
                    $users[] = array(
                        'id' => $user->getId(),
                        'name' => $user->getName()->getFull(),
                        'email' => (string) $user->getEmail(),
                        'phone' => $user->getPhoneNumber()
                    );
                }
            } catch (Exception $e) {
                error_log("getOrganization: Error processing user ID " . $row[0] . ": " . $e->getMessage());
                continue;
            }
        }
        
        $this->success(array(
            'id' => $org->getId(),
            'name' => $org->getName(),
            'website' => $this->getOrgField($org, 'website'),
            'phone' => $this->getOrgField($org, 'phone'),
            'address' => $this->getOrgField($org, 'address'),
            'notes' => $this->getOrgField($org, 'notes'),
            'created' => $org->getCreateDate(),
            'updated' => $org->getUpdateDate(),
            'users' => array(
                'count' => count($users),
                'users' => $users
            )
        ));
    }
    
    // ========== USER ENDPOINTS ==========
    
    public function createUser() {
        $data = $this->getRequestBody();
        
        // Validate required fields
        if (empty($data['email'])) {
            $this->error(400, 'Email is required');
        }
        if (empty($data['name'])) {
            $this->error(400, 'Name is required');
        }
        if (empty($data['phone'])) {
            $this->error(400, 'Phone number is required');
        }
        
        // Check if user already exists by email
        $existingUser = User::lookupByEmail($data['email']);
        if ($existingUser) {
            $this->error(400, 'User with this email already exists');
        }
        
        // Create user
        $errors = array();
        
        $vars = array(
            'email' => $data['email'],
            'name' => $data['name'],
            'phone' => $data['phone'],
        );
        
        // Add optional fields
        if (isset($data['phone_mobile'])) {
            $vars['phone_mobile'] = $data['phone_mobile'];
        }
        if (isset($data['notes'])) {
            $vars['notes'] = $data['notes'];
        }
        if (isset($data['organization_id'])) {
            $vars['org_id'] = $data['organization_id'];
        }
        
        $user = User::create($vars, $errors);
        
        if (!$user) {
            $this->error(500, 'Failed to create user: ' . implode(', ', $errors));
        }
        
        // Get organization info if set
        $org = null;
        if ($user->getOrgId()) {
            if ($o = Organization::lookup($user->getOrgId())) {
                $org = array(
                    'id' => $o->getId(),
                    'name' => $o->getName()
                );
            }
        }
        
        $this->success(array(
            'id' => $user->getId(),
            'name' => $user->getName()->getFull(),
            'email' => (string) $user->getEmail(),
            'phone' => $user->getPhoneNumber(),
            'organization' => $org,
            'created' => $user->created
        ), 201);
    }
    
    public function getUsers() {
        try {
            $sql = 'SELECT id FROM '.USER_TABLE.' ORDER BY created DESC';
            $result = db_query($sql);
            
            if (!$result) {
                $this->error(500, 'Database query failed');
            }
            
            $users = array();
            while ($row = db_fetch_row($result)) {
                try {
                    $user = User::lookup($row[0]);
                    if ($user) {
                        // Get organization info if set
                        $org = null;
                        if ($user->getOrgId()) {
                            try {
                                if ($o = Organization::lookup($user->getOrgId())) {
                                    $org = array(
                                        'id' => $o->getId(),
                                        'name' => $o->getName()
                                    );
                                }
                            } catch (Exception $e) {
                                // Skip organization lookup error, continue with null
                                error_log('Error looking up organization: ' . $e->getMessage());
                            }
                        }
                        
                        // Extract primitive values to avoid circular references
                        $userName = $user->getName();
                        $userNameString = is_object($userName) ? $userName->getFull() : (string)$userName;
                        
                        $userCreated = $user->getCreateDate();
                        $userCreatedString = is_object($userCreated) ? $userCreated->format('Y-m-d H:i:s') : (string)$userCreated;
                        
                        $userUpdated = $user->getUpdateDate();
                        $userUpdatedString = is_object($userUpdated) ? $userUpdated->format('Y-m-d H:i:s') : (string)$userUpdated;
                        
                        $users[] = array(
                            'id' => (int)$user->getId(),
                            'name' => $userNameString,
                            'email' => (string)$user->getEmail(),
                            'phone' => (string)$user->getPhoneNumber(),
                            'organization' => $org,
                            'created' => $userCreatedString,
                            'updated' => $userUpdatedString
                        );
                    }
                } catch (Exception $e) {
                    // Log error but continue with other users
                    error_log('Error processing user ID ' . $row[0] . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            $this->success(array(
                'count' => count($users),
                'users' => $users
            ));
        } catch (Exception $e) {
            $this->error(500, 'Error fetching users: ' . $e->getMessage());
        } catch (Error $e) {
            $this->error(500, 'Fatal error fetching users: ' . $e->getMessage());
        }
    }
    
    public function getUser($userId) {
        $user = User::lookup($userId);
        
        if (!$user) {
            $this->error(404, 'User not found');
        }
        
        // Get organization info if set
        $org = null;
        if ($user->getOrgId()) {
            if ($o = Organization::lookup($user->getOrgId())) {
                $org = array(
                    'id' => $o->getId(),
                    'name' => $o->getName()
                );
            }
        }
        
        // Get user's tickets
        $sql = 'SELECT ticket_id FROM '.TICKET_TABLE.' WHERE user_id='.db_input($userId).' ORDER BY created DESC';
        $result = db_query($sql);
        
        $tickets = array();
        while ($row = db_fetch_row($result)) {
            $ticket = Ticket::lookup($row[0]);
            if ($ticket) {
                $status = $ticket->getStatus();
                $tickets[] = array(
                    'ticket_id' => $ticket->getId(),
                    'ticket_number' => $ticket->getNumber(),
                    'subject' => $ticket->getSubject(),
                    'status' => array(
                        'name' => $status ? $status->getName() : 'Open',
                        'is_closed' => $ticket->isClosed()
                    ),
                    'created' => $ticket->getCreateDate()
                );
            }
        }
        
        $this->success(array(
            'id' => $user->getId(),
            'name' => $user->getName()->getFull(),
            'email' => (string) $user->getEmail(),
            'phone' => $user->getPhoneNumber(),
            'phone_mobile' => $user->getVar('phone_mobile'),  // Use getVar for custom/dynamic fields
            'organization' => $org,
            'created' => $user->getCreateDate(),
            'updated' => $user->getUpdateDate(),
            'tickets' => array(
                'count' => count($tickets),
                'tickets' => $tickets
            )
        ));
    }
    
    // ========== TASK ENDPOINTS ==========
    
    public function createTask() {
        $data = $this->getRequestBody();
        
        // Validate required fields
        if (empty($data['title'])) {
            $this->error(400, 'Task title is required');
        }
        
        // Create task
        $errors = array();
        
        $vars = array(
            'number' => '', // Will be auto-generated
            'title' => $data['title'],
            'description' => isset($data['description']) ? $data['description'] : '',
            'dept_id' => isset($data['dept_id']) ? $data['dept_id'] : 0,
        );
        
        // Add optional fields
        if (isset($data['staff_id'])) {
            $vars['staff_id'] = $data['staff_id'];
        }
        if (isset($data['team_id'])) {
            $vars['team_id'] = $data['team_id'];
        }
        if (isset($data['duedate'])) {
            $vars['duedate'] = $data['duedate'];
        }
        
        $task = Task::create($vars, $errors);
        
        if (!$task) {
            $this->error(500, 'Failed to create task: ' . implode(', ', $errors));
        }
        
        $this->success(array(
            'id' => $task->getId(),
            'number' => $task->getNumber(),
            'title' => $task->getTitle(),
            'created' => $task->getCreateDate()
        ), 201);
    }
    
    public function getTasks() {
        try {
            $sql = 'SELECT id FROM '.TASK_TABLE.' ORDER BY created DESC';
            $result = db_query($sql);
            
            if (!$result) {
                $this->error(500, 'Database query failed');
            }
            
            $tasks = array();
            while ($row = db_fetch_row($result)) {
                try {
                    $task = Task::lookup($row[0]);
                    if ($task) {
                        // Get department
                        $dept = null;
                        if ($task->getDeptId()) {
                            try {
                                if ($d = Dept::lookup($task->getDeptId())) {
                                    $dept = array(
                                        'id' => $d->getId(),
                                        'name' => $d->getName()
                                    );
                                }
                            } catch (Exception $e) {
                                error_log('Error looking up department: ' . $e->getMessage());
                            }
                        }
                        
                        // Get assignee (staff or team)
                        $assignee = null;
                        if ($task->getStaffId()) {
                            try {
                                if ($staff = Staff::lookup($task->getStaffId())) {
                                    $assignee = array(
                                        'type' => 'staff',
                                        'id' => $staff->getId(),
                                        'name' => $staff->getName()->getFull()
                                    );
                                }
                            } catch (Exception $e) {
                                error_log('Error looking up staff: ' . $e->getMessage());
                            }
                        } elseif ($task->getTeamId()) {
                            try {
                                if ($team = Team::lookup($task->getTeamId())) {
                                    $assignee = array(
                                        'type' => 'team',
                                        'id' => $team->getId(),
                                        'name' => $team->getName()
                                    );
                                }
                            } catch (Exception $e) {
                                error_log('Error looking up team: ' . $e->getMessage());
                            }
                        }
                        
                        $tasks[] = array(
                            'id' => $task->getId(),
                            'number' => $task->getNumber(),
                            'title' => $task->getTitle(),
                            'status' => $task->isClosed() ? 'closed' : 'open',
                            'department' => $dept,
                            'assignee' => $assignee,
                            'due_date' => $task->getDueDate(),
                            'created' => $task->getCreateDate(),
                            'updated' => $task->getUpdateDate()
                        );
                    }
                } catch (Exception $e) {
                    error_log('Error processing task ID ' . $row[0] . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            $this->success(array(
                'count' => count($tasks),
                'tasks' => $tasks
            ));
        } catch (Exception $e) {
            $this->error(500, 'Error fetching tasks: ' . $e->getMessage());
        }
    }
    
    public function getTask($taskId) {
        $task = Task::lookup($taskId);
        
        if (!$task) {
            $this->error(404, 'Task not found');
        }
        
        // Get department
        $dept = null;
        if ($task->getDeptId()) {
            if ($d = Dept::lookup($task->getDeptId())) {
                $dept = array(
                    'id' => $d->getId(),
                    'name' => $d->getName()
                );
            }
        }
        
        // Get assignee (staff or team)
        $assignee = null;
        if ($task->getStaffId()) {
            if ($staff = Staff::lookup($task->getStaffId())) {
                $assignee = array(
                    'type' => 'staff',
                    'id' => $staff->getId(),
                    'name' => $staff->getName()->getFull(),
                    'email' => (string) $staff->getEmail()
                );
            }
        } elseif ($task->getTeamId()) {
            if ($team = Team::lookup($task->getTeamId())) {
                $assignee = array(
                    'type' => 'team',
                    'id' => $team->getId(),
                    'name' => $team->getName()
                );
            }
        }
        
        // Get thread entries (updates and notes)
        $entries = array();
        $thread = $task->getThread();
        
        if ($thread) {
            foreach ($thread->getEntries() as $entry) {
                $type = $entry->getType();
                
                // M = Message, N = Internal note, R = Response
                $entryData = array(
                    'id' => $entry->getId(),
                    'type' => $type == 'N' ? 'internal_note' : 'update',
                    'poster' => $entry->getName()->getFull(),
                    'message' => $entry->getBody()->getClean(),
                    'created' => $entry->getCreateDate(),
                );
                
                // Add staff info if available
                if ($staff = $entry->getStaff()) {
                    $entryData['staff'] = array(
                        'id' => $staff->getId(),
                        'name' => $staff->getName()->getFull(),
                        'email' => (string) $staff->getEmail()
                    );
                }
                
                $entries[] = $entryData;
            }
        }
        
        $this->success(array(
            'id' => $task->getId(),
            'number' => $task->getNumber(),
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'status' => $task->isClosed() ? 'closed' : 'open',
            'department' => $dept,
            'assignee' => $assignee,
            'due_date' => $task->getDueDate(),
            'created' => $task->getCreateDate(),
            'updated' => $task->getUpdateDate(),
            'closed' => $task->isClosed() ? $task->getCloseDate() : null,
            'thread' => array(
                'total_entries' => count($entries),
                'entries' => $entries
            )
        ));
    }
    
    // ========== FAQ ENDPOINTS ==========
    
    public function createFAQ() {
        $data = $this->getRequestBody();
        
        // Validate required fields
        if (empty($data['question'])) {
            $this->error(400, 'Question is required');
        }
        if (empty($data['answer'])) {
            $this->error(400, 'Answer is required');
        }
        
        // Create FAQ
        $errors = array();
        
        $vars = array(
            'question' => $data['question'],
            'answer' => $data['answer'],
            'category_id' => isset($data['category_id']) ? $data['category_id'] : 0,
            'ispublished' => isset($data['ispublished']) ? $data['ispublished'] : 0,
            'notes' => isset($data['notes']) ? $data['notes'] : '',
        );
        
        // Add optional keywords
        if (isset($data['keywords'])) {
            $vars['keywords'] = $data['keywords'];
        }
        
        $faq = FAQ::create($vars, $errors);
        
        if (!$faq) {
            $this->error(500, 'Failed to create FAQ: ' . implode(', ', $errors));
        }
        
        $this->success(array(
            'id' => $faq->getId(),
            'question' => $faq->getQuestion(),
            'category_id' => $faq->getCategoryId(),
            'ispublished' => $faq->isPublished(),
            'created' => $faq->getCreateDate()
        ), 201);
    }
    
    public function getFAQs() {
        try {
            $sql = 'SELECT faq_id FROM '.FAQ_TABLE.' ORDER BY created DESC';
            $result = db_query($sql);
            
            if (!$result) {
                $this->error(500, 'Database query failed');
            }
            
            $faqs = array();
            while ($row = db_fetch_row($result)) {
                try {
                    $faq = FAQ::lookup($row[0]);
                    if ($faq) {
                        // Get category info
                        $category = null;
                        if ($faq->getCategoryId()) {
                            try {
                                if ($cat = Category::lookup($faq->getCategoryId())) {
                                    $category = array(
                                        'id' => $cat->getId(),
                                        'name' => $cat->getName()
                                    );
                                }
                            } catch (Exception $e) {
                                error_log('Error looking up category: ' . $e->getMessage());
                            }
                        }
                        
                        $faqs[] = array(
                            'id' => $faq->getId(),
                            'question' => $faq->getQuestion(),
                            'answer' => $faq->getAnswerWithImages(),
                            'category' => $category,
                            'ispublished' => $faq->isPublished(),
                            'created' => $faq->getCreateDate(),
                            'updated' => $faq->getUpdateDate()
                        );
                    }
                } catch (Exception $e) {
                    error_log('Error processing FAQ ID ' . $row[0] . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            $this->success(array(
                'count' => count($faqs),
                'faqs' => $faqs
            ));
        } catch (Exception $e) {
            $this->error(500, 'Error fetching FAQs: ' . $e->getMessage());
        }
    }
    
    public function getFAQ($faqId) {
        $faq = FAQ::lookup($faqId);
        
        if (!$faq) {
            $this->error(404, 'FAQ not found');
        }
        
        // Get category info
        $category = null;
        if ($faq->getCategoryId()) {
            if ($cat = Category::lookup($faq->getCategoryId())) {
                $category = array(
                    'id' => $cat->getId(),
                    'name' => $cat->getName(),
                    'description' => $cat->getDescription()
                );
            }
        }
        
        $this->success(array(
            'id' => $faq->getId(),
            'question' => $faq->getQuestion(),
            'answer' => $faq->getAnswerWithImages(),
            'category' => $category,
            'keywords' => $faq->getKeywords(),
            'ispublished' => $faq->isPublished(),
            'notes' => $faq->getNotes(),
            'created' => $faq->getCreateDate(),
            'updated' => $faq->getUpdateDate()
        ));
    }
    
    // ========== FAQ CATEGORY ENDPOINTS ==========
    
    public function createFAQCategory() {
        $data = $this->getRequestBody();
        
        // Validate required fields
        if (empty($data['name'])) {
            $this->error(400, 'Category name is required');
        }
        
        // Create category
        $errors = array();
        
        $vars = array(
            'name' => $data['name'],
            'description' => isset($data['description']) ? $data['description'] : '',
            'ispublic' => isset($data['ispublic']) ? $data['ispublic'] : 1,
            'notes' => isset($data['notes']) ? $data['notes'] : '',
        );
        
        $category = Category::create($vars, $errors);
        
        if (!$category) {
            $this->error(500, 'Failed to create category: ' . implode(', ', $errors));
        }
        
        $this->success(array(
            'id' => $category->getId(),
            'name' => $category->getName(),
            'ispublic' => $category->isPublic(),
            'created' => $category->getCreateDate()
        ), 201);
    }
    
    public function getFAQCategories() {
        try {
            // Get all categories (public and private)
            $sql = 'SELECT category_id FROM '.FAQ_CATEGORY_TABLE.' ORDER BY name';
            $res = db_query($sql);
            
            if (!$res) {
                $this->error(500, 'Database query failed');
            }
            
            $result = array();
            while ($row = db_fetch_row($res)) {
                try {
                    $category = Category::lookup($row[0]);
                    if ($category) {
                        $faqCount = 0;
                        $countSql = 'SELECT COUNT(*) FROM '.FAQ_TABLE.' WHERE category_id='.db_input($category->getId());
                        $countResult = db_query($countSql);
                        if ($countResult && ($countRow = db_fetch_row($countResult))) {
                            $faqCount = (int)$countRow[0];
                        }
                        
                        $result[] = array(
                            'id' => $category->getId(),
                            'name' => $category->getName(),
                            'description' => $category->getDescription(),
                            'ispublic' => $category->isPublic(),
                            'faq_count' => $faqCount,
                            'created' => $category->getCreateDate(),
                            'updated' => $category->getUpdateDate()
                        );
                    }
                } catch (Exception $e) {
                    // Log error but continue with other categories
                    error_log('Error processing category ID ' . $row[0] . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            $this->success(array(
                'count' => count($result),
                'categories' => $result
            ));
        } catch (Exception $e) {
            $this->error(500, 'Error fetching FAQ categories: ' . $e->getMessage());
        }
    }
    
    public function getFAQCategory($categoryId) {
        $category = Category::lookup($categoryId);
        
        if (!$category) {
            $this->error(404, 'Category not found');
        }
        
        // Get FAQs in this category
        $faqs = array();
        $sql = 'SELECT faq_id FROM '.FAQ_TABLE.' WHERE category_id='.db_input($categoryId).' ORDER BY question';
        $result = db_query($sql);
        
        while ($row = db_fetch_row($result)) {
            $faq = FAQ::lookup($row[0]);
            if ($faq) {
                $faqs[] = array(
                    'id' => $faq->getId(),
                    'question' => $faq->getQuestion(),
                    'ispublished' => $faq->isPublished()
                );
            }
        }
        
        $this->success(array(
            'id' => $category->getId(),
            'name' => $category->getName(),
            'description' => $category->getDescription(),
            'ispublic' => $category->isPublic(),
            'notes' => $category->getNotes(),
            'created' => $category->getCreateDate(),
            'updated' => $category->getUpdateDate(),
            'faqs' => array(
                'count' => count($faqs),
                'faqs' => $faqs
            )
        ));
    }
    
    // ========== CANNED RESPONSE ENDPOINTS ==========
    
    public function createCannedResponse() {
        $data = $this->getRequestBody();
        
        // Validate required fields
        if (empty($data['title'])) {
            $this->error(400, 'Title is required');
        }
        if (empty($data['response'])) {
            $this->error(400, 'Response text is required');
        }
        
        // Create canned response
        $errors = array();
        
        $vars = array(
            'title' => $data['title'],
            'response' => $data['response'],
            'isenabled' => isset($data['isenabled']) ? $data['isenabled'] : 1,
            'dept_id' => isset($data['dept_id']) ? $data['dept_id'] : 0,
            'notes' => isset($data['notes']) ? $data['notes'] : '',
        );
        
        $canned = Canned::create($vars, $errors);
        
        if (!$canned) {
            $this->error(500, 'Failed to create canned response: ' . implode(', ', $errors));
        }
        
        $this->success(array(
            'id' => $canned->getId(),
            'title' => $canned->getTitle(),
            'isenabled' => $canned->isEnabled(),
            'created' => $canned->created
        ), 201);
    }
    
    public function getCannedResponses() {
        try {
            error_log("getCannedResponses: Fetching all canned responses");
            $sql = 'SELECT canned_id FROM '.CANNED_TABLE.' ORDER BY title';
            $result = db_query($sql);
            
            if (!$result) {
                error_log("getCannedResponses: Database query failed");
                $this->error(500, 'Database query failed');
            }
            
            $responses = array();
            while ($row = db_fetch_row($result)) {
                try {
                    $canned = Canned::lookup($row[0]);
                    if ($canned) {
                        // Get department info
                        $dept = null;
                        if ($canned->getDeptId()) {
                            try {
                                if ($d = Dept::lookup($canned->getDeptId())) {
                                    $dept = array(
                                        'id' => $d->getId(),
                                        'name' => $d->getName()
                                    );
                                }
                            } catch (Exception $e) {
                                error_log('getCannedResponses: Error looking up department: ' . $e->getMessage());
                            }
                        }
                        
                        $responses[] = array(
                            'id' => $canned->getId(),
                            'title' => $canned->getTitle(),
                            'response' => $canned->getResponse(),
                            'department' => $dept,
                            'isenabled' => $canned->isEnabled(),
                            'created' => $canned->created,
                            'updated' => $canned->updated
                        );
                    }
                } catch (Exception $e) {
                    error_log('getCannedResponses: Error processing canned response ID ' . $row[0] . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            $this->success(array(
                'count' => count($responses),
                'canned_responses' => $responses
            ));
        } catch (Exception $e) {
            $this->error(500, 'Error fetching canned responses: ' . $e->getMessage());
        }
    }
    
    public function getCannedResponse($cannedId) {
        $canned = Canned::lookup($cannedId);
        
        if (!$canned) {
            $this->error(404, 'Canned response not found');
        }
        
        // Get department info
        $dept = null;
        try {
            if ($canned->getDeptId()) {
                if ($d = Dept::lookup($canned->getDeptId())) {
                    $dept = array(
                        'id' => $d->getId(),
                        'name' => $d->getName()
                    );
                }
            }
        } catch (Exception $e) {
            error_log("getCannedResponse: Error retrieving department: " . $e->getMessage());
        }
        
        $this->success(array(
            'id' => $canned->getId(),
            'title' => $canned->getTitle(),
            'response' => $canned->getResponse(),
            'department' => $dept,
            'isenabled' => $canned->isEnabled(),
            'notes' => $canned->getNotes(),
            'created' => $canned->created,
            'updated' => $canned->updated
        ));
    }
}

// Simple routing
$path = $_SERVER['PATH_INFO'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$controller = new SimpleApiController();

// ========== TICKET ROUTES ==========
// Route: POST /tickets - Create ticket
if ($method === 'POST' && $path === '/tickets') {
    $controller->createTicket();
}
// Route: GET /tickets - List all tickets
elseif ($method === 'GET' && $path === '/tickets') {
    $controller->getTickets();
}
// Route: POST /tickets/{id}/reply - Reply to ticket (as user)
elseif ($method === 'POST' && preg_match('#^/tickets/(\d+)/reply$#', $path, $matches)) {
    $controller->replyToTicket($matches[1]);
}
// Route: POST /tickets/{id}/staff-reply - Reply to ticket (as staff)
elseif ($method === 'POST' && preg_match('#^/tickets/(\d+)/staff-reply$#', $path, $matches)) {
    $controller->replyToTicketAsStaff($matches[1]);
}
// Route: GET /tickets/{id} - Get ticket
elseif ($method === 'GET' && preg_match('#^/tickets/(\d+)$#', $path, $matches)) {
    $controller->getTicket($matches[1]);
}

// ========== DEPARTMENT ROUTES ==========
// Route: POST /departments - Create department
elseif ($method === 'POST' && $path === '/departments') {
    $controller->createDepartment();
}
// Route: GET /departments - List all departments
elseif ($method === 'GET' && $path === '/departments') {
    $controller->getDepartments();
}
// Route: GET /departments/{id} - Get department details
elseif ($method === 'GET' && preg_match('#^/departments/(\d+)$#', $path, $matches)) {
    $controller->getDepartment($matches[1]);
}

// ========== STAFF ROUTES ==========
// Route: POST /staff - Create staff member
elseif ($method === 'POST' && $path === '/staff') {
    $controller->createStaff();
}
// Route: GET /staff - List all staff
elseif ($method === 'GET' && $path === '/staff') {
    $controller->getStaffList();
}
// Route: GET /staff/{id} - Get staff details
elseif ($method === 'GET' && preg_match('#^/staff/(\d+)$#', $path, $matches)) {
    $controller->getStaff($matches[1]);
}

// ========== TOPIC ROUTES ==========
// Route: POST /topics - Create help topic
elseif ($method === 'POST' && $path === '/topics') {
    $controller->createTopic();
}
// Route: GET /topics - List all topics
elseif ($method === 'GET' && $path === '/topics') {
    $controller->getTopics();
}
// Route: GET /topics/{id} - Get topic details
elseif ($method === 'GET' && preg_match('#^/topics/(\d+)$#', $path, $matches)) {
    $controller->getTopic($matches[1]);
}

// ========== USER ROUTES ==========
// Route: POST /users - Create user
elseif ($method === 'POST' && $path === '/users') {
    $controller->createUser();
}
// Route: GET /users - List all users
elseif ($method === 'GET' && $path === '/users') {
    $controller->getUsers();
}
// Route: GET /users/{id} - Get user details
elseif ($method === 'GET' && preg_match('#^/users/(\d+)$#', $path, $matches)) {
    $controller->getUser($matches[1]);
}

// ========== ORGANIZATION ROUTES ==========
// Route: POST /organizations - Create organization
elseif ($method === 'POST' && $path === '/organizations') {
    $controller->createOrganization();
}
// Route: GET /organizations - List all organizations
elseif ($method === 'GET' && $path === '/organizations') {
    $controller->getOrganizations();
}
// Route: GET /organizations/{id} - Get organization details
elseif ($method === 'GET' && preg_match('#^/organizations/(\d+)$#', $path, $matches)) {
    $controller->getOrganization($matches[1]);
}

// ========== TASK ROUTES ==========
// Route: POST /tasks - Create task
elseif ($method === 'POST' && $path === '/tasks') {
    $controller->createTask();
}
// Route: GET /tasks - List all tasks
elseif ($method === 'GET' && $path === '/tasks') {
    $controller->getTasks();
}
// Route: GET /tasks/{id} - Get task details
elseif ($method === 'GET' && preg_match('#^/tasks/(\d+)$#', $path, $matches)) {
    $controller->getTask($matches[1]);
}

// ========== FAQ ROUTES ==========
// Route: POST /faqs - Create FAQ
elseif ($method === 'POST' && $path === '/faqs') {
    $controller->createFAQ();
}
// Route: GET /faqs - List all FAQs
elseif ($method === 'GET' && $path === '/faqs') {
    $controller->getFAQs();
}
// Route: GET /faqs/{id} - Get FAQ details
elseif ($method === 'GET' && preg_match('#^/faqs/(\d+)$#', $path, $matches)) {
    $controller->getFAQ($matches[1]);
}

// ========== FAQ CATEGORY ROUTES ==========
// Route: POST /faq-categories - Create FAQ category
elseif ($method === 'POST' && $path === '/faq-categories') {
    $controller->createFAQCategory();
}
// Route: GET /faq-categories - List all categories
elseif ($method === 'GET' && $path === '/faq-categories') {
    $controller->getFAQCategories();
}
// Route: GET /faq-categories/{id} - Get category details
elseif ($method === 'GET' && preg_match('#^/faq-categories/(\d+)$#', $path, $matches)) {
    $controller->getFAQCategory($matches[1]);
}

// ========== CANNED RESPONSE ROUTES ==========
// Route: POST /canned-responses - Create canned response
elseif ($method === 'POST' && $path === '/canned-responses') {
    $controller->createCannedResponse();
}
// Route: GET /canned-responses - List all canned responses
elseif ($method === 'GET' && $path === '/canned-responses') {
    $controller->getCannedResponses();
}
// Route: GET /canned-responses/{id} - Get canned response details
elseif ($method === 'GET' && preg_match('#^/canned-responses/(\d+)$#', $path, $matches)) {
    $controller->getCannedResponse($matches[1]);
}

// ========== 404 NOT FOUND ==========
else {
    http_response_code(404);
    echo json_encode(array(
        'success' => false,
        'error' => 'Endpoint not found',
        'available_endpoints' => array(
            'Tickets' => array(
                'POST /api/simple.php/tickets' => 'Create a new ticket',
                'GET /api/simple.php/tickets' => 'List all tickets',
                'POST /api/simple.php/tickets/{id}/reply' => 'Reply to a ticket (as user)',
                'POST /api/simple.php/tickets/{id}/staff-reply' => 'Reply to a ticket (as staff)',
                'GET /api/simple.php/tickets/{id}' => 'Get ticket details'
            ),
            'Departments' => array(
                'POST /api/simple.php/departments' => 'Create a new department',
                'GET /api/simple.php/departments' => 'List all departments',
                'GET /api/simple.php/departments/{id}' => 'Get department details'
            ),
            'Staff' => array(
                'POST /api/simple.php/staff' => 'Create a new staff member',
                'GET /api/simple.php/staff' => 'List all staff',
                'GET /api/simple.php/staff/{id}' => 'Get staff details'
            ),
            'Topics' => array(
                'POST /api/simple.php/topics' => 'Create a new help topic',
                'GET /api/simple.php/topics' => 'List all topics',
                'GET /api/simple.php/topics/{id}' => 'Get topic details'
            ),
            'Users' => array(
                'POST /api/simple.php/users' => 'Create a new user',
                'GET /api/simple.php/users' => 'List all users',
                'GET /api/simple.php/users/{id}' => 'Get user details'
            ),
            'Organizations' => array(
                'POST /api/simple.php/organizations' => 'Create a new organization',
                'GET /api/simple.php/organizations' => 'List all organizations',
                'GET /api/simple.php/organizations/{id}' => 'Get organization details'
            ),
            'Tasks' => array(
                'POST /api/simple.php/tasks' => 'Create a new task',
                'GET /api/simple.php/tasks' => 'List all tasks',
                'GET /api/simple.php/tasks/{id}' => 'Get task details'
            ),
            'FAQs' => array(
                'POST /api/simple.php/faqs' => 'Create a new FAQ',
                'GET /api/simple.php/faqs' => 'List all FAQs',
                'GET /api/simple.php/faqs/{id}' => 'Get FAQ details'
            ),
            'FAQ Categories' => array(
                'POST /api/simple.php/faq-categories' => 'Create a new FAQ category',
                'GET /api/simple.php/faq-categories' => 'List all FAQ categories',
                'GET /api/simple.php/faq-categories/{id}' => 'Get FAQ category details'
            ),
            'Canned Responses' => array(
                'POST /api/simple.php/canned-responses' => 'Create a new canned response',
                'GET /api/simple.php/canned-responses' => 'List all canned responses',
                'GET /api/simple.php/canned-responses/{id}' => 'Get canned response details'
            )
        )
    ));
}
?>


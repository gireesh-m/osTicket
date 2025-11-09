<?php
/*********************************************************************
    simple.php

    Simple REST API for local development - NO AUTHENTICATION
    
    WARNING: This API has NO authentication and should ONLY be used
    for local development. DO NOT use in production!

    Endpoints:
    Tickets:
    - POST /api/simple.php/tickets - Create a new ticket
    - POST /api/simple.php/tickets/{id}/reply - Reply to a ticket
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

**********************************************************************/

require 'api.inc.php';
require_once INCLUDE_DIR.'class.ticket.php';
require_once INCLUDE_DIR.'class.json.php';
require_once INCLUDE_DIR.'class.dept.php';
require_once INCLUDE_DIR.'class.staff.php';
require_once INCLUDE_DIR.'class.topic.php';

header('Content-Type: application/json');

class SimpleApiController {
    
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
    
    public function getTicket($ticketId) {
        // Look up the ticket
        $ticket = Ticket::lookup($ticketId);
        
        if (!$ticket) {
            $this->error(404, 'Ticket not found');
        }
        
        // Get thread entries (full conversation)
        $entries = array();
        $thread = $ticket->getThread();
        
        foreach ($thread->getEntries() as $entry) {
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
                        'email' => $staff->getEmail()
                    );
                }
                
                $entries[] = $entryData;
            }
        }
        
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
                'name' => $priority->getDesc(),
                'priority' => $priority->getTag(),
                'urgency' => $priority->getUrgency(),
                'color' => $priority->getColor()
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
                'name' => $staff->getName()->getFull(),
                'email' => $staff->getEmail()
            );
        } elseif ($team = $ticket->getTeam()) {
            $assignee = array(
                'type' => 'team',
                'id' => $team->getId(),
                'name' => $team->getName()
            );
        }
        
        // Get topic
        $topic = $ticket->getTopic();
        $topicData = null;
        if ($topic) {
            $topicData = array(
                'id' => $topic->getId(),
                'name' => $topic->getName()
            );
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
            'created' => $ticket->getCreateDate(),
            'updated' => $ticket->getUpdateDate(),
            'closed' => $isClosed ? $ticket->getCloseDate() : null,
            'user' => array(
                'id' => $ticket->getUserId(),
                'name' => $ticket->getName()->getFull(),
                'email' => $ticket->getEmail(),
                'phone' => $ticket->getPhoneNumber()
            ),
            'thread' => array(
                'total_entries' => count($entries),
                'entries' => $entries
            )
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
        
        $this->success(array(
            'id' => $dept->getId(),
            'name' => $dept->getName(),
            'ispublic' => $dept->isPublic(),
            'created' => $dept->getCreateDate()
        ), 201);
    }
    
    public function getDepartments() {
        $depts = Dept::getDepartments();
        $result = array();
        
        foreach ($depts as $id => $name) {
            $dept = Dept::lookup($id);
            if ($dept) {
                $result[] = array(
                    'id' => $dept->getId(),
                    'name' => $dept->getName(),
                    'ispublic' => $dept->isPublic(),
                    'status' => $dept->isActive() ? 'active' : 'disabled'
                );
            }
        }
        
        $this->success(array(
            'count' => count($result),
            'departments' => $result
        ));
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
                    'email' => $mgr->getEmail()
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
            'created' => $dept->getCreateDate(),
            'updated' => $dept->getUpdateDate()
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
            'dept_id' => isset($data['dept_id']) ? $data['dept_id'] : 0,
        );
        
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
        
        if (!$staff->update($vars, $errors)) {
            $this->error(500, 'Failed to create staff: ' . implode(', ', $errors));
        }
        
        $this->success(array(
            'id' => $staff->getId(),
            'username' => $staff->getUsername(),
            'name' => $staff->getName()->getFull(),
            'email' => $staff->getEmail(),
            'created' => $staff->getCreateDate()
        ), 201);
    }
    
    public function getStaffList() {
        $sql = 'SELECT staff_id FROM '.STAFF_TABLE.' ORDER BY lastname, firstname';
        $result = db_query($sql);
        
        $staffList = array();
        while ($row = db_fetch_row($result)) {
            $staff = Staff::lookup($row[0]);
            if ($staff) {
                $dept = null;
                if ($staff->getDeptId()) {
                    if ($d = Dept::lookup($staff->getDeptId())) {
                        $dept = array(
                            'id' => $d->getId(),
                            'name' => $d->getName()
                        );
                    }
                }
                
                $staffList[] = array(
                    'id' => $staff->getId(),
                    'username' => $staff->getUsername(),
                    'firstname' => $staff->getFirstName(),
                    'lastname' => $staff->getLastName(),
                    'name' => $staff->getName()->getFull(),
                    'email' => $staff->getEmail(),
                    'department' => $dept,
                    'isactive' => $staff->isActive(),
                    'isadmin' => $staff->isAdmin()
                );
            }
        }
        
        $this->success(array(
            'count' => count($staffList),
            'staff' => $staffList
        ));
    }
    
    public function getStaff($staffId) {
        $staff = Staff::lookup($staffId);
        
        if (!$staff) {
            $this->error(404, 'Staff member not found');
        }
        
        // Get department info
        $dept = null;
        if ($staff->getDeptId()) {
            if ($d = Dept::lookup($staff->getDeptId())) {
                $dept = array(
                    'id' => $d->getId(),
                    'name' => $d->getName()
                );
            }
        }
        
        $this->success(array(
            'id' => $staff->getId(),
            'username' => $staff->getUsername(),
            'firstname' => $staff->getFirstName(),
            'lastname' => $staff->getLastName(),
            'name' => $staff->getName()->getFull(),
            'email' => $staff->getEmail(),
            'phone' => $staff->getPhoneNumber(),
            'mobile' => $staff->getMobileNumber(),
            'department' => $dept,
            'isactive' => $staff->isActive(),
            'isadmin' => $staff->isAdmin(),
            'created' => $staff->getCreateDate(),
            'updated' => $staff->getUpdateDate()
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
            'created' => $topic->getCreateDate()
        ), 201);
    }
    
    public function getTopics() {
        $topics = Topic::getHelpTopics(false, true, false);
        $result = array();
        
        foreach ($topics as $id => $name) {
            $topic = Topic::lookup($id);
            if ($topic) {
                $dept = null;
                if ($topic->getDeptId()) {
                    if ($d = Dept::lookup($topic->getDeptId())) {
                        $dept = array(
                            'id' => $d->getId(),
                            'name' => $d->getName()
                        );
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
        }
        
        $this->success(array(
            'count' => count($result),
            'topics' => $result
        ));
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
            'created' => $topic->getCreateDate(),
            'updated' => $topic->getUpdateDate()
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
// Route: POST /tickets/{id}/reply - Reply to ticket
elseif ($method === 'POST' && preg_match('#^/tickets/(\d+)/reply$#', $path, $matches)) {
    $controller->replyToTicket($matches[1]);
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

// ========== 404 NOT FOUND ==========
else {
    http_response_code(404);
    echo json_encode(array(
        'success' => false,
        'error' => 'Endpoint not found',
        'available_endpoints' => array(
            'Tickets' => array(
                'POST /api/simple.php/tickets' => 'Create a new ticket',
                'POST /api/simple.php/tickets/{id}/reply' => 'Reply to a ticket',
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
            )
        )
    ));
}
?>


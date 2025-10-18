<?php
/*********************************************************************
    simple.php

    Simple REST API for local development - NO AUTHENTICATION
    
    WARNING: This API has NO authentication and should ONLY be used
    for local development. DO NOT use in production!

    Endpoints:
    - POST /api/simple.php/tickets - Create a new ticket
    - POST /api/simple.php/tickets/{id}/reply - Reply to a ticket
    - GET /api/simple.php/tickets/{id} - Get ticket details

**********************************************************************/

require 'api.inc.php';
require_once INCLUDE_DIR.'class.ticket.php';
require_once INCLUDE_DIR.'class.json.php';

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
                'priority' => $priority->getPriority()
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
            'updated' => $ticket->getLastUpdate(),
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
}

// Simple routing
$path = $_SERVER['PATH_INFO'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$controller = new SimpleApiController();

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
else {
    http_response_code(404);
    echo json_encode(array(
        'success' => false,
        'error' => 'Endpoint not found',
        'available_endpoints' => array(
            'POST /api/simple.php/tickets' => 'Create a new ticket',
            'POST /api/simple.php/tickets/{id}/reply' => 'Reply to a ticket',
            'GET /api/simple.php/tickets/{id}' => 'Get ticket details'
        )
    ));
}
?>


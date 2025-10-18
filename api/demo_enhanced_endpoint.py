#!/usr/bin/env python3
"""
Quick demo of the enhanced GET endpoint
Shows all the new data fields available
"""

import requests
import json

BASE_URL = "http://localhost/osTicket/api/simple.php"

def demo_enhanced_get_endpoint():
    """
    Demonstrates the enhanced GET endpoint with full ticket details
    """
    
    print("=" * 70)
    print("DEMO: Enhanced GET Ticket Endpoint")
    print("=" * 70)
    print()
    
    # First, create a test ticket
    print("Step 1: Creating a test ticket...")
    response = requests.post(f"{BASE_URL}/tickets", json={
        "name": "Demo User",
        "email": "demo@example.com",
        "subject": "Demo Ticket for Enhanced Endpoint",
        "message": "This ticket demonstrates all the enhanced data fields",
        "phone": "555-1234"
    })
    
    if not response.json().get('success'):
        print("❌ Failed to create ticket")
        return
    
    ticket_id = response.json()['data']['ticket_id']
    ticket_number = response.json()['data']['ticket_number']
    print(f"✓ Created ticket #{ticket_number} (ID: {ticket_id})")
    print()
    
    # Get the full ticket details
    print("Step 2: Fetching ticket details with enhanced data...")
    response = requests.get(f"{BASE_URL}/tickets/{ticket_id}")
    result = response.json()
    
    if not result.get('success'):
        print("❌ Failed to get ticket")
        return
    
    data = result['data']
    
    print("✓ Retrieved ticket details!")
    print()
    print("=" * 70)
    print("TICKET OVERVIEW")
    print("=" * 70)
    print(f"Ticket Number: #{data['ticket_number']}")
    print(f"Ticket ID: {data['ticket_id']}")
    print(f"Subject: {data['subject']}")
    print()
    
    # Status information
    print("STATUS:")
    print(f"  Name: {data['status']['name']}")
    print(f"  Status ID: {data['status']['id']}")
    print(f"  Is Closed: {data['status']['is_closed']}")
    print()
    
    # Priority information
    if data['priority']:
        print("PRIORITY:")
        print(f"  Name: {data['priority']['name']}")
        print(f"  Priority ID: {data['priority']['id']}")
        print(f"  Priority Level: {data['priority']['priority']}")
    else:
        print("PRIORITY: Not set")
    print()
    
    # Department information
    if data['department']:
        print("DEPARTMENT:")
        print(f"  Name: {data['department']['name']}")
        print(f"  Department ID: {data['department']['id']}")
    else:
        print("DEPARTMENT: Not assigned")
    print()
    
    # Topic information
    if data['topic']:
        print("TOPIC:")
        print(f"  Name: {data['topic']['name']}")
        print(f"  Topic ID: {data['topic']['id']}")
    else:
        print("TOPIC: Not set")
    print()
    
    # Assignee information
    if data['assignee']:
        print("ASSIGNEE:")
        print(f"  Type: {data['assignee']['type']}")
        print(f"  Name: {data['assignee']['name']}")
        if data['assignee']['type'] == 'staff':
            print(f"  Email: {data['assignee']['email']}")
    else:
        print("ASSIGNEE: Unassigned")
    print()
    
    # Timestamps
    print("TIMESTAMPS:")
    print(f"  Created: {data['created']}")
    print(f"  Updated: {data['updated']}")
    print(f"  Closed: {data['closed'] or 'N/A'}")
    print()
    
    # User information
    print("USER:")
    print(f"  ID: {data['user']['id']}")
    print(f"  Name: {data['user']['name']}")
    print(f"  Email: {data['user']['email']}")
    print(f"  Phone: {data['user']['phone']}")
    print()
    
    # Conversation thread
    print("=" * 70)
    print(f"CONVERSATION ({data['thread']['total_entries']} messages)")
    print("=" * 70)
    
    for i, entry in enumerate(data['thread']['entries'], 1):
        print()
        if entry['type'] == 'user_message':
            print(f"Message #{i} - 👤 USER MESSAGE")
        else:
            print(f"Message #{i} - 👨‍💼 AGENT RESPONSE")
        
        print(f"  From: {entry['poster']}")
        print(f"  Date: {entry['created']}")
        
        if entry['type'] == 'agent_response' and 'staff' in entry:
            print(f"  Staff: {entry['staff']['name']} ({entry['staff']['email']})")
        
        print(f"  Message:")
        # Wrap message text
        words = entry['message'].split()
        line = "    "
        for word in words:
            if len(line) + len(word) + 1 > 70:
                print(line)
                line = "    " + word
            else:
                line += " " + word if line != "    " else word
        if line.strip():
            print(line)
    
    print()
    print("=" * 70)
    print()
    
    # Show raw JSON
    print("RAW JSON RESPONSE:")
    print("-" * 70)
    print(json.dumps(result, indent=2))
    print()
    
    print("=" * 70)
    print("✅ Demo Complete!")
    print("=" * 70)
    print()
    print("Key Features Demonstrated:")
    print("  ✓ Full ticket metadata (status, priority, department, topic)")
    print("  ✓ User information (including phone)")
    print("  ✓ Assignment details (staff or team)")
    print("  ✓ Complete conversation history")
    print("  ✓ Clear message type distinction (user vs agent)")
    print("  ✓ Agent details in responses")
    print("  ✓ Timestamps for all events")
    print()

if __name__ == "__main__":
    try:
        demo_enhanced_get_endpoint()
    except requests.exceptions.ConnectionError:
        print("❌ ERROR: Cannot connect to osTicket API")
        print(f"   Make sure osTicket is running at: {BASE_URL}")
    except Exception as e:
        print(f"❌ ERROR: {str(e)}")


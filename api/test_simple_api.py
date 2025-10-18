#!/usr/bin/env python3
"""
Simple test script for osTicket Simple API
Run this to test the API endpoints
"""

import requests
import json
import sys

# Configuration - adjust to your setup
BASE_URL = "http://localhost/osTicket/api/simple.php"

def test_create_ticket():
    """Test creating a new ticket"""
    print("=" * 60)
    print("TEST 1: Creating a new ticket")
    print("=" * 60)
    
    data = {
        "name": "Test User",
        "email": "test@example.com",
        "subject": "API Test Ticket",
        "message": "This is a test ticket created via the Simple API",
        "phone": "555-1234"
    }
    
    print(f"POST {BASE_URL}/tickets")
    print(f"Request: {json.dumps(data, indent=2)}")
    
    try:
        response = requests.post(f"{BASE_URL}/tickets", json=data)
        result = response.json()
        
        print(f"\nStatus Code: {response.status_code}")
        print(f"Response: {json.dumps(result, indent=2)}")
        
        if result.get('success'):
            ticket_id = result['data']['ticket_id']
            ticket_number = result['data']['ticket_number']
            print(f"\n✓ SUCCESS: Ticket created - #{ticket_number} (ID: {ticket_id})")
            return ticket_id
        else:
            print(f"\n✗ FAILED: {result.get('error', 'Unknown error')}")
            return None
            
    except Exception as e:
        print(f"\n✗ ERROR: {str(e)}")
        return None

def test_reply_to_ticket(ticket_id):
    """Test replying to a ticket"""
    print("\n" + "=" * 60)
    print(f"TEST 2: Replying to ticket {ticket_id}")
    print("=" * 60)
    
    data = {
        "message": "This is a test reply posted via the Simple API"
    }
    
    print(f"POST {BASE_URL}/tickets/{ticket_id}/reply")
    print(f"Request: {json.dumps(data, indent=2)}")
    
    try:
        response = requests.post(f"{BASE_URL}/tickets/{ticket_id}/reply", json=data)
        result = response.json()
        
        print(f"\nStatus Code: {response.status_code}")
        print(f"Response: {json.dumps(result, indent=2)}")
        
        if result.get('success'):
            print(f"\n✓ SUCCESS: Reply posted")
            return True
        else:
            print(f"\n✗ FAILED: {result.get('error', 'Unknown error')}")
            return False
            
    except Exception as e:
        print(f"\n✗ ERROR: {str(e)}")
        return False

def test_get_ticket(ticket_id):
    """Test getting ticket details"""
    print("\n" + "=" * 60)
    print(f"TEST 3: Getting ticket {ticket_id} details")
    print("=" * 60)
    
    print(f"GET {BASE_URL}/tickets/{ticket_id}")
    
    try:
        response = requests.get(f"{BASE_URL}/tickets/{ticket_id}")
        result = response.json()
        
        print(f"\nStatus Code: {response.status_code}")
        print(f"Response: {json.dumps(result, indent=2)}")
        
        if result.get('success'):
            ticket = result['data']
            print(f"\n✓ SUCCESS: Retrieved ticket #{ticket['ticket_number']}")
            print(f"  Subject: {ticket['subject']}")
            print(f"  Status: {ticket['status']}")
            print(f"  User: {ticket['user']['name']} ({ticket['user']['email']})")
            print(f"  Entries: {len(ticket['entries'])}")
            for i, entry in enumerate(ticket['entries'], 1):
                print(f"    {i}. [{entry['type']}] {entry['poster']}: {entry['message'][:50]}...")
            return True
        else:
            print(f"\n✗ FAILED: {result.get('error', 'Unknown error')}")
            return False
            
    except Exception as e:
        print(f"\n✗ ERROR: {str(e)}")
        return False

def test_invalid_ticket():
    """Test getting a non-existent ticket"""
    print("\n" + "=" * 60)
    print("TEST 4: Getting non-existent ticket (should fail)")
    print("=" * 60)
    
    ticket_id = 999999
    print(f"GET {BASE_URL}/tickets/{ticket_id}")
    
    try:
        response = requests.get(f"{BASE_URL}/tickets/{ticket_id}")
        result = response.json()
        
        print(f"\nStatus Code: {response.status_code}")
        print(f"Response: {json.dumps(result, indent=2)}")
        
        if not result.get('success') and response.status_code == 404:
            print(f"\n✓ SUCCESS: Correctly returned 404 for non-existent ticket")
            return True
        else:
            print(f"\n✗ FAILED: Should have returned 404 error")
            return False
            
    except Exception as e:
        print(f"\n✗ ERROR: {str(e)}")
        return False

def main():
    print("\n" + "=" * 60)
    print("osTicket Simple API Test Suite")
    print("=" * 60)
    print(f"Base URL: {BASE_URL}")
    print()
    
    # Check if the API is accessible
    try:
        response = requests.get(BASE_URL)
        if response.status_code != 404:
            print("⚠ Warning: API endpoint seems to respond but may not be configured correctly")
    except requests.exceptions.RequestException as e:
        print(f"✗ ERROR: Cannot connect to {BASE_URL}")
        print(f"  {str(e)}")
        print("\nMake sure:")
        print("  1. osTicket is running")
        print("  2. The URL is correct")
        print("  3. The simple.php file is in the api/ directory")
        sys.exit(1)
    
    # Run tests
    results = []
    
    # Test 1: Create ticket
    ticket_id = test_create_ticket()
    results.append(("Create Ticket", ticket_id is not None))
    
    if ticket_id:
        # Test 2: Reply to ticket
        reply_success = test_reply_to_ticket(ticket_id)
        results.append(("Reply to Ticket", reply_success))
        
        # Test 3: Get ticket details
        get_success = test_get_ticket(ticket_id)
        results.append(("Get Ticket Details", get_success))
    else:
        results.append(("Reply to Ticket", False))
        results.append(("Get Ticket Details", False))
    
    # Test 4: Invalid ticket
    invalid_success = test_invalid_ticket()
    results.append(("Invalid Ticket (404)", invalid_success))
    
    # Summary
    print("\n" + "=" * 60)
    print("TEST SUMMARY")
    print("=" * 60)
    
    passed = sum(1 for _, success in results if success)
    total = len(results)
    
    for test_name, success in results:
        status = "✓ PASS" if success else "✗ FAIL"
        print(f"{status}: {test_name}")
    
    print(f"\nTotal: {passed}/{total} tests passed")
    
    if passed == total:
        print("\n🎉 All tests passed!")
        sys.exit(0)
    else:
        print("\n⚠ Some tests failed")
        sys.exit(1)

if __name__ == "__main__":
    main()


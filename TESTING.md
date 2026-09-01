# Testing Guide

This document records the manual test plan for the International Book Project.

## Test Environment

- Application: PHP web application running at `http://localhost/siqi_demo/index.php`
- Database: MySQL database `international_book_project`
- Browser: Local browser
- Test data: Development database records

## Automated Checks

| Check | Expected Result | Status |
|---|---|---|
| PHP syntax check for `index.php` | No syntax errors | Passed |
| PHP syntax check for `manage.php` | No syntax errors | Passed |
| PHP syntax check for `request_create.php` | No syntax errors | Passed |
| PHP syntax check for `shipment_create.php` | No syntax errors | Passed |
| PHP syntax check for `return_create.php` | No syntax errors | Passed |
| Git whitespace check | No whitespace errors | Passed |

## Manual Test Cases

| ID | Test | Expected Result | Status |
|---|---|---|---|
| REQ-01 | Open the Requests tab | Request records are displayed | Ready to test |
| REQ-02 | Create a request with an existing recipient and one item | A new request is created and inventory decreases | Ready to test |
| REQ-03 | Create a request with a new recipient | Recipient, request, and request items are created | Ready to test |
| REQ-04 | Request more items than available inventory | The request is rejected and inventory does not change | Ready to test |
| REQ-05 | Create a request with multiple items | All request items are saved under one request | Ready to test |
| REQ-06 | Delete a request without a shipment | Request items are deleted, inventory is restored, and the request is deleted | Ready to test |
| REQ-07 | Delete a request with a shipment | The request is not deleted and an error message is shown | Ready to test |
| SHP-01 | Open the Shipments tab | Shipment records are displayed | Ready to test |
| SHP-02 | Open Create Shipment | Only requests without an existing shipment are listed | Ready to test |
| SHP-03 | Create a shipment for an available request | A shipment is created and linked to the request | Ready to test |
| SHP-04 | Try to create a second shipment for the same request | The second shipment is rejected | Ready to test |
| RET-01 | Open the Returns tab | Return records are displayed | Ready to test |
| RET-02 | Open Process Return | Only shipments without an existing return are listed | Ready to test |
| RET-03 | Process a return | A return is created, linked to the shipment, and inventory is restored | Ready to test |
| RET-04 | Try to process a second return for the same shipment | The second return is rejected | Ready to test |
| UI-01 | Open each application tab | The correct table and actions are displayed | Ready to test |
| UI-02 | Submit a form with a missing required value | The browser or server rejects the incomplete form | Ready to test |
| UI-03 | Open the application on a narrow screen | Tables remain usable through horizontal scrolling | Ready to test |

## Manual Testing Procedure

1. Start Apache from XAMPP.
2. Start the standalone MySQL server.
3. Open `http://localhost/siqi_demo/index.php`.
4. Run each test case using development data.
5. Compare the actual result with the expected result.
6. Change the status from `Ready to test` to `Passed` or `Failed`.
7. Record any failure below.

## Defect Report Template

```text
Test ID:
Date:
Summary:
Steps to reproduce:
Expected result:
Actual result:
Severity:
Status:
```

## Current Verified Results

- PHP files pass syntax checks.
- The main page loads successfully.
- The Create Shipment page loads successfully.
- The Process Return page loads successfully.
- No production data was changed during the page-load checks.

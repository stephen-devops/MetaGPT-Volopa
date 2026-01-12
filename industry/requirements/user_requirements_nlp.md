# Volopa Mass Payments
## Consolidated Natural Language Functional Requirements (v4)

---

This document consolidates all unique user-facing functional requirements for the Volopa Mass Payments feature. Requirements are written from a user and business perspective only, without reference to technical implementation, system architecture, or technology choices. This version incorporates comprehensive coverage of all user workflows, data requirements, validation rules, approval processes, and user experience needs.

**Version:** 4.0  
**Purpose:** Product Requirements Document (PRD) - Business Objectives and User Requirements  
**Scope:** Functional requirements only (excludes technical implementation details)

---

## 1. File Template Management

### 1.1 Template Download

**1.** The system shall allow business users to initiate bulk payment processing through the Volopa platform using a file upload journey.

**2.** The system shall provide users with the ability to download a CSV template for mass payments from the Payments section of the platform.

**3.** The system shall allow users to download recipient lists filtered by a single currency at a time.

**4.** The system shall include up-to-date recipient information in downloaded templates to minimize manual data entry.

### 1.2 Template Content and Structure

**5.** The system shall include the following recipient information fields in downloaded templates: Recipient ID, Client Recipient ID, Recipient Name, Recipient Bank Country, Recipient Account Currency, Available Settlement Methods, Recipient Email Address, and CC Email Address.

**6.** The system shall include the following payment fields in the template for user completion: Payment Amount, Settlement Method, Payment Reason, Payments Reference, Purpose of Payment Code, Invoice Number, Invoice Date, and Incorporation Number.

**7.** The system shall indicate which fields require dropdown selection versus free-text entry in the template guidance.

**8.** The system shall pre-populate Available Settlement Methods for each recipient (values: Priority, Regular, or Priority and Regular).

### 1.3 Template Guidance

**9.** The system shall provide users with comprehensive guidance or instructions explaining how to complete the payment file template correctly.

**10.** The system shall provide contextual help or tooltips explaining field requirements and validation rules.

**11.** The system shall clearly indicate when specific fields are mandatory based on payment currency or recipient type.

---

## 2. Data Entry and Conditional Requirements

### 2.1 Payment Data Entry

**12.** The system shall allow users to enter payment details such as amounts, settlement methods, references, and reasons directly into the CSV template.

**13.** The system shall allow users to specify settlement method by selecting from dropdown options: Priority or Regular.

**14.** The system shall allow users to specify Purpose of Payment Code from a dropdown list dependent on recipient Bank Country and Account Currency.

### 2.2 Currency-Specific Requirements

**15.** The system shall require Invoice Number when the recipient account currency is INR (Indian Rupee).

**16.** The system shall require Invoice Date when the recipient account currency is INR (Indian Rupee).

**17.** The system shall require Incorporation Number for business recipients when the account currency is TRY (Turkish Lira).

### 2.3 Email Notification Fields

**18.** The system shall allow users to include recipient email addresses for payment notifications.

**19.** The system shall allow users to include CC email addresses for additional payment notification recipients.

---

## 3. File Upload and Size Constraints

### 3.1 Upload Interface

**20.** The system shall allow users to upload completed payment files using a drag-and-drop area or file selection option.

**21.** The system shall display clear visual feedback during the file upload process.

### 3.2 File Constraints

**22.** The system shall support up to 10,000 payment instructions within a single uploaded file.

**23.** The system shall restrict each uploaded payment file to contain payments in one currency only.

**24.** The system shall reject files exceeding the maximum payment limit of 10,000 rows.

**25.** The system shall prevent duplicate uploads of the same payment file.

---

## 4. File Validation and Error Handling

### 4.1 Format and Structure Validation

**26.** The system shall validate that uploaded files are in the correct CSV format before processing.

**27.** The system shall validate that all required fields and columns are present in the uploaded file.

**28.** The system shall validate the file structure matches the expected template format.

### 4.2 Data Validation Rules

**29.** The system shall validate uploaded data for correctness, including proper email formats, valid country codes, currency codes, purpose codes, and payment methods.

**30.** The system shall validate that recipient IDs exist in the system before allowing file processing.

**31.** The system shall validate that payment amounts are positive numeric values in the correct format.

**32.** The system shall validate that settlement methods match the available options for each recipient.

**33.** The system shall validate that all mandatory fields contain values based on currency and recipient type requirements.

### 4.3 Error Reporting and Resolution

**34.** The system shall identify and report validation errors with clear information including row number, column name, provided value, and specific error description.

**35.** The system shall allow users to download or view a detailed list of validation errors.

**36.** The system shall allow users to correct errors and re-upload the payment file.

**37.** The system shall allow users to proceed with processing valid payments even if some rows contain errors.

---

## 5. File Summary and Review

### 5.1 Summary Display

**38.** The system shall provide a summary view of each uploaded file, including total number of payments, valid payments, errored payments, and currency breakdown.

**39.** The system shall display the file name and upload timestamp in the file summary.

**40.** The system shall display the funding currency associated with each payment file.

**41.** The system shall allow users to review uploaded file summaries before proceeding or cancelling.

---

## 6. Approval Workflow

### 6.1 Approval Determination

**42.** The system shall enforce client-specific approval requirements based on currency and transaction rules.

**43.** The system shall automatically determine whether uploaded payments require approval based on predefined business rules.

### 6.2 Approver Notification

**44.** The system shall send bell icon notifications to all authorized approvers when a payment file requires approval.

**45.** The system shall ensure that notifications are sent immediately upon successful file validation when approval is required.

### 6.3 Approval Process Management

**46.** The system shall ensure that only one approver can act on a payment file at a time.

**47.** The system shall inform other approvers when a payment file has already been picked up for approval.

**48.** The system shall ensure that only authorized users can approve payment files.

**49.** The system shall prevent the same user who uploaded a file from also approving it.

### 6.4 Post-Approval Actions

**50.** The system shall redirect the first approver to click the notification to a Payment Confirmation Page to complete the payment creation.

**51.** The system shall redirect subsequent approvers who click after another approver has picked up the file to the Draft Payments Page.

---

## 7. Status Tracking and File Management

### 7.1 Status Visibility

**52.** The system shall track payment files through the following states: Draft, Validating, Validation Failed, Awaiting Approval, Approved, Processing, Completed, and Failed.

**53.** The system shall allow users to view the current processing status of uploaded payment files.

**54.** The system shall display clear status indicators (e.g., icons, colors) to distinguish between different file states.

**55.** The system shall allow users to track progress while uploaded files are being validated or processed.

**56.** The system shall display processing progress for large payment files, including percentage complete or estimated time remaining.

### 7.2 File History and Lists

**57.** The system shall allow users to view a list of all previously uploaded payment files associated with their account.

**58.** The system shall maintain a complete history of all payment files processed by each client.

**59.** The system shall allow users to filter file lists by status (e.g., draft, pending approval, completed).

### 7.3 File Deletion and Cancellation

**60.** The system shall allow users to delete payment files that are still in draft or failed validation states.

**61.** The system shall prevent users from deleting payment files that have already been approved or completed.

**62.** The system shall allow users to cancel file processing while in the validation stage.

---

## 8. User Permissions and Security

### 8.1 Role-Based Access Control

**63.** The system shall restrict file upload, approval, and deletion capabilities to authorized users based on their assigned roles.

**64.** The system shall enforce separation of duties by preventing uploaders from approving their own payment files.

---

## 9. Notifications and User Communication

### 9.1 User Notifications

**65.** The system shall notify the file uploader when file validation is complete.

**66.** The system shall notify the file uploader of validation failures with actionable guidance.

**67.** The system shall send email notifications to recipients and CC addresses once payments are successfully processed.

---

## 10. Audit and Traceability

### 10.1 Activity Logging

**68.** The system shall record the timestamp and user identity for all file uploads, approvals, and status changes.

**69.** The system shall maintain a complete audit trail of all actions taken on each payment file.

**70.** The system shall allow authorized users to view the audit history for any payment file.

---

## 11. User Experience and Guidance

### 11.1 Help and Documentation

**71.** The system shall provide accessible user guides explaining the complete mass payment workflow.

**72.** The system shall provide examples of correctly completed payment file templates.

**73.** The system shall provide error resolution guidance for common validation failures.

### 11.2 User Feedback

**74.** The system shall provide immediate visual feedback for all user actions (upload, validate, approve, delete).

**75.** The system shall display success confirmations when files are successfully uploaded, validated, or approved.

**76.** The system shall display clear error messages when operations fail, with guidance on next steps.

---

## Requirements Summary

| Section | Requirements Count |
|---------|-------------------|
| 1. File Template Management | 11 |
| 2. Data Entry and Conditional Requirements | 8 |
| 3. File Upload and Size Constraints | 6 |
| 4. File Validation and Error Handling | 12 |
| 5. File Summary and Review | 4 |
| 6. Approval Workflow | 10 |
| 7. Status Tracking and File Management | 11 |
| 8. User Permissions and Security | 2 |
| 9. Notifications and User Communication | 3 |
| 10. Audit and Traceability | 3 |
| 11. User Experience and Guidance | 6 |
| **Total** | **76** |

---

*End of Document*

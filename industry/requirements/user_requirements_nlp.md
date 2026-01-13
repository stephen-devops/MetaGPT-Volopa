# Volopa Mass Payments
## Consolidated Natural Language Functional Requirements (v5)

---

This document consolidates all unique user-facing functional requirements for the Volopa Mass Payments feature. Requirements are written from a user and business perspective only, without reference to technical implementation, system architecture, or technology choices. This version incorporates comprehensive coverage of all user workflows, data requirements, validation rules, approval processes, and user experience needs.

**Version:** 5.0
**Purpose:** Product Requirements Document (PRD) - Business Objectives and User Requirements
**Scope:** Functional requirements only (excludes technical implementation details)
**Last Updated:** 2026-01-13 (Enhanced with critical security and integration requirements)

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

### 1.4 Recipient Data Queries

**12.** The system shall allow users to retrieve lists of recipients filtered by specific currency codes to support template preparation.

**13.** The system shall allow users to view all recipients associated with a specific uploaded payment file for review and verification purposes.

---

## 2. Data Entry and Conditional Requirements

### 2.1 Payment Data Entry

**14.** The system shall allow users to enter payment details such as amounts, settlement methods, references, and reasons directly into the CSV template.

**15.** The system shall allow users to specify settlement method by selecting from dropdown options: Priority or Regular.

**16.** The system shall allow users to specify Purpose of Payment Code from a dropdown list dependent on recipient Bank Country and Account Currency.

### 2.2 Currency-Specific Requirements

**17.** The system shall require Invoice Number when the recipient account currency is INR (Indian Rupee).

**18.** The system shall require Invoice Date when the recipient account currency is INR (Indian Rupee).

**19.** The system shall require Incorporation Number for business recipients when the account currency is TRY (Turkish Lira).

### 2.3 Email Notification Fields

**20.** The system shall allow users to include recipient email addresses for payment notifications.

**21.** The system shall allow users to include CC email addresses for additional payment notification recipients.

---

## 3. File Upload and Size Constraints

### 3.1 Upload Interface and Navigation

**22.** The system shall provide access to the mass payment file upload feature from the Payments section under the navigation path: Batch Payments > File Uploads.

**23.** The system shall allow users to upload completed payment files using a drag-and-drop area or file selection option.

**24.** The system shall display clear visual feedback during the file upload process.

### 3.2 File Constraints

**25.** The system shall support up to 10,000 payment instructions within a single uploaded file.

**26.** The system shall restrict each uploaded payment file to contain payments in one currency only.

**27.** The system shall reject files exceeding the maximum payment limit of 10,000 rows.

**28.** The system shall prevent duplicate uploads of the same payment file.

---

## 4. File Validation and Error Handling

### 4.1 Format and Structure Validation

**29.** The system shall validate that uploaded files are in the correct CSV format before processing.

**30.** The system shall validate that all required fields and columns are present in the uploaded file.

**31.** The system shall validate the file structure matches the expected template format.

### 4.2 Data Validation Rules

**32.** The system shall validate uploaded data for correctness, including proper email formats, valid country codes, currency codes, purpose codes, and payment methods.

**33.** The system shall validate that recipient IDs exist in the system before allowing file processing.

**34.** The system shall validate that payment amounts are positive numeric values in the correct format.

**35.** The system shall validate that settlement methods match the available options for each recipient.

**36.** The system shall validate that all mandatory fields contain values based on currency and recipient type requirements.

### 4.3 Error Reporting and Resolution

**37.** The system shall identify and report validation errors with clear information including row number, column name, provided value, and specific error description.

**38.** The system shall allow users to download or view a detailed list of validation errors.

**39.** The system shall allow users to correct errors and re-upload the payment file.

**40.** The system shall allow users to proceed with processing valid payments even if some rows contain errors.

---

## 5. File Summary and Review

### 5.1 Summary Display

**41.** The system shall provide a summary view of each uploaded file, including total number of payments, valid payments, errored payments, and currency breakdown.

**42.** The system shall display the file name and upload timestamp in the file summary.

**43.** The system shall display the funding currency associated with each payment file.

**44.** The system shall allow users to review uploaded file summaries before proceeding or cancelling.

---

## 6. Approval Workflow

### 6.1 Approval Determination

**45.** The system shall enforce client-specific approval requirements based on currency and transaction rules.

**46.** The system shall automatically determine whether uploaded payments require approval based on predefined business rules.

### 6.2 Approver Notification

**47.** The system shall send bell icon notifications to all authorized approvers when a payment file requires approval.

**48.** The system shall ensure that notifications are sent immediately upon successful file validation when approval is required.

### 6.3 Approval Process Management

**49.** The system shall ensure that only one approver can act on a payment file at a time.

**50.** The system shall inform other approvers when a payment file has already been picked up for approval.

**51.** The system shall ensure that only authorized users can approve payment files.

**52.** The system shall prevent the same user who uploaded a file from also approving it.

### 6.4 Post-Approval Actions

**53.** The system shall redirect the first approver to click the notification to a Payment Confirmation Page to complete the payment creation.

**54.** The system shall redirect subsequent approvers who click after another approver has picked up the file to the Draft Payments Page.

---

## 7. Status Tracking and File Management

### 7.1 Status Visibility

**55.** The system shall track payment files through the following states: Draft, Validating, Validation Failed, Awaiting Approval, Approved, Processing, Completed, and Failed.

**56.** The system shall allow users to view the current processing status of uploaded payment files.

**57.** The system shall display clear status indicators (e.g., icons, colors) to distinguish between different file states.

**58.** The system shall allow users to track progress while uploaded files are being validated or processed.

**59.** The system shall display processing progress for large payment files, including percentage complete or estimated time remaining.

### 7.2 File History and Lists

**60.** The system shall allow users to view a list of all previously uploaded payment files associated with their account.

**61.** The system shall maintain a complete history of all payment files processed by each client.

**62.** The system shall allow users to filter file lists by status (e.g., draft, pending approval, completed).

### 7.3 File Deletion and Cancellation

**63.** The system shall allow users to delete payment files that are still in draft or failed validation states.

**64.** The system shall prevent users from deleting payment files that have already been approved or completed.

**65.** The system shall allow users to cancel file processing while in the validation stage.

---

## 8. User Permissions and Security

### 8.1 Role-Based Access Control

**66.** The system shall restrict file upload, approval, and deletion capabilities to authorized users based on their assigned roles.

**67.** The system shall enforce separation of duties by preventing uploaders from approving their own payment files.

### 8.2 Multi-Tenant Data Isolation

**68.** The system shall ensure that users can only access payment files and data belonging to their own organization or client account.

**69.** The system shall prevent any cross-organization visibility, ensuring complete data isolation between different client organizations.

**70.** The system shall automatically filter all data queries, file lists, and reports by the authenticated user's organization identifier.

**71.** The system shall enforce data isolation at all system levels to prevent unauthorized access to other organizations' payment information.

---

## 9. Notifications and User Communication

### 9.1 User Notifications

**72.** The system shall notify the file uploader when file validation is complete.

**73.** The system shall notify the file uploader of validation failures with actionable guidance.

**74.** The system shall send email notifications to recipients and CC addresses once payments are successfully processed.

---

## 10. Audit and Traceability

### 10.1 Activity Logging

**75.** The system shall record the timestamp and user identity for all file uploads, approvals, and status changes.

**76.** The system shall maintain a complete audit trail of all actions taken on each payment file.

**77.** The system shall allow authorized users to view the audit history for any payment file.

---

## 11. User Experience and Guidance

### 11.1 Help and Documentation

**78.** The system shall provide accessible user guides explaining the complete mass payment workflow.

**79.** The system shall provide examples of correctly completed payment file templates.

**80.** The system shall provide error resolution guidance for common validation failures.

### 11.2 User Feedback

**81.** The system shall provide immediate visual feedback for all user actions (upload, validate, approve, delete).

**82.** The system shall display success confirmations when files are successfully uploaded, validated, or approved.

**83.** The system shall display clear error messages when operations fail, with guidance on next steps.

---

## 12. Platform Integration

### 12.1 Admin Platform Integration

**84.** The system shall integrate seamlessly with the existing Volopa admin platform to provide a unified user experience.

**85.** The system shall maintain consistent look-and-feel, navigation patterns, and terminology with the broader Volopa platform.

**86.** The system shall leverage the existing Volopa authentication and authorization infrastructure for single sign-on capabilities.

**87.** The system shall integrate with existing Volopa recipient and account management systems to avoid data duplication.

---

## Requirements Summary

| Section | Requirements Count |
|---------|-------------------|
| 1. File Template Management | 13 (1-13) |
| 2. Data Entry and Conditional Requirements | 8 (14-21) |
| 3. File Upload and Size Constraints | 7 (22-28) |
| 4. File Validation and Error Handling | 12 (29-40) |
| 5. File Summary and Review | 4 (41-44) |
| 6. Approval Workflow | 10 (45-54) |
| 7. Status Tracking and File Management | 11 (55-65) |
| 8. User Permissions and Security | 6 (66-71) |
| 9. Notifications and User Communication | 3 (72-74) |
| 10. Audit and Traceability | 3 (75-77) |
| 11. User Experience and Guidance | 6 (78-83) |
| 12. Platform Integration | 4 (84-87) |
| **Total** | **87** |

---

## Change Log

### Version 5.0 (2026-01-13)
**Enhancements added based on comparative analysis with JSON requirements:**

1. **Section 1.4 (Requirements 12-13)**: Added recipient data query capabilities
   - Retrieve recipients filtered by currency
   - View recipients associated with specific payment files

2. **Section 3.1 (Requirement 22)**: Added navigation path specification
   - Explicit location: Payments > Batch Payments > File Uploads

3. **Section 8.2 (Requirements 68-71)**: Added critical multi-tenant data isolation requirements
   - Organization-level data access control
   - Cross-organization visibility prevention
   - Automatic filtering by organization identifier
   - System-wide data isolation enforcement

4. **Section 12 (Requirements 84-87)**: Added platform integration requirements
   - Seamless integration with Volopa admin platform
   - Consistent UI/UX patterns
   - Single sign-on authentication
   - Integration with existing recipient and account systems

**Critical Security Enhancement:** Multi-tenant data isolation (Requirements 68-71) addresses the security-critical requirement that was identified as missing from the previous version.

---

*End of Document*

# Laravel Architectural Requirements - LaravelArchitect
## Consolidated Natural Language Architecture Patterns and Design Constraints

---

This document consolidates all architectural design patterns and constraints for the Volopa Mass Payments Laravel API system. Requirements are written from a system design perspective, focusing on architectural decisions, design patterns, and structural constraints without implementation-specific syntax.

**Version:** 1.0
**Purpose:** Architectural Design Requirements - System Architecture and Design Patterns
**Scope:** Architectural requirements only (excludes implementation syntax and code-level details)
**Source:** industry/dos_and_donts.pdf + industry/Volopa - Proposed Architecture.pdf
**Target Agent:** LaravelArchitect
**Output:** docs/system_design/volopa_mass_payments.md

---

## Core Architectural Mental Model

The system shall follow this architectural flow for all API requests:

**Client → route (versioned, throttled, auth) → controller → FormRequest (validation + policy) → domain logic (services/models/transactions) → API Resource (shape output) → JSON with correct status codes**

### Architectural Layers

**1.** The routing layer shall handle API versioning, rate limiting, and authentication through middleware.

**2.** The controller layer shall route requests to domain logic without containing business logic.

**3.** The validation layer shall perform input validation through FormRequests and authorization through Policies.

**4.** The domain layer shall contain all business logic, data access, and transaction coordination through Services and Models.

**5.** The response layer shall transform and format output through API Resources.

---

## 1. API Structure Design

### 1.1 Route Versioning and Naming

**6.** The system shall design all API routes under versioned prefixes (e.g., /api/v1) to enable backward compatibility.

**7.** The system shall use consistent route naming conventions following the pattern: api.v1.{resource}.{action}.

**8.** The system shall apply authentication middleware to all API routes.

**9.** The system shall apply rate limiting middleware to protect against abuse.

**10.** The system shall use Volopa-specific custom authorization via OAuth2 or WSSE credentials through middleware.

---

## 2. Database Schema Design

### 2.1 Migration Architecture

**11.** The system shall design database schema changes through version-controlled migrations.

**12.** The system shall specify table structures including column types, nullable constraints, and default values.

**13.** The system shall plan migrations for new tables including mass_payment_files and payment_instructions.

### 2.2 Relationship Design

**14.** The system shall design model relationships using ORM patterns (belongsTo, hasMany, belongsToMany).

**15.** The system shall specify that MassPaymentFile belongs to TccAccount and has many PaymentInstructions.

**16.** The system shall specify that PaymentInstruction belongs to MassPaymentFile and Beneficiary.

### 2.3 Index and Constraint Design

**17.** The system shall design database indexes on foreign keys for query performance.

**18.** The system shall design indexes on frequently queried columns including status and created_at.

**19.** The system shall design indexes on client_id for tenant isolation enforcement.

**20.** The system shall use UUID primary keys where appropriate for distributed systems.

**21.** The system shall design unique constraints to enforce data integrity rules.

**22.** The system shall design foreign key constraints with appropriate cascade behavior.

---

## 3. Validation & Authorization Design

### 3.1 FormRequest Architecture

**23.** The system shall centralize validation logic in FormRequest classes separate from controllers.

**24.** The system shall create dedicated FormRequest classes for each endpoint operation (e.g., UploadMassPaymentFileRequest, ApproveMassPaymentFileRequest).

**25.** The system shall define validation rules within FormRequest classes.

### 3.2 Policy-Based Authorization

**26.** The system shall centralize permission checks in Policy classes.

**27.** The system shall create Policy classes with methods for each action (view, create, update, delete, approve).

**28.** The system shall design MassPaymentFilePolicy with methods for approve(), view(), and delete() actions.

### 3.3 Safe Query Filtering

**29.** The system shall design query filter architecture to prevent SQL injection.

**30.** The system shall define allowed filter fields as a whitelist.

**31.** The system shall validate filter input before query building to prevent query manipulation.

---

## 4. Controller-Service Separation Design

### 4.1 Thin Controller Pattern

**32.** The system shall design thin controllers that only coordinate between layers without containing business logic.

**33.** Controllers shall perform only these responsibilities: validate via FormRequest, authorize via Policy, delegate to service, and return Resource.

**34.** Controller methods shall rarely exceed 10-15 lines.

### 4.2 Service Layer Architecture

**35.** The system shall design a service layer to contain all business logic.

**36.** The system shall create service classes for complex operations (e.g., CsvValidationService, PaymentProcessingService).

**37.** Services shall handle multi-step operations, external API calls, complex validation, and transaction coordination.

### 4.3 HTTP Status Code Design

**38.** The system shall use HTTP 201 Created for successful POST operations that create resources.

**39.** The system shall use HTTP 204 No Content for successful DELETE operations.

**40.** The system shall use HTTP 200 OK for successful GET and PUT operations.

**41.** The system shall use HTTP 400 Bad Request for malformed requests.

**42.** The system shall use HTTP 401 Unauthorized for missing or invalid credentials.

**43.** The system shall use HTTP 403 Forbidden for authenticated users without authorization.

**44.** The system shall use HTTP 404 Not Found when requested resources don't exist.

**45.** The system shall use HTTP 422 Unprocessable Entity for validation failures.

---

## 5. Transaction Boundary Design

### 5.1 Multi-Write Transaction Architecture

**46.** The system shall design transaction boundaries around all multi-write operations to ensure data integrity.

**47.** The system shall wrap the following operations in transactions: creating mass payment file with payment instructions, approving payment file with status updates and audit logs, and deleting payment files with cascade deletion of instructions.

**48.** All writes within a transaction shall succeed together or fail together.

### 5.2 Transaction Scope Design

**49.** The system shall keep transaction scopes small to balance data integrity with performance.

**50.** The system shall avoid including long-running operations inside transactions.

**51.** The system shall not include external API calls, file I/O, or email sending inside transaction boundaries.

---

## 6. N+1 Query Prevention Design

### 6.1 Eager Loading Strategy

**52.** The system shall design eager loading strategies to prevent N+1 query problems.

**53.** The system shall specify eager loading for all relationships accessed in API responses.

**54.** The system shall eager load MassPaymentFile with client and paymentInstructions relationships.

**55.** The system shall eager load PaymentInstruction with beneficiary and massPaymentFile.client relationships.

**56.** The system shall apply eager loading especially in API Resource classes and list endpoints.

### 6.2 Pagination Design

**57.** The system shall design pagination for all list endpoints to prevent unbounded result sets.

**58.** The system shall use a default page size of 20 items.

**59.** The system shall enforce a maximum page size of 100 items.

---

## 7. API Response Design

### 7.1 API Resource Architecture

**60.** The system shall design API Resource classes for all API responses.

**61.** The system shall transform models to JSON through API Resources, never returning raw Eloquent models.

**62.** The system shall create dedicated Resource classes (e.g., MassPaymentFileResource, PaymentInstructionResource).

**63.** API Resources shall hide internal fields and decouple the API from the database schema.

### 7.2 Response Consistency Design

**64.** The system shall use snake_case for all JSON keys.

**65.** The system shall maintain consistent error format with message and errors fields.

**66.** The system shall use consistent pagination structure across all endpoints.

**67.** Error responses shall include a human-readable message and validation errors keyed by field name.

### 7.3 Pagination Response Structure

**68.** The system shall use standardized pagination format for all paginated collections.

**69.** Pagination responses shall include a data array containing the resources.

**70.** Pagination responses shall include links for navigation (first, last, prev, next).

**71.** Pagination responses shall include metadata (current_page, total, per_page).

---

## 8. Asynchronous Processing Design

### 8.1 Queue Job Architecture

**72.** The system shall design queue jobs for operations processing more than 1000 rows.

**73.** The system shall design queue jobs for operations exceeding 30 seconds.

**74.** The system shall handle CSV files containing up to 10,000 payment instructions asynchronously.

**75.** The system shall design jobs for ValidateMassPaymentFile and ProcessPaymentInstructions operations.

### 8.2 Status Polling Pattern

**76.** The system shall design status polling endpoints for clients to track long-running operations.

**77.** The system shall return HTTP 201 Created with status='processing' for async operations.

**78.** The system shall provide GET endpoints to poll operation status.

**79.** The system shall design status flow: uploading → validating → validation_failed/validation_successful → pending_approval → approved → completed.

---

## 9. Caching Strategy Design

### 9.1 Reference Data Caching

**80.** The system shall design caching strategy for immutable or rarely-changing reference data.

**81.** The system shall cache currency codes with TTL of 1 day.

**82.** The system shall cache purpose codes by country and currency with TTL of 1 hour.

**83.** The system shall cache client feature flags with TTL of 5 minutes.

### 9.2 Cache Invalidation Design

**84.** The system shall design cache invalidation strategies to ensure consistency with database.

**85.** The system shall define cache invalidation triggers including admin updates and TTL expiration.

---

## 10. Security Design

### 10.1 Stateless API Architecture

**86.** The system shall design stateless API architecture without relying on server-side sessions.

**87.** The system shall use token-based authentication (OAuth2 or WSSE) instead of session cookies.

**88.** The system shall implement Volopa's custom authorization via OAuth2 access tokens or WSSE credentials.

### 10.2 Tenant Isolation Architecture

**89.** The system shall design multi-tenant architecture where all data access is filtered by client_id.

**90.** The system shall enforce tenant isolation at the global scope level.

**91.** The system shall implement tenant isolation through global scopes on models, middleware enforcement, and Policy checks.

**92.** The system shall include client_id on all tenant-scoped database tables.

### 10.3 Safe Input Handling Design

**93.** The system shall validate all input through FormRequests to prevent injection attacks.

**94.** The system shall use fillable or guarded properties on models to prevent mass-assignment vulnerabilities.

---

## 11. Architectural Anti-Patterns to Avoid

### 11.1 Response Anti-Patterns

**95.** The system shall not return raw Eloquent models in API responses as this exposes internal structure and leaks sensitive fields.

**96.** The system shall not use session or redirect patterns in APIs as APIs must be stateless.

**97.** The system shall not return HTTP 200 status code for errors as this violates HTTP semantics.

**98.** The system shall not use inconsistent response shapes or casing as this breaks client expectations.

### 11.2 Query Anti-Patterns

**99.** The system shall not build query filters directly from user input as this creates SQL injection risk.

**100.** The system shall not access relationships without eager loading as this causes N+1 query problems.

**101.** The system shall not return unbounded lists as this causes memory exhaustion and slow responses.

### 11.3 Data Integrity Anti-Patterns

**102.** The system shall not perform multi-write operations without transactions as this causes data inconsistency.

### 11.4 Performance Anti-Patterns

**103.** The system shall not ignore caching for reference data as this causes repeated unnecessary database queries.

**104.** The system shall not process large file uploads synchronously as this causes request timeouts and poor user experience.

---

## 12. Volopa-Specific Architecture

### 12.1 Authentication Architecture

**105.** The system shall use Volopa's custom authentication methods: OAuth2 or WSSE.

**106.** The system shall implement custom authorization via middleware, not standard Laravel auth.

**107.** The system shall apply Volopa's custom auth middleware to all routes.

### 12.2 Multi-Tenant Design

**108.** The system shall enable multi-tenant architecture where all user actions are scoped to their client.

**109.** The system shall prevent cross-client data access.

**110.** The system shall filter all queries by the authenticated user's client_id.

---

## Architecture Requirements Summary

| Section | Requirements Count |
|---------|-------------------|
| 1. API Structure Design | 5 |
| 2. Database Schema Design | 12 |
| 3. Validation & Authorization Design | 9 |
| 4. Controller-Service Separation Design | 13 |
| 5. Transaction Boundary Design | 6 |
| 6. N+1 Query Prevention Design | 8 |
| 7. API Response Design | 12 |
| 8. Asynchronous Processing Design | 8 |
| 9. Caching Strategy Design | 6 |
| 10. Security Design | 9 |
| 11. Architectural Anti-Patterns to Avoid | 10 |
| 12. Volopa-Specific Architecture | 5 |
| **Total** | **103** |

---

## Output Sections Expected from LaravelArchitect

When creating system design documentation, the architect shall address:

1. Data Models (with relationships, indexes, constraints)
2. API Endpoint Design (routes, middleware, versioning)
3. Service Layer Architecture (business logic separation)
4. Transaction Boundaries (multi-write operations)
5. Eager Loading Strategy (N+1 prevention)
6. Pagination Requirements (all list endpoints)
7. API Resource Design (response transformation)
8. Async Job Architecture (large file processing)
9. Caching Strategy (reference data)
10. Security Design (auth, tenant isolation, validation)

---

*End of Document*
# Dimension Mapping: Six Ingestion Dimensions to Requirements Files

This table maps the six fixed classification dimensions [Intent, Requirement, Constraint, Flow, Interface, Design] to the three `*_requirements.json` files used for MetaGPT role generation.

## Mapping Table

| Dimension | `user_requirements.json` | `architectural_requirements.json` | `technical_requirements.json` |
|-----------|--------------------------|-----------------------------------|-------------------------------|
| **1. Intent** (why / for whom / success) | **Strong** - `project_metadata.description` (L7), `agent_assignments` (L15-58) clarify stakeholder roles and responsibilities | **Minimal** - `meta.role` (L7) describes architect purpose only | **None** - No intent-level content; purely implementation-focused |
| **2. Requirement** (capability user expects) | **Heavy** - 15 FRs with 42 sub-requirements (L60-919), acceptance criteria, user stories | **Moderate** - ARCH-* requirements (L38-321) define expected system capabilities | **Moderate** - IMPL-* requirements (L47-423) define expected code behaviors |
| **3. Constraint** (must/shall; limits; policies) | **Moderate** - 10K row limit (L116-127), currency-specific validation (L227-244), single currency per file (L653-670), role permissions (L898-916) | **Heavy** - `architectural_donts` (L324-400), transaction boundaries (L156-178), N+1 prevention (L181-205), security constraints (L294-320) | **Heavy** - `implementation_anti_patterns` (L425-560), validation rules (L509-523), security anti-patterns (L468-489) |
| **4. Flow** (sequence/state/approval) | **Strong** - Status state machine with 8 states (L340-349), approval workflow (L393-486), async processing (L783-826), user journeys (L927) | **Moderate** - `mental_model.flow` (L13), `async_processing_architecture.status_flow` (L264) | **Minimal** - `implementation_workflow.steps` (L16-44) - procedural, not business flow |
| **5. Interface** (API, routes, CSV format, status codes) | **Strong** - CSV columns (L88-105), API endpoints per FR (L145, L157), route paths, file structure (L939-950) | **Heavy** - Routes ARCH-ROUTE-* (L39-63), HTTP status codes ARCH-CTRL-003 (L138-153), API Resources ARCH-RESP-* (L208-241) | **Heavy** - Route syntax IMPL-ROUTE-* (L48-76), API Resource syntax IMPL-RES-* (L245-273), error handling (L388-403), testing (L350-385) |
| **6. Design** (how to build; components; patterns; tech) | **Minimal** - Tech stack only (L9-10), file structure overview (L939-950) | **Heavy** - Layered architecture (L11-35), service patterns (L120-154), caching strategy (L269-291), async job design (L244-266), security design (L294-320) | **Heavy** - Laravel syntax patterns (L47-423), file organization (L563-584), naming conventions (L578-584), volopa-specific (L587-601) |

---

## Line Number Reference Index

### user_requirements.json

| Dimension | Line Numbers | Content |
|-----------|--------------|---------|
| **Intent** | L2-13 | `project_metadata` with description, framework, version |
| | L15-58 | `agent_assignments` defining roles (PM, Architect, Engineer) |
| **Requirement** | L60-127 | FR-1: Template Management |
| | L130-165 | FR-2: File Upload |
| | L168-265 | FR-3: File Validation |
| | L268-329 | FR-4: File Review & Summary |
| | L332-390 | FR-5: File Status Management |
| | L393-486 | FR-6: Approval Workflow |
| | L489-532 | FR-7: Payment Instructions Creation |
| | L535-599 | FR-8: Data Retrieval & Queries |
| | L602-645 | FR-9: File Management Actions |
| | L648-691 | FR-10: Multi-Currency Support |
| | L694-736 | FR-11: User Guidance |
| | L739-780 | FR-12: Error Handling |
| | L783-826 | FR-13: Asynchronous Processing |
| | L829-872 | FR-14: Integration Requirements |
| | L875-917 | FR-15: Security & Access Control |
| **Constraint** | L116-127 | FR-1.3: 10K row limit |
| | L192-201 | FR-3.2: Size validation |
| | L227-244 | FR-3.4: Currency-specific mandatory fields (INR, TRY) |
| | L653-670 | FR-10.1: Single currency per file |
| | L671-691 | FR-10.2: Currency-specific validation rules |
| | L880-896 | FR-15.1: Client data isolation |
| | L898-916 | FR-15.2: Role-based permissions |
| **Flow** | L340-349 | 8 file statuses (Uploading → Deleted) |
| | L398-413 | FR-6.1: Approval requirement check workflow |
| | L432-448 | FR-6.3: First approver action flow |
| | L450-465 | FR-6.4: Subsequent approver handling |
| | L788-805 | FR-13.1: Background job processing flow |
| | L927 | `key_journeys`: Download Template, Upload CSV, Review & Approve |
| **Interface** | L88-105 | CSV columns (16 required columns) |
| | L145, L157 | API endpoint paths (/api/v1/mass-payments) |
| | L939-950 | `laravel_file_structure` paths |
| **Design** | L9-10 | Tech stack: Laravel 10+, PHP 8.2+ |
| | L11-12 | API prefix, max capacity |

### architectural_requirements.json

| Dimension | Line Numbers | Content |
|-----------|--------------|---------|
| **Intent** | L7 | `meta.role`: "Designs system architecture following these patterns" |
| **Requirement** | L43-47 | ARCH-ROUTE-001: Versioned routes |
| | L49-54 | ARCH-ROUTE-002: Consistent route naming |
| | L56-62 | ARCH-ROUTE-003: Authentication middleware |
| | L70-74 | ARCH-DATA-001: Database schema with migrations |
| | L76-81 | ARCH-DATA-002: Model relationships |
| | L83-89 | ARCH-DATA-003: Database indexes |
| | L97-101 | ARCH-VALID-001: FormRequest validation |
| | L104-108 | ARCH-VALID-002: Policy-based authorization |
| | L124-128 | ARCH-CTRL-001: Thin controllers |
| | L131-135 | ARCH-CTRL-002: Service layer |
| | L138-152 | ARCH-CTRL-003: HTTP status codes |
| | L185-194 | ARCH-PERF-001: Eager loading strategy |
| | L197-203 | ARCH-PERF-002: Pagination |
| | L211-217 | ARCH-RESP-001: API Resources |
| | L248-257 | ARCH-ASYNC-001: Queue jobs for large files |
| | L260-265 | ARCH-ASYNC-002: Status polling pattern |
| | L272-282 | ARCH-CACHE-001: Caching strategy |
| **Constraint** | L110-116 | ARCH-VALID-003: Safe query filter architecture |
| | L160-169 | ARCH-TRANS-001: Transaction boundaries |
| | L171-177 | ARCH-TRANS-002: Transaction scope |
| | L297-303 | ARCH-SEC-001: Stateless API |
| | L305-311 | ARCH-SEC-002: Tenant isolation |
| | L313-319 | ARCH-SEC-003: Safe input handling |
| | L324-400 | `architectural_donts` (all anti-patterns) |
| | L327-332 | ARCH-DONT-001: Raw Eloquent models |
| | L356-359 | ARCH-DONT-005: Query filters from user input |
| | L361-366 | ARCH-DONT-006: N+1 queries |
| | L377-383 | ARCH-DONT-008: Multi-write without transactions |
| **Flow** | L13 | `mental_model.flow`: Client → route → controller → FormRequest → domain logic → API Resource → JSON |
| | L264 | `status_flow`: uploading → validating → validation_failed/successful → pending_approval → approved → completed |
| **Interface** | L39-63 | `routing_design` with ARCH-ROUTE-001 to 003 |
| | L141-150 | HTTP status codes mapping (201, 204, 200, 400, 401, 403, 404, 422) |
| | L219-228 | ARCH-RESP-002: Response shapes and casing |
| | L230-240 | ARCH-RESP-003: Pagination response structure |
| **Design** | L11-35 | `mental_model` with 5 layers (routing, controller, validation, domain, response) |
| | L120-154 | `controller_service_architecture` |
| | L156-178 | `transaction_architecture` |
| | L181-205 | `query_performance_architecture` |
| | L208-241 | `response_architecture` |
| | L244-266 | `async_processing_architecture` |
| | L269-291 | `caching_architecture` |
| | L294-320 | `security_architecture` |
| | L402-416 | `volopa_specific_architecture` |

### technical_requirements.json

| Dimension | Line Numbers | Content |
|-----------|--------------|---------|
| **Intent** | - | None |
| **Requirement** | L52-56 | IMPL-ROUTE-001: Route facade syntax |
| | L59-68 | IMPL-ROUTE-002: Route naming |
| | L71-75 | IMPL-ROUTE-003: Middleware syntax |
| | L83-87 | IMPL-MIG-001: Schema::create() |
| | L90-97 | IMPL-MIG-002: Indexes |
| | L100-103 | IMPL-MIG-003: Foreign keys |
| | L118-128 | IMPL-MODEL-001: Relationships |
| | L131-135 | IMPL-MODEL-002: $fillable/$guarded |
| | L138-141 | IMPL-MODEL-003: Global scopes |
| | L157-161 | IMPL-REQ-001: Validation rules |
| | L164-167 | IMPL-REQ-002: Authorization |
| | L182-187 | IMPL-POL-001: Policy methods |
| | L202-205 | IMPL-CTRL-001: Thin controllers |
| | L208-211 | IMPL-CTRL-002: Dependency injection |
| | L214-222 | IMPL-CTRL-003: Status code responses |
| | L231-234 | IMPL-SVC-001: DB::transaction() |
| | L249-253 | IMPL-RES-001: Resource toArray() |
| | L280-283 | IMPL-QUERY-001: Eager loading |
| | L286-290 | IMPL-QUERY-002: Pagination |
| | L305-309 | IMPL-JOB-001: ShouldQueue jobs |
| | L330-333 | IMPL-CACHE-001: Cache::remember() |
| | L354-357 | IMPL-TEST-001: actingAs() |
| | L392-395 | IMPL-ERR-001: Error response format |
| | L410-414 | IMPL-LOG-001: Log facade |
| **Constraint** | L428-441 | IMPL-DONT-001/002: Code existence checks |
| | L444-465 | IMPL-DONT-003/004/005: Hardcoding anti-patterns |
| | L468-489 | IMPL-DONT-006/007/008: Security anti-patterns |
| | L492-506 | IMPL-DONT-009/010: Query anti-patterns |
| | L509-523 | IMPL-DONT-011/012: Validation anti-patterns |
| | L526-533 | IMPL-DONT-013: Transaction anti-patterns |
| | L536-550 | IMPL-DONT-014/015: Response anti-patterns |
| | L553-559 | IMPL-DONT-016: Observability anti-patterns |
| **Flow** | L18-25 | `implementation_workflow.steps` (7 steps) |
| | L27-44 | `implementation_checklist` (16 items) |
| **Interface** | L48-76 | `routing` syntax patterns |
| | L79-111 | `migrations` syntax patterns |
| | L153-175 | `form_requests` syntax patterns |
| | L198-224 | `controllers` syntax patterns |
| | L245-273 | `resources` syntax patterns |
| | L276-298 | `queries` syntax patterns |
| | L350-385 | `testing` syntax patterns |
| | L388-403 | `error_handling` syntax patterns |
| **Design** | L47-423 | Complete `laravel_syntax_patterns` section |
| | L114-150 | `models` patterns |
| | L178-195 | `policies` patterns |
| | L227-242 | `services` patterns |
| | L301-323 | `jobs` patterns |
| | L326-347 | `caching` patterns |
| | L406-421 | `logging` patterns |
| | L563-584 | `file_structure` with paths and naming conventions |
| | L587-601 | `volopa_specific_implementation` |

---

## Detailed Dimension Analysis

### 1. Intent
- **Primary Source**: `user_requirements.json` (L2-58)
- **Coverage**: User requirements capture the "why" through functional requirements and user stories
- **Gap**: Architectural and technical files lack explicit business justification

### 2. Requirement
- **Primary Source**: `user_requirements.json` (L60-919), `architectural_requirements.json` (L38-321), `technical_requirements.json` (L47-423)
- **Coverage**: Well distributed across all three files at different abstraction levels
- **Gap**: None - all files contribute requirements at their respective levels

### 3. Constraint
- **Primary Sources**: `architectural_requirements.json` (L324-400) and `technical_requirements.json` (L425-560)
- **Coverage**: Heavy in architectural (system constraints) and technical (code constraints), moderate in user requirements (business rules at L116-127, L227-244, L653-691)
- **Gap**: None - constraints are well-covered

### 4. Flow
- **Primary Source**: `user_requirements.json` (L340-349, L393-486, L783-826)
- **Coverage**: User requirements define business workflows; architectural defines request pipeline (L13)
- **Gap**: Technical requirements lack flow content (L16-44 is procedural only)

### 5. Interface
- **Primary Sources**: All three files
- **Coverage**: Well distributed - user (L88-105, L939-950), architectural (L39-63, L138-153, L208-241), technical (L48-76, L245-273)
- **Gap**: None - interfaces covered at all levels

### 6. Design
- **Primary Sources**: `architectural_requirements.json` (L11-320) and `technical_requirements.json` (L47-601)
- **Coverage**: Heavy in both architectural (patterns/architecture) and technical (syntax/implementation)
- **Gap**: `user_requirements.json` intentionally minimal on design choices (correct separation)

---

## Dimensions Not Mapped (Gaps)

| Dimension | Missing From | Impact |
|-----------|--------------|--------|
| Intent | `architectural_requirements.json` (only L7), `technical_requirements.json` (none) | Low - Intent belongs at PM level, correctly absent from technical files |
| Flow | `technical_requirements.json` (only procedural L16-44) | Low - Technical implementation is procedural; business flows belong elsewhere |

---

## Routing Recommendation

Based on the dimension mapping above, the routing aligns with the MetaGPT role structure:

| Dimension | Routes To | Current File Mapping |
|-----------|-----------|---------------------|
| Intent | PM Input | `user_requirements.json` (L2-58) -> LaravelProductManager |
| Requirement | PM Input | `user_requirements.json` (L60-919) -> LaravelProductManager |
| Constraint | Context | `architectural_requirements.json` (L324-400) -> LaravelArchitect |
| Flow | Context | `user_requirements.json` (L340-349, L393-486) -> shared |
| Interface | Context (machine-checkable) | All three files -> distributed |
| Design | Architect Input | `architectural_requirements.json` (L11-320) + `technical_requirements.json` (L47-601) -> LaravelArchitect + LaravelEngineer |

---

## Summary

The three requirements JSON files collectively cover all six dimensions with appropriate distribution:

- **`user_requirements.json`**: Covers Intent (L2-58), Requirement (L60-919), Flow (L340-486, L783-826), Interface (L88-105), Constraint (L116-244, L653-916)
- **`architectural_requirements.json`**: Covers Design (L11-320), Constraint (L324-400), Interface (L39-241), Flow (L13, L264)
- **`technical_requirements.json`**: Covers Design (L47-601), Constraint (L425-560), Interface (L48-403)

**No critical gaps identified.** The current structure follows the recommended separation of concerns from the ingestion process.

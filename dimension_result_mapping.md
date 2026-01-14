# Dimension Mapping: Six Ingestion Dimensions to Generated Output Files

This table maps the six fixed classification dimensions [Intent, Requirement, Constraint, Flow, Interface, Design] to the three MetaGPT-generated output files.

## Generated Files

| File | Path | Purpose |
|------|------|---------|
| PRD | `resources/prd/20260107101324.md` | Product Requirements Document |
| Sequence Flow | `resources/seq_flow/20260107101324.mmd` | Mermaid sequence diagram |
| System Design | `resources/system_design/20260107101324.md` | Technical architecture document |

---

## Mapping Table

| Dimension | `prd/*.md` | `seq_flow/*.mmd` | `system_design/*.md` |
|-----------|------------|------------------|----------------------|
| **1. Intent** (why / for whom / success) | **Strong** - Product Goals (3), User Stories (5), Original Requirements define "why" and "for whom" | **None** - Pure technical flow, no business justification | **Minimal** - Brief mention in "Implementation approach" intro |
| **2. Requirement** (capability user expects) | **Heavy** - Requirement Pool (5 items with P0/P1 priority), Requirement Analysis, Competitive Analysis | **Minimal** - Implicit capabilities shown via flows (upload, approve, view) | **Minimal** - Capabilities implied through class methods but not explicitly stated |
| **3. Constraint** (must/shall; limits; policies) | **Moderate** - 10K row limit, OAuth2/WSSE auth, validation rules mentioned in analysis | **Minimal** - Authorization checks via Policy, but rules not defined | **Heavy** - Multi-tenant isolation (client_id scopes), transaction boundaries, FormRequest validation, proper indexing |
| **4. Flow** (sequence/state/approval) | **Minimal** - User stories imply flows but no explicit sequence | **Heavy** - Complete upload→validate→process→approve flow with all participants | **Strong** - Duplicates seq_flow diagram, includes async job flow (Queue→Job→Services) |
| **5. Interface** (API, routes, CSV format, status codes) | **Moderate** - API endpoints mentioned in UI Design draft, /api/v1 prefix noted | **Moderate** - Shows route endpoints (POST/GET /api/v1/mass-payment-files), HTTP status codes (201, 200) | **Heavy** - Complete file list, class diagram with method signatures, controller actions, resource transformers |
| **6. Design** (how to build; components; patterns; tech) | **Minimal** - Only tech stack (Laravel 10+, PHP 8.2+), no architecture patterns | **Moderate** - Shows component interactions (Controller→Service→Model pattern) | **Heavy** - Microservice-oriented architecture, Controller-Service separation, async queuing pattern, caching strategy, data models with relationships |

---

## Detailed Dimension Analysis

### 1. Intent
- **Primary Source**: `prd/*.md`
- **Coverage**: PRD captures product goals, user personas, and success criteria well
- **Gap**: Sequence flow and system design lack business context - engineers reading these won't understand "why"

### 2. Requirement
- **Primary Source**: `prd/*.md`
- **Coverage**: PRD has prioritized requirements (P0/P1) with clear descriptions
- **Gap**: Technical files don't trace back to requirements - no requirement IDs referenced

### 3. Constraint
- **Primary Source**: `system_design/*.md`
- **Coverage**: System design captures technical constraints well (isolation, transactions)
- **Gap**: Business rules (currency-specific validation, approval rules) not explicit in any file

### 4. Flow
- **Primary Source**: `seq_flow/*.mmd` and `system_design/*.md`
- **Coverage**: Excellent - complete request lifecycle from client to database and back
- **Gap**: Status state machine (8 states) from requirements not shown - only "processed" and "approved" visible

### 5. Interface
- **Primary Source**: `system_design/*.md`
- **Coverage**: Good class diagram with methods, file list shows all components
- **Gap**: CSV template structure (16 columns) not defined, HTTP error responses not specified

### 6. Design
- **Primary Source**: `system_design/*.md`
- **Coverage**: Comprehensive architecture patterns, technology choices, component relationships
- **Gap**: None - well covered

---

## Critical Gaps Identified

| Gap | Severity | Affected Dimension | Missing From | Impact |
|-----|----------|-------------------|--------------|--------|
| **Status State Machine** | **HIGH** | Flow | All three files | 8 statuses defined in requirements (Uploading, Validating, Validation Failed, etc.) reduced to just 2-3 in outputs |
| **Business Intent in Technical Docs** | **MEDIUM** | Intent | `seq_flow/*.mmd`, `system_design/*.md` | Developers lose context on "why" decisions were made |
| **Currency-Specific Validation Rules** | **HIGH** | Constraint | All three files | INR (invoice required), TRY (incorporation required) rules not captured |
| **CSV Column Structure** | **MEDIUM** | Interface | All three files | 16 columns defined in requirements not mapped to code |
| **Requirement Traceability** | **MEDIUM** | Requirement | `seq_flow/*.mmd`, `system_design/*.md` | No FR-* IDs linking design decisions to requirements |
| **Error Response Formats** | **LOW** | Interface | All three files | 422 validation error structure not defined |
| **First-Approver-Wins Pattern** | **HIGH** | Flow, Constraint | `seq_flow/*.mmd` | Mentioned in PRD but not shown in sequence diagram |
| **Notification Flow** | **MEDIUM** | Flow | `seq_flow/*.mmd` | FileApprovalRequiredNotification in file list but not in sequence |

---

## Gap Severity Summary

| Severity | Count | Description |
|----------|-------|-------------|
| **HIGH** | 3 | Critical functionality gaps - implementation will likely miss these |
| **MEDIUM** | 4 | Important but recoverable - may cause rework |
| **LOW** | 1 | Minor - can be inferred from patterns |

---

## Dimension Coverage Summary

| Dimension | PRD | Seq Flow | System Design | Overall Coverage |
|-----------|-----|----------|---------------|------------------|
| Intent | Strong | None | Minimal | **PARTIAL** - siloed in PRD |
| Requirement | Heavy | Minimal | Minimal | **PARTIAL** - siloed in PRD |
| Constraint | Moderate | Minimal | Heavy | **PARTIAL** - technical covered, business rules missing |
| Flow | Minimal | Heavy | Strong | **GOOD** - but missing state machine |
| Interface | Moderate | Moderate | Heavy | **GOOD** - but missing CSV spec |
| Design | Minimal | Moderate | Heavy | **GOOD** - well distributed |

---

## Recommendations

1. **Add State Machine Diagram** to `system_design/*.md` showing all 8 file statuses and valid transitions
2. **Include Requirement IDs** in technical docs to maintain traceability (e.g., "// FR-3.4: INR validation")
3. **Document Currency Rules** as explicit constraints in system design
4. **Add CSV Schema Definition** to system design with all 16 columns
5. **Expand Sequence Diagram** to include notification flow and first-approver-wins pattern
6. **Add "Why" Section** to system design linking architectural decisions to business requirements

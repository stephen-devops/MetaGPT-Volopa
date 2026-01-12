# Laravel Technical Implementation Requirements - LaravelEngineer
## Consolidated Natural Language Implementation Patterns and Code-Level Constraints

---

This document consolidates all implementation-specific patterns, syntax rules, and code-level constraints for the Volopa Mass Payments Laravel API system. Requirements are written from an implementation perspective, focusing on exact Laravel syntax, coding patterns, and implementation details.

**Version:** 1.0
**Purpose:** Technical Implementation Requirements - Code Syntax and Implementation Patterns
**Scope:** Implementation requirements only (excludes high-level architectural design)
**Source:** industry/dos_and_donts.pdf (implementation-specific subset)
**Target Agent:** LaravelEngineer
**Requires:** architectural_requirements.json (design patterns) + technical_requirements.json (this file)
**Output:** app/ directory (~40 Laravel files)

---

## Implementation Workflow

The engineer shall follow this step-by-step implementation process:

1. Read task from ProjectManager's task breakdown
2. Read architectural design from LaravelArchitect's system design
3. Apply architectural patterns (from architectural_requirements.json)
4. Apply implementation patterns (from this file)
5. Write code using exact Laravel syntax
6. Write feature tests for implemented code
7. Verify no anti-patterns are present

---

## 1. Route Definition Syntax

### 1.1 Route Registration

**1.** The implementation shall define all API routes in the routes/api.php file using the Route facade.

**2.** The implementation shall use Route::prefix('v1')->middleware(['auth:api'])->group() syntax for versioned route groups.

**3.** The implementation shall use Route::{method}() methods including post(), get(), put(), patch(), and delete() for defining endpoints.

### 1.2 Route Naming Conventions

**4.** The implementation shall name routes using dot notation following the pattern ->name('api.v1.{resource}.{action}').

**5.** The implementation shall use standard RESTful action names: index, store, show, update, and destroy.

**6.** The implementation shall name the mass payments index route as api.v1.mass-payments.index.

**7.** The implementation shall name the mass payments store route as api.v1.mass-payments.store.

### 1.3 Middleware Application

**8.** The implementation shall apply middleware using array syntax: ->middleware(['auth:api', 'throttle:60,1']).

**9.** The implementation shall use Volopa-specific middleware: ->middleware(['volopa.auth', 'throttle:60,1']).

---

## 2. Migration Syntax

### 2.1 Table Creation

**10.** The implementation shall create migrations in database/migrations/ with filename pattern {timestamp}_create_{table}_table.php.

**11.** The implementation shall use Schema::create('table_name', function (Blueprint $table) { ... }) syntax for new tables.

**12.** The implementation shall use $table->uuid('id')->primary() for UUID primary keys.

**13.** The implementation shall use $table->foreignId('column')->constrained('table') for foreign key columns.

**14.** The implementation shall use $table->timestamps() to add created_at and updated_at columns.

### 2.2 Index Creation

**15.** The implementation shall add single-column indexes using $table->index('column_name').

**16.** The implementation shall add composite indexes using $table->index(['col1', 'col2']).

**17.** The implementation shall always add indexes on foreign key columns for query performance.

**18.** The implementation shall add indexes on status columns that are frequently queried.

### 2.3 Constraint Definition

**19.** The implementation shall define foreign keys using $table->foreign('column')->references('id')->on('table')->onDelete('cascade').

**20.** The implementation shall define unique constraints using $table->unique('column') or $table->unique(['col1', 'col2']).

---

## 3. Eloquent Model Syntax

### 3.1 Relationship Definition

**21.** The implementation shall define belongsTo relationships using: public function relation() { return $this->belongsTo(RelatedModel::class, 'foreign_key'); }.

**22.** The implementation shall define hasMany relationships using: public function relation() { return $this->hasMany(RelatedModel::class, 'foreign_key'); }.

**23.** The implementation shall define belongsToMany relationships using: public function relation() { return $this->belongsToMany(RelatedModel::class, 'pivot_table'); }.

**24.** The implementation shall define the client relationship on MassPaymentFile as: public function client() { return $this->belongsTo(TccAccount::class, 'client_id'); }.

**25.** The implementation shall define the paymentInstructions relationship on MassPaymentFile as: public function paymentInstructions() { return $this->hasMany(PaymentInstruction::class); }.

### 3.2 Mass Assignment Protection

**26.** The implementation shall set either $fillable or $guarded properties on all models for mass-assignment protection.

**27.** The implementation shall use protected $fillable = ['field1', 'field2'] to whitelist fillable fields.

**28.** The implementation shall never use protected $guarded = [] with an empty array as this disables all protection.

### 3.3 Global Scopes

**29.** The implementation shall add global scopes in the booted() method using: protected static function booted() { static::addGlobalScope('name', function ($query) { ... }); }.

**30.** The implementation shall add client tenant scope as: static::addGlobalScope('client', function ($query) { $query->where('client_id', auth()->user()->client_id); }).

### 3.4 Query Scopes

**31.** The implementation shall define local scopes using: public function scopeName($query, $param) { return $query->where('...'); }.

**32.** The implementation shall define a status scope as: public function scopeByStatus($query, $status) { return $query->where('status', $status); }.

---

## 4. FormRequest Syntax

### 4.1 Validation Rules

**33.** The implementation shall create FormRequest files in app/Http/Requests/ with pattern {Action}{Resource}Request.php.

**34.** The implementation shall define validation rules in the rules() method returning an array.

**35.** The implementation shall use Laravel validation syntax: ['field' => 'required|string|max:255'].

**36.** The implementation shall validate file uploads using: ['file' => 'required|file|mimes:csv|max:10240'].

**37.** The implementation shall validate foreign key existence using: ['client_id' => 'required|exists:tcc_accounts,id'].

### 4.2 Authorization Logic

**38.** The implementation shall implement authorization in the authorize() method.

**39.** The implementation shall use policy checks in authorize(): return $this->user()->can('action', Model::class).

### 4.3 Validated Data Access

**40.** The implementation shall access validated data using $request->validated() method.

**41.** The implementation shall never use $request->all() as this bypasses validation.

---

## 5. Policy Syntax

### 5.1 Policy Method Definition

**42.** The implementation shall create Policy files in app/Policies/ with pattern {Model}Policy.php.

**43.** The implementation shall define policy methods using: public function action(User $user, Model $model) { return boolean; }.

**44.** The implementation shall implement standard policy methods: viewAny, view, create, update, and delete.

**45.** The implementation shall implement custom approve method as: public function approve(User $user, MassPaymentFile $file) { return $user->hasRole('approver') && $file->status === 'pending_approval'; }.

### 5.2 Policy Usage

**46.** The implementation shall authorize actions in controllers using: $this->authorize('action', $model).

---

## 6. Controller Implementation Syntax

### 6.1 Thin Controller Pattern

**47.** The implementation shall keep controller methods to maximum 10-15 lines.

**48.** Controller methods shall follow this pattern: Validate via FormRequest → Authorize via Policy → Delegate to Service → Return Resource.

**49.** The implementation shall inject FormRequest as method parameter for automatic validation.

### 6.2 Dependency Injection

**50.** The implementation shall use constructor property promotion for dependency injection: public function __construct(private ServiceClass $service) {}.

**51.** The implementation shall inject services in controller constructors, not instantiate them directly.

### 6.3 Response Syntax

**52.** The implementation shall return successful GET/PUT responses using: return new ResourceClass($model).

**53.** The implementation shall return 201 Created responses using: return response()->json(new ResourceClass($model), 201).

**54.** The implementation shall return 204 No Content responses using: return response()->noContent().

**55.** The implementation shall return 404 Not Found responses using: return response()->json(['message' => 'Not found'], 404).

---

## 7. Service Layer Syntax

### 7.1 Transaction Handling

**56.** The implementation shall wrap multi-write operations in DB::transaction(function () use ($data) { ... }).

**57.** The implementation shall include all related database writes within a single transaction closure.

**58.** The implementation shall not include external API calls or file I/O inside transaction closures.

### 7.2 Model Injection

**59.** The implementation shall inject model dependencies in service constructors: public function __construct(private MassPaymentFile $massPaymentFile) {}.

**60.** The implementation shall use injected models instead of model facades.

---

## 8. API Resource Syntax

### 8.1 Resource Transformation

**61.** The implementation shall create Resource files in app/Http/Resources/ with pattern {Model}Resource.php.

**62.** The implementation shall define transformation logic in the toArray($request) method.

**63.** The implementation shall return arrays with snake_case keys from toArray() method.

**64.** The implementation shall never use camelCase for JSON keys.

### 8.2 Relationship Handling

**65.** The implementation shall use $this->whenLoaded('relation') to conditionally include relationships.

**66.** The implementation shall wrap related resources: 'client' => new ClientResource($this->whenLoaded('client')).

### 8.3 Collection Resources

**67.** The implementation shall return resource collections using: ResourceClass::collection($models).

**68.** The implementation shall combine collections with pagination: ResourceClass::collection($query->paginate(20)).

---

## 9. Eloquent Query Syntax

### 9.1 Eager Loading

**69.** The implementation shall use ->with(['relation1', 'relation2']) for eager loading relationships.

**70.** The implementation shall use ->with(['relation1.nested']) for nested eager loading.

**71.** The implementation shall eager load all relationships that will be accessed in the response.

### 9.2 Pagination

**72.** The implementation shall use ->paginate($perPage) for paginated queries.

**73.** The implementation shall use default page size of 20: ->paginate(20).

**74.** The implementation shall never use Model::all() or Model::get() without pagination in controller list methods.

### 9.3 Column Selection

**75.** The implementation shall use ->select(['id', 'name', 'status']) to limit selected columns when not all are needed.

---

## 10. Queue Job Syntax

### 10.1 Job Class Definition

**76.** The implementation shall create Job files in app/Jobs/ with pattern {Operation}Job.php.

**77.** The implementation shall implement ShouldQueue interface on job classes.

**78.** The implementation shall use these traits: Dispatchable, InteractsWithQueue, Queueable, SerializesModels.

**79.** The implementation shall define job logic in the handle() method.

### 10.2 Job Dispatching

**80.** The implementation shall dispatch jobs using: JobClass::dispatch($parameters).

### 10.3 Failure Handling

**81.** The implementation shall implement failed(Throwable $exception) method to handle job failures.

**82.** The implementation shall update model status on job failure: $this->file->update(['status' => 'validation_failed', 'error' => $exception->getMessage()]).

---

## 11. Cache Syntax

### 11.1 Cache Storage and Retrieval

**83.** The implementation shall use Cache::remember($key, $ttl, function () { return $value; }) for retrieving or storing cached data.

**84.** The implementation shall use integer TTL values in seconds (e.g., 86400 for 1 day).

### 11.2 Cache Invalidation

**85.** The implementation shall use Cache::forget($key) to invalidate specific cache entries.

**86.** The implementation shall use Cache::tags(['tag1'])->flush() for group invalidation.

---

## 12. Feature Test Syntax

### 12.1 Authentication

**87.** The implementation shall authenticate test users using: $this->actingAs($user)->get('/api/v1/endpoint').

### 12.2 Assertions

**88.** The implementation shall assert JSON structure using: $response->assertJsonStructure(['data' => ['id', 'name']]).

**89.** The implementation shall assert HTTP status codes using: $response->assertStatus(200) or shortcut methods.

**90.** The implementation shall use status assertion shortcuts: assertOk(), assertCreated(), assertNoContent(), assertUnauthorized(), assertForbidden(), assertNotFound(), assertUnprocessable().

**91.** The implementation shall assert database state using: $this->assertDatabaseHas('table', ['column' => 'value']).

---

## 13. Error Handling Syntax

### 13.1 Error Response Format

**92.** The implementation shall return consistent error responses: return response()->json(['message' => 'Error message', 'errors' => [...]], $statusCode).

### 13.2 Exception Handling

**93.** The implementation shall use try-catch blocks for exception handling.

**94.** The implementation shall log exceptions using Log::error($exception).

**95.** The implementation shall never expose exception messages or stack traces in production responses.

---

## 14. Logging Syntax

### 14.1 Log Levels and Context

**96.** The implementation shall use Log facade with appropriate levels: debug(), info(), warning(), error(), critical().

**97.** The implementation shall always include context arrays in log calls: Log::info('message', ['context' => 'value']).

**98.** The implementation shall log important events with IDs: Log::info('Mass payment file uploaded', ['file_id' => $file->id, 'user_id' => $user->id]).

---

## 15. Implementation Anti-Patterns to Avoid

### 15.1 Code Existence Checks

**99.** The implementation shall never use classes or methods that don't exist in the repository.

**100.** The implementation shall search the codebase before using classes to verify they exist.

**101.** The implementation shall never add methods that already exist in the repository.

### 15.2 Hardcoding Anti-Patterns

**102.** The implementation shall never hardcode timestamps; use now() or Carbon::now() instead.

**103.** The implementation shall never hardcode timezone strings; use config values instead.

**104.** The implementation shall never hardcode environment-specific values; use config() or env() instead.

**105.** The implementation shall define constants for magic numbers and strings instead of hardcoding them.

### 15.3 Security Anti-Patterns

**106.** The implementation shall never disable mass-assignment protection with empty $guarded array.

**107.** The implementation shall never expose stack traces in API responses.

**108.** The implementation shall never return environment variables or config secrets in API responses.

### 15.4 Query Anti-Patterns

**109.** The implementation shall never use Model::all() or Model::get() without pagination in controllers.

**110.** The implementation shall never access relationships without eager loading to prevent N+1 queries.

### 15.5 Validation Anti-Patterns

**111.** The implementation shall never use $request->all() instead of $request->validated().

**112.** The implementation shall never build queries directly from user input without validation.

### 15.6 Transaction Anti-Patterns

**113.** The implementation shall never perform multi-write operations without wrapping in DB::transaction().

### 15.7 Response Anti-Patterns

**114.** The implementation shall never use inconsistent casing in JSON responses; always use snake_case.

**115.** The implementation shall never return raw Eloquent models; always use API Resources.

### 15.8 Observability Anti-Patterns

**116.** The implementation shall never skip logging important events; always log significant actions with context.

---

## 16. File Structure and Naming Conventions

### 16.1 File Locations

**117.** The implementation shall place routes in routes/api.php.

**118.** The implementation shall place controllers in app/Http/Controllers/Api/V1/ with pattern {Resource}Controller.php.

**119.** The implementation shall place FormRequests in app/Http/Requests/ with pattern {Action}{Resource}Request.php.

**120.** The implementation shall place API Resources in app/Http/Resources/ with pattern {Model}Resource.php.

**121.** The implementation shall place models in app/Models/ with pattern {Model}.php.

**122.** The implementation shall place services in app/Services/ with pattern {Domain}Service.php.

**123.** The implementation shall place policies in app/Policies/ with pattern {Model}Policy.php.

**124.** The implementation shall place jobs in app/Jobs/ with pattern {Operation}Job.php.

**125.** The implementation shall place migrations in database/migrations/ with pattern {timestamp}_create_{table}_table.php.

**126.** The implementation shall place factories in database/factories/ with pattern {Model}Factory.php.

**127.** The implementation shall place feature tests in tests/Feature/ with pattern {Resource}Test.php.

### 16.2 Naming Conventions

**128.** The implementation shall name controllers using PascalCase with Controller suffix (e.g., MassPaymentController).

**129.** The implementation shall name models using PascalCase singular form (e.g., MassPaymentFile, not MassPaymentFiles).

**130.** The implementation shall name database tables using snake_case plural form (e.g., mass_payment_files).

**131.** The implementation shall name migrations using snake_case with action (e.g., create_mass_payment_files_table).

**132.** The implementation shall name routes using kebab-case (e.g., /mass-payments, not /massPayments).

---

## 17. Volopa-Specific Implementation

### 17.1 Authentication

**133.** The implementation shall use Volopa's custom OAuth2/WSSE middleware, not Laravel's default auth:api.

**134.** The implementation shall apply volopa.auth middleware to all protected routes.

### 17.2 Tenant Isolation

**135.** The implementation shall filter all queries by client_id using global scopes.

**136.** The implementation shall add global scope: static::addGlobalScope('client', fn($query) => $query->where('client_id', auth()->user()->client_id)).

**137.** The implementation shall enforce tenant isolation through middleware.

**138.** The implementation shall verify $model->client_id === $user->client_id in policy methods.

---

## Implementation Requirements Summary

| Section | Requirements Count |
|---------|-------------------|
| 1. Route Definition Syntax | 9 |
| 2. Migration Syntax | 11 |
| 3. Eloquent Model Syntax | 12 |
| 4. FormRequest Syntax | 9 |
| 5. Policy Syntax | 5 |
| 6. Controller Implementation Syntax | 9 |
| 7. Service Layer Syntax | 5 |
| 8. API Resource Syntax | 8 |
| 9. Eloquent Query Syntax | 7 |
| 10. Queue Job Syntax | 7 |
| 11. Cache Syntax | 4 |
| 12. Feature Test Syntax | 5 |
| 13. Error Handling Syntax | 4 |
| 14. Logging Syntax | 3 |
| 15. Implementation Anti-Patterns to Avoid | 18 |
| 16. File Structure and Naming Conventions | 16 |
| 17. Volopa-Specific Implementation | 6 |
| **Total** | **138** |

---

## Implementation Checklist

Before completing any task, verify:

- ✓ Routes defined in routes/api.php with proper naming
- ✓ Migrations with indexes, foreign keys, constraints
- ✓ Models with relationships, $fillable, global scopes
- ✓ FormRequests with validation rules
- ✓ Policies with authorization methods
- ✓ Thin controllers (10-15 lines max)
- ✓ Services for complex logic with DB::transaction()
- ✓ Eager loading ->with() to prevent N+1
- ✓ Pagination ->paginate(20) for all lists
- ✓ API Resources for all responses
- ✓ Proper HTTP status codes (201, 204, 404, 422)
- ✓ Queue jobs dispatched for large operations
- ✓ Cache::remember() for reference data
- ✓ Feature tests covering all endpoints
- ✓ Logging for important events
- ✓ No hardcoded values or timestamps

---

*End of Document*
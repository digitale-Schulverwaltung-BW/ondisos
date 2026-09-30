# Architecture Research

**Domain:** Multi-tenant school registration system (PHP/MySQL, custom MVC)
**Researched:** 2026-03-13
**Confidence:** HIGH (all findings from authoritative project source files)

## Standard Architecture

### System Overview

The multi-tenant architecture extends the existing two-application structure. A new TenantContext layer cuts horizontally through the backend's existing layers, providing tenant identity to every component that needs it.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          FRONTEND APPLICATION                                │
│  ┌──────────────────────┐     ┌─────────────────────────────────────────┐   │
│  │  index.php?form=bs   │     │  save.php  →  BackendApiClient          │   │
│  │  &tenant=5 (v3.0)    │     │  signs request with tenant api_secret   │   │
│  └──────────────────────┘     └─────────────────────────────────────────┘   │
└────────────────────────────────────────────┬────────────────────────────────┘
                                             │ HMAC-signed HTTP (per-tenant secret)
┌────────────────────────────────────────────▼────────────────────────────────┐
│                           BACKEND APPLICATION                                │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │                         BOOTSTRAP LAYER (inc/)                         │ │
│  │  bootstrap.php → TenantContext::initialize()  (one per request)        │ │
│  │  auth.php → session stores tenant_id + role (platform/tenant admin)    │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │                       PRESENTATION LAYER (public/)                    │   │
│  │  index.php  detail.php  trash.php  dashboard.php  bulk_actions.php   │   │
│  │  excel_export.php  api/submit.php  api/upload.php  pdf/download.php  │   │
│  │  NEW: tenants/  tenant_admins/                                        │   │
│  └──────────────────────┬───────────────────────────────────────────────┘   │
│                         │                                                    │
│  ┌──────────────────────▼───────────────────────────────────────────────┐   │
│  │                     CONTROLLER LAYER (src/Controllers/)               │   │
│  │  AnmeldungController  DetailController  BulkActionsController         │   │
│  │  NEW: TenantController  TenantAdminController                         │   │
│  └──────────────────────┬───────────────────────────────────────────────┘   │
│                         │                                                    │
│  ┌──────────────────────▼───────────────────────────────────────────────┐   │
│  │                      SERVICE LAYER (src/Services/)                    │   │
│  │  AnmeldungService  ExportService  StatusService  ExpungeService       │   │
│  │  PdfGeneratorService  PdfTokenService  AuditLogger  RateLimiter       │   │
│  │  NEW: TenantService  TenantAdminService  ApiAuthService               │   │
│  └──────────────────────┬───────────────────────────────────────────────┘   │
│                         │                                                    │
│  ┌──────────────────────▼───────────────────────────────────────────────┐   │
│  │                    REPOSITORY LAYER (src/Repositories/)               │   │
│  │  AnmeldungRepository (modified: +tenant_id on all ~15 methods)        │   │
│  │  NEW: TenantRepository  FormConfigRepository                           │   │
│  └──────────────────────┬───────────────────────────────────────────────┘   │
│                         │                                                    │
│  ┌──────────────────────▼───────────────────────────────────────────────┐   │
│  │                    CROSS-CUTTING: TenantContext (src/Config/)          │   │
│  │  TenantContext::getTenantId()  — available globally, set once at boot  │   │
│  └──────────────────────┬───────────────────────────────────────────────┘   │
└────────────────────────────────────────────┬────────────────────────────────┘
                                             │
┌────────────────────────────────────────────▼────────────────────────────────┐
│                              DATABASE (MySQL/MariaDB)                        │
│                                                                              │
│  anmeldungen (+tenant_id)   tenants   tenant_admins   form_configs           │
│                                                                              │
│  uploads/tenant-{id}/       audit.log (+tenant_id per entry)                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | Responsibility | New vs Modified |
|-----------|----------------|-----------------|
| `TenantContext` | Request-scoped singleton holding resolved tenant_id | NEW |
| `TenantRepository` | CRUD on `tenants` table | NEW |
| `FormConfigRepository` | Load form config from DB instead of file | NEW |
| `TenantService` | Tenant create/read/update/disable business logic | NEW |
| `TenantAdminService` | Create/manage tenant admin credentials | NEW |
| `ApiAuthService` | Validate per-tenant HMAC signatures on API requests | NEW |
| `AnmeldungRepository` | All ~15 existing data access methods | MODIFIED (+tenant_id) |
| `FormConfig` (backend) | Delegates to FormConfigRepository instead of file | MODIFIED |
| `auth.php` | Session management expanded to multi-role, multi-tenant | MODIFIED |
| `bootstrap.php` | Adds TenantContext initialization step | MODIFIED |
| `AuditLogger` | Adds tenant_id field to every log entry | MODIFIED |
| `upload.php` + `DetailController` | Path uses `uploads/tenant-{id}/` | MODIFIED |
| `PdfTokenService` | No change — stateless HMAC, tenant-agnostic | UNCHANGED |
| `RateLimiter` | No change — per-IP, tenant-agnostic | UNCHANGED |
| `VirusScanService` | No change — file scanning, tenant-agnostic | UNCHANGED |
| `MessageService` | No change — UI strings, tenant-agnostic | UNCHANGED |

## Recommended Project Structure

```
backend/
├── src/
│   ├── Config/
│   │   ├── Database.php            # UNCHANGED — singleton still works for shared schema
│   │   ├── Config.php              # UNCHANGED
│   │   ├── EnvLoader.php           # UNCHANGED
│   │   ├── FormConfig.php          # MODIFIED — delegates to FormConfigRepository
│   │   └── TenantContext.php       # NEW — request-scoped singleton
│   ├── Controllers/
│   │   ├── AnmeldungController.php # UNCHANGED — reads TenantContext automatically
│   │   ├── DetailController.php    # MODIFIED — scoped file paths
│   │   ├── BulkActionsController.php # UNCHANGED
│   │   ├── TenantController.php    # NEW — tenant CRUD
│   │   └── TenantAdminController.php # NEW — tenant admin CRUD
│   ├── Models/
│   │   ├── Anmeldung.php           # UNCHANGED
│   │   ├── CompleteAnmeldung.php   # UNCHANGED
│   │   ├── AnmeldungStatus.php     # UNCHANGED
│   │   ├── Tenant.php              # NEW — readonly class
│   │   └── TenantAdmin.php         # NEW — readonly class
│   ├── Repositories/
│   │   ├── AnmeldungRepository.php # MODIFIED — all methods get tenant_id filter
│   │   ├── TenantRepository.php    # NEW
│   │   └── FormConfigRepository.php # NEW
│   ├── Services/
│   │   ├── AnmeldungService.php    # UNCHANGED — uses TenantContext via repository
│   │   ├── ExportService.php       # UNCHANGED — idem
│   │   ├── StatusService.php       # UNCHANGED — idem
│   │   ├── ExpungeService.php      # UNCHANGED — idem
│   │   ├── TenantService.php       # NEW
│   │   ├── TenantAdminService.php  # NEW
│   │   ├── ApiAuthService.php      # NEW — HMAC signature validation
│   │   ├── AuditLogger.php         # MODIFIED — +tenant_id on all log() calls
│   │   └── [all others unchanged]
│   └── Validators/
│       ├── AnmeldungValidator.php  # UNCHANGED
│       └── TenantValidator.php     # NEW
├── public/
│   ├── [all existing pages unchanged]
│   ├── tenants/
│   │   ├── index.php               # NEW — tenant list (platform admin only)
│   │   ├── create.php              # NEW
│   │   ├── edit.php                # NEW
│   │   └── delete.php              # NEW
│   └── tenant_admins/
│       ├── index.php               # NEW — tenant admin list
│       ├── create.php              # NEW
│       └── delete.php              # NEW
├── inc/
│   ├── bootstrap.php               # MODIFIED — TenantContext::initialize()
│   ├── auth.php                    # MODIFIED — multi-role sessions
│   └── [csrf.php, header.php, footer.php unchanged]
├── config/
│   └── [unchanged]
├── uploads/
│   ├── tenant-1/                   # NEW structure — per-tenant subdirectories
│   └── tenant-N/
└── tests/
    ├── Unit/
    │   └── Services/
    │       ├── TenantServiceTest.php     # NEW
    │       ├── ApiAuthServiceTest.php    # NEW
    │       └── TenantAdminServiceTest.php # NEW
    └── Integration/
        └── TenantRepositoryTest.php      # NEW
```

## Architectural Patterns

### Pattern 1: TenantContext Request-Scoped Singleton

**What:** A static class initialized once per HTTP request in `bootstrap.php`. All downstream components call `TenantContext::getTenantId()` instead of receiving tenant_id as method arguments.

**When to use:** When tenant identity must be available across the entire call stack without threading it through every method signature. Avoids a signature-breaking change to all ~15 repository methods.

**Trade-offs:** Simple and zero-friction to adopt; the singleton avoids prop-drilling through services and repositories. The downside is that it is implicit — callers can't see tenant scoping from method signatures alone. Tests must set `TenantContext` before calling repository methods.

**Example:**
```php
// backend/src/Config/TenantContext.php
final class TenantContext
{
    private static ?int $tenantId = null;

    public static function initialize(int $tenantId): void
    {
        if (self::$tenantId !== null) {
            throw new \LogicException('TenantContext already initialized for this request');
        }
        self::$tenantId = $tenantId;
    }

    public static function getTenantId(): int
    {
        return self::$tenantId ?? throw new \RuntimeException('TenantContext not initialized');
    }

    /** Used by tests only. */
    public static function reset(): void
    {
        self::$tenantId = null;
    }
}

// backend/inc/bootstrap.php — initialization
// For API requests: resolved from HMAC signature (tenant slug/id in payload)
// For admin sessions: resolved from $_SESSION['tenant_id'] (set at login)
// Single-tenant fallback: always 1 when MULTI_TENANT_ENABLED=false
TenantContext::initialize(resolveTenantId());
```

**Initialization sources by entry point:**

| Entry point | How tenant_id is resolved |
|-------------|--------------------------|
| `api/submit.php` (API) | Tenant slug/id in request payload; HMAC validated with tenant's `api_secret` |
| `api/upload.php` (API) | Same as submit.php |
| Admin pages (`index.php`, etc.) | `$_SESSION['tenant_id']` (set at login) |
| Platform admin pages | Overrides to view any tenant via GET param |
| Single-tenant mode | Always `1` (default tenant, skip DB lookup) |

### Pattern 2: Repository Tenant Filtering

**What:** Every `AnmeldungRepository` method that reads or writes data appends `AND tenant_id = ?` to its WHERE clause. The tenant_id value comes from `TenantContext::getTenantId()`, called inside the repository — not passed in as a parameter.

**When to use:** All read/write operations on `anmeldungen` without exception. Never query `anmeldungen` without tenant isolation.

**Trade-offs:** Calling `TenantContext` inside the repository keeps method signatures identical to current code, making the change minimally invasive. The implicit coupling is acceptable because TenantContext is always initialized before any repository is instantiated.

**Example:**
```php
// backend/src/Repositories/AnmeldungRepository.php
public function findById(int $id): ?Anmeldung
{
    $tenantId = TenantContext::getTenantId();
    $stmt = $this->pdo->prepare(
        'SELECT * FROM anmeldungen WHERE id = ? AND tenant_id = ? AND deleted = 0'
    );
    $stmt->execute([$id, $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ? Anmeldung::fromArray($row) : null;
}

public function findPaginated(array $filters, ...): array
{
    $tenantId = TenantContext::getTenantId();
    // All filter WHERE clauses start with: WHERE tenant_id = ?
    // $params always has $tenantId as first bound parameter
}
```

**Methods requiring modification (full list):**
- `findPaginated()`, `countFiltered()`
- `findById()`, `findDeleted()`
- `insert()` — adds tenant_id column value on INSERT
- `updateStatus()`, `softDelete()`, `hardDelete()`, `restore()`
- Any statistics/aggregation queries in `StatusService` that bypass the repository

### Pattern 3: Per-Tenant HMAC API Authentication

**What:** Each tenant has a unique `api_secret` stored in the `tenants` table. The frontend signs API requests using HMAC-SHA256 of the payload with the tenant's secret. The backend's new `ApiAuthService` validates the signature before any business logic runs.

**When to use:** Every inbound API call to `api/submit.php` and `api/upload.php`. Replaces the current global `API_SECRET_KEY`.

**Trade-offs:** Consistent with the existing PDF token pattern (already HMAC-SHA256), so no new concepts. The backend must do a DB lookup to fetch the tenant's `api_secret` before validation — this is a fast indexed query (tenant_id on `tenants`). In single-tenant mode the default tenant's `api_secret` from the DB serves the same role as the current .env secret.

**Example:**
```php
// backend/src/Services/ApiAuthService.php
final class ApiAuthService
{
    public function __construct(private TenantRepository $tenants) {}

    public function validateRequest(int $tenantId, string $payload, string $signature): bool
    {
        $tenant = $this->tenants->findById($tenantId);
        if ($tenant === null || !$tenant->enabled) {
            return false;
        }
        $expected = hash_hmac('sha256', $payload, $tenant->apiSecret);
        return hash_equals($expected, $signature);
    }
}
```

### Pattern 4: DB-Driven Form Configuration

**What:** `backend/src/Config/FormConfig.php` becomes a thin adapter that delegates to `FormConfigRepository` instead of reading `forms-config.php`. The frontend fetches form config from a new backend API endpoint, replacing the static `forms-config.php` lookup.

**When to use:** All form config access — backend validation of allowed form keys, frontend form loading.

**Trade-offs:** Adds a DB call at form load time (previously free static array lookup). This is acceptable — it is one indexed query per page load, cacheable in the request scope. The benefit is full per-tenant form config without file system management.

**New backend endpoint:** `backend/public/api/form-config.php?form=bs&tenant=5`
Returns: survey JSON path/content, PDF config, notify email — all the fields currently in `forms-config.php`.

### Pattern 5: Multi-Role Session Model

**What:** The session stores role and scope alongside authentication. Platform admin has system-wide scope. Tenant admin has restricted scope to their `tenant_id`.

**When to use:** All admin-facing pages behind `auth.php`.

**Example session structure:**
```php
$_SESSION = [
    'authenticated'   => true,
    'admin_id'        => 1,           // tenant_admins.id (or 0 for platform admin)
    'is_platform_admin' => false,
    'tenant_id'       => 5,           // the tenant this session is scoped to
    'username'        => 'admin-school-a',
];
```

Platform admin session: `is_platform_admin = true`, `tenant_id` reflects the tenant currently being viewed (switchable via UI).

## Data Flow

### Multi-Tenant Submission Flow

```
Frontend (save.php, tenant=5)
    ↓
BackendApiClient signs payload with tenant 5's api_secret
    ↓
POST /api/submit.php  {data, tenant_id: 5, hmac_signature: ...}
    ↓
RateLimiter::check() (per-IP, tenant-agnostic)
    ↓
ApiAuthService::validateRequest(tenant_id=5, payload, signature)
    → TenantRepository::findById(5) to fetch api_secret
    → hash_equals() timing-safe compare
    ↓
TenantContext::initialize(5)
    ↓
AnmeldungRepository::insert()  [includes tenant_id=5]
    ↓
VirusScanService::scan() (tenant-agnostic)
    ↓
PdfTokenService::generate(id)  [token encodes submission id only, no tenant_id needed — repository re-scopes on lookup]
    ↓
AuditLogger::log('upload_success', ..., tenant_id: 5)
    ↓
Response: {success, id, pdf_download}
```

### Tenant Admin Login Flow

```
POST /login.php {username, password}
    ↓
TenantAdminService::authenticate(username, password)
    → TenantRepository::findAdminByUsername(username)
    → password_verify()
    ↓
If platform admin (.env credentials):
    session: {is_platform_admin: true, tenant_id: null (views all)}
Else if tenant admin (DB credentials):
    session: {is_platform_admin: false, tenant_id: admin.tenant_id}
    ↓
TenantContext::initialize(session.tenant_id)
    ↓
All downstream queries auto-scoped to that tenant
```

### Platform Admin Tenant Switching

```
Platform admin at /tenants/index.php
    ↓
Selects "View as Tenant 5"
    ↓
GET /index.php?tenant_view=5
    ↓
bootstrap.php checks: is_platform_admin=true, uses GET param tenant_view
    ↓
TenantContext::initialize(5)
    ↓
All page queries scoped to tenant 5 (read-only impersonation)
```

## Integration Points

### New vs Existing Component Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| `bootstrap.php` → `TenantContext` | Direct static call at boot | Must run before any controller or service |
| `auth.php` → `TenantContext` | Reads session, then initializes TenantContext | Auth must resolve before TenantContext for admin pages |
| `ApiAuthService` → `TenantRepository` | Constructor injection | Tenant lookup needed to retrieve api_secret for validation |
| `AnmeldungRepository` → `TenantContext` | Static call inside each method | No signature change required on existing service callers |
| `FormConfig` (backend) → `FormConfigRepository` | Constructor injection, replaces static file load | Existing `FormConfig` interface preserved — callers unchanged |
| `AuditLogger` → `TenantContext` | Static call at log time | Adds tenant_id to every entry transparently |
| `DetailController` → tenant upload path | Uses `TenantContext::getTenantId()` to build path | `uploads/tenant-{id}/filename` instead of flat `uploads/` |
| Platform admin UI → `TenantService` | Standard controller → service → repository | New code, follows existing MVC pattern |

### External Service Impact

| Service | Impact | Notes |
|---------|--------|-------|
| ClamAV (VirusScanService) | None — file content agnostic | Tenant-scoped path is just a path string to it |
| mPDF (PdfGeneratorService) | None — renders from Anmeldung data | Anmeldung now has tenant_id field but PDF templates ignore it |
| PhpSpreadsheet (ExportService) | None | Export is already scoped via filtered repository queries |
| MySQL | Schema migration needed | `ALTER TABLE anmeldungen ADD COLUMN tenant_id INT NOT NULL DEFAULT 1` + new tables |

### Authentication System Boundaries

Current `auth.php` validates a single admin against `.env` credentials. The modified version must:

1. If `MULTI_TENANT_ENABLED=false`: preserve current behavior exactly (single admin, .env credentials)
2. If `MULTI_TENANT_ENABLED=true`:
   - Check against platform admin credentials (`.env` — `PLATFORM_ADMIN_USERNAME`, `PLATFORM_ADMIN_PASSWORD_HASH`)
   - Check against `tenant_admins` table for tenant admins
   - Store role and tenant_id in session
   - Restrict all data access for tenant admins to their `tenant_id`

This is the most complex modification in the entire milestone. It touches the core security boundary.

## Build Order and Dependencies

The correct implementation sequence, each step building on the prior:

```
1. DB SCHEMA MIGRATION (foundation — nothing else works without this)
   └── tenants table + seed default tenant (id=1)
   └── tenant_admins table
   └── form_configs table
   └── ALTER anmeldungen ADD tenant_id

2. TenantContext (core abstraction — needed by repository and auth)
   └── backend/src/Config/TenantContext.php
   └── Unit tests: TenantContextTest

3. TenantRepository + Tenant model (needed by auth and ApiAuthService)
   └── backend/src/Repositories/TenantRepository.php
   └── backend/src/Models/Tenant.php
   └── Unit + integration tests

4. Auth expansion (multi-role session — needed before any admin page works)
   └── backend/inc/auth.php (modified)
   └── backend/src/Services/TenantAdminService.php (new)
   └── backend/src/Models/TenantAdmin.php (new)
   └── TenantAdminServiceTest

5. bootstrap.php TenantContext initialization (wires context to requests)
   └── backend/inc/bootstrap.php (modified)
   └── Resolves tenant_id from session (admin) or request (API)

6. AnmeldungRepository tenant filtering (core data isolation)
   └── Modify all ~15 methods to add WHERE tenant_id = ?
   └── Integration tests: AnmeldungRepositoryTest

7. ApiAuthService + api/submit.php changes (API security)
   └── backend/src/Services/ApiAuthService.php (new)
   └── backend/public/api/submit.php (modified)
   └── backend/public/api/upload.php (modified)
   └── ApiAuthServiceTest

8. FormConfigRepository + FormConfig adapter (form config migration)
   └── backend/src/Repositories/FormConfigRepository.php (new)
   └── backend/src/Config/FormConfig.php (modified)
   └── New backend API endpoint: api/form-config.php
   └── Frontend FormConfig.php modified to call backend API
   └── Seed script for existing forms-config.php data

9. Upload directory restructuring (file isolation)
   └── uploads/tenant-{id}/ structure
   └── DetailController modified for scoped paths
   └── Migration for existing flat uploads/

10. AuditLogger tenant_id (audit completeness)
    └── Add tenant_id param to AuditLogger::log()
    └── All existing call sites pass TenantContext::getTenantId()

11. Platform admin + tenant CRUD UI (management interface)
    └── backend/public/tenants/ pages
    └── backend/public/tenant_admins/ pages
    └── backend/src/Controllers/TenantController.php
    └── backend/src/Controllers/TenantAdminController.php
    └── backend/src/Services/TenantService.php

12. Frontend tenant parameter (Scenario A)
    └── frontend/public/index.php reads &tenant= param
    └── frontend/src/Services/BackendApiClient.php includes tenant_id + signature
    └── frontend/config/forms-config.php migration path
    └── frontend/surveys/tenant-{id}/ subdirectory structure
    └── WordPress shortcode: [ondisos form="bs" tenant=5]

13. Database migration script (upgrade path)
    └── database/migrations/v3.0.0.sql
    └── Handles existing single-tenant data → tenant_id=1
    └── Seeds default tenant with existing API secret
```

## Anti-Patterns

### Anti-Pattern 1: Bypassing TenantContext in Service Methods

**What people do:** Services that need tenant_id call the database directly or accept tenant_id as a parameter in some methods but not others.

**Why it's wrong:** Creates inconsistent scoping. A service method without a tenant_id parameter looks tenant-agnostic but actually relies on the repository doing filtering — this is invisible from the calling code and hard to audit for security.

**Do this instead:** All tenant_id resolution happens in TenantContext. Services never accept tenant_id as a parameter. Repositories always read from TenantContext internally. This makes the pattern consistent and auditable — if a repository method doesn't call TenantContext, it is obviously wrong.

### Anti-Pattern 2: Conditional Schema Logic

**What people do:** Adding `IF MULTI_TENANT_ENABLED: WHERE tenant_id = 1` vs `WHERE tenant_id = ?` conditional branches throughout the codebase.

**Why it's wrong:** Doubles the code paths, doubles the test surface, and creates a diverging implementation that will inevitably get out of sync. Single-tenant mode is "multi-tenant with one tenant," not a separate code path.

**Do this instead:** Always use the full multi-tenant query path. In single-tenant mode, TenantContext is initialized to tenant_id=1 and the default tenant row exists in the DB. The `WHERE tenant_id = 1` clause costs nothing and all existing data migrates to tenant_id=1 automatically.

### Anti-Pattern 3: Checking Permissions in Views

**What people do:** Adding `if ($isTenantAdmin && $tenantId !== $row->tenantId) continue;` loops in template/view code.

**Why it's wrong:** Data isolation is a security property. It must be enforced at the repository layer (SQL WHERE clause), not at presentation time. If a template leaks a row because of a missed check, it is a data breach.

**Do this instead:** The repository never returns data outside the TenantContext's tenant_id. By the time data reaches a view, it is already correctly scoped. Views are stateless on tenant identity.

### Anti-Pattern 4: Platform Admin Reading All Data Without Scoping

**What people do:** Adding an "is_platform_admin bypass" directly inside `AnmeldungRepository` — e.g., skip the `WHERE tenant_id` clause for platform admins.

**Why it's wrong:** Repository should be tenant-unaware about role logic. Mixing authorization rules into data access muddies the layering and makes repository behavior unpredictable.

**Do this instead:** Platform admin uses the same scoped repository as everyone else. When viewing a specific tenant's data, TenantContext is initialized to that tenant_id. When the platform admin wants to see aggregate stats across all tenants, that is a separate dashboard query (a new repository method like `countAllTenants()` explicitly designed for cross-tenant aggregation, clearly marked as platform-admin-only).

### Anti-Pattern 5: Storing Survey JSON in form_configs Table

**What people do:** Embedding the full survey JSON (potentially hundreds of KB) as LONGTEXT in `form_configs.config_json` and serving it on every page load from DB.

**Why it's wrong:** The DB is the wrong place for static binary-like content that changes rarely. It also makes the form config API response huge and adds unnecessary DB I/O per form load.

**Do this instead:** `form_configs.config_json` stores the form configuration metadata (PDF config, notify email, backend URL, enabled flag). The actual SurveyJS JSON files remain on disk (`frontend/surveys/tenant-{id}/bs.json`). The config API returns the path to the survey JSON, which the frontend loads separately (consistent with current architecture). v3.1 can add an upload mechanism if needed.

## Scaling Considerations

| Scale | Architecture Adjustment |
|-------|------------------------|
| 1-10 tenants | Current architecture unchanged — single MySQL instance, shared schema |
| 10-100 tenants | Add DB read replica for admin list queries; rate-limit per-tenant not just per-IP |
| 100+ tenants | Consider per-tenant DB or schema isolation; TenantContext design supports this as a future change |

The expected scale for this system (school districts) is 1-50 tenants. The shared schema approach is the correct choice for this scale. No pre-optimization is needed.

## Sources

- `/Users/seyfried/Documents/src/ondisos/backend/MULTI-TENANT.md` (architectural decisions, HIGH confidence)
- `/Users/seyfried/Documents/src/ondisos/.planning/codebase/ARCHITECTURE.md` (current architecture analysis, HIGH confidence)
- `/Users/seyfried/Documents/src/ondisos/.planning/codebase/STRUCTURE.md` (directory layout, HIGH confidence)
- `/Users/seyfried/Documents/src/ondisos/.planning/PROJECT.md` (requirements and constraints, HIGH confidence)

---
*Architecture research for: Multi-tenant PHP school registration system*
*Researched: 2026-03-13*

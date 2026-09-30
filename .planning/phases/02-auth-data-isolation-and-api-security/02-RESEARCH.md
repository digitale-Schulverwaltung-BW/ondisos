# Phase 2: Auth, Data Isolation, and API Security - Research

**Researched:** 2026-03-13
**Domain:** PHP 8.2 session auth, multi-role access control, repository-layer tenant isolation, HMAC API authentication, file path isolation
**Confidence:** HIGH

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Login flow & roles:**
- Tenant admin usernames are globally unique — no tenant selector needed on login form
- Login form stays simple: username + password only. System resolves tenant automatically from DB lookup on `tenant_admins` table
- Platform admin authenticates via `.env` credentials (existing `ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH`)
- Platform admin sees a tenant switcher dropdown in the nav bar header to switch between tenants; default view shows "All tenants"
- `MULTI_TENANT_ENABLED=true` forces authentication on regardless of `AUTH_ENABLED` setting — multi-tenant without auth is a security hole

**Repository isolation:**
- All `AnmeldungRepository` methods read `TenantContext::getTenantId()` and add `WHERE tenant_id = ?` automatically — no method signature changes
- `TenantContext` gets an `isAllTenants()` flag for platform admin "all tenants" view — repository methods skip `tenant_id` filter when this is true
- `insert()` auto-injects `tenant_id` from `TenantContext` — caller cannot forget
- IDOR prevention: `findById` with a cross-tenant ID returns `null` + logs the attempt to audit trail (DSGVO incident detection)

**File & audit isolation:**
- Existing uploads in `uploads/` are moved to `uploads/tenant-1/` during migration (part of `migrate.php`, not a separate script)
- New uploads go to `uploads/tenant-{id}/` for all tenants
- Download access control via path validation: verify requested path starts with `uploads/tenant-{currentTenantId}/`, reject otherwise — no DB lookup needed
- `AuditLogger::log()` auto-injects `tenant_id` from `TenantContext` into every log entry — callers don't change

**API HMAC security:**
- Frontend identifies tenant via slug in URL parameter: `?tenant=schule-a`
- Backend looks up tenant by slug, validates HMAC signature against that tenant's `api_secret`
- HMAC mechanism: Frontend computes `HMAC-SHA256(request_body, tenant_secret)` and sends as `X-Signature` header. Backend recomputes and compares (timing-safe `hash_equals`)
- Per-tenant CORS: Each tenant record has an `origin` column. Backend validates `Origin` header against the tenant's configured origin
- Generic HTTP 401 for all auth failures (wrong tenant slug, disabled tenant, invalid HMAC) — prevents tenant enumeration

### Claude's Discretion
- Session structure details (`$_SESSION` keys beyond `is_platform_admin` and `allowed_tenant_ids`)
- Tenant switcher UI component design (dropdown placement, styling)
- Exact test structure for repository isolation integration tests
- How `isAllTenants()` interacts with write operations (likely should throw on insert/update/delete in all-tenants mode)
- PDF token generation: whether to scope tokens to tenant context

### Deferred Ideas (OUT OF SCOPE)
- WP shortcode and standalone frontend slug parameter usage — Phase 3 scope (FORM-03)
- Tenant CRUD management UI — Phase 3 scope (MGMT-01, MGMT-02)
- Per-tenant rate limiting — explicitly out of scope (REQUIREMENTS.md)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| AUTH-01 | Platform admin authenticates via `.env` credentials and can manage all tenants | Existing `login.php` `.env`-based path is already implemented; extend with `is_platform_admin` session flag and `TenantContext::initAllTenants()` |
| AUTH-02 | Tenant admins authenticate via DB-stored credentials and see only their tenant's data | New `tenant_admins` table (seeded by Phase 1 migration); lookup by username → set `TenantContext::initialize($tenantId)` |
| AUTH-03 | Login form includes tenant selector for tenant admin login | CONTEXT.md locked this to NO selector — username uniqueness resolves tenant automatically |
| AUTH-04 | Session stores `is_platform_admin` and `allowed_tenant_ids` for role-based access | Extend existing session keys; `auth.php` checks these after the existing `admin_logged_in` check |
| ISOL-01 | All `AnmeldungRepository` methods (~15+) filter by `tenant_id` | All 15 methods identified and analyzed; `TenantContext::getTenantId()` / `isAllTenants()` pattern maps cleanly to each query |
| ISOL-02 | File uploads stored in tenant-scoped directories (`uploads/tenant-{id}/`) | `upload.php` currently writes to `uploads/`; change to `uploads/tenant-{id}/`; migration moves existing files to `uploads/tenant-1/` |
| ISOL-03 | Audit trail entries include `tenant_id` field | `AuditLogger::log()` private method adds `tenant_id` from `TenantContext`; all callers unchanged |
| ISOL-05 | `findById` validates tenant ownership (prevents IDOR across tenants) | Add `AND tenant_id = ?` to `findById` query; log cross-tenant access attempt to audit trail |
| MGMT-03 | Per-tenant `api_secret` generated on tenant creation for HMAC authentication | `tenants` table already has `api_secret` column (seeded with `API_SECRET_KEY`); need slug + origin columns; `submit.php`/`upload.php` HMAC validation added |
| FORM-04 (via MGMT-03) | Backend API validates per-tenant HMAC signature on `submit.php` and `upload.php` | New `HmacValidator` service or inline in API endpoints; `X-Signature` header, `hash_equals` comparison, generic 401 |
</phase_requirements>

---

## Summary

Phase 2 extends an already-solid PHP 8.2 foundation to enforce multi-tenant security at every layer. The codebase provides excellent building blocks: `TenantContext` is a static singleton but currently lacks `isAllTenants()` and `isPlatformAdmin()` flags. All 15 `AnmeldungRepository` methods exist and need `tenant_id` filtering added without signature changes. The session-based auth system in `login.php` + `auth.php` only supports a single `.env` admin path; a second code path for DB-based tenant admin lookup must be added.

The largest bodies of work are: (1) repository isolation — mechanical but risk-heavy because every query must be updated and the integration test must prove correctness before this ships; (2) extending `TenantContext` to support platform admin's "all tenants" mode while preventing any write operation in that mode; and (3) HMAC validation in the two API endpoints (`submit.php`, `upload.php`) using per-tenant secrets from the `tenants` table. The `tenants` table also needs `slug` and `origin` columns, which were not in the Phase 1 migration.

The test infrastructure (PHPUnit 10.5, `tests/Integration/` directory exists but is empty) is the right place to put the IDOR and cross-tenant isolation proofs. These integration tests are DSGVO-critical — they must exist before Phase 2 is considered complete.

**Primary recommendation:** Implement in this order: (1) extend `TenantContext` and schema, (2) dual-path login, (3) auth middleware, (4) repository isolation, (5) file/audit isolation, (6) HMAC API validation — verifying with integration tests before closing.

---

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| PHP 8.2+ built-ins | — | `password_verify`, `hash_hmac`, `hash_equals`, `session_*` | Project already uses; no new dependencies needed |
| PHPUnit | 10.5 | Integration tests for repository isolation | Already in `require-dev`; `tests/Integration/` directory exists |
| MySQLi prepared statements | — | Tenant-scoped queries | Project exclusively uses MySQLi prepared statements |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| Bootstrap 5 | CDN 5.3 | Tenant switcher dropdown UI in navbar | Already in `header.php`; add dropdown component there |

### No New Dependencies Required
All security mechanisms (`hash_hmac`, `hash_equals`, `password_verify`, `password_hash`) are PHP built-ins. The project has a firm pattern of adding no external dependencies unless truly necessary. HMAC, session management, and file path validation all use native PHP.

**Installation:** No new packages needed.

---

## Architecture Patterns

### Recommended Changes to TenantContext

Current `TenantContext` (`src/Config/TenantContext.php`) has `initialize(int $tenantId)`, `getTenantId()`, `reset()`. Phase 2 needs:

```php
// Source: existing TenantContext.php + Phase 2 CONTEXT.md decisions

class TenantContext
{
    private static ?int $tenantId = null;
    private static bool $allTenants = false;

    public static function initialize(int $tenantId): void
    {
        self::$tenantId = $tenantId;
        self::$allTenants = false;
    }

    public static function initAllTenants(): void
    {
        self::$tenantId = null;
        self::$allTenants = true;
    }

    public static function isAllTenants(): bool
    {
        return self::$allTenants;
    }

    public static function getTenantId(): int
    {
        if (self::$tenantId === null && !self::$allTenants) {
            throw new RuntimeException(
                'TenantContext not initialized. Call initialize() or initAllTenants() first.'
            );
        }
        if (self::$allTenants) {
            throw new RuntimeException(
                'TenantContext is in all-tenants mode. getTenantId() is not available.'
            );
        }
        return self::$tenantId;
    }

    public static function reset(): void
    {
        self::$tenantId = null;
        self::$allTenants = false;
    }
}
```

**Key insight on write guard:** When `isAllTenants()` is true, any call to `getTenantId()` throws. This means `insert()`, `updateStatus()`, `softDelete()`, and other write methods that call `getTenantId()` will throw automatically — no extra guard needed.

### Pattern 1: Dual-Path Login

The existing `login.php` checks only `.env` credentials. Phase 2 adds a second path:

```php
// Path 1: Platform admin via .env (existing, unchanged logic)
$adminUsername = $_ENV['ADMIN_USERNAME'] ?? '';
if ($username === $adminUsername && password_verify($password, $adminPasswordHash)) {
    $_SESSION['admin_logged_in']    = true;
    $_SESSION['is_platform_admin']  = true;
    $_SESSION['admin_username']     = $username;
    $_SESSION['login_time']         = time();
    // TenantContext resolved in bootstrap.php from session
}

// Path 2: Tenant admin via tenant_admins table
// SELECT ta.*, t.active FROM tenant_admins ta JOIN tenants t ON ta.tenant_id = t.id
// WHERE ta.username = ? LIMIT 1
else if ($tenantAdmin && password_verify($password, $tenantAdmin['password_hash'])) {
    $_SESSION['admin_logged_in']    = true;
    $_SESSION['is_platform_admin']  = false;
    $_SESSION['tenant_id']          = $tenantAdmin['tenant_id'];
    $_SESSION['admin_username']     = $username;
    $_SESSION['login_time']         = time();
}
```

**Important:** Platform admin check runs first. If `ADMIN_USERNAME` is set and matches, `.env` path wins — no DB lookup for platform admin.

### Pattern 2: Bootstrap TenantContext Resolution (replacing the `else` branch)

`bootstrap.php` line 49-52 currently has a comment `// Phase 2: else { resolve tenant from session or API key }`:

```php
if (!$multiTenantEnabled) {
    TenantContext::initialize(1);
} else {
    // Determine if this is an API request (no session) or browser request
    $isApiRequest = defined('API_REQUEST') && API_REQUEST === true;

    if ($isApiRequest) {
        // API requests: resolve tenant from slug URL param + HMAC validation happens in the API endpoint
        // bootstrap only resolves TenantContext; HMAC check is in the endpoint
        $slug = $_GET['tenant'] ?? $_POST['tenant'] ?? '';
        $tenant = TenantRepository::findBySlug($slug);
        if ($tenant && $tenant['active']) {
            TenantContext::initialize((int)$tenant['id']);
        }
        // If slug invalid, TenantContext remains uninitialized → endpoint throws 401
    } else {
        // Browser requests: resolve from session
        if (!empty($_SESSION['is_platform_admin'])) {
            // Platform admin: start in all-tenants mode (or tenant switcher selection)
            $switchedTenantId = $_SESSION['switched_tenant_id'] ?? null;
            if ($switchedTenantId !== null) {
                TenantContext::initialize((int)$switchedTenantId);
            } else {
                TenantContext::initAllTenants();
            }
        } elseif (!empty($_SESSION['tenant_id'])) {
            TenantContext::initialize((int)$_SESSION['tenant_id']);
        }
        // If neither, TenantContext uninitialized — auth.php will redirect to login
    }
}
```

### Pattern 3: Repository Isolation — Standard Method Modification

Every repository method follows the same mechanical pattern. Three sub-cases:

**READ methods (SELECT):**
```php
// Before:
$sql = "SELECT ... FROM anmeldungen WHERE deleted = 0";

// After:
$sql = "SELECT ... FROM anmeldungen WHERE deleted = 0";
if (!TenantContext::isAllTenants()) {
    $tenantId = TenantContext::getTenantId();
    $sql .= " AND tenant_id = ?";
    // prepend 'i' to $types, prepend $tenantId to $params
}
```

**INSERT method:**
```php
// After — tenant_id auto-injected:
$tenantId = TenantContext::getTenantId(); // throws if all-tenants (correct)
$sql = "INSERT INTO anmeldungen (tenant_id, formular, ...) VALUES (?, ?, ...)";
// add $tenantId as first bind param
```

**WRITE methods (UPDATE/DELETE):**
```php
// After — tenant scoping prevents cross-tenant writes even with a known ID:
$sql = "UPDATE anmeldungen SET status = ? WHERE id = ? AND deleted = 0 AND tenant_id = ?";
// bind_param: 'sii', $newStatus, $id, TenantContext::getTenantId()
```

**findById IDOR prevention:**
```php
public function findById(int $id): ?Anmeldung
{
    $sql = "SELECT ... FROM anmeldungen WHERE id = ?";
    if (!TenantContext::isAllTenants()) {
        $tenantId = TenantContext::getTenantId();
        $sql .= " AND tenant_id = ?";
        // if query returns null due to tenant mismatch, log the IDOR attempt
    }
    $row = $result->fetch_assoc();
    if ($row === null && !TenantContext::isAllTenants()) {
        // Could be IDOR — log without revealing whether ID exists
        AuditLogger::log('idor_attempt', ['requested_id' => $id, 'tenant_id' => $tenantId]);
    }
    return $row ? Anmeldung::fromArray($row) : null;
}
```

### Pattern 4: HMAC Validation in API Endpoints

```php
// In submit.php and upload.php — add before processing:
// define('API_REQUEST', true) at top of API endpoint files

$slug = $_GET['tenant'] ?? '';
if (empty($slug)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// TenantContext was set in bootstrap.php from slug
// If bootstrap couldn't resolve tenant (invalid/inactive slug), TenantContext is uninitialized
try {
    $tenantId = TenantContext::getTenantId();
} catch (\RuntimeException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// HMAC validation
$providedSig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$body = file_get_contents('php://input');
// For uploads, body is empty — sign over fieldname+anmeldung_id+filename instead

$tenant = TenantRepository::findById($tenantId);
$expectedSig = hash_hmac('sha256', $body, $tenant['api_secret']);

if (!hash_equals($expectedSig, $providedSig)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Per-tenant CORS (replaces global ALLOWED_ORIGINS check)
$tenantOrigin = $tenant['origin'] ?? '';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($tenantOrigin) && $requestOrigin === $tenantOrigin) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
}
```

### Pattern 5: Schema Extension for `tenants` Table

The Phase 1 migration created `tenants` with `(id, name, api_secret, active, created_at)`. Phase 2 needs two additional columns: `slug` (for URL param tenant identification) and `origin` (for per-tenant CORS). This requires a migration step added to `migrate.php`:

```sql
-- Add slug column (URL-safe identifier like 'schule-a')
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS slug VARCHAR(100) UNIQUE;
-- Back-fill default tenant with slug
UPDATE tenants SET slug = LOWER(REPLACE(name, ' ', '-')) WHERE slug IS NULL;
-- Make it NOT NULL after back-fill
ALTER TABLE tenants MODIFY COLUMN slug VARCHAR(100) NOT NULL;

-- Add origin column for per-tenant CORS
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS origin VARCHAR(255) NULL;
-- Default tenant gets global ALLOWED_ORIGINS value (or NULL = allow global)
```

**Note:** `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` requires MySQL 8.0+. MariaDB 10.5+ also supports it. The project targets MySQL 8.0+ / MariaDB 10.5+ per CLAUDE.md — this is safe to use.

### Pattern 6: File Upload Path Isolation

In `upload.php`, change the upload directory construction:

```php
// Before:
$uploadDir = __DIR__ . '/../../uploads';

// After:
$tenantId = TenantContext::getTenantId();
$uploadDir = __DIR__ . '/../../uploads/tenant-' . $tenantId;
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
```

For download access control (wherever file downloads are served), validate the path:

```php
$tenantId = TenantContext::getTenantId();
$allowedPrefix = realpath(__DIR__ . '/../../uploads/tenant-' . $tenantId);
$requestedPath = realpath(__DIR__ . '/../../uploads/' . $requestedFile);

if ($requestedPath === false || strpos($requestedPath, $allowedPrefix) !== 0) {
    http_response_code(403);
    exit;
}
```

### Pattern 7: AuditLogger tenant_id injection

The private `log()` method in `AuditLogger` currently builds the JSON entry without `tenant_id`. Add it:

```php
private static function log(string $event, array $details = []): void
{
    // ...
    $tenantId = null;
    try {
        if (!TenantContext::isAllTenants()) {
            $tenantId = TenantContext::getTenantId();
        }
    } catch (\RuntimeException $e) {
        // TenantContext not initialized (e.g., pre-auth log entry) — log without tenant_id
    }

    $entry = json_encode([
        'ts'        => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        'event'     => $event,
        'user'      => self::getUser(),
        'ip'        => self::getIp(),
        'tenant_id' => $tenantId,
        'details'   => $details,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // ...
}
```

**Callers are unchanged** — the `tenant_id` injection is transparent.

### Anti-Patterns to Avoid

- **Passing `$tenantId` as method parameter:** Rejected by design decision. TenantContext is the single source. If you add it as a parameter, callers can accidentally pass the wrong tenant ID or omit it.
- **Initializing TenantContext inside repository methods:** Repository must not resolve tenant from session/request — that's bootstrap's job. Repository only calls `getTenantId()`.
- **Using `is_platform_admin` from `$_SESSION` inside the repository:** Only `TenantContext::isAllTenants()` drives the query branching. Session is auth; TenantContext is data scope.
- **Checking `MULTI_TENANT_ENABLED` inside the repository:** The repository does not know or care about this flag. By Phase 2, `TenantContext` is always initialized (either to a specific tenant or `allTenants`) before any repository call.
- **Storing `allowed_tenant_ids` as a JSON array in session for tenant admins:** Tenant admins have exactly one tenant. Only platform admins might switch between tenants, and that's handled via `switched_tenant_id`. No array needed.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Password hashing | Custom hash function | `password_hash()` / `password_verify()` | PHP built-in, bcrypt with proper cost factor, already used in codebase |
| Timing-safe comparison | String equality `==` | `hash_equals()` | Prevents timing attacks on HMAC and token comparison; already used in `PdfTokenService` |
| HMAC generation | Custom signature | `hash_hmac('sha256', $data, $secret)` | PHP built-in, same algorithm as `PdfTokenService`; consistent pattern |
| Session fixation prevention | Manual session ID rotation | `session_regenerate_id(true)` | PHP built-in; already used in `login.php` on successful login |
| Path traversal prevention | String manipulation | `realpath()` + prefix check | Resolves symlinks and `../` segments; safer than string comparison alone |
| Directory creation | Error-prone mkdir | `mkdir($path, 0755, true)` | `true` = recursive; idempotent with is_dir() pre-check |

---

## Common Pitfalls

### Pitfall 1: `isAllTenants()` Called Before `TenantContext` is Initialized
**What goes wrong:** `isAllTenants()` returns `false` (default), so the repository tries `getTenantId()`, which throws `RuntimeException` instead of being routed to auth redirect.
**Why it happens:** Repository code runs before auth middleware catches an uninitialized context.
**How to avoid:** `auth.php` must redirect to `login.php` before any repository call if `TenantContext` is uninitialized. In `bootstrap.php`, the `else` branch must handle all cases (API unresolved, session empty) and either initialize TenantContext or leave it uninitialized for `auth.php` to catch.
**Warning signs:** `RuntimeException: TenantContext not initialized` in error logs on non-login pages.

### Pitfall 2: Platform Admin Writes Data in All-Tenants Mode
**What goes wrong:** Platform admin, with "All tenants" view active, triggers an action (e.g., bulk archive) — `getTenantId()` throws since `allTenants = true`.
**Why it happens:** Write operations call `getTenantId()` which correctly throws. But the user gets an unhandled 500 error.
**How to avoid:** The tenant switcher must set `switched_tenant_id` in session before any write action is allowed. The admin UI should disable write actions (archive, delete) when "All tenants" view is active, or prompt the admin to select a specific tenant first.
**Warning signs:** HTTP 500 on bulk actions when platform admin is in all-tenants mode.

### Pitfall 3: IDOR Log Flood vs. False Positives
**What goes wrong:** `findById` logs every `null` result as an IDOR attempt — including legitimate 404 cases where a valid tenant queries a deleted or non-existent record.
**Why it happens:** The log check fires on all `null` results without distinguishing why the record wasn't found.
**How to avoid:** First query without `tenant_id` filter to check if the ID exists at all. If it exists but wrong tenant → genuine IDOR attempt, log it. If it doesn't exist at all → normal 404, don't log as IDOR. This requires a second query but is only for `findById`.
**Warning signs:** Audit log flooded with `idor_attempt` events even for normal usage patterns.

### Pitfall 4: HMAC Body Mismatch for `upload.php`
**What goes wrong:** `upload.php` receives a `multipart/form-data` request. `file_get_contents('php://input')` on a multipart form returns the raw multipart body including file content — the frontend can't compute that before sending.
**Why it happens:** The HMAC is designed to sign the JSON body of `submit.php`, but `upload.php` uses form data.
**How to avoid:** For `upload.php`, sign over a canonical string of the non-file fields: `"{anmeldung_id}:{fieldname}"`. Frontend computes `HMAC-SHA256("{anmeldung_id}:{fieldname}", tenant_secret)`. Document this in frontend integration notes.
**Warning signs:** HMAC validation always fails on upload requests even with correct secret.

### Pitfall 5: `migrate.php` Re-runs Breaking `slug` NOT NULL Addition
**What goes wrong:** If `migrate.php` is run multiple times (idempotent design required), the `ALTER TABLE ... MODIFY COLUMN slug VARCHAR(100) NOT NULL` step fails if any row has `slug = NULL`.
**Why it happens:** The back-fill `UPDATE` only runs in a fresh migration. If migration is interrupted after `ADD COLUMN` but before `UPDATE`, re-run skips ADD COLUMN but still tries MODIFY COLUMN with null slugs.
**How to avoid:** Check `slug IS NOT NULL` before issuing MODIFY COLUMN. Or use a safer two-step in the same migration with explicit NULL check.
**Warning signs:** `ERROR 1138 (22004): Invalid use of NULL value` during migration.

### Pitfall 6: `TenantContext` Uninitialized in `AuditLogger` for Pre-Auth Events
**What goes wrong:** `AuditLogger::loginFailed()` is called during login processing, before TenantContext is resolved. The added `TenantContext::getTenantId()` call throws.
**Why it happens:** Login happens at the authentication step before tenant context is known.
**How to avoid:** Wrap the `TenantContext::getTenantId()` call in `AuditLogger::log()` in a try/catch and default to `null`. This is the correct behavior — login events have no tenant_id (or have it as null).
**Warning signs:** Login page throws 500 instead of showing error message.

### Pitfall 7: `password_hash` vs Plaintext in `tenant_admins` Table
**What goes wrong:** A setup script or seed stores plaintext password in `tenant_admins.password_hash`.
**Why it happens:** During development/testing, quick inserts bypass `password_hash()`.
**How to avoid:** The login path uses `password_verify()`, which will always fail against plaintext. Always use `password_hash($password, PASSWORD_DEFAULT)` when inserting tenant admin records. Provide a `create-tenant-admin.php` CLI script (similar to the existing password hash generator).
**Warning signs:** All tenant admin logins fail with correct credentials.

---

## Code Examples

### Integration Test Pattern for Repository Isolation

```php
// Source: PHPUnit TestCase pattern, project tests/bootstrap.php
// File: tests/Integration/Repositories/AnmeldungRepositoryIsolationTest.php

namespace Tests\Integration\Repositories;

use App\Config\TenantContext;
use App\Config\Database;
use App\Repositories\AnmeldungRepository;
use PHPUnit\Framework\TestCase;

class AnmeldungRepositoryIsolationTest extends TestCase
{
    private \mysqli $db;
    private int $tenantAId;
    private int $tenantBId;
    private int $recordAId;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();
        $this->db = Database::getConnection();
        // Insert two test tenants
        // Insert one record for Tenant A
        // Store IDs for assertions
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        // Clean up test tenants and records
        parent::tearDown();
    }

    public function testFindByIdReturnNullForCrossTenantId(): void
    {
        TenantContext::initialize($this->tenantBId);
        $repo = new AnmeldungRepository($this->db);

        $result = $repo->findById($this->recordAId); // belongs to Tenant A

        $this->assertNull($result, 'findById must return null for cross-tenant IDs (IDOR prevention)');
    }

    public function testFindPaginatedReturnsZeroForTenantB(): void
    {
        TenantContext::initialize($this->tenantBId);
        $repo = new AnmeldungRepository($this->db);

        $result = $repo->findPaginated();

        $this->assertSame(0, $result['total'], 'Tenant B must see zero records');
        $this->assertEmpty($result['items']);
    }

    // ... one test per method named in success criteria
}
```

### Session Structure After Phase 2 Login

```php
// Platform admin session (after .env path login):
$_SESSION = [
    'admin_logged_in'    => true,
    'admin_username'     => 'admin',
    'is_platform_admin'  => true,
    'login_time'         => time(),
    'csrf_token'         => '...',
    // Optional: switched_tenant_id when tenant switcher is used
    // 'switched_tenant_id' => 3,
];

// Tenant admin session (after DB path login):
$_SESSION = [
    'admin_logged_in'    => true,
    'admin_username'     => 'schule-a-admin',
    'is_platform_admin'  => false,
    'tenant_id'          => 2,
    'login_time'         => time(),
    'csrf_token'         => '...',
];
```

### `auth.php` Extended Logic

```php
// After existing admin_logged_in check — add tenant context guard:
if ($multiTenantEnabled) {
    if (!empty($_SESSION['is_platform_admin'])) {
        // TenantContext was resolved in bootstrap.php (allTenants or switched)
    } elseif (!empty($_SESSION['tenant_id'])) {
        // TenantContext::initialize() was called in bootstrap.php
    } else {
        // Session has admin_logged_in but no tenant info — data error, re-login
        session_destroy();
        header('Location: login.php');
        exit;
    }
}
```

### Tenant Switcher in `header.php` (Platform Admin Only)

```php
// Condition for display:
$isPlatformAdmin = $_SESSION['is_platform_admin'] ?? false;

// Bootstrap 5 dropdown (in existing navbar):
if ($isPlatformAdmin && $multiTenantEnabled):
?>
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
        <?= TenantContext::isAllTenants() ? 'Alle Tenants' : htmlspecialchars($currentTenantName) ?>
    </a>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="?switch_tenant=0">Alle Tenants</a></li>
        <?php foreach ($tenants as $t): ?>
        <li><a class="dropdown-item" href="?switch_tenant=<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></a></li>
        <?php endforeach; ?>
    </ul>
</li>
<?php endif; ?>
```

The `?switch_tenant=` parameter is handled in `bootstrap.php` by updating `$_SESSION['switched_tenant_id']`.

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Single admin auth via `.env` | Dual-path: `.env` (platform admin) + DB (tenant admin) | Phase 2 | Login must try `.env` path first, DB path second |
| Global `ALLOWED_ORIGINS` | Per-tenant `origin` column | Phase 2 | `submit.php`/`upload.php` CORS changes |
| `uploads/` flat directory | `uploads/tenant-{id}/` | Phase 2 | Migration moves existing files in `migrate.php` |
| No `tenant_id` in audit log | `tenant_id` injected automatically | Phase 2 | AuditLogger private `log()` method changes |
| Repository queries without tenant filter | All queries include `AND tenant_id = ?` | Phase 2 | 15 methods updated |

**Schema additions needed (not in Phase 1 migration):**
- `tenants.slug VARCHAR(100) NOT NULL UNIQUE` — needed for API tenant identification
- `tenants.origin VARCHAR(255) NULL` — needed for per-tenant CORS

---

## Open Questions

1. **IDOR detection: two-query approach vs. single-query approach**
   - What we know: Single query returns `null` for both "not found" and "cross-tenant" cases; two-query approach distinguishes them but costs an extra DB call
   - What's unclear: Whether DSGVO requires distinguishing genuine IDOR from 404 in the audit log, or if logging all `null` results (false positives included) is acceptable
   - Recommendation: Use the two-query approach for `findById` specifically (one query to check existence, one to check ownership). The extra query cost is minimal and the audit signal is cleaner. All other methods can use single-query with tenant filter.

2. **`MULTI_TENANT_ENABLED=false` + `auth.php` interaction**
   - What we know: Single-tenant mode always sets `TenantContext::initialize(1)`; `AUTH_ENABLED` still controls session auth independently
   - What's unclear: When `MULTI_TENANT_ENABLED=false`, should `auth.php` treat `is_platform_admin` session key as valid? Or is that key only meaningful in multi-tenant mode?
   - Recommendation: When `MULTI_TENANT_ENABLED=false`, treat all logged-in admins as if they have platform-admin-level access (only one tenant exists). The `is_platform_admin` key can be set but is effectively meaningless in single-tenant mode.

3. **`upload.php` HMAC body canonical form**
   - What we know: Multipart form data body cannot be pre-computed by frontend for signing; need to sign over structured fields instead
   - What's unclear: Exact canonical string format is not defined in CONTEXT.md
   - Recommendation: Use `"{anmeldung_id}:{fieldname}:{original_filename}"` as the signed string. This ties the signature to a specific upload intent, preventing replay attacks on different fields or filenames.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 10.5 |
| Config file | `backend/phpunit.xml` |
| Quick run command | `cd backend && composer test -- --testsuite=Unit` |
| Full suite command | `cd backend && composer test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AUTH-01 | Platform admin `.env` login sets `is_platform_admin=true` in session | unit | `composer test:filter LoginPlatformAdminTest` | ❌ Wave 0 |
| AUTH-02 | Tenant admin DB login sets `tenant_id` in session | unit | `composer test:filter LoginTenantAdminTest` | ❌ Wave 0 |
| AUTH-03 | No tenant selector on login form; username resolves tenant automatically | unit | `composer test:filter LoginTenantAdminTest` | ❌ Wave 0 |
| AUTH-04 | Session contains `is_platform_admin` and (for tenant admins) `tenant_id` | unit | `composer test:filter SessionStructureTest` | ❌ Wave 0 |
| ISOL-01 | Every repository method returns 0 results for Tenant B in Tenant A context | integration | `composer test -- --testsuite=Integration` | ❌ Wave 0 |
| ISOL-02 | Upload creates file in `uploads/tenant-{id}/` directory | unit | `composer test:filter UploadIsolationTest` | ❌ Wave 0 |
| ISOL-03 | AuditLogger entries contain `tenant_id` field | unit | `composer test:filter AuditLoggerTenantTest` | ❌ Wave 0 (extend existing `AuditLoggerTest`) |
| ISOL-05 | `findById` with cross-tenant ID returns null (IDOR) | integration | `composer test -- --testsuite=Integration --filter IsolationTest` | ❌ Wave 0 |
| MGMT-03 | HMAC with wrong secret → HTTP 401; correct secret → accepted | unit | `composer test:filter HmacValidationTest` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `cd backend && composer test -- --testsuite=Unit`
- **Per wave merge:** `cd backend && composer test`
- **Phase gate:** Full suite green (including Integration) before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/Auth/LoginTest.php` — covers AUTH-01, AUTH-02, AUTH-03, AUTH-04
- [ ] `tests/Integration/Repositories/AnmeldungRepositoryIsolationTest.php` — covers ISOL-01, ISOL-05 (DSGVO-critical)
- [ ] `tests/Unit/Config/TenantContextAllTenantsTest.php` — covers `isAllTenants()` and write-guard behavior
- [ ] `tests/Unit/Services/HmacValidationTest.php` — covers MGMT-03 / FORM-04
- [ ] `tests/Unit/Services/AuditLoggerTenantIdTest.php` — extends ISOL-03 (tenant_id in log entries)
- [ ] `tests/Unit/Upload/UploadPathIsolationTest.php` — covers ISOL-02
- [ ] Integration test DB setup: `backend/.env.test` with `DB_NAME=anmeldung_test` (already referenced in `phpunit.xml` but file may not exist on developer machine)

Note: The `tests/Integration/` directory exists but is empty — all integration tests are new.

---

## Sources

### Primary (HIGH confidence)
- Codebase direct inspection:
  - `backend/src/Config/TenantContext.php` — current static singleton implementation
  - `backend/src/Repositories/AnmeldungRepository.php` — all 15 methods, their SQL patterns
  - `backend/inc/bootstrap.php` — TenantContext initialization hook (line 49-52)
  - `backend/inc/auth.php` — existing session auth logic
  - `backend/public/login.php` — existing dual-path login skeleton
  - `backend/public/api/submit.php` — current CORS and HMAC placeholder
  - `backend/public/api/upload.php` — current upload directory structure
  - `backend/src/Services/AuditLogger.php` — `log()` method structure
  - `backend/migrate.php` — Phase 1 schema (tenants, tenant_admins, anmeldungen.tenant_id)
  - `backend/phpunit.xml` — PHPUnit 10.5 configuration
  - `backend/tests/bootstrap.php` — test environment setup
  - `backend/tests/Unit/Config/TenantContextTest.php` — existing test patterns
  - `backend/tests/Unit/Services/AuditLoggerTest.php` — Reflection-based test pattern
  - `backend/composer.json` — dependency inventory (PHPUnit 10.5)
- PHP official documentation (built-in functions): `hash_hmac`, `hash_equals`, `password_verify`, `session_regenerate_id`, `realpath`

### Secondary (MEDIUM confidence)
- `.planning/phases/02-auth-data-isolation-and-api-security/02-CONTEXT.md` — locked decisions and integration points
- `.planning/REQUIREMENTS.md` — requirement traceability
- CLAUDE.md — project conventions (strict_types, PSR-4, camelCase, no new dependencies)

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — codebase fully read; all relevant files inspected; no new libraries needed
- Architecture: HIGH — patterns derived directly from existing code and CONTEXT.md locked decisions; no speculation
- Pitfalls: HIGH — derived from actual code paths and edge cases visible in the repository; one pitfall (IDOR log flood) is based on design knowledge about the pattern chosen
- Integration tests: HIGH — `tests/Integration/` directory exists, PHPUnit 10.5 configured; test patterns from existing unit tests confirm approach works

**Research date:** 2026-03-13
**Valid until:** 2026-04-13 (stable codebase; PHP 8.2 built-ins are stable)

# Phase 3: Form Config, Frontend, and Tenant Management UI - Research

**Researched:** 2026-03-15
**Domain:** PHP multi-tenant admin UI, DB-driven config, API endpoint design, seed scripts
**Confidence:** HIGH

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Tenant Management UI — Structure**
- "Tenants" is a top-level nav item in the main header nav, visible only to platform admins (alongside Submissions, Dashboard, etc.)
- `/tenants.php` — list page showing all tenants; each row links to edit
- `/tenants.php?id=3` — edit page showing tenant fields at top, tenant admin list section below
- Platform admin only — tenant admins have no visibility into tenant config

**Tenant Management UI — List page**
- Each row shows: tenant name, slug, active status badge (green/red)
- No admin count or created date columns

**Tenant Management UI — Tenant edit page**
- Editable fields: name, CORS origin, active toggle
- API secret shown as masked (***) with a "Regenerate" button — regeneration shows new secret once in a highlighted box
- Slug shown read-only (not editable after creation)
- Tenant admin management lives as a section on the same page: list of admins, each with an active/disable toggle, plus an "Add Admin" button
- Disabling a tenant is an immediate toggle — no confirmation dialog

**Tenant creation workflow**
- API secret is auto-generated on creation (cryptographically secure random bytes), shown once in a highlighted box after creation — never shown again
- Platform admin sets the initial password for new tenant admin accounts (no auto-generation)
- Platform admin can reset a tenant admin's password at any time via the edit section on the tenant page
- Slug field is pre-filled based on the tenant name (lowercased, spaces → hyphens), but editable — input rejects non-URL-safe characters in real time

**Form config DB schema**
- All form configuration stored as a JSON blob in a single `config_json` column on the `form_configs` table (mirrors the existing PHP array structure exactly — no data loss, no nested schema)
- `form_configs` table: `id`, `tenant_id`, `form_key`, `config_json` (LONGTEXT), `active` (note: schema has no `active` column — will be added by seed or migrate if needed per design)
- `forms-config.php` is deleted after the seed script successfully migrates all entries to DB with `tenant_id=1`
- `FormConfig.php` is DB-only: `FormConfig::get($formKey)` queries `WHERE form_key = ? AND tenant_id = TenantContext::getTenantId()`. No file-based fallback.
- Single-tenant mode works transparently — TenantContext is always initialized to 1, so the same query path is used
- Seed script is a prerequisite for running v3.0+ — documented in upgrade instructions (run `migrate.php` then `seed-forms.php`)

**Frontend config fetch strategy**
- Form config is fetched PHP-side at page load — `index.php` calls `BackendApiClient` to hit `/api/form-config.php?form=bs&tenant=<slug>` before rendering the page
- If the backend API is unreachable, render a user-friendly error page ("Formular vorübergehend nicht verfügbar") — no partial renders or JS fallback
- Tenant identity passed via query param: `?tenant=<slug>`. Frontend `.env` has `TENANT_SLUG=berufsschule-musterstadt`; `index.php` appends it to all API calls (config fetch, submit, upload)
- New backend endpoint: `backend/public/api/form-config.php` — returns JSON config for the given `?form=` and `?tenant=` params

**ExpungeService tenant scoping (ISOL-04)**
- Mechanical fix: `ExpungeService::autoExpunge()` must scope `findExpiredArchived()` to current tenant context (already handled by the Phase 2 repository isolation — confirm it applies here and add a test)

### Claude's Discretion
- Exact Bootstrap 5 markup and styling for the tenant management pages (consistent with existing admin pages)
- HMAC authentication for `/api/form-config.php` (public read is likely fine since form config is not sensitive — or use the same per-tenant HMAC as submit.php)
- Exact error page template for frontend API unreachable
- Test structure for FormConfig DB-backed read and tenant scoping

### Deferred Ideas (OUT OF SCOPE)
- Form config admin UI (CRUD via backend UI) — v3.1.0 scope
- Tenant admin self-service password reset — not needed
- Per-tenant rate limiting — out of scope
- Managed multi-tenant frontend (Scenario B, MTFE-01) — v3.0.5 scope
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| MGMT-01 | Platform admin can create, edit, enable/disable tenants via backend UI | TenantRepository needs create/update methods; tenants.php implements full CRUD UI with Bootstrap 5 patterns from existing pages |
| MGMT-02 | Platform admin can create and manage tenant admin accounts via backend UI | tenant_admins table exists; need TenantAdminRepository or extend TenantRepository; password_verify/password_hash PHP builtins; section on tenants.php?id=X |
| ISOL-04 | ExpungeService scopes auto-expunge to tenant context (not global) | `findExpiredArchived()` already has TenantContext guard — confirmed in Phase 2; ExpungeServiceTest exists and uses mock repo; add one targeted test to verify the guard is exercised |
| FORM-01 | Form configurations stored in form_configs DB table per tenant | Table exists (schema.sql confirmed); FormConfig.php needs DB-backed rewrite replacing file load; column is `config_json` not `config` |
| FORM-02 | Seed scripts migrate existing forms-config.php entries to DB for default tenant | seed-forms.php is a new CLI script; reads existing frontend/config/forms-config.php, inserts rows into form_configs WHERE tenant_id=1; deletes the file or prints reminder |
| FORM-03 | Frontend passes tenant parameter in API calls (?form=bs&tenant=5) | index.php reads TENANT_SLUG from env; appends to BackendApiClient calls; submit.php already parses ?tenant= slug |
| FORM-05 | Frontend fetches form config from backend API instead of local file | BackendApiClient gets new fetchFormConfig() method; index.php replaces local FormConfig::load() with API call; new backend/public/api/form-config.php endpoint |
</phase_requirements>

---

## Summary

Phase 3 is primarily a backend UI construction phase plus a data migration. The database schema is entirely in place (all four tables exist from Phases 1 and 2). The TenantRepository currently only handles lookups — it needs write methods (create, update, toggle active) for tenant management. The `tenant_admins` table has no repository class at all; one must be introduced for MGMT-02. The ExpungeService isolation concern (ISOL-04) is already solved at the repository layer: `findExpiredArchived()` has a `TenantContext::isAllTenants()` guard that scopes to the current tenant. The only work needed is a targeted test confirming this behavior and ensuring bootstrap.php initializes TenantContext before the expunge block runs (which was fixed in Phase 1 and is confirmed in the current bootstrap.php).

The frontend-side changes are straightforward rewrites: `frontend/src/Config/FormConfig.php` currently throws `RuntimeException` if `forms-config.php` is missing — after Phase 3 it becomes a thin wrapper around data fetched server-side from the backend API. The existing `BackendApiClient` class needs one new method (`fetchFormConfig()`). The backend endpoint `form-config.php` follows the same `define('API_REQUEST', true)` + `define('SKIP_AUTH_CHECK', true)` pattern already used by `submit.php`.

The Tenant Management UI follows the established Bootstrap 5 + `inc/header.php` + `inc/footer.php` pattern exactly. A single `tenants.php` file handles both the list view (no `?id=`) and the edit view (`?id=N`), with POST handling for create/update/toggle in the same file — consistent with how other admin pages (detail.php, trash.php) are structured.

**Primary recommendation:** Build in five logical work chunks: (1) TenantRepository write methods + TenantAdminRepository, (2) tenants.php UI, (3) FormConfig DB rewrite + form-config.php API endpoint, (4) frontend index.php + BackendApiClient fetch, (5) seed-forms.php migration script.

---

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| PHP 8.2+ | 8.2+ | Language | Already required; strict_types everywhere |
| mysqli | bundled | DB access | Already used throughout project |
| Bootstrap 5 | 5.x (CDN) | Admin UI | Already in header.php (CDN link) |
| PHPUnit | 10.5 | Testing | Already in composer.json |
| openssl_random_pseudo_bytes | bundled | Secret generation | Established pattern in PdfTokenService |
| password_hash / password_verify | bundled | Admin passwords | Already used in LoginService |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| bin2hex | bundled | Hex-encode raw bytes for API secret display | Pairing with openssl_random_pseudo_bytes(32) |
| json_encode / json_decode | bundled | Serialize form config blob to/from DB | FormConfig::get() reads config_json column |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Single tenants.php (list+edit) | Two separate files | Two files match current pattern for large pages; single file avoids navigation complexity and matches the locked decision |
| No auth on form-config.php | HMAC like submit.php | Form config is not sensitive data; public read avoids the frontend needing to sign requests it doesn't yet sign for config fetches |

**Installation:** No new packages needed. All dependencies already in place.

---

## Architecture Patterns

### Recommended Project Structure (new files only)
```
backend/
├── public/
│   ├── tenants.php                    # List + edit (platform admin only)
│   └── api/
│       └── form-config.php            # New API: returns form config JSON
├── src/
│   ├── Config/
│   │   └── FormConfig.php             # Rewrite: DB-backed, same public API
│   └── Repositories/
│       ├── TenantRepository.php       # Extend: add create/update/toggle methods
│       └── TenantAdminRepository.php  # New: CRUD for tenant_admins table
│
frontend/
├── public/
│   └── index.php                      # Replace local FormConfig with API call
├── src/
│   ├── Config/
│   │   └── FormConfig.php             # Replace load() with data from API response
│   └── Services/
│       └── BackendApiClient.php       # Add fetchFormConfig() method
└── .env                               # Add TENANT_SLUG=
│
backend/
└── seed-forms.php                     # New CLI script: migrate forms-config.php → DB
```

### Pattern 1: DB-Backed Static Singleton (FormConfig)
**What:** Replace `require $configFile` with a prepared statement against `form_configs`, cached in `static ?array $cache` keyed by form_key.
**When to use:** Any config that was previously file-based and is now tenant-scoped.
**Example:**
```php
// Source: mirrors existing Config.php and Database.php singleton pattern in backend/src/Config/
public static function get(string $formKey): ?array
{
    if (self::$cache === null) {
        self::$cache = [];
    }
    if (array_key_exists($formKey, self::$cache)) {
        return self::$cache[$formKey];
    }
    $db = Database::getConnection();
    $tenantId = TenantContext::getTenantId();
    $stmt = $db->prepare(
        'SELECT config_json FROM form_configs WHERE form_key = ? AND tenant_id = ? LIMIT 1'
    );
    $stmt->bind_param('si', $formKey, $tenantId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $config = $row ? json_decode($row['config_json'], true) : null;
    self::$cache[$formKey] = $config;
    return $config;
}
```

### Pattern 2: Admin Page (list + edit in one file)
**What:** `tenants.php` detects `?id=` to switch between list view and edit view, handles POST for create/update/toggles within the same file.
**When to use:** Simple CRUD pages with two views (list + detail/edit) — keeps routing simple without a front controller.
**Example:**
```php
// Source: mirrors backend/public/detail.php + backend/public/change_status.php pattern
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

// Only platform admins may access
if (empty($_SESSION['is_platform_admin'])) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
// $id === null → list view; $id > 0 → edit view
```

### Pattern 3: API Endpoint for Form Config
**What:** `backend/public/api/form-config.php` sets `define('API_REQUEST', true)` so bootstrap.php resolves `TenantContext` from `?tenant=<slug>`, then returns form config JSON.
**When to use:** Any public read API that needs tenant isolation without session auth.
**Example:**
```php
// Source: mirrors backend/public/api/submit.php pattern exactly
define('SKIP_AUTH_CHECK', true);
define('API_REQUEST', true);
require_once __DIR__ . '/../../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $tenantId = TenantContext::getTenantId();
} catch (\RuntimeException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$formKey = $_GET['form'] ?? '';
$config = FormConfig::get($formKey);
if ($config === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Form not found']);
    exit;
}
echo json_encode(['success' => true, 'config' => $config]);
```

### Pattern 4: Frontend Server-Side Config Fetch
**What:** `frontend/public/index.php` calls `BackendApiClient::fetchFormConfig()` before page render; the returned config array replaces what `FormConfig::load()` used to provide from the file.
**When to use:** Moving from file-based config to API-backed config while keeping the page render PHP-side (no JS config loading).
**Example:**
```php
// New method on BackendApiClient — mirrors healthCheck() curl pattern
public function fetchFormConfig(string $formKey, string $tenantSlug): ?array
{
    $url = $this->baseUrl . '/form-config.php?form=' . urlencode($formKey)
         . '&tenant=' . urlencode($tenantSlug);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return null; // caller renders the unavailable error page
    }
    $result = json_decode($response, true);
    return ($result['success'] ?? false) ? ($result['config'] ?? null) : null;
}
```

### Pattern 5: Tenant Create with Auto-Generated Secret
**What:** `openssl_random_pseudo_bytes(32)` → `bin2hex()` produces a 64-character hex string used as the `api_secret`. Shown once in a highlighted `<div class="alert alert-success">` after creation POST.
**When to use:** Any tenant creation flow.
**Example:**
```php
// Source: mirrors PdfTokenService HMAC key generation pattern
$apiSecret = bin2hex(openssl_random_pseudo_bytes(32));
// Store hashed or raw? Existing tenants store raw in DB (api_secret column VARCHAR(255))
// Look at submit.php HMAC validation — it reads $tenant['api_secret'] raw from DB
// Conclusion: store raw, as the current single tenant does
```

### Pattern 6: Seed Script (CLI only)
**What:** `backend/seed-forms.php` reads `frontend/config/forms-config.php`, iterates entries, inserts into `form_configs` with `tenant_id=1`, uses `INSERT IGNORE` for idempotency.
**When to use:** One-time data migration from file to DB.
**Example:**
```php
// CLI guard — same as migrate.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
// Read existing file
$formsConfigPath = __DIR__ . '/../frontend/config/forms-config.php';
if (!file_exists($formsConfigPath)) { echo "forms-config.php not found — skipping.\n"; exit(0); }
$forms = require $formsConfigPath;
// Insert each form
$stmt = $db->prepare(
    'INSERT IGNORE INTO form_configs (tenant_id, form_key, config_json) VALUES (1, ?, ?)'
);
foreach ($forms as $key => $config) {
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $stmt->bind_param('ss', $key, $json);
    $stmt->execute();
    echo "Seeded: $key\n";
}
// Reminder to delete forms-config.php (do not auto-delete — safer to prompt)
echo "\nDone. Now delete frontend/config/forms-config.php manually.\n";
```

### Anti-Patterns to Avoid
- **Re-using TenantContext::initAllTenants() in API context:** `form-config.php` is an API request — TenantContext must be initialized to a specific tenant_id. Never call `initAllTenants()` from an API endpoint.
- **Storing password in session:** After platform admin creates a new tenant admin password, never persist it in `$_SESSION`. Show once via PRG + flash, then it's gone.
- **File deletion in seed script:** Auto-deleting `forms-config.php` from the seed script risks data loss if the DB insert partially failed. Output a manual reminder instead.
- **Relying on `active` column in form_configs:** The current schema has no `active` column on `form_configs` — only `id`, `tenant_id`, `form_key`, `config_json`, `created_at`, `updated_at`. Do not add this column unless explicitly needed (CONTEXT.md does not mention it).
- **Calling FormConfig::get() before TenantContext is initialized:** All callers are either in `bootstrap.php` scope (TenantContext already set) or in API endpoints that set it from `?tenant=`. Ensure `form-config.php` endpoint validates TenantContext before calling FormConfig::get().

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Password hashing | Custom hash scheme | `password_hash(PASSWORD_BCRYPT)` + `password_verify()` | PHP built-in, already used in LoginService |
| API secret generation | UUID or rand() | `bin2hex(openssl_random_pseudo_bytes(32))` | Cryptographically secure; existing PdfTokenService pattern |
| CSRF on POST forms | Manual token strings | `CsrfProtection::getToken()` + `csrf_field()` + `CsrfProtection::verify()` | Already used on every admin POST form |
| Slug sanitization | Custom filter | `preg_replace('/[^a-z0-9-]/', '', strtolower($name))` inline | Simple enough; same approach as migrate.php's UPDATE slug logic |
| Tenant-scoped DB query | Manual WHERE string | Prepared statement with `TenantContext::getTenantId()` | Existing repository pattern; prevents SQL injection |
| JSON config serialization | Custom format | `json_encode($config)` / `json_decode($json, true)` | Mirrors the existing PHP array structure exactly; no schema migration |

**Key insight:** The entire data layer (tables, indexes, FK constraints, TenantContext) is already built. Phase 3 is wiring existing primitives together — not inventing new infrastructure.

---

## Common Pitfalls

### Pitfall 1: ISOL-04 Already Solved — But Needs a Test
**What goes wrong:** Assuming ExpungeService does not scope to tenant and adding redundant logic.
**Why it happens:** `findExpiredArchived()` already has the `TenantContext::isAllTenants()` guard (confirmed at line 440 of AnmeldungRepository.php). Bootstrap.php initializes TenantContext before the expunge block (line 49-87 runs before line 151-163). So ISOL-04 is already implemented.
**How to avoid:** Add a targeted unit test to `ExpungeServiceTest` (or a new `ExpungeServiceTenantIsolationTest`) that verifies `findExpiredArchived()` is called with tenant-scoped context when TenantContext is initialized to a specific tenant_id. The mock already exists in `ExpungeServiceTest`.
**Warning signs:** Any change to ExpungeService or AnmeldungRepository that removes the TenantContext guard from `findExpiredArchived()`.

### Pitfall 2: form_configs column name mismatch
**What goes wrong:** CONTEXT.md refers to the column as `config`, but the actual schema (schema.sql line 38, migrate.php line 69) uses `config_json`. Using the wrong column name causes silent failures.
**Why it happens:** The CONTEXT.md decision description says "single `config` column" but the real schema has `config_json`.
**How to avoid:** Always use `config_json` in all SQL and PHP code. The `FormConfig::get()` rewrite must query `SELECT config_json FROM form_configs`.

### Pitfall 3: Slug uniqueness not enforced at application layer
**What goes wrong:** Two tenants get the same slug, breaking TenantRepository::findBySlug() lookups.
**Why it happens:** The DB has `UNIQUE KEY uq_tenants_slug (slug)` but the application may not surface the DB error cleanly.
**How to avoid:** Before INSERT in the create flow, check `SELECT COUNT(*) FROM tenants WHERE slug = ?`. Return a user-friendly error from tenants.php if slug already exists. The DB constraint is a safety net, not the primary guard.

### Pitfall 4: tenant_admins username uniqueness scope
**What goes wrong:** Username "admin" is used for multiple tenants; login resolution breaks.
**Why it happens:** The schema has `UNIQUE KEY uq_tenant_username (tenant_id, username)` — usernames are unique per tenant. BUT: login resolves username globally (AUTH-03 from REQUIREMENTS.md says "tenant admin usernames are globally unique"). These constraints conflict.
**Root cause confirmed:** The DB schema allows per-tenant duplicate usernames (unique within tenant), but AUTH-03 requires global uniqueness for the self-resolving login to work. This means the application layer must enforce global uniqueness at create-time (SELECT COUNT(*) FROM tenant_admins WHERE username = ? across all tenants).
**Warning signs:** Two tenant admins with the same username across different tenants — the login query would return the first match, silently logging into the wrong tenant.

### Pitfall 5: FormConfig static cache survives across test cases
**What goes wrong:** `static ?array $cache` in the new DB-backed FormConfig persists between test invocations if not reset.
**Why it happens:** PHP static properties are per-process. PHPUnit runs multiple tests in one process.
**How to avoid:** Add a `public static function reset(): void { self::$cache = null; }` method (same pattern as TenantContext::reset()). Call it in test `tearDown()`.

### Pitfall 6: Frontend TENANT_SLUG not set in single-tenant mode
**What goes wrong:** `index.php` reads `TENANT_SLUG` from env, but existing single-tenant deployments have no such variable. API call to `form-config.php?tenant=` has empty slug → TenantContext uninitialized → 401.
**Why it happens:** New env variable required for existing deployments.
**How to avoid:** Default `TENANT_SLUG` to `'default'` (the slug of the default tenant, seeded in schema.sql). Document this in .env.example. The form-config API resolves by slug; `default` maps to tenant_id=1.

### Pitfall 7: TenantRepository::findAll() excludes inactive tenants
**What goes wrong:** The current `findAll()` queries `WHERE active = 1`. The tenant list in `tenants.php` should show ALL tenants (including inactive ones) so platform admins can re-enable them.
**Why it happens:** `findAll()` was designed for the dropdown switcher (active only). The management UI needs a separate method.
**How to avoid:** Add `findAllForAdmin(): array` that omits the `active = 1` filter. Use `findAll()` (active only) for the header dropdown; use `findAllForAdmin()` for `tenants.php` list view.

### Pitfall 8: Password reset flow must not expose hash
**What goes wrong:** Displaying the new password hash in a success message instead of the plain password.
**Why it happens:** The only time the plain password is known is immediately after `password_hash()` is called. After that, only the hash is stored.
**How to avoid:** In the POST handler for password reset, store the plain password in a local variable for the response, then immediately `password_hash()` it and persist only the hash. Show the plain password once in the success box, then discard it. Never write it to a log.

---

## Code Examples

Verified patterns from existing codebase:

### Checking platform admin gate in a new page
```php
// Source: mirrors existing SKIP_AUTH_CHECK + session check pattern in backend pages
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php'; // redirects non-logged-in users

if (empty($_SESSION['is_platform_admin'])) {
    http_response_code(403);
    die('Zugriff nur für Plattform-Admins.');
}
```

### Generating and displaying API secret once
```php
// Source: openssl pattern from PdfTokenService.php; display pattern follows existing
// "new secret shown once" UX decision in CONTEXT.md
$rawSecret = openssl_random_pseudo_bytes(32);
$apiSecret = bin2hex($rawSecret); // 64-char hex string
// Store $apiSecret in DB (raw), show in response view
```

### Password hash for new tenant admin
```php
// Source: mirrors LoginService password_hash usage
$passwordHash = password_hash($plainPassword, PASSWORD_BCRYPT);
```

### Bootstrap 5 active/inactive badge (existing pages use this)
```php
// Source: existing index.php status badge pattern
$badgeClass = $tenant['active'] ? 'badge bg-success' : 'badge bg-danger';
$label = $tenant['active'] ? 'Aktiv' : 'Inaktiv';
echo "<span class=\"{$badgeClass}\">" . htmlspecialchars($label) . '</span>';
```

### Slug pre-fill JavaScript (frontend, inline in form)
```javascript
// Source: CONTEXT.md specifies real-time slug generation on name input
document.getElementById('name').addEventListener('input', function () {
    const slug = this.value
        .toLowerCase()
        .replace(/\s+/g, '-')
        .replace(/[^a-z0-9-]/g, '');
    document.getElementById('slug').value = slug;
});
```

### TenantAdminRepository: find admins for a tenant
```php
// Source: mirrors TenantRepository.findAll() pattern
public function findByTenantId(int $tenantId): array
{
    $sql = 'SELECT id, username, active FROM tenant_admins WHERE tenant_id = ? ORDER BY username ASC';
    $stmt = $this->db->prepare($sql);
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $rows = [];
    while ($row = $stmt->get_result()->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| forms-config.php (flat PHP file) | form_configs DB table with JSON blob | Phase 3 | Tenant-isolated, no file management per deployment |
| Frontend FormConfig reads local file | Frontend index.php fetches config from backend API | Phase 3 | Tenant identity travels with every request |
| TenantRepository: read-only | TenantRepository: read + write (create, update, toggle) | Phase 3 | Platform admin can manage tenants without DB access |
| No tenant admin management UI | tenants.php with admin list + password reset | Phase 3 | Full onboarding flow via UI |

**Deprecated/outdated after Phase 3:**
- `frontend/config/forms-config.php`: Deleted after seed-forms.php succeeds
- `backend/config/forms-config.php`: Deleted after seed-forms.php succeeds (if it exists — checked at FormConfig load time as a fallback path)
- `FormConfig::load()` (file-based path in both frontend and backend): Removed entirely

---

## Open Questions

1. **tenant_admins active column**
   - What we know: The DB schema (`schema.sql`) has no `active` column on `tenant_admins`. CONTEXT.md says each admin row has an "active/disable toggle".
   - What's unclear: Does `tenant_admins` need an `active` TINYINT(1) column, or is disabling achieved by deleting the row?
   - Recommendation: Add `active TINYINT(1) DEFAULT 1` to `tenant_admins` via `migrate.php` Step N. The toggle should flip `active`, not delete — consistent with soft-delete patterns used elsewhere.

2. **form-config.php authentication**
   - What we know: CONTEXT.md marks this as Claude's discretion. Form config data (form JSON paths, email addresses, PDF settings) is not credentials but could expose internal config.
   - What's unclear: Should the endpoint require HMAC signature like submit.php?
   - Recommendation: No HMAC required. The endpoint is called server-side by the frontend PHP (not from the browser), so it operates inside the server network. Tenant resolution via slug is sufficient. If future threat model changes, HMAC can be added without breaking callers.

3. **forms-config.php existence in both frontend and backend config dirs**
   - What we know: `backend/src/Config/FormConfig.php` currently tries `backend/config/forms-config.php` first, then falls back to `frontend/config/forms-config.php`. The seed script targets the frontend file.
   - What's unclear: Does a `backend/config/forms-config.php` exist in production deployments?
   - Recommendation: The seed script should check both paths and seed from whichever exists (or both if both exist). After seeding, output a reminder to delete both.

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
| MGMT-01 | TenantRepository::create() stores new tenant with correct fields | unit | `composer test -- --filter TenantRepositoryWriteTest` | ❌ Wave 0 |
| MGMT-01 | TenantRepository::update() changes name/origin/active | unit | `composer test -- --filter TenantRepositoryWriteTest` | ❌ Wave 0 |
| MGMT-01 | TenantRepository::findAllForAdmin() returns active and inactive tenants | unit | `composer test -- --filter TenantRepositoryWriteTest` | ❌ Wave 0 |
| MGMT-02 | TenantAdminRepository::create() stores hashed password | unit | `composer test -- --filter TenantAdminRepositoryTest` | ❌ Wave 0 |
| MGMT-02 | TenantAdminRepository::findByTenantId() returns admins for correct tenant only | unit | `composer test -- --filter TenantAdminRepositoryTest` | ❌ Wave 0 |
| MGMT-02 | TenantAdminRepository::resetPassword() stores new hash | unit | `composer test -- --filter TenantAdminRepositoryTest` | ❌ Wave 0 |
| ISOL-04 | findExpiredArchived() calls getTenantId() when not in all-tenants mode | unit | `composer test -- --filter ExpungeServiceTenantScopingTest` | ❌ Wave 0 |
| ISOL-04 | autoExpunge() in all-tenants context does not scope to a single tenant | unit | `composer test -- --filter ExpungeServiceTenantScopingTest` | ❌ Wave 0 |
| FORM-01 | FormConfig::get() queries DB with correct tenant_id from TenantContext | unit | `composer test -- --filter FormConfigDbTest` | ❌ Wave 0 |
| FORM-01 | FormConfig::get() returns null for unknown form key | unit | `composer test -- --filter FormConfigDbTest` | ❌ Wave 0 |
| FORM-01 | FormConfig::get() decodes config_json to PHP array | unit | `composer test -- --filter FormConfigDbTest` | ❌ Wave 0 |
| FORM-02 | seed-forms.php inserts correct rows (manual smoke test via CLI) | manual | `php backend/seed-forms.php` then verify DB | N/A |
| FORM-03 | BackendApiClient::fetchFormConfig() appends form + tenant params to URL | unit | `composer test -- --filter BackendApiClientTest` | ❌ Wave 0 |
| FORM-03 | BackendApiClient::fetchFormConfig() returns null on non-200 response | unit | `composer test -- --filter BackendApiClientTest` | ❌ Wave 0 |
| FORM-05 | index.php renders error page when fetchFormConfig() returns null | manual-only | Load frontend with backend stopped | N/A |

### Sampling Rate
- **Per task commit:** `cd backend && composer test -- --testsuite=Unit`
- **Per wave merge:** `cd backend && composer test`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `backend/tests/Unit/Repositories/TenantRepositoryWriteTest.php` — covers MGMT-01 (create, update, findAllForAdmin)
- [ ] `backend/tests/Unit/Repositories/TenantAdminRepositoryTest.php` — covers MGMT-02 (create, findByTenantId, resetPassword, toggle active)
- [ ] `backend/tests/Unit/Services/ExpungeServiceTenantScopingTest.php` — covers ISOL-04 (verifies mock repo receives correct tenant context)
- [ ] `backend/tests/Unit/Config/FormConfigDbTest.php` — covers FORM-01 (DB-backed FormConfig::get() with mocked mysqli)
- [ ] `backend/tests/Unit/Services/BackendApiClientTest.php` — covers FORM-03 (fetchFormConfig URL construction, error handling)
- [ ] `backend/src/Repositories/TenantAdminRepository.php` — implementation file (new class, no existing file)

---

## Sources

### Primary (HIGH confidence)
- Direct code inspection of `/Users/seyfried/Documents/src/ondisos/backend/src/Repositories/AnmeldungRepository.php` — confirmed `findExpiredArchived()` has TenantContext guard at line 440
- Direct code inspection of `/Users/seyfried/Documents/src/ondisos/backend/inc/bootstrap.php` — confirmed TenantContext is initialized before the expunge block
- Direct code inspection of `/Users/seyfried/Documents/src/ondisos/database/schema.sql` — confirmed `form_configs` column is `config_json` (not `config`)
- Direct code inspection of `/Users/seyfried/Documents/src/ondisos/backend/src/Repositories/TenantRepository.php` — confirmed only read methods exist
- Direct code inspection of `/Users/seyfried/Documents/src/ondisos/backend/public/api/submit.php` — confirmed `define('API_REQUEST', true)` + slug resolution pattern
- Direct code inspection of `/Users/seyfried/Documents/src/ondisos/backend/inc/header.php` — confirmed Bootstrap 5 + nav structure + `$_SESSION['is_platform_admin']` check pattern

### Secondary (MEDIUM confidence)
- `.planning/phases/03-form-config-frontend-and-tenant-management-ui/03-CONTEXT.md` — locked decisions and deferred scope
- `.planning/REQUIREMENTS.md` — requirement IDs and descriptions (AUTH-03 global username uniqueness requirement confirmed)

### Tertiary (LOW confidence)
- None

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — entire stack is the existing codebase; no new dependencies
- Architecture: HIGH — all patterns confirmed by direct code inspection of established files
- Pitfalls: HIGH — all pitfalls derived from concrete code observations (column name, missing `active` column, TenantRepository scope gap, AUTH-03 vs DB schema conflict)
- Test map: HIGH — test file paths follow existing naming conventions; gap list verified against actual test directory

**Research date:** 2026-03-15
**Valid until:** 2026-04-15 (stable PHP codebase; no fast-moving external deps)

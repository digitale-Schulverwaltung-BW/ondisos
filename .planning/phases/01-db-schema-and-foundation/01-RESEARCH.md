# Phase 1: DB Schema and Foundation - Research

**Researched:** 2026-03-13
**Domain:** PHP 8.2 / MySQLi — database migration, static singleton pattern, PHPUnit unit testing
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- `tenants` table columns: `id`, `name`, `api_secret`, `active`, `created_at`
- Default tenant name: hardcoded `'Default'` (no `.env` dependency for migration)
- Default tenant `api_secret`: read from `API_SECRET_KEY` in `.env` (preserves backward compatibility for existing frontends)
- `active` column type: `TINYINT(1) DEFAULT 1` — consistent with existing `deleted` column pattern in `anmeldungen`
- All `CREATE TABLE` statements use `IF NOT EXISTS`
- `ALTER TABLE` for `tenant_id` column: check `INFORMATION_SCHEMA.COLUMNS` first, skip if column already exists
- Default tenant seed: `INSERT IGNORE INTO tenants ...` — silent skip if id=1 already present
- `migrate.php` prints verbose output for every step
- `TenantContext`: static singleton, `initialize(int $tenantId)` + `getTenantId()` that throws `RuntimeException` before `initialize()` is called
- When `MULTI_TENANT_ENABLED=false`: `bootstrap.php` explicitly calls `TenantContext::initialize(1)`
- `TenantContext::initialize()` called in `bootstrap.php` **before** auto-expunge block
- Location: `backend/migrate.php` — CLI only, not web-accessible
- Invocation: `php backend/migrate.php`
- No dry-run flag
- Fail fast on missing `.env` or failed DB connection with specific error message and `exit(1)`

### Claude's Discretion

- Exact SQL DDL for `tenant_admins` and `form_configs` table schemas (design for Phase 2 and 3 needs)
- Unit test structure for `TenantContext` (must prove `getTenantId()` throws before `initialize()`)
- How `migrate.php` loads the `.env` file (reuse `EnvLoader` or inline)

### Deferred Ideas (OUT OF SCOPE)

None — discussion stayed within phase scope.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| SCHEMA-01 | Database migration creates `tenants`, `tenant_admins`, `form_configs` tables | DDL patterns, idempotency via IF NOT EXISTS, INFORMATION_SCHEMA checks |
| SCHEMA-02 | Existing `anmeldungen` table gains `tenant_id` column with default value 1 | ALTER TABLE idempotency pattern, compound index recommendations |
| SCHEMA-03 | Default tenant (id=1) seeded automatically, inheriting existing `API_SECRET_KEY` | EnvLoader::require() usage, INSERT IGNORE pattern |
| SCHEMA-04 | `TenantContext` request-scoped singleton resolves tenant from session or API request | Existing Config/Database singleton patterns to model after |
| SCHEMA-05 | Single-tenant mode (`MULTI_TENANT_ENABLED=false`) operates transparently with tenant_id=1 | bootstrap.php integration point, SKIP_AUTO_EXPUNGE constant pattern |
</phase_requirements>

---

## Summary

Phase 1 establishes the database schema and request-scoped singleton that all subsequent phases depend on. The work is purely additive: three new tables, one new column on `anmeldungen`, one new class (`TenantContext`), and one new CLI script (`migrate.php`). No existing code is modified except `bootstrap.php` (two lines added) and optionally `Config.php` (one new env var read).

The architecture is already defined precisely in CONTEXT.md and MULTI-TENANT.md. The key constraint driving every implementation decision is idempotency: the migration script must be safe to run against both a fresh v2.6 database and a partially-migrated database. The second constraint is backward compatibility: `MULTI_TENANT_ENABLED=false` must leave the v2.6 admin backend entirely unaffected.

The `tenant_admins` and `form_configs` table schemas are left to Claude's discretion but must anticipate Phase 2 and Phase 3 needs respectively. Both are documented in `backend/MULTI-TENANT.md` and validated here as the authoritative design.

**Primary recommendation:** Follow the singleton pattern from `App\Config\Config` exactly for `TenantContext`. For `migrate.php`, reuse `EnvLoader::load()` and `Database::getConnection()` rather than inlining connection logic — this gives consistent error handling for free.

---

## Standard Stack

### Core

| Library / Tool | Version | Purpose | Why Standard |
|----------------|---------|---------|--------------|
| PHP 8.2+ | 8.2 | Runtime | Project requirement, all existing code targets 8.2+ |
| MySQLi | bundled | Database driver | Already used throughout; `Database::getConnection()` returns `mysqli` |
| PHPUnit | 10.5 | Unit testing | Already installed (`backend/vendor`), test suite in `backend/tests/Unit/` |

### Project-Internal Reusable Assets

| Asset | Location | Purpose | How to Use |
|-------|----------|---------|------------|
| `App\Config\EnvLoader` | `backend/src/Config/EnvLoader.php` | `.env` parsing | `EnvLoader::load($path)`, `EnvLoader::require($key)` |
| `App\Config\Database` | `backend/src/Config/Database.php` | MySQLi singleton | `Database::getConnection()` returns `mysqli` after env is loaded |
| `App\Config\Config` | `backend/src/Config/Config.php` | Singleton pattern template | Copy pattern: `private static ?self $instance`, `getInstance()`, private constructor |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `EnvLoader::load()` in migrate.php | Inline env parsing | Inline is simpler for a standalone script but duplicates logic and loses EnvLoader's quote-stripping; reuse is better |
| `Database::getConnection()` in migrate.php | Inline `new mysqli(...)` | Inline couples migrate.php to config format; reuse gives identical connection behavior and error handling |
| Static singleton for TenantContext | Constructor-injected value | Injection is cleaner but would require touching every service and repository constructor; singleton matches the existing `Config`/`Database` pattern and changes only bootstrap.php |

---

## Architecture Patterns

### TenantContext Singleton

Model `TenantContext` exactly after `App\Config\Config` and `App\Config\Database`:

```
backend/src/Config/TenantContext.php
```

**Pattern (verified from `Config.php` and `Database.php`):**

```php
<?php
// src/Config/TenantContext.php
declare(strict_types=1);

namespace App\Config;

use RuntimeException;

class TenantContext
{
    private static ?int $tenantId = null;

    private function __construct() {}

    public static function initialize(int $tenantId): void
    {
        self::$tenantId = $tenantId;
    }

    public static function getTenantId(): int
    {
        if (self::$tenantId === null) {
            throw new RuntimeException(
                'TenantContext not initialized. Call TenantContext::initialize() first.'
            );
        }
        return self::$tenantId;
    }

    // For test teardown / reset between tests
    public static function reset(): void
    {
        self::$tenantId = null;
    }
}
```

**Key decisions baked in:**
- `getTenantId()` throws — never silently returns a default. This is the contract the unit test must prove.
- `initialize()` is idempotent (calling it twice just overwrites — safe).
- `reset()` is needed for PHPUnit test isolation (static state persists between test methods).

### bootstrap.php Integration Point

Insert `TenantContext::initialize()` call **after** env load, **before** the auto-expunge block. Exact location in existing `bootstrap.php`:

```
[line 41] App\Config\EnvLoader::load($envFile);         ← already exists
[INSERT HERE] TenantContext initialization block
[line 86] if (!defined('SKIP_AUTO_EXPUNGE')) {          ← already exists
```

**Pattern to insert (after env load, before auto-expunge):**

```php
// Initialize TenantContext (always, even in single-tenant mode)
$multiTenantEnabled = filter_var(
    App\Config\EnvLoader::get('MULTI_TENANT_ENABLED', 'false'),
    FILTER_VALIDATE_BOOLEAN
);
if (!$multiTenantEnabled) {
    App\Config\TenantContext::initialize(1);
}
// Phase 2 replaces the else branch with session/API-key resolution
```

**Why before auto-expunge:** `ExpungeService` accesses `AnmeldungRepository`, which in Phase 2+ will call `TenantContext::getTenantId()`. Initializing first prevents any future breakage when repository methods gain tenant scope.

### migrate.php Structure

```
backend/migrate.php
```

**Pattern:**

```php
<?php
declare(strict_types=1);

// CLI-only guard
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

// Load autoloader and env
require_once __DIR__ . '/vendor/autoload.php';

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Error: .env file not found at {$envFile}\n");
    exit(1);
}

App\Config\EnvLoader::load($envFile);

// Verify required env vars before connecting
$apiSecret = App\Config\EnvLoader::get('API_SECRET_KEY');
if (empty($apiSecret)) {
    fwrite(STDERR, "Error: API_SECRET_KEY not set in .env\n");
    exit(1);
}

// Get DB connection (reuses Database singleton, which reads from env)
try {
    $db = App\Config\Database::getConnection();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "Error: Cannot connect to database — " . $e->getMessage() . "\n");
    exit(1);
}

// --- Migration steps ---
// Each step: print "Step N: Description... ", execute, print "OK\n" or "SKIPPED\n"
```

**Idempotency implementation for `ALTER TABLE`:**

```php
// Check INFORMATION_SCHEMA before ALTER TABLE
$checkSql = "SELECT COUNT(*) as cnt
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'anmeldungen'
               AND COLUMN_NAME = 'tenant_id'";
$result = $db->query($checkSql);
$row = $result->fetch_assoc();

if ((int)$row['cnt'] === 0) {
    echo "Step X: Adding tenant_id to anmeldungen... ";
    $db->query("ALTER TABLE anmeldungen ADD COLUMN tenant_id INT NOT NULL DEFAULT 1");
    $db->query("ALTER TABLE anmeldungen ADD INDEX idx_tenant (tenant_id)");
    $db->query("ALTER TABLE anmeldungen ADD INDEX idx_tenant_formular (tenant_id, formular)");
    echo "OK\n";
} else {
    echo "Step X: tenant_id column already exists, skipping.\n";
}
```

**Seed pattern (INSERT IGNORE for idempotency):**

```php
echo "Step Y: Seeding default tenant... ";
$stmt = $db->prepare(
    "INSERT IGNORE INTO tenants (id, name, api_secret, active, created_at)
     VALUES (1, 'Default', ?, 1, NOW())"
);
$stmt->bind_param('s', $apiSecret);
$stmt->execute();
if ($stmt->affected_rows > 0) {
    echo "OK\n";
} else {
    echo "SKIPPED (already exists)\n";
}
$stmt->close();
```

### Recommended tenant_admins Schema

Phase 2 needs: tenant-scoped login (username+password), `is_platform_admin` flag, foreign key to `tenants`. From `backend/MULTI-TENANT.md` (authoritative design):

```sql
CREATE TABLE IF NOT EXISTS tenant_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_platform_admin TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_username (tenant_id, username),
    FOREIGN KEY fk_tenant_admin_tenant (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Note:** `is_platform_admin TINYINT(1)` matches the project's boolean convention (`deleted TINYINT(1)` in `anmeldungen`). The `UNIQUE KEY (tenant_id, username)` means usernames are unique per-tenant, not globally. The open decision about global uniqueness (in STATE.md) affects Phase 2 auth but not this DDL — Phase 2 can add a global unique constraint if needed without a destructive migration.

### Recommended form_configs Schema

Phase 3 needs: tenant-scoped form config stored as JSON, keyed by `form_key`. From `backend/MULTI-TENANT.md`:

```sql
CREATE TABLE IF NOT EXISTS form_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    form_key VARCHAR(100) NOT NULL,
    config_json LONGTEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_form (tenant_id, form_key),
    FOREIGN KEY fk_form_config_tenant (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**`LONGTEXT` for `config_json`** matches `anmeldungen.data` — consistent and handles large form configs.

### Anti-Patterns to Avoid

- **`TenantContext::getTenantId()` returning `null` silently:** The CONTEXT.md decision is "throws" — enforce this. A silent `null` or default `1` masks initialization bugs.
- **`TenantContext::initialize()` doing a DB lookup:** Phase 1 always initializes to `1`. Phase 2 replaces the bootstrap call — no DB query in the class itself.
- **Inlining env parsing in migrate.php:** EnvLoader handles comment-stripping, quote-removal, and three-source lookup (`$_ENV`, `$_SERVER`, `getenv()`). Inline code misses edge cases.
- **Using `$db->query()` for the seed INSERT:** Seed uses a dynamic value (`$apiSecret`). Use `$db->prepare()` + `bind_param()` — consistent with "prepared statements ALWAYS" convention.
- **Adding `tenant_id` foreign key before seeding the default tenant:** The column has `DEFAULT 1` but no row with `id=1` in `tenants` yet. Add FK constraint only after seeding — or rely on the `DEFAULT 1` + seed happening in the same migration run.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| `.env` parsing in migrate.php | Custom file reader | `App\Config\EnvLoader::load()` | Already handles quotes, comments, three-source lookup |
| DB connection in migrate.php | `new mysqli(...)` inline | `App\Config\Database::getConnection()` | Consistent error handling, charset setting, singleton |
| Singleton boilerplate in TenantContext | Custom registry | Copy `Config` pattern verbatim | Proven pattern already in codebase, same namespace |
| Idempotency check for column existence | Application-level tracking | `INFORMATION_SCHEMA.COLUMNS` query | Database-authoritative, no state file needed |

---

## Common Pitfalls

### Pitfall 1: TenantContext Reset Not Available for PHPUnit

**What goes wrong:** PHPUnit runs multiple test methods in one process. Static state from `TenantContext::initialize()` persists between test methods. Tests that expect `getTenantId()` to throw (before initialization) will fail if a previous test method called `initialize()`.

**Why it happens:** PHP static properties are process-global. PHPUnit does not isolate static state between test methods unless explicitly reset.

**How to avoid:** Add a `public static function reset(): void { self::$tenantId = null; }` method to `TenantContext`. Call it in `tearDown()` of the test class. (The `TESTING` constant defined in `tests/bootstrap.php` could gate this method if desired, but it's simpler to always expose it.)

**Warning signs:** `testGetTenantIdThrowsWhenNotInitialized` passes in isolation but fails when the full test suite runs.

### Pitfall 2: Adding tenant_id FK Before Seeding Default Tenant

**What goes wrong:** `ALTER TABLE anmeldungen ADD FOREIGN KEY ... REFERENCES tenants(id)` runs after the column is added but before `INSERT IGNORE INTO tenants` seeds id=1. All existing rows have `tenant_id=1` but no matching row exists in `tenants` yet. MySQL raises a foreign key violation.

**Why it happens:** Migration steps are executed in the wrong order.

**How to avoid:** Migration step order MUST be:
1. `CREATE TABLE IF NOT EXISTS tenants`
2. `CREATE TABLE IF NOT EXISTS tenant_admins`
3. `CREATE TABLE IF NOT EXISTS form_configs`
4. Seed default tenant (`INSERT IGNORE INTO tenants ... id=1`)
5. `ALTER TABLE anmeldungen ADD COLUMN tenant_id` (with INFORMATION_SCHEMA check)
6. `ALTER TABLE anmeldungen ADD INDEX ...`
7. `ALTER TABLE anmeldungen ADD FOREIGN KEY ...` (only after tenant row exists)

**Warning signs:** `ERROR 1452 (23000): Cannot add or update a child row: a foreign key constraint fails`.

### Pitfall 3: migrate.php Accessible via Web

**What goes wrong:** A webserver misconfiguration makes `backend/migrate.php` accessible as `http://intranet/migrate.php`, allowing anyone on the intranet to re-run the migration.

**Why it happens:** `backend/public/` is the webroot, but `migrate.php` lives in `backend/` — it should not be served. However, some deployments map the whole `backend/` directory.

**How to avoid:** Add the CLI-only guard at the top of migrate.php:
```php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
```
The migrations are idempotent, so accidental re-runs cause no data loss — but the guard prevents confusion.

### Pitfall 4: INFORMATION_SCHEMA Query Uses Wrong DATABASE()

**What goes wrong:** The `INFORMATION_SCHEMA.COLUMNS` check uses `DATABASE()` which returns the currently selected database. If the connection was established but no database selected (edge case in some environments), the check returns 0 (column not found) and the ALTER TABLE is attempted again, failing with "duplicate column."

**Why it happens:** `Database::getConnection()` selects the database from `$config->dbName`, so `DATABASE()` is correct in normal operation. But test environments with `DB_NAME=anmeldung_test` may not have run the migration yet, causing confusion during integration tests.

**How to avoid:** Use the literal database name from env rather than `DATABASE()` if defensive coding is needed:
```php
$dbName = App\Config\EnvLoader::require('DB_NAME');
$stmt = $db->prepare(
    "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'anmeldungen' AND COLUMN_NAME = 'tenant_id'"
);
$stmt->bind_param('s', $dbName);
```
This is more explicit and avoids the `DATABASE()` edge case.

### Pitfall 5: bootstrap.php TenantContext Block Breaks Test Suite

**What goes wrong:** Adding `TenantContext::initialize(1)` to `bootstrap.php` runs on every test execution. But the test bootstrap (`tests/bootstrap.php`) does NOT include `inc/bootstrap.php` — it loads only the Composer autoloader and sets env vars. So `TenantContext` is NOT initialized by default when tests run.

**Why it happens:** The test bootstrap intentionally avoids running `inc/bootstrap.php` (it sets `SKIP_AUTO_EXPUNGE`, `SKIP_AUTH_CHECK` for isolation). The `TenantContext` initialization lives in `inc/bootstrap.php`, not in the test bootstrap.

**How to avoid:** The unit test for `TenantContext` must explicitly call `TenantContext::reset()` in `setUp()` and `tearDown()` to control state. Any test that needs a valid tenant context must call `TenantContext::initialize(1)` in its own `setUp()`. This is the correct approach — tests that depend on `inc/bootstrap.php` behavior are integration tests, not unit tests.

**Warning signs:** Unit tests for `TenantContext` pass, but other unit tests that instantiate repository classes (in future phases) throw "TenantContext not initialized" because no initialization happened.

---

## Code Examples

### Complete tenants Table DDL

```sql
-- Source: backend/MULTI-TENANT.md + CONTEXT.md decisions
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    api_secret VARCHAR(255) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Note:** The CONTEXT.md locks `id, name, api_secret, active, created_at`. The `backend/MULTI-TENANT.md` includes `slug` and `updated_at` columns. The CONTEXT.md decision takes precedence for Phase 1 — keep to the locked schema. Phase 2 or 3 can ADD columns via their own migration step if needed.

### anmeldungen ALTER TABLE (idempotent)

```sql
-- Check first (application code), then conditionally run:
ALTER TABLE anmeldungen
    ADD COLUMN tenant_id INT NOT NULL DEFAULT 1;

ALTER TABLE anmeldungen
    ADD INDEX idx_tenant (tenant_id);

ALTER TABLE anmeldungen
    ADD INDEX idx_tenant_formular (tenant_id, formular);

ALTER TABLE anmeldungen
    ADD INDEX idx_tenant_status (tenant_id, status);

-- Add FK only after tenants id=1 row is seeded:
ALTER TABLE anmeldungen
    ADD FOREIGN KEY fk_anmeldung_tenant (tenant_id)
        REFERENCES tenants(id);
```

**Compound indexes:** `(tenant_id, formular)` and `(tenant_id, status)` are needed for Phase 2's `findPaginated` filtering. Adding them in Phase 1 is correct — they have zero overhead in single-tenant mode and prevent a performance regression the moment multi-tenant queries start.

### TenantContext Unit Test Pattern

Model after `PdfTokenServiceTest.php` (PHPUnit 10.5 pattern, already in codebase):

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\TenantContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TenantContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();  // Ensure clean state
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TenantContext::reset();  // Don't bleed state into next test
    }

    public function testGetTenantIdThrowsWhenNotInitialized(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not initialized/i');

        TenantContext::getTenantId();
    }

    public function testGetTenantIdReturnsValueAfterInitialize(): void
    {
        TenantContext::initialize(1);

        $this->assertSame(1, TenantContext::getTenantId());
    }

    public function testInitializeOverwritesPreviousValue(): void
    {
        TenantContext::initialize(1);
        TenantContext::initialize(42);

        $this->assertSame(42, TenantContext::getTenantId());
    }

    public function testResetClearsInitializedState(): void
    {
        TenantContext::initialize(1);
        TenantContext::reset();

        $this->expectException(RuntimeException::class);

        TenantContext::getTenantId();
    }
}
```

**File location:** `backend/tests/Unit/Config/TenantContextTest.php`

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| No migration infrastructure | `migrate.php` CLI script with idempotency guards | Phase 1 | One-time migration from v2.6; idempotent for re-runs |
| Single implicit tenant (no tracking) | Explicit `TenantContext` singleton with mandatory initialization | Phase 1 | `getTenantId()` throws before init — bugs surface immediately |
| `bootstrap.php` auto-expunge runs before any context | `TenantContext::initialize()` inserted before auto-expunge block | Phase 1 | Unblocks Phase 2 tenant-scoped expunge |

**Nothing deprecated in Phase 1** — all changes are additive. Existing v2.6 code paths untouched except bootstrap.php (two new lines).

---

## Open Questions

1. **`tenants.slug` column: include or defer?**
   - What we know: CONTEXT.md locks `id, name, api_secret, active, created_at` for the `tenants` table. `backend/MULTI-TENANT.md` shows `slug VARCHAR(100) UNIQUE NOT NULL` in the design.
   - What's unclear: Phase 2 may need `slug` for login form tenant selection (dropdown shows slug, not id). If `slug` is added in Phase 2, it requires a second migration step that ALTER TABLEs a table with live data.
   - Recommendation: Add `slug` as a nullable column in Phase 1's `tenants` DDL even if not locked (it's additive to the schema, zero cost). Alternatively, confirm with user if `slug` is needed in Phase 2 auth before finalizing DDL. If left out, Phase 2 plan must include its own migration step.

2. **Foreign key on `anmeldungen.tenant_id`: strict or deferred?**
   - What we know: The INFORMATION_SCHEMA idempotency check works for the column, but adding a FK constraint a second time will fail if it already exists (MySQL names FKs).
   - What's unclear: The FK constraint name must also be checked for existence before adding. INFORMATION_SCHEMA approach for FK: `SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = 'anmeldungen' AND CONSTRAINT_NAME = 'fk_anmeldung_tenant'`.
   - Recommendation: Include this check in migrate.php alongside the column check. Alternatively, omit the FK in Phase 1 (column + indexes only) since FK enforcement is nice-to-have not functionally required. FK can be added in Phase 2 if desired.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 10.5 |
| Config file | `backend/phpunit.xml` |
| Quick run command | `cd backend && ./vendor/bin/phpunit --testsuite=Unit` |
| Full suite command | `cd backend && composer test` |

### Phase Requirements to Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SCHEMA-04 | `TenantContext::getTenantId()` throws before `initialize()` | unit | `cd backend && ./vendor/bin/phpunit --filter TenantContextTest` | ❌ Wave 0 |
| SCHEMA-04 | `TenantContext::getTenantId()` returns correct value after `initialize()` | unit | `cd backend && ./vendor/bin/phpunit --filter TenantContextTest` | ❌ Wave 0 |
| SCHEMA-04 | `TenantContext::reset()` clears initialized state (test isolation) | unit | `cd backend && ./vendor/bin/phpunit --filter TenantContextTest` | ❌ Wave 0 |
| SCHEMA-01/02/03/05 | Migration idempotency (run twice = no errors) | manual-only | `php backend/migrate.php && php backend/migrate.php` | N/A |
| SCHEMA-02 | All existing `anmeldungen` rows have `tenant_id = 1` after migration | manual-only | `mysql -e "SELECT COUNT(*) FROM anmeldungen WHERE tenant_id != 1"` | N/A |
| SCHEMA-05 | v2.6 backend loads normally with `MULTI_TENANT_ENABLED=false` | manual-only | Load `backend/public/index.php` after migration | N/A |

**Manual-only justification:** Migration tests require a real database (v2.6 schema). These are smoke tests run once during deployment validation, not automated in the unit test suite.

### Sampling Rate

- **Per task commit:** `cd backend && ./vendor/bin/phpunit --filter TenantContextTest`
- **Per wave merge:** `cd backend && composer test`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `backend/tests/Unit/Config/TenantContextTest.php` — covers SCHEMA-04 (new file, covers 4 test cases minimum)
- [ ] `backend/src/Config/TenantContext.php` — the class itself must exist before tests can run

*(Existing test infrastructure — PHPUnit 10.5, phpunit.xml, tests/bootstrap.php — is fully in place. Only the new class and its test file are missing.)*

---

## Sources

### Primary (HIGH confidence)

- `backend/src/Config/Config.php` — singleton pattern template for TenantContext
- `backend/src/Config/Database.php` — singleton + mysqli connection pattern
- `backend/src/Config/EnvLoader.php` — env loading API: `load()`, `get()`, `require()`
- `backend/inc/bootstrap.php` — exact insertion point for TenantContext initialization
- `backend/tests/bootstrap.php` — SKIP_AUTO_EXPUNGE constant, confirms test suite does NOT run inc/bootstrap.php
- `backend/phpunit.xml` — PHPUnit 10.5, test suite structure, env var setup
- `backend/tests/Unit/Services/PdfTokenServiceTest.php` — established PHPUnit test pattern for this project
- `backend/MULTI-TENANT.md` — authoritative DDL for tenant_admins, form_configs, anmeldungen ALTER
- `.planning/phases/01-db-schema-and-foundation/01-CONTEXT.md` — locked decisions

### Secondary (MEDIUM confidence)

- `.planning/research/PITFALLS.md` — migration order, INFORMATION_SCHEMA idempotency patterns, compound index recommendations (derived from codebase analysis)
- `.planning/ROADMAP.md` — Phase 2/3 requirements that constrain Phase 1 table designs

### Tertiary (LOW confidence)

None — all findings are directly verified from codebase files.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — verified from codebase files
- Architecture: HIGH — TenantContext pattern directly derived from Config.php/Database.php; DDL from MULTI-TENANT.md
- Pitfalls: HIGH — derived from actual codebase structure and existing pitfalls research
- Table schemas for tenant_admins/form_configs: HIGH — taken from MULTI-TENANT.md which is the design document

**Research date:** 2026-03-13
**Valid until:** Stable — not dependent on external library versions; all findings from the codebase itself

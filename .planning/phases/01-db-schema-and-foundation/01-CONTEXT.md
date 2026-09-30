# Phase 1: DB Schema and Foundation - Context

**Gathered:** 2026-03-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Create the tenant infrastructure in the database and give every request a way to know which tenant it belongs to. This phase delivers: migration runner, three new tables (`tenants`, `tenant_admins`, `form_configs`), `tenant_id` column on `anmeldungen`, and the `TenantContext` singleton. Auth, data isolation, form config migration, and admin UI are later phases.

</domain>

<decisions>
## Implementation Decisions

### Default tenant seed scope
- `tenants` table columns: `id`, `name`, `api_secret`, `active`, `created_at`
- Default tenant name: hardcoded `'Default'` (admin can edit later — no .env dependency for migration)
- Default tenant `api_secret`: read from `API_SECRET_KEY` in `.env` (preserves backward compatibility for existing frontends)
- `active` column type: `TINYINT(1) DEFAULT 1` — consistent with existing `deleted` column pattern in `anmeldungen`

### Idempotency strategy
- All `CREATE TABLE` statements use `IF NOT EXISTS`
- `ALTER TABLE` for `tenant_id` column: check `INFORMATION_SCHEMA.COLUMNS` first, skip if column already exists
- Default tenant seed: `INSERT IGNORE INTO tenants ...` — silent skip if id=1 already present
- migrate.php prints verbose output for every step: `Creating tenants table... OK`, `tenant_id column already exists, skipping`

### TenantContext design
- Static singleton class, matching existing `Config` and `Database` singleton patterns
- `TenantContext::initialize(int $tenantId)` sets state; `getTenantId()` throws `RuntimeException` if called before `initialize()`
- When `MULTI_TENANT_ENABLED=false`: `bootstrap.php` explicitly calls `TenantContext::initialize(1)` — no auto-magic in the class itself
- Phase 1 always initializes to `1`; Phase 2 replaces the bootstrap logic with real session/API-key resolution
- `TenantContext::initialize()` called in `bootstrap.php` **before** auto-expunge runs — fixes the existing blocker where `ExpungeService` runs before tenant context is available

### Migration runner interface
- Location: `backend/migrate.php` — CLI only, not web-accessible
- Invocation: `php backend/migrate.php`
- No dry-run flag — verbose output is sufficient since the script is idempotent
- One-time v3.0 migration script; future versions get their own scripts
- Fail fast on missing `.env` or failed DB connection: print specific error (`'DB_HOST not set'`, `'Cannot connect to database'`) and `exit(1)`

### Claude's Discretion
- Exact SQL DDL for `tenant_admins` and `form_configs` table schemas (Phase 2 and 3 use these — design for their needs)
- Unit test structure for `TenantContext` (must prove `getTenantId()` throws before `initialize()`)
- How `migrate.php` loads the `.env` file (reuse `EnvLoader` or inline)

</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- `App\Config\EnvLoader`: Static helper for reading `.env` — `migrate.php` can use `EnvLoader::load()` and `EnvLoader::require()` to read `DB_*` and `API_SECRET_KEY`
- `App\Config\Database`: Static singleton for mysqli connection — reusable in `migrate.php` after env is loaded
- `App\Config\Config`: Existing singleton pattern to model `TenantContext` after (static `$instance`, private constructor, `getInstance()`)

### Established Patterns
- Static singleton pattern: `Config`, `Database` — `TenantContext` follows same structure
- `TINYINT(1) DEFAULT 0/1` for boolean columns — established in `anmeldungen.deleted`
- Prepared statements everywhere — migrate.php SQL can use `$db->query()` for DDL but `$db->prepare()` for seed inserts with dynamic values
- `declare(strict_types=1)` in every PHP file — required

### Integration Points
- `backend/inc/bootstrap.php`: Add `TenantContext::initialize()` call immediately after `EnvLoader` and before auto-expunge block
- `AnmeldungRepository`: All query methods need `tenant_id` filter added in Phase 2 — Phase 1 adds the column but does not touch the repository
- `SKIP_AUTO_EXPUNGE` constant: already defined in tests bootstrap — `TenantContext` initialization in bootstrap must not break test setup

</code_context>

<specifics>
## Specific Ideas

No specific requirements — open to standard approaches

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 01-db-schema-and-foundation*
*Context gathered: 2026-03-13*

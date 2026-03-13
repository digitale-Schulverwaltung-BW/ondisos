# Stack Research

**Domain:** Multi-tenant capability for PHP 8.2+ custom MVC school registration system
**Researched:** 2026-03-13
**Confidence:** HIGH (all recommendations derived from existing codebase patterns and constraints; no external library additions required or recommended)

---

## Context: What This Is NOT

This is a **stack additions** document for milestone v3.0.0. The base stack (PHP 8.2, mysqli, mPDF, PHPSpreadsheet, PHPUnit, Docker) is fully validated at v2.6 and is not re-evaluated here. The constraint from PROJECT.md is explicit: **no new framework dependencies**.

Every recommendation below either (a) uses PHP built-ins, (b) extends an existing singleton/service pattern, or (c) adds one narrowly-scoped dev-only tool with a clear justification.

---

## Recommended Stack

### Core Technologies

No new runtime dependencies are needed. The existing stack handles all multi-tenant requirements:

| Capability | Implementation | Why No Library Needed |
|------------|---------------|----------------------|
| TenantContext propagation | Custom static singleton class (mirrors `Config.php`) | Request-scoped singletons are 20 lines of PHP; a DI container adds no value here |
| RBAC (platform admin / tenant admin) | Session array + `AuthContext` static class | Two roles, session-stored claims — no RBAC library warranted |
| Per-tenant HMAC API auth | `hash_hmac('sha256', ...)` built-in | Already used by `PdfTokenService`; no new library needed |
| Password hashing for tenant_admins | `password_hash()` / `password_verify()` built-ins | Already used by existing admin auth system |
| DB migration (schema changes) | Custom versioned migration runner + numbered SQL files | See migration section below |
| Tenant-scoped file paths | PHP string operations on existing upload path logic | `backend/uploads/tenant-{id}/` is a path manipulation, not a library problem |

### Migration Tooling: Custom Runner vs Library

**Decision: Custom versioned migration runner. No external library.**

**Rationale:**

The v3.0.0 migration is a defined, finite set of SQL statements (4 DDL statements from MULTI-TENANT.md). This is not a greenfield project where migrations will be written weekly. A migration library like Phinx (~600KB + dependencies) or Doctrine Migrations (~900KB + DBAL) would:
- Add Composer dependencies that need maintaining
- Require learning a new API for what is essentially `mysqli_query()`
- Conflict with the "no new framework dependencies" constraint

A custom runner with the following structure costs nothing and integrates directly with the existing `Database.php` singleton:

```
backend/
└── migrations/
    ├── MigrationRunner.php       # Tracks applied migrations in a schema_versions table
    ├── 001_create_tenants.sql
    ├── 002_create_tenant_admins.sql
    ├── 003_create_form_configs.sql
    └── 004_add_tenant_id_to_anmeldungen.sql
```

`MigrationRunner::run()` is called once from a CLI script or first-request guard. It reads all `*.sql` files in order, checks the `schema_versions` table for applied migrations, and runs only new ones. This is a ~60-line class.

**MEDIUM confidence** — Phinx is commonly used in no-framework PHP projects and is a valid alternative if the project grows to need rollback tooling. For this milestone's scope, the custom runner is the better fit.

### Supporting Libraries

No new supporting libraries are recommended for v3.0.0.

| Library | Decision | Reason |
|---------|----------|--------|
| `robmorgan/phinx` ^0.16 | Skip | Overkill for 4 targeted DDL migrations; adds ~600KB deps; "no new framework" constraint |
| `doctrine/migrations` ^3.8 | Skip | Requires Doctrine DBAL, which pulls in a full ORM ecosystem — antithetical to the project's custom MVC approach |
| `symfony/security-core` | Skip | Two-role RBAC is 30 lines of PHP session logic; framework component would be massive over-engineering |
| `firebase/php-jwt` | Skip | JWT is a good token format for distributed systems; HMAC shared secrets are sufficient for single-server intranet deployment and already proven in this codebase |
| Any PSR-11 container | Skip | DI containers help when you have many dependencies; the existing singleton pattern (`Config`, `Database`) is consistent and testable via constructor injection in repositories |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| PHPUnit 10.5 (existing) | Tests for TenantRepository, TenantContext, AuthContext | Already installed; use constructor injection in new classes for testability (pass `?mysqli $db = null` pattern already established in `AnmeldungRepository`) |
| PHP built-in CLI | Run migration script | `php backend/migrate.php` — no additional tool needed |

---

## Installation

No new Composer packages. Existing `composer.json` is unchanged for v3.0.0.

```bash
# No changes to:
# composer.json
# composer.lock
# vendor/

# New files to create (no install step):
# backend/migrations/MigrationRunner.php
# backend/migrations/001_*.sql through 004_*.sql
# backend/migrate.php  (CLI entry point)
# backend/src/Config/TenantContext.php
# backend/src/Config/AuthContext.php
# backend/src/Repositories/TenantRepository.php
```

---

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|------------------------|
| Custom migration runner | `robmorgan/phinx` ^0.16 | Use Phinx if the project will have ongoing weekly schema changes, needs rollback (down migrations), or has multiple developers needing a migration workflow. Not warranted for a defined 4-migration one-time upgrade. |
| Static `TenantContext` singleton | Constructor injection of `$tenantId` | Use injection if the codebase moves toward a DI container. The static singleton is consistent with the existing `Config` and `Database` patterns and avoids changing 15+ method signatures in `AnmeldungRepository`. |
| Session-stored RBAC claims | Database role lookup per request | Use DB lookup if roles change frequently mid-session or if audit requirements demand live role enforcement. For two stable roles (platform admin, tenant admin) a session array is sufficient and faster. |
| `password_hash()` built-in | `sodium_crypto_pwhash_str()` | Use libsodium if Argon2id memory parameters need tuning. `password_hash(PASSWORD_BCRYPT)` is sufficient for school admin credentials; `PASSWORD_DEFAULT` (currently Bcrypt in PHP 8.2) is fine. |

---

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| Doctrine ORM / Eloquent | Would require replacing all mysqli prepared statements — a rewrite, not an extension | Extend existing `AnmeldungRepository` pattern with a new `TenantRepository` |
| Laravel Sanctum / Passport | Token systems designed for REST APIs with many clients; overkill for intranet session auth | PHP `$_SESSION` with HMAC-signed per-tenant API secrets (existing pattern) |
| Redis for session storage | Adds infrastructure complexity; the project explicitly uses file-based patterns (rate limiter) | PHP native session storage (files) is fine for single-server deployment |
| JWT (JSON Web Tokens) | Stateless tokens require a secret rotation strategy; HMAC shared secrets are simpler and already proven in this codebase | `hash_hmac('sha256', ...)` as used in `PdfTokenService` |
| `vlucas/phpdotenv` | The existing `EnvLoader.php` is a hand-rolled .env parser that already works correctly | Keep `EnvLoader.php` — adding a library to do what 60 lines already do is waste |

---

## Stack Patterns by Variant

**TenantContext initialization (single-tenant mode, `MULTI_TENANT_ENABLED=false`):**
- `TenantContext::initialize(tenantId: 1)` called in `bootstrap.php` unconditionally
- All queries always include `WHERE tenant_id = 1` — transparent, no conditional code paths
- Because: consistent data model, zero-friction upgrade, matches MULTI-TENANT.md decision

**TenantContext initialization (multi-tenant mode, API request):**
- Frontend sends `tenant_id` + HMAC signature in request header
- `bootstrap.php` or API endpoint calls `TenantContext::initializeFromHmac($tenantId, $signature)`
- `TenantRepository` loads tenant, verifies `api_secret` via `hash_equals(hash_hmac(...), $signature)`
- Because: per-tenant HMAC is already the decided approach; mirrors `PdfTokenService::validateToken()`

**TenantContext initialization (multi-tenant mode, admin session):**
- After login, session stores `['admin_id' => X, 'is_platform_admin' => bool, 'allowed_tenant_ids' => [1,2,3]]`
- `TenantContext::initializeFromSession()` reads these values
- Platform admin: `TenantContext` allows any `tenant_id` (used in tenant management UI)
- Tenant admin: `TenantContext` enforces `allowed_tenant_ids` check on every request
- Because: AuthContext and TenantContext are separate concerns — MULTI-TENANT.md makes this explicit

**AuthContext class structure:**
```php
// Mirrors Config.php singleton pattern exactly
class AuthContext {
    private static ?self $instance = null;
    public readonly int $adminId;
    public readonly bool $isPlatformAdmin;
    public readonly array $allowedTenantIds;

    public static function fromSession(): self { ... }
    public static function isAuthenticated(): bool { ... }
    public function canAccessTenant(int $tenantId): bool { ... }
}
```

**TenantContext class structure:**
```php
// Mirrors Config.php singleton pattern exactly
class TenantContext {
    private static ?int $tenantId = null;

    public static function initialize(int $tenantId): void { ... }
    public static function getTenantId(): int { ... }  // throws if not initialized
    public static function reset(): void { ... }       // test isolation only
}
```

**TenantRepository (new, follows AnmeldungRepository pattern):**
- Constructor accepts `?mysqli $db = null` (testable, matches existing pattern)
- Methods: `findById()`, `findBySlug()`, `create()`, `update()`, `delete()`, `findAll()`
- Used by: API auth validation, platform admin UI, TenantContext initialization

---

## Version Compatibility

| Package | Version | PHP Requirement | Status |
|---------|---------|-----------------|--------|
| PHP | 8.2+ | — | Already in use |
| PHPUnit | ^10.5 | PHP 8.1+ | Already in use, no change |
| mpdf/mpdf | ^8.2 | PHP 7.4+ | Already in use, no change |
| phpoffice/phpspreadsheet | ^3.0 | PHP 8.1+ | Already in use, no change |
| Custom MigrationRunner | N/A (no Composer package) | PHP 8.2+ | New, no deps |

No version conflicts introduced by v3.0.0 changes. The Composer lockfile is stable.

---

## Integration Points with Existing Patterns

| Existing Component | v3.0.0 Change | Integration Note |
|-------------------|---------------|-----------------|
| `Config.php` singleton | No change | `TenantContext` is a new sibling class in `App\Config` namespace |
| `Database.php` singleton | No change | Shared schema; single connection; `tenant_id` is just another column |
| `bootstrap.php` | Add `TenantContext::initialize(...)` call | Called after `EnvLoader::load()`, before `ExpungeService` |
| `AnmeldungRepository` constructor | No change | `TenantContext::getTenantId()` called inside query methods, not the constructor |
| Existing admin auth (`login.php`) | Extend session fields | Add `is_platform_admin` and `allowed_tenant_ids` to session on login |
| `AuditLogger` | Add `tenant_id` field | Single log file, one new JSON field per entry |
| `upload.php` | Change upload directory | `backend/uploads/tenant-{id}/` instead of `backend/uploads/` |

---

## Sources

- `/Users/seyfried/Documents/src/ondisos/backend/MULTI-TENANT.md` — Architectural decisions (HIGH confidence, primary source)
- `/Users/seyfried/Documents/src/ondisos/.planning/PROJECT.md` — Constraints and scope (HIGH confidence)
- `/Users/seyfried/Documents/src/ondisos/.planning/codebase/STACK.md` — Existing validated stack (HIGH confidence)
- `/Users/seyfried/Documents/src/ondisos/backend/src/Config/Config.php` — Singleton pattern to replicate (HIGH confidence)
- `/Users/seyfried/Documents/src/ondisos/backend/src/Config/Database.php` — Connection pattern (HIGH confidence)
- `/Users/seyfried/Documents/src/ondisos/backend/src/Repositories/AnmeldungRepository.php` — Repository pattern to extend (HIGH confidence)
- Training data: Phinx ^0.16, Doctrine Migrations ^3.8, their dependency footprints (MEDIUM confidence — versions not independently verified due to tool availability; use `composer require robmorgan/phinx --dev` to check current version if Phinx is ever reconsidered)

---

*Stack research for: Multi-tenant v3.0.0 additions to ondisos PHP 8.2+ custom MVC*
*Researched: 2026-03-13*

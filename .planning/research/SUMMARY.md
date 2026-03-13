# Project Research Summary

**Project:** ondisos — Multi-tenant school registration system (v3.0.0)
**Domain:** Adding multi-tenancy to an existing single-tenant PHP 8.2 custom MVC web application
**Researched:** 2026-03-13
**Confidence:** HIGH

## Executive Summary

This is a brownfield multi-tenancy migration, not a greenfield project. The existing v2.6 codebase (PHP 8.2, mysqli, custom MVC, mPDF, PhpSpreadsheet, PHPUnit, Docker) is fully validated and the constraint is explicit: no new framework dependencies. The recommended approach is a shared-schema multi-tenancy model with a `tenant_id` column on the `anmeldungen` table and a request-scoped `TenantContext` singleton that propagates tenant identity through every layer without changing method signatures across 15+ repository methods. This mirrors patterns already proven in the codebase — `PdfTokenService` for HMAC, `Config.php` for the singleton pattern, and `AnmeldungRepository` for the injectable repository pattern.

The recommended approach is conservative by design. All four new tables (`tenants`, `tenant_admins`, `form_configs`, and the `ALTER TABLE anmeldungen`) are handled by a custom versioned migration runner — no external migration library is warranted for this defined, finite set of DDL statements. The two-role RBAC (platform admin vs. tenant admin) is implemented with session arrays and an `AuthContext` static class rather than any external security library. Backward compatibility with single-tenant deployments is maintained by always running the full multi-tenant code path with `tenant_id = 1` as the default when `MULTI_TENANT_ENABLED=false` — no conditional schema branches.

The dominant risk is incomplete tenant isolation in the repository layer. `AnmeldungRepository` has 15+ methods, all of which must receive `AND tenant_id = ?` clauses. Missing even one is a silent DSGVO data breach — no exception is thrown, the query simply returns wrong data. The mitigation is mandatory: write integration tests that prove Tenant B cannot access Tenant A's data via every repository method before implementing any repository changes. Secondary risks are the auth system expansion (the most complex modification in the milestone) and the FormConfig dual-source-of-truth transition from file-based config to the `form_configs` DB table.

## Key Findings

### Recommended Stack

The stack is unchanged from v2.6. No new runtime dependencies are needed. All multi-tenant capabilities are implemented using PHP built-ins (`hash_hmac`, `password_hash`, `$_SESSION`) and two new custom classes (`TenantContext`, `AuthContext`) that mirror existing singleton patterns. A custom ~60-line migration runner replaces any external library like Phinx or Doctrine Migrations for the 4-migration one-time upgrade.

**Core technologies:**
- PHP 8.2 built-ins (`hash_hmac`, `password_hash`, `$_SESSION`) — per-tenant HMAC, password storage, role-bearing sessions — no new library warranted for two-role RBAC or HMAC auth
- Custom `TenantContext` static singleton — request-scoped tenant identity — mirrors existing `Config.php` pattern, avoids threading tenant_id through 15+ method signatures
- Custom `AuthContext` static singleton — session role context (platform admin / tenant admin) — same pattern as TenantContext, 30 lines of PHP
- Custom `MigrationRunner` + numbered SQL files — versioned schema migration — no external library needed for 4 defined DDL statements
- PHPUnit 10.5 (existing) — test coverage for all new services and repositories — constructor injection (`?mysqli $db = null`) already established in `AnmeldungRepository`

### Expected Features

**Must have (v3.0.0 table stakes — DSGVO-critical):**
- DB migration + new tables (`tenants`, `tenant_admins`, `form_configs`, `ALTER TABLE anmeldungen`) — foundational; nothing else works without it
- TenantContext request-scoped singleton — prerequisite for all tenant-aware code
- Tenant-aware `AnmeldungRepository` (all 15+ methods) — data isolation is DSGVO-mandatory
- Multi-role authentication (platform admin + tenant admin) — required to gate management UI and enforce data scope
- Tenant CRUD management UI — platform admin must create and disable schools without code deploys
- Tenant admin management UI — platform admin must create school admin credentials
- Per-tenant HMAC `api_secret` — each school's frontend must authenticate against its own tenant
- DB-driven form configuration + seed scripts — `forms-config.php` cannot serve per-tenant forms
- Frontend tenant parameter support (Scenario A: dedicated frontend per school)
- Tenant-scoped file upload directories (`uploads/tenant-{id}/`)
- Tenant-aware audit trail (`tenant_id` field in every log entry)
- Zero-config backward compatibility for existing single-tenant deployments
- Tests alongside all new features (project constraint, not optional)

**Should have (differentiators — low cost, high value):**
- Per-tenant `api_secret` rotation capability — compromised secret can be rotated without affecting other tenants
- Tenant slug as human-readable identifier — enables log filtering and future subdomain routing
- Seed scripts for form config — platform admin can provision a new school's forms without writing SQL
- Automatic tenant detection from session vs. HMAC — unified `TenantContext` for both auth paths

**Defer (v3.0.5 or v3.1+):**
- Managed multi-tenant frontend / Scenario B (tenant-switching UI) — once core multi-tenancy is proven
- Form config admin UI — seed scripts suffice for v3.0; proper UI requires SurveyJS Creator integration
- Survey JSON file upload mechanism — frontend management feature, not a v3.0 blocker
- Per-tenant message customization — low value for municipal government context

### Architecture Approach

The architecture extends the existing two-application structure with a horizontal `TenantContext` layer that cuts across all backend layers. `TenantContext` is initialized once per HTTP request in `bootstrap.php` — from the validated HMAC signature for API requests, from the session for admin pages, and hardcoded to `1` in single-tenant mode. Repositories call `TenantContext::getTenantId()` internally, keeping method signatures unchanged for all existing service callers. Three new tables, five new classes (`TenantContext`, `AuthContext`, `TenantRepository`, `FormConfigRepository`, `ApiAuthService`), and 15+ modified repository methods are the core of the migration. Build order is strictly sequential: DB schema first, then TenantContext, then auth, then repositories, then API endpoints, then UI.

**Major components:**
1. `TenantContext` (NEW, `src/Config/`) — request-scoped singleton; single source of truth for `tenant_id` per request; throws if accessed before initialization
2. `AuthContext` (NEW, `src/Config/`) — session role context; separates authentication (is logged in?) from authorization (what tenant/scope?)
3. `AnmeldungRepository` (MODIFIED) — all 15+ methods gain `AND tenant_id = ?`; tenant_id sourced internally from `TenantContext`, not from callers
4. `ApiAuthService` (NEW, `src/Services/`) — validates per-tenant HMAC signatures on `submit.php` and `upload.php`; replaces global `.env` API secret
5. `FormConfigRepository` (NEW, `src/Repositories/`) — DB-backed form config lookup; `FormConfig.php` becomes a thin adapter delegating to this repository
6. Platform admin UI pages (NEW, `public/tenants/`, `public/tenant_admins/`) — CRUD for tenant and admin management; gated to `is_platform_admin` sessions
7. `MigrationRunner` + 4 SQL migration files (NEW, `migrations/`) — versioned, idempotent schema upgrade; CLI entry point `migrate.php`

### Critical Pitfalls

1. **Missing `tenant_id` on one or more `AnmeldungRepository` methods** — silent cross-tenant data leak; no exception, just wrong data returned. Avoid by writing integration tests for every method asserting Tenant B cannot see Tenant A's data before implementing any code changes. `getAllFormNames`, `getStatistics`, `findDeleted`, `findExpiredArchived`, `bulkUpdateStatus`, `bulkSoftDelete`, `hardDelete`, `restore`, and `findByIds` are the highest-risk overlooked methods.

2. **`findById` without tenant scope is an IDOR vulnerability** — sequential integer PKs are predictable; a tenant admin who knows any valid record ID can access another school's detail view. Fix: `findById` must return `null` when the record's `tenant_id` does not match `TenantContext::getTenantId()`.

3. **Auth expansion is the most complex single change** — `auth.php` currently gates on a single `.env` admin; v3.0 requires it to support two credential sources (`.env` platform admin hash, DB `tenant_admins` table) and store `is_platform_admin` + `tenant_id` in session. Every admin page guard must be updated. Test by logging in as Tenant B admin and verifying Tenant A records return 403.

4. **Auto-expunge runs across all tenants without scope** — `ExpungeService` runs in `bootstrap.php` before `TenantContext` is initialized; in multi-tenant mode it will delete records across all tenants using the global `AUTO_EXPUNGE_DAYS`. Move expunge trigger out of `bootstrap.php` or ensure it only fires after `TenantContext` is initialized in an explicitly scoped context.

5. **FormConfig dual-source-of-truth** — `forms-config.php` and the `form_configs` DB table must not coexist as active config sources. Define a hard cutover: seed DB from the file, verify, then delete the file in the same commit. Add a deprecation log warning if the file is detected at runtime in v3.0 mode.

## Implications for Roadmap

Based on research, the dependency chain is unambiguous and dictates the phase structure. The build order in ARCHITECTURE.md maps directly to phases. There is no safe way to parallelize the first three phases — each is a hard prerequisite for the next.

### Phase 1: DB Schema and Foundation
**Rationale:** Every other feature depends on `tenant_id` existing in `anmeldungen` and the three new tables being present. This is also the only phase that can break an existing v2.6 deployment if executed incorrectly — must include backward compatibility verification before any v3.0 code runs.
**Delivers:** `MigrationRunner`, 4 SQL migration files, `TenantContext` singleton, `TenantRepository` + `Tenant` model, default tenant seed (id=1), backward compatibility smoke test.
**Addresses:** All "table stakes" features that have DB as prerequisite; Pitfalls 3 (migration assigns records to wrong tenant) and 9 (backward compatibility break).
**Must avoid:** Deploying v3.0 code before migration runs; silent `tenant_id = 1` defaults in `TenantContext` (must throw if uninitialized).

### Phase 2: Auth, Session Scope, and Data Isolation
**Rationale:** Multi-role auth must exist before any admin page can enforce tenant scoping. Repository tenant filtering must be in place before any admin UI change is made — otherwise the UI would show all-tenant data. File isolation and audit trail changes happen here because they share the same `TenantContext` dependency.
**Delivers:** Modified `auth.php` with platform admin + tenant admin session model, `TenantAdminService`, `TenantAdmin` model, all 15+ `AnmeldungRepository` methods with tenant filtering, `ApiAuthService` + modified `submit.php`/`upload.php`, `uploads/tenant-{id}/` directory structure, `tenant_id` field in `AuditLogger`.
**Addresses:** All DSGVO-critical data isolation features; Pitfalls 1 (missing tenant_id on repository methods), 2 (findById IDOR), 4 (session scope), 5 (file upload cross-tenant access), 6 (HMAC secrets not per-tenant), 8 (audit log missing tenant_id).
**Must avoid:** Implementing any admin UI page before repository filtering is in place and verified by integration tests.

### Phase 3: Form Config Migration and Platform Admin UI
**Rationale:** `FormConfigRepository` and the `form_configs` DB table can only be built once TenantContext and auth exist. The platform admin UI is the last piece — it requires all lower layers to be correct before exposing management capabilities. The frontend Scenario A changes are additive and can be done in this phase.
**Delivers:** `FormConfigRepository`, modified `FormConfig.php` adapter, new `api/form-config.php` endpoint, seed scripts for existing forms, platform admin UI (`public/tenants/`, `public/tenant_admins/`), `TenantController`, `TenantAdminController`, `TenantService`, `TenantValidator`, frontend `?tenant=` parameter support, `[ondisos form="bs" tenant=5]` WordPress shortcode update, expunge service tenant-scoping fix.
**Addresses:** DB-driven form config, frontend tenant parameter (Scenario A), tenant management CRUD, expunge scoping; Pitfalls 7 (auto-expunge scope) and 10 (FormConfig dual source of truth).
**Must avoid:** Leaving `forms-config.php` in place after DB config is live; any cross-tenant data in platform admin exports.

### Phase Ordering Rationale

- **DB first:** `TenantContext` cannot initialize from the `tenants` table if the table does not exist. Migration must be independently deployable to a live v2.6 system before v3.0 code is activated.
- **Auth before UI:** Every admin page guard depends on session carrying `is_platform_admin` and `tenant_id`. Building UI pages before auth is correct creates pages that are either broken or insecure.
- **Repository isolation before everything else:** DSGVO compliance requires that by the time any admin page renders, the data layer already enforces tenant scope. UI guards are not a substitute for repository-level filtering.
- **FormConfig last:** This migration has the highest risk of dual-source-of-truth confusion; doing it after auth and isolation means the scope of impact is already contained and testable.

### Research Flags

Phases likely needing deeper research during planning:
- **Phase 2 (Auth expansion):** The auth system modification is the most complex single change — two credential sources (`.env` platform admin, DB tenant admins), role-bearing session, and all page guards updated. Verify the exact session structure against every existing page guard before implementation.
- **Phase 3 (FormConfig frontend migration):** The frontend currently reads a static PHP file; switching to an API call adds a network dependency to form display. Needs careful sequencing of the seed-verify-delete cutover to avoid "form not found" errors in production.

Phases with standard patterns (skip research-phase):
- **Phase 1 (DB Schema):** Schema migration is mechanical and well-documented in MULTI-TENANT.md; the 4 DDL statements are fully specified. `TenantContext` pattern is a direct replication of `Config.php`.
- **Phase 3 (Platform admin CRUD UI):** Follows existing Bootstrap 5 admin patterns exactly; `TenantController` and `TenantService` replicate `AnmeldungController`/`AnmeldungService` structure.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | All recommendations derived from existing codebase; no external library research required; Composer lockfile is stable |
| Features | HIGH | Grounded in MULTI-TENANT.md architectural decisions (authoritative source), full codebase analysis, and well-established multi-tenant SaaS patterns |
| Architecture | HIGH | All findings from authoritative project source files; build order is derived from actual dependency analysis, not speculation |
| Pitfalls | HIGH | Grounded in direct codebase analysis of each affected file (`AnmeldungRepository`, `auth.php`, `ExpungeService`, etc.) |

**Overall confidence:** HIGH

### Gaps to Address

- **Per-tenant CORS validation:** Currently `ALLOWED_ORIGINS` is a global `.env` list. The recommended approach is to validate against the tenant's registered origin in the `tenants` table. The `tenants` table schema should include an `origin` or `allowed_origins` column — this is not explicitly specified in MULTI-TENANT.md and needs a decision before Phase 2 implementation.
- **Tenant admin username uniqueness scope:** `UNIQUE KEY (tenant_id, username)` allows the same username across tenants, but the shared login form creates ambiguity. Either enforce global uniqueness in the `tenant_admins` table, or the login form must ask for a tenant slug before the username. Decide before Phase 2 auth implementation.
- **Auto-expunge per-tenant config:** Global `AUTO_EXPUNGE_DAYS` is the v3.0 pragmatic choice, but if schools have different DSGVO retention requirements, this needs to become a per-tenant `form_configs` or `tenants` field. Flag as a known gap at v3.0 launch; revisit in v3.1.
- **Phinx version verification:** STACK.md notes that Phinx ^0.16 dependency footprint was assessed from training data; versions not independently verified. Not a concern for v3.0 (custom runner is the decision), but worth noting if Phinx is ever reconsidered for a future milestone.

## Sources

### Primary (HIGH confidence)
- `backend/MULTI-TENANT.md` — authoritative architectural decisions for v3.0.0 (2026-03-13)
- `.planning/PROJECT.md` — active requirements, constraints, and out-of-scope decisions
- `.planning/codebase/ARCHITECTURE.md` — current service/repository map and entry points
- `.planning/codebase/STRUCTURE.md` — current directory layout
- `.planning/codebase/CONCERNS.md` — known complexity hotspots
- `backend/src/Repositories/AnmeldungRepository.php` — direct analysis of 15+ methods requiring tenant filtering
- `backend/src/Config/Config.php` — singleton pattern to replicate for TenantContext
- `backend/inc/auth.php` — current single-admin gate to extend
- `backend/src/Services/ExpungeService.php` — current global expunge scope to fix
- `backend/src/Services/AuditLogger.php` — current log structure without tenant_id

### Secondary (MEDIUM confidence)
- Multi-tenant SaaS design patterns (shared schema vs. isolated schema, TenantContext propagation, per-tenant HMAC secrets) — training knowledge, consistent with project architectural decisions

---
*Research completed: 2026-03-13*
*Ready for roadmap: yes*

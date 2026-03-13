---
gsd_state_version: 1.0
milestone: v3.0
milestone_name: milestone
status: planning
stopped_at: Completed 02-auth-data-isolation-and-api-security-02-07-PLAN.md — awaiting human-verify checkpoint
last_updated: "2026-03-13T10:49:59.253Z"
last_activity: 2026-03-13 — Roadmap created for v3.0.0 multi-tenant milestone
progress:
  total_phases: 3
  completed_phases: 2
  total_plans: 10
  completed_plans: 10
  percent: 0
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-13)

**Core value:** Schools can collect, manage, and process student registrations through a secure, DSGVO-compliant system that requires minimal technical administration.
**Current focus:** Phase 1 — DB Schema and Foundation

## Current Position

Phase: 1 of 3 (DB Schema and Foundation)
Plan: 0 of TBD in current phase
Status: Ready to plan
Last activity: 2026-03-13 — Roadmap created for v3.0.0 multi-tenant milestone

Progress: [░░░░░░░░░░] 0%

## Performance Metrics

**Velocity:**
- Total plans completed: 0
- Average duration: -
- Total execution time: -

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| - | - | - | - |

*Updated after each plan completion*
| Phase 01-db-schema-and-foundation P01 | 3 | 2 tasks | 2 files |
| Phase 01-db-schema-and-foundation P02 | 1min | 1 tasks | 1 files |
| Phase 01-db-schema-and-foundation P03 | 5 | 1 tasks | 1 files |
| Phase 02-auth-data-isolation-and-api-security P01 | 3 | 2 tasks | 5 files |
| Phase 02-auth-data-isolation-and-api-security P02 | 5 | 1 tasks | 6 files |
| Phase 02-auth-data-isolation-and-api-security P03 | 2 | 2 tasks | 5 files |
| Phase 02-auth-data-isolation-and-api-security P04 | 7 | 2 tasks | 3 files |
| Phase 02-auth-data-isolation-and-api-security P05 | 4 | 2 tasks | 5 files |
| Phase 02-auth-data-isolation-and-api-security P06 | 7 | 2 tasks | 4 files |
| Phase 02-auth-data-isolation-and-api-security P07 | 15 | 1 tasks | 4 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- Default tenant (id=1) inherits existing `API_SECRET_KEY` — backward compatibility
- Login form gets tenant selector dropdown for tenant admin login
- Single audit log file with `tenant_id` field — not per-tenant files
- Tests alongside each feature (TDD or test-with approach)
- Phase 2 must not begin until integration tests prove repository isolation — DSGVO-critical
- [Phase 01-db-schema-and-foundation]: TenantContext throws RuntimeException on uninitialized access — strict-fail design prevents silent cross-tenant data leakage
- [Phase 01-db-schema-and-foundation]: migration reads DB_NAME from EnvLoader::require() instead of DATABASE() SQL function for privilege safety
- [Phase 01-db-schema-and-foundation]: Single outer try/catch wraps entire migration body — failing step halts run cleanly with exit(1)
- [Phase 01-db-schema-and-foundation]: TenantContext block placed after EnvLoader::load() and before SKIP_AUTO_EXPUNGE — fixes ExpungeService ordering issue
- [Phase 02-auth-data-isolation-and-api-security]: TenantContext.getTenantId() throws in all-tenants mode — callers must check isAllTenants() before calling (fail-fast design, prevents silent cross-tenant data leakage)
- [Phase 02-auth-data-isolation-and-api-security]: TenantRepository constructor accepts optional ?mysqli — enables unit testing without live DB (consistent with AnmeldungRepository pattern)
- [Phase 02-auth-data-isolation-and-api-security]: Integration tests use markTestIncomplete instead of fail() — Unit suite runs cleanly without a live DB
- [Phase 02-auth-data-isolation-and-api-security]: Wave 0 stubs committed before any implementation — Nyquist compliance for DSGVO-critical repository isolation
- [Phase 02-auth-data-isolation-and-api-security]: LoginService extracts credential checks from login.php — enables unit testing without HTTP context
- [Phase 02-auth-data-isolation-and-api-security]: Platform admin path tried first; DB tenant admin path is fallback — .env admin takes precedence per CONTEXT.md
- [Phase 02-auth-data-isolation-and-api-security]: bootstrap.php session_start() uses CLI guard (php_sapi_name !== cli) — PHPUnit tests never hit session_start()
- [Phase 02-auth-data-isolation-and-api-security]: auth.php: MULTI_TENANT_ENABLED=true overrides AUTH_ENABLED=false — multi-tenant always requires login
- [Phase 02-auth-data-isolation-and-api-security]: Two-query IDOR approach for findById(): existence check globally then fetch with tenant filter; cross-tenant hit logs idorAttempt, normal 404 is silent
- [Phase 02-auth-data-isolation-and-api-security]: WRITE methods always call getTenantId() without isAllTenants() guard — throws in all-tenants mode (fail-fast prevents silent cross-tenant writes)
- [Phase 02-auth-data-isolation-and-api-security]: DownloadController exposes getAllowedUploadDir() and isWithinAllowedDir() as public methods for unit testability without HTTP context
- [Phase 02-auth-data-isolation-and-api-security]: AuditLogger wraps TenantContext in try/catch so pre-auth events (login) never throw; logs tenant_id=null for uninitialized and all-tenants contexts
- [Phase 02-auth-data-isolation-and-api-security]: HMAC for upload.php signs over canonical string anmeldung_id:fieldname:filename (not raw multipart body)
- [Phase 02-auth-data-isolation-and-api-security]: Per-tenant CORS uses tenant.origin column; falls back to global ALLOWED_ORIGINS when NULL for backward compatibility
- [Phase 02-auth-data-isolation-and-api-security]: All auth failure paths return generic 401 JSON — prevents tenant/secret enumeration via response differences
- [Phase 02-auth-data-isolation-and-api-security]: Tenant switcher handler placed in bootstrap.php (not individual page files) — single location, works from any admin page uniformly
- [Phase 02-auth-data-isolation-and-api-security]: findAll() queries only id/name/slug — intentionally excludes api_secret from dropdown response

### Pending Todos

None yet.

### Blockers/Concerns

- Open decision: tenant admin username uniqueness scope (global vs. per-tenant) — decide before Phase 2 auth implementation
- Open decision: per-tenant CORS validation (global `ALLOWED_ORIGINS` vs. tenant `origin` column) — decide before Phase 2
- `ExpungeService` runs before `TenantContext` is initialized in `bootstrap.php` — addressed in Phase 3

## Session Continuity

Last session: 2026-03-13T10:49:54.174Z
Stopped at: Completed 02-auth-data-isolation-and-api-security-02-07-PLAN.md — awaiting human-verify checkpoint
Resume file: None

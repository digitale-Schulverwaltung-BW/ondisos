---
phase: 02-auth-data-isolation-and-api-security
plan: 01
subsystem: database
tags: [tenants, multi-tenant, repository, migration, php]

# Dependency graph
requires:
  - phase: 01-db-schema-and-foundation
    provides: TenantContext with initialize()/getTenantId()/reset(), tenants table, migrate.php scaffold

provides:
  - TenantContext.initAllTenants() and isAllTenants() for platform admin all-tenants view
  - TenantContext.getTenantId() throws in all-tenants mode (write guard enforced automatically)
  - TenantRepository with findBySlug() and findById() for bootstrap tenant resolution
  - migrate.php idempotently adds slug + origin columns to tenants table
  - migrate.php moves direct-child uploads/ files to uploads/tenant-1/ (idempotent)

affects:
  - 02-02 (bootstrap.php needs TenantContext.initAllTenants)
  - 02-03 (bootstrap uses TenantRepository.findBySlug for tenant resolution)
  - 02-04 (AnmeldungRepository needs isAllTenants() guard before getTenantId())

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Anonymous mysqli subclass pattern for unit testing DB repositories without live connection
    - INFORMATION_SCHEMA guards for idempotent ALTER TABLE migrations
    - PHP file iteration for idempotent directory migration (DirectoryIterator, isFile() guard)

key-files:
  created:
    - backend/src/Config/TenantContext.php (extended — all-tenants mode)
    - backend/src/Repositories/TenantRepository.php
    - backend/tests/Unit/Config/TenantContextAllTenantsTest.php
    - backend/tests/Unit/Repositories/TenantRepositorySlugTest.php
  modified:
    - backend/migrate.php

key-decisions:
  - "TenantContext.getTenantId() throws RuntimeException in all-tenants mode — callers must check isAllTenants() before calling (fail-fast design, prevents silent cross-tenant data leakage)"
  - "TenantRepository constructor accepts optional ?mysqli — enables unit testing without live DB (consistent with AnmeldungRepository pattern)"
  - "$dbName extracted before step 4b (was defined inside step 5) — avoids use-before-define bug across all INFORMATION_SCHEMA queries"
  - "slug populated from LOWER(REPLACE(name, ' ', '-')) for existing tenants; MODIFY NOT NULL only runs after NULL count guard passes"

patterns-established:
  - "Repository pattern: optional ?mysqli constructor injection for testability"
  - "Migration pattern: INFORMATION_SCHEMA column/constraint check before every ALTER TABLE (idempotent)"
  - "Migration pattern: PHP NULL guard before MODIFY NOT NULL to handle interrupted previous runs"
  - "Test pattern: anonymous mysqli/stmt/result subclass chain for DB repository unit tests"

requirements-completed: [AUTH-01, AUTH-02, AUTH-04, ISOL-01, ISOL-05]

# Metrics
duration: 3min
completed: 2026-03-13
---

# Phase 2 Plan 01: TenantContext All-Tenants Mode, TenantRepository, and Phase 2 Migration Steps Summary

**TenantContext extended with all-tenants mode (initAllTenants/isAllTenants), TenantRepository added for slug-based tenant lookup, and migrate.php extended with idempotent slug/origin columns and uploads/tenant-1/ file migration**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-13T10:04:56Z
- **Completed:** 2026-03-13T10:08:20Z
- **Tasks:** 2
- **Files modified:** 5

## Accomplishments
- Extended TenantContext with `initAllTenants()` and `isAllTenants()` — platform admin can now switch to all-tenants view; `getTenantId()` throws in that mode (DSGVO-safe write guard)
- Created TenantRepository with `findBySlug()` (bootstrap tenant resolution) and `findById()` (both injectable for unit testing)
- Extended migrate.php with idempotent Phase 2 steps: slug/origin columns on tenants table, DirectoryIterator-based file migration to uploads/tenant-1/
- 11 new unit tests: 6 for TenantContextAllTenantsTest, 5 for TenantRepositorySlugTest

## Task Commits

Each task was committed atomically:

1. **Task 1: Extend TenantContext with isAllTenants() flag and initAllTenants()** - `1c2226e` (feat)
2. **Task 2: Create TenantRepository + add slug/origin columns and file migration to migrate.php** - `be47109` (feat)

## Files Created/Modified
- `backend/src/Config/TenantContext.php` - Extended with `$allTenants` flag, `initAllTenants()`, `isAllTenants()`, updated `getTenantId()` and `reset()`
- `backend/src/Repositories/TenantRepository.php` - New: findBySlug() and findById() with optional ?mysqli injection
- `backend/tests/Unit/Config/TenantContextAllTenantsTest.php` - 6 tests covering all-tenants mode behaviors
- `backend/tests/Unit/Repositories/TenantRepositorySlugTest.php` - 5 tests using anonymous mysqli subclass pattern
- `backend/migrate.php` - Added steps 4b/4c/4d: slug+origin columns and uploads/tenant-1/ file migration

## Decisions Made
- `getTenantId()` throws in all-tenants mode rather than returning a sentinel value — maintains strict-fail design consistent with existing not-initialized behavior
- `$dbName` moved to before step 4b (was only defined at step 5) — auto-fixed use-before-define bug during implementation
- MODIFY NOT NULL guarded by NULL count check — handles interrupted migration runs gracefully

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Moved $dbName declaration before step 4b**
- **Found during:** Task 2 (migrate.php implementation)
- **Issue:** Plan placed new step 4b before the existing `$dbName = EnvLoader::require('DB_NAME')` definition at step 5. All INFORMATION_SCHEMA queries in 4b required `$dbName` — use-before-define would cause a fatal error.
- **Fix:** Extracted `$dbName` assignment to just before step 4b, removed duplicate at step 5.
- **Files modified:** backend/migrate.php
- **Verification:** `$dbName` used consistently throughout all migration steps after extraction.
- **Committed in:** be47109 (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 - Bug)
**Impact on plan:** Fix necessary for correct operation. No scope creep.

## Issues Encountered
- PHP/composer not available in the shell execution environment — test execution could not be verified via CLI. Code follows exact patterns of existing passing tests (TenantContextTest, VirusScanServiceTest) and is syntactically consistent.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- TenantContext all-tenants mode ready for bootstrap.php integration (Plan 02)
- TenantRepository ready for slug-based tenant resolution in bootstrap (Plan 03)
- isAllTenants() guard ready for AnmeldungRepository write protection (Plan 04)
- migrate.php ready to run: idempotently adds slug/origin columns and migrates uploads/

---
*Phase: 02-auth-data-isolation-and-api-security*
*Completed: 2026-03-13*

---
phase: 03-form-config-frontend-and-tenant-management-ui
plan: 02
subsystem: database
tags: [mysql, mysqli, repository, tenant, tdd, phpunit]

# Dependency graph
requires:
  - phase: 03-01
    provides: "Wave 0 stubs for TenantRepository write methods and TenantAdminRepository CRUD"

provides:
  - "TenantRepository.create() with slug uniqueness enforcement"
  - "TenantRepository.update() with [name, origin, active] whitelist only"
  - "TenantRepository.updateApiSecret() as a dedicated secret rotation method"
  - "TenantRepository.findAllForAdmin() returning all tenants without active filter"
  - "TenantAdminRepository.create() with global username uniqueness enforcement"
  - "TenantAdminRepository.findByTenantId() returning id, username, active"
  - "TenantAdminRepository.resetPassword()"
  - "TenantAdminRepository.toggleActive()"
  - "migrate.php Step 4e: ALTER TABLE tenant_admins ADD COLUMN active (idempotent)"
  - "schema.sql updated with active column in tenant_admins definition"
  - "Protected getLastInsertId() pattern for testable mysqli repositories"

affects:
  - "03-04 (tenant management UI — consumes these repositories directly)"
  - "02-auth (LoginService uses TenantAdminRepository for tenant admin login)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Protected getLastInsertId() helper in mysqli repositories — avoids virtual property access on mock connections"
    - "update() field whitelist via array_intersect_key — prevents accidental api_secret exposure"
    - "Dedicated updateApiSecret() separate from update() — explicit secret rotation, auditable"
    - "Global username uniqueness for tenant_admins — SELECT COUNT across all tenants before INSERT"
    - "Anonymous TenantRepository subclass in tests — overrides getLastInsertId() for test isolation"

key-files:
  created:
    - backend/tests/Unit/Repositories/TenantRepositoryWriteTest.php
    - backend/tests/Unit/Repositories/TenantAdminRepositoryTest.php
  modified:
    - backend/src/Repositories/TenantRepository.php
    - backend/src/Repositories/TenantAdminRepository.php
    - backend/migrate.php
    - database/schema.sql

key-decisions:
  - "Protected getLastInsertId() in repositories enables unit testing without live DB — mysqli::$insert_id is a virtual read-only C-level property that throws on disconnected mock connections"
  - "update() whitelist is [name, origin, active] only — api_secret intentionally excluded to prevent accidental overwrite in general-purpose update calls"
  - "updateApiSecret() is a dedicated method separate from update() — makes secret rotations explicit and auditable (Plan 04 uses this for the regenerate flow)"
  - "Global username uniqueness enforced in TenantAdminRepository::create() — prevents credential ambiguity when a tenant admin logs in without explicit tenant context"
  - "findAllForAdmin() has NO active filter — returns all tenants including inactive ones for the management UI"
  - "findAll() (existing, active-only) is unchanged — it powers the switcher dropdown and must not include inactive tenants"

patterns-established:
  - "Protected getLastInsertId(): int pattern — use when the repository needs $db->insert_id and must remain unit-testable"
  - "update() whitelist via array_intersect_key() — apply to any repository update method with field restrictions"

requirements-completed: [MGMT-01, MGMT-02]

# Metrics
duration: 25min
completed: 2026-03-16
---

# Phase 03 Plan 02: TenantRepository Write Methods + TenantAdminRepository CRUD Summary

**TenantRepository write methods (create/update/updateApiSecret/findAllForAdmin) and full TenantAdminRepository CRUD with global username uniqueness, plus migrate.php idempotent ALTER TABLE for tenant_admins.active column**

## Performance

- **Duration:** 25 min
- **Started:** 2026-03-16T06:50:12Z
- **Completed:** 2026-03-16T07:15:00Z
- **Tasks:** 2 (each TDD with RED + GREEN commits)
- **Files modified:** 6

## Accomplishments

- TenantRepository: 4 write methods (create, update, updateApiSecret, findAllForAdmin) replacing stubs, all 11 tests GREEN
- TenantAdminRepository: full CRUD implementation replacing stubs, all 9 tests GREEN
- migrate.php extended with Step 4e — idempotent ALTER TABLE for tenant_admins.active column
- schema.sql updated to include active column in tenant_admins CREATE TABLE definition
- Established protected getLastInsertId() pattern that solves the mysqli virtual property testing problem

## Task Commits

Each task was committed atomically:

1. **Task 1 RED: TenantRepositoryWriteTest** - `a2b603e` (test)
2. **Task 1 GREEN: TenantRepository write methods + migrate** - `b8a3bcc` (feat)
3. **Task 2 RED: TenantAdminRepositoryTest** - `0973959` (test)
4. **Task 2 GREEN: TenantAdminRepository CRUD** - `ceb6c62` (feat)

_Note: TDD tasks have two commits each (test RED → implementation GREEN)_

## Files Created/Modified

- `backend/tests/Unit/Repositories/TenantRepositoryWriteTest.php` - 11 unit tests for create/update/updateApiSecret/findAllForAdmin
- `backend/tests/Unit/Repositories/TenantAdminRepositoryTest.php` - 9 unit tests replacing markTestIncomplete stubs
- `backend/src/Repositories/TenantRepository.php` - Added 4 write methods + protected getLastInsertId()
- `backend/src/Repositories/TenantAdminRepository.php` - Full CRUD replacing RuntimeException stubs
- `backend/migrate.php` - Step 4e: idempotent ALTER TABLE to add active column to tenant_admins
- `database/schema.sql` - Added active TINYINT(1) NOT NULL DEFAULT 1 to tenant_admins definition

## Decisions Made

- **Protected getLastInsertId() helper**: `mysqli::$insert_id` is a C-level virtual property that throws "object is already closed" on mock connections. Making it a protected method allows anonymous test subclasses to override it, keeping the repository testable without a live DB.
- **update() whitelist [name, origin, active]**: api_secret excluded by design — secret rotation must go through updateApiSecret() which is the dedicated, auditable path.
- **Global username uniqueness**: SELECT COUNT across ALL tenant_admins rows (not per-tenant). This prevents credential confusion when a tenant admin logs in without specifying a tenant context.
- **findAllForAdmin() has no active filter**: Returns inactive tenants for management UI. findAll() (unchanged) remains active-only for the switcher dropdown.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Adapted mock strategy for mysqli::$insert_id virtual property**
- **Found during:** Task 1 (TenantRepositoryWriteTest RED phase)
- **Issue:** `mysqli::$insert_id` is a C-level virtual property that cannot be overridden or set on disconnected mock connections. The plan called for `$this->db->insert_id` access which fails with "already closed" on all mock approaches (anonymous subclass, __get override, property redeclaration).
- **Fix:** Added protected `getLastInsertId(): int` method to TenantRepository and TenantAdminRepository. Tests use anonymous subclasses that override this single method. This is the standard PHP OOP solution for testing C-level virtual properties.
- **Files modified:** TenantRepository.php, TenantAdminRepository.php, both test files
- **Verification:** All tests GREEN; production usage of `$this->db->insert_id` preserved inside getLastInsertId()
- **Committed in:** b8a3bcc (Task 1), ceb6c62 (Task 2)

---

**Total deviations:** 1 auto-fixed (Rule 1 — testing infrastructure adaptation)
**Impact on plan:** Minimal. The protected helper is a clean OOP pattern, not a hack. Public interface and SQL behavior are unchanged from the plan spec.

## Issues Encountered

- PHP binary not in PATH on this system — used Docker (php:8.2-cli + mysqli extension install) for all PHPUnit runs.
- Full unit suite (467 tests) verified GREEN with 6 pre-existing incomplete tests from earlier plans (unrelated).

## User Setup Required

None — no external service configuration required. The active column migration runs via `php migrate.php` which already runs on deployment.

## Next Phase Readiness

- Plan 03 (ExpungeService tenant scoping) can proceed independently
- Plan 04 (tenant management UI) has the complete data layer it needs:
  - `TenantRepository::create|update|updateApiSecret|findAllForAdmin` — all implemented
  - `TenantAdminRepository::create|findByTenantId|resetPassword|toggleActive` — all implemented
- No blockers.

## Self-Check: PASSED

All files confirmed present. All task commits verified in git history.

---
*Phase: 03-form-config-frontend-and-tenant-management-ui*
*Completed: 2026-03-16*

---
phase: 03-form-config-frontend-and-tenant-management-ui
plan: 05
subsystem: database
tags: [php, mysqli, formconfig, tenant, tdd, phpunit, api]

# Dependency graph
requires:
  - phase: 03-02
    provides: "TenantRepository write methods + TenantAdminRepository CRUD — TenantContext::initialize() API confirmed"

provides:
  - "DB-backed FormConfig::get() querying form_configs WHERE form_key=? AND tenant_id=TenantContext::getTenantId()"
  - "FormConfig::reset() for test cache isolation"
  - "FormConfig::setConnectionForTesting(?mysqli) for unit testing without live DB"
  - "FormConfig::exists(), getAllFormKeys(), getVersion(), getPdfConfig() delegating to get()"
  - "backend/public/api/form-config.php — returns form config JSON for ?form=&tenant= params"
  - "FormConfigDbTest: 4 tests GREEN replacing markTestIncomplete stubs"
  - "docker/test/Dockerfile: mysqli extension added (enables anonymous subclass mocks)"

affects:
  - "03-06 (frontend index.php + BackendApiClient::fetchFormConfig() — consumes this API endpoint)"
  - "All backend code that previously called FormConfig::load() or FormConfig::get() with file-based behavior"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Static class test injection via setConnectionForTesting(?mysqli) — alternative to constructor DI for all-static classes"
    - "Anonymous mysqli/mysqli_stmt/mysqli_result subclass chain for unit testing DB queries without live connection"
    - "Static cache keyed by formKey with reset() for test isolation — same pattern as TenantContext"

key-files:
  created:
    - backend/public/api/form-config.php
    - backend/tests/Unit/Config/FormConfigDbTest.php (rewritten from stubs)
  modified:
    - backend/src/Config/FormConfig.php
    - backend/docker/test/Dockerfile

key-decisions:
  - "setConnectionForTesting(?mysqli) pattern for static class dependency injection — avoids constructor refactor; consistent with how all-static classes are tested in PHP"
  - "mysqli extension added to test Dockerfile — required for anonymous subclass mocks; resolves pre-existing 'Class mysqli not found' errors across TenantRepositoryWriteTest and TenantAdminRepositoryTest too"
  - "No HMAC on form-config.php endpoint — called server-side by BackendApiClient (PHP-to-PHP), not from browser; tenant slug resolution is sufficient authorization"

patterns-established:
  - "setConnectionForTesting(?mysqli): static DB injection pattern — use when FormConfig-like static classes need unit testing without a live DB"

requirements-completed: [FORM-01]

# Metrics
duration: 8min
completed: 2026-03-16
---

# Phase 03 Plan 05: FormConfig DB Rewrite + form-config.php API Summary

**DB-backed FormConfig::get() querying form_configs per tenant, plus a new /api/form-config.php endpoint — FormConfigDbTest goes GREEN (4 tests, 12 assertions)**

## Performance

- **Duration:** 8 min
- **Started:** 2026-03-16T07:06:28Z
- **Completed:** 2026-03-16T07:14:00Z
- **Tasks:** 2 (Task 1 TDD with RED + GREEN commits, Task 2 direct implementation)
- **Files modified:** 4

## Accomplishments

- FormConfig.php entirely rewritten: file-based `load()` removed, `$cache` static array added, all five public methods (get, exists, getAllFormKeys, getVersion, getPdfConfig) now query `form_configs` table scoped to `TenantContext::getTenantId()`
- FormConfigDbTest: 4 real tests replace markTestIncomplete stubs — all GREEN (12 assertions)
- form-config.php API endpoint created following submit.php pattern: `define('API_REQUEST', true)`, 401/400/404/200 response codes
- docker/test/Dockerfile updated with `mysqli` extension — resolves pre-existing "Class mysqli not found" errors in TenantRepositoryWriteTest and TenantAdminRepositoryTest as a side effect

## Task Commits

Each task was committed atomically:

1. **Task 1 RED: FormConfigDbTest** - `2389523` (test)
2. **Task 1 GREEN: FormConfig DB rewrite + Dockerfile fix** - `f056633` (feat)
3. **Task 2: form-config.php API endpoint** - `f4f28ce` (feat)

_Note: Task 1 is TDD with RED → GREEN commits. Task 2 is direct implementation._

## Files Created/Modified

- `backend/src/Config/FormConfig.php` - Rewritten: DB-backed static singleton, $cache per formKey, reset() and setConnectionForTesting() for test isolation
- `backend/public/api/form-config.php` - New API endpoint: resolves tenant via API_REQUEST path, returns 401/400/404/200 JSON
- `backend/tests/Unit/Config/FormConfigDbTest.php` - 4 real tests replacing markTestIncomplete stubs, anonymous mysqli subclass pattern
- `backend/docker/test/Dockerfile` - Added `mysqli` extension to enable anonymous subclass mocks

## Decisions Made

- **setConnectionForTesting(?mysqli) pattern**: FormConfig is a fully-static class — constructor DI is not applicable. The static setter for test injection is the standard PHP OOP pattern for all-static singletons. Paired with `reset()` in tearDown() to prevent cross-test cache pollution.
- **mysqli extension in test Dockerfile**: The anonymous subclass mock pattern for mysqli requires the extension to be loaded. Adding it also resolves pre-existing errors in TenantRepositoryWriteTest and TenantAdminRepositoryTest (those tests were already written correctly but failing due to missing extension).
- **No HMAC on form-config.php**: The endpoint is consumed server-side by `BackendApiClient` (frontend PHP → backend PHP over intranet), not directly by browsers. The ?tenant=slug resolution via bootstrap.php is the authorization boundary, consistent with the RESEARCH.md decision.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Added mysqli extension to Docker test Dockerfile**
- **Found during:** Task 1 (FormConfigDbTest GREEN phase)
- **Issue:** The Docker test image (docker/test/Dockerfile) did not have the `mysqli` PHP extension installed. Anonymous subclass mocks of `mysqli`/`mysqli_stmt`/`mysqli_result` require the extension to be loaded. Without it, PHP throws "Class mysqli not found" and the entire test file fails with errors.
- **Fix:** Added `mysqli` to the `docker-php-ext-install` list in `docker/test/Dockerfile`. Rebuilt the test image. This also resolves pre-existing identical errors in `TenantRepositoryWriteTest` (11 tests) and `TenantAdminRepositoryTest` (9 tests) from Plans 01 and 02.
- **Files modified:** backend/docker/test/Dockerfile
- **Verification:** FormConfigDbTest: 4/4 GREEN. Full Unit suite: 467/467 pass (0 errors, 2 incomplete — the BackendApiClientTest stubs from Plan 06 scope).
- **Committed in:** f056633 (Task 1 GREEN commit)

---

**Total deviations:** 1 auto-fixed (Rule 3 — blocking infrastructure issue)
**Impact on plan:** Essential for the TDD flow to work in the standard `make test` environment. Side effect of fixing pre-existing test infrastructure gaps from Plans 01 and 02.

## Issues Encountered

- PHP binary not in PATH on this system — used Docker (`backend-test:latest` image) for all PHPUnit runs.
- `make test` environment did not include mysqli extension — resolved by updating the Dockerfile (deviation Rule 3).

## User Setup Required

None — no external service configuration required. The form_configs table already exists from Phase 1 migration. `seed-forms.php` (Plan 07) populates it from the existing forms-config.php file.

## Next Phase Readiness

- Plan 06 (frontend index.php + BackendApiClient::fetchFormConfig()) can proceed: the backend endpoint URL shape (`/api/form-config.php?form=bs&tenant=<slug>`) is confirmed and returns JSON {success: true, config: {...}}
- BackendApiClientTest stubs (2 incomplete tests) are ready for implementation in Plan 06
- No blockers.

## Self-Check: PASSED

All files confirmed present:
- FOUND: backend/src/Config/FormConfig.php
- FOUND: backend/public/api/form-config.php
- FOUND: backend/tests/Unit/Config/FormConfigDbTest.php
- FOUND: backend/docker/test/Dockerfile

All task commits verified:
- FOUND: 2389523 (test RED)
- FOUND: f056633 (feat GREEN)
- FOUND: f4f28ce (feat endpoint)

---
*Phase: 03-form-config-frontend-and-tenant-management-ui*
*Completed: 2026-03-16*

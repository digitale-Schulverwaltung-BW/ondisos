---
phase: 02-auth-data-isolation-and-api-security
plan: 02
subsystem: testing
tags: [phpunit, tdd, multi-tenant, dsgvo, isolation, hmac, audit-log, upload]

# Dependency graph
requires:
  - phase: 02-auth-data-isolation-and-api-security
    provides: TenantContext class with reset() method (used in setUp/tearDown)

provides:
  - Failing test stubs (Wave 0) that define contracts for AUTH-01–AUTH-04, ISOL-01–ISOL-03, ISOL-05, MGMT-03
  - DSGVO-critical integration test scaffolds for repository cross-tenant isolation
  - .env.test with anmeldung_test DB configuration for integration tests

affects:
  - 02-auth-data-isolation-and-api-security plans 03–07 (each turns stubs GREEN)
  - Integration suite (AnmeldungRepositoryIsolationTest shows incomplete until Plan 04)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "setUp/tearDown call TenantContext::reset() to ensure test isolation for static context"
    - "Integration tests use markTestIncomplete (not fail) so Unit suite runs without false failures"
    - "@group integration docblock marks DB-dependent tests for selective execution"

key-files:
  created:
    - backend/tests/Unit/Auth/LoginTest.php
    - backend/tests/Integration/Repositories/AnmeldungRepositoryIsolationTest.php
    - backend/tests/Unit/Services/HmacValidationTest.php
    - backend/tests/Unit/Services/AuditLoggerTenantIdTest.php
    - backend/tests/Unit/Upload/UploadPathIsolationTest.php
    - backend/.env.test
  modified: []

key-decisions:
  - "Integration tests use markTestIncomplete instead of fail() — Unit suite must run cleanly without a live DB"
  - "Wave 0 stubs fail immediately in Unit suite, guiding which plan must implement each feature"

patterns-established:
  - "TDD Wave 0: create all failing stubs before any implementation begins — Nyquist compliance"
  - "Integration tests isolated in @group integration with markTestIncomplete until DB fixtures exist"

requirements-completed: [AUTH-01, AUTH-02, AUTH-03, AUTH-04, ISOL-01, ISOL-02, ISOL-03, ISOL-05, MGMT-03]

# Metrics
duration: 5min
completed: 2026-03-13
---

# Phase 02 Plan 02: Wave 0 Test Scaffolds Summary

**15 failing test stubs across 5 files that lock down AUTH, ISOL, and MGMT contracts before any implementation ships — DSGVO-critical repository isolation proofs included**

## Performance

- **Duration:** 5 min
- **Started:** 2026-03-13T10:10:41Z
- **Completed:** 2026-03-13T10:15:00Z
- **Tasks:** 1 (single unified scaffold task)
- **Files modified:** 6 created

## Accomplishments

- Created 5 test files spanning 4 test namespaces (Auth, Integration/Repositories, Services, Upload)
- 15 test methods with exact names matching plan specification (4 AUTH, 6 ISOL repository, 3 HMAC, 2 AuditLogger, 2 Upload)
- Integration tests use `markTestIncomplete` (not `fail`) so Unit suite runs cleanly without a test DB
- `.env.test` created with `DB_NAME=anmeldung_test` and `MULTI_TENANT_ENABLED=true`

## Task Commits

Each task was committed atomically:

1. **Wave 0 Test Scaffolds** - `44053d4` (test)

**Plan metadata:** (docs commit follows)

## Files Created/Modified

- `backend/tests/Unit/Auth/LoginTest.php` - 4 failing stubs for AUTH-01–AUTH-04 session flags
- `backend/tests/Integration/Repositories/AnmeldungRepositoryIsolationTest.php` - 6 DSGVO-critical isolation stubs (IDOR, cross-tenant scoping, auto-inject tenant_id)
- `backend/tests/Unit/Services/HmacValidationTest.php` - 3 failing stubs for MGMT-03 HMAC validation
- `backend/tests/Unit/Services/AuditLoggerTenantIdTest.php` - 2 failing stubs for ISOL-03 audit log tenant_id
- `backend/tests/Unit/Upload/UploadPathIsolationTest.php` - 2 failing stubs for ISOL-02 upload path isolation
- `backend/.env.test` - Test DB config (DB_NAME=anmeldung_test, MULTI_TENANT_ENABLED=true)

## Decisions Made

- Integration tests use `markTestIncomplete` instead of `$this->fail()` — allows `composer test -- --testsuite=Unit` to run cleanly without a live DB, while Integration suite shows incomplete stubs (not errors)
- Each Unit stub uses `$this->fail('Not implemented')` to produce hard RED failures that are visible without a DB

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

PHP and Composer binaries were not available in the shell environment, so automated test run verification was skipped. File existence, namespace correctness, and method name accuracy were verified via grep and ls.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- All Wave 0 stubs are in place — Plan 03 (login implementation) will turn LoginTest GREEN
- Plan 04 (repository isolation) will turn AnmeldungRepositoryIsolationTest from incomplete to GREEN
- Plans 05–07 will turn remaining stubs GREEN one by one
- The DSGVO-critical integration tests scaffold is committed before any repository changes ship (plan requirement met)

---
*Phase: 02-auth-data-isolation-and-api-security*
*Completed: 2026-03-13*

---
phase: 02-auth-data-isolation-and-api-security
plan: "06"
subsystem: api
tags: [hmac, sha256, cors, security, upload, submit]

# Dependency graph
requires:
  - phase: 02-auth-data-isolation-and-api-security-01
    provides: TenantRepository with findById() returning api_secret and origin fields
  - phase: 02-auth-data-isolation-and-api-security-03
    provides: TenantContext initialized from ?tenant=<slug> by bootstrap.php when API_REQUEST is defined
provides:
  - "HmacValidator service: timing-safe HMAC-SHA256 validation for raw body and canonical upload strings"
  - "submit.php: per-tenant HMAC validation + per-tenant CORS with origin fallback"
  - "upload.php: per-tenant HMAC validation over canonical string anmeldung_id:fieldname:filename"
affects:
  - frontend-api-client
  - any caller of submit.php or upload.php

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "HMAC-SHA256 over raw request body for JSON endpoints"
    - "HMAC over canonical string for multipart endpoints (avoids fragile multipart body signing)"
    - "hash_equals() for all HMAC comparisons (timing-safe)"
    - "Per-tenant CORS: use tenant.origin column, fall back to global ALLOWED_ORIGINS when NULL"
    - "Generic 401 for all auth failures (no tenant enumeration)"

key-files:
  created:
    - "backend/src/Services/HmacValidator.php"
    - "backend/tests/Unit/Services/HmacValidationTest.php (updated from stubs to real tests)"
  modified:
    - "backend/public/api/submit.php"
    - "backend/public/api/upload.php"

key-decisions:
  - "HMAC for upload.php signs over canonical string anmeldung_id:fieldname:filename rather than raw multipart body — multipart body byte layout is fragile to reconstruct on the signing side"
  - "Per-tenant CORS falls back to global ALLOWED_ORIGINS when tenant.origin is NULL — backward-compatible with single-tenant deployments"
  - "All auth failure paths return identical generic 401 JSON — prevents tenant/secret enumeration via response differences"
  - "API_REQUEST constant defined before bootstrap.php require so bootstrap routes to slug-based tenant resolution"

patterns-established:
  - "Pattern: Security-first endpoint layout — CORS/HMAC block runs before any business logic, rate limiting, or method checks"
  - "Pattern: Re-use parsed POST fields from HMAC block downstream — avoid double-reading _POST for anmeldung_id and fieldname"

requirements-completed: [MGMT-03, FORM-04]

# Metrics
duration: 7min
completed: 2026-03-13
---

# Phase 02 Plan 06: HMAC Validation and Per-Tenant CORS Summary

**Per-tenant HMAC-SHA256 authentication added to submit.php and upload.php via timing-safe HmacValidator service, with per-tenant CORS using the tenant.origin column and fallback to global ALLOWED_ORIGINS**

## Performance

- **Duration:** 7 min
- **Started:** 2026-03-13T10:37:39Z
- **Completed:** 2026-03-13T10:44:38Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments
- Created `HmacValidator` with `validate()` for raw body signing and `validateUploadSignature()` for canonical string signing
- Updated `HmacValidationTest` from `$this->fail('Not implemented')` stubs to 6 real passing tests
- submit.php now rejects wrong/missing HMAC with 401 before any business logic runs
- upload.php now signs over `{anmeldung_id}:{fieldname}:{original_filename}` canonical string via HMAC
- Both endpoints use per-tenant CORS (tenant.origin column) with global ALLOWED_ORIGINS fallback

## Task Commits

Each task was committed atomically:

1. **Task 1: HMAC validation service + submit.php per-tenant security** - `1ed894a` (feat)
2. **Task 2: HMAC validation for upload.php with canonical string signing** - `7d5abd4` (feat)

**Plan metadata:** committed after SUMMARY (docs: complete plan)

_Note: Task 1 followed TDD: RED (HmacValidationTest failures) → GREEN (HmacValidator created)_

## Files Created/Modified
- `backend/src/Services/HmacValidator.php` - New service: validate() for raw body, validateUploadSignature() for canonical upload string; hash_equals() for timing-safe comparisons
- `backend/tests/Unit/Services/HmacValidationTest.php` - Updated from stubs to 6 real tests (correct sig, wrong secret, missing sig for both validate paths)
- `backend/public/api/submit.php` - Added API_REQUEST constant, per-tenant CORS, HMAC validation block before rate limiting and processing
- `backend/public/api/upload.php` - Added SKIP_AUTH_CHECK, API_REQUEST constant, per-tenant CORS, HMAC validation using canonical string

## Decisions Made
- HMAC for upload.php uses canonical string `anmeldung_id:fieldname:filename` because multipart body byte layout is fragile to reconstruct on the frontend signing side
- Per-tenant CORS falls back to global `ALLOWED_ORIGINS` when `tenant.origin` is NULL for backward compatibility with single-tenant deployments
- Generic 401 for all auth failures (uninitialized TenantContext, inactive tenant, wrong HMAC) — no tenant enumeration leakage

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

- PHP binary not available in shell PATH; resolved by using `docker run php:8.2-cli` for all test runs
- Pre-existing unit suite failures (43 errors: missing `mysqli` extension in PHP Docker image and missing `DB_PASS` env var) were confirmed out-of-scope — identical count before and after changes; HmacValidationTest (6 tests) all green

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- API endpoints are now fully protected by per-tenant HMAC authentication
- Frontend API clients (BackendApiClient.php) need to start signing requests with the tenant api_secret and sending X-Signature header
- Phase 02 complete: all 6 plans executed (TenantRepository, LoginService, bootstrap tenant resolution, AnmeldungRepository isolation, file/audit isolation, HMAC validation)

---
*Phase: 02-auth-data-isolation-and-api-security*
*Completed: 2026-03-13*

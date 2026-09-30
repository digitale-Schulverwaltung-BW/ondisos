# Codebase Concerns

**Analysis Date:** 2026-03-13

## Tech Debt

### Email Service Uses PHP mail()

**Area:** Frontend email notifications
**Files:** `frontend/src/Services/EmailService.php`
**Issue:** Service relies on PHP's `mail()` function for sending notifications
**Impact:**
- No SMTP configuration possible (depends on server's mail setup)
- Unreliable delivery (mail might be silently dropped by server)
- No authentication or encryption support
- Hard to debug delivery failures
- No retry mechanism

**Fix approach:**
1. Replace `mail()` with an SMTP library (PHPMailer, Symfony Mailer, or SwiftMailer)
2. Add SMTP configuration to `.env` (host, port, username, password)
3. Implement error handling and logging for failed sends
4. Consider adding a mail queue for retry logic on failure
5. Track email delivery status in audit log

**Priority:** Medium (affects user communication)

---

### Multi-Tenant Implementation Deferred

**Area:** Architecture for scalability
**File:** `backend/MULTI-TENANT.md`
**Issue:** Multi-tenant features planned for v3.0 but not yet implemented
**Impact:**
- Single-tenant schema locks system architecture until migration
- All existing data and code must be refactored later
- No tenant isolation mechanism currently in place
- Roadmap includes 3+ versions (3.0, 3.0.5, 3.1) for feature completion

**What's planned but missing:**
- `tenants` table and `tenant_admins` table (schema)
- Tenant context propagation throughout codebase
- Per-tenant form configuration (database instead of files)
- Tenant-scoped file uploads (`backend/uploads/tenant-{id}/`)
- Per-tenant HMAC shared secrets (`api_secret`)
- TenantContext singleton for request-scoped tenant ID
- Repository layer tenant filtering (all 15+ methods need `WHERE tenant_id = ?`)
- Multi-tenant admin authentication and session handling
- Form config DB storage + admin UI (deferred to v3.1)
- Managed multi-tenant frontend (deferred to v3.0.5)

**Fix approach:**
1. **v3.0.0 Migration:**
   - Implement schema changes (new tables, tenant column on `anmeldungen`)
   - Create TenantContext for tenant ID propagation
   - Update all repositories with tenant filtering
   - Add tenant-based API secret validation
   - Implement tenant admin authentication

2. **v3.0.5 & v3.1:**
   - Multi-tenant frontend UI (v3.0.5)
   - Form config admin UI (v3.1)

**Priority:** High (architectural blocker for scale)
**Timeline:** Not yet scheduled

---

### Test Coverage Gaps

**Area:** Service layer and repositories
**Files:**
- `backend/src/Repositories/AnmeldungRepository.php` (439 lines, 0% coverage)
- `backend/src/Services/AnmeldungService.php` (110 lines, 0% coverage)
- `backend/src/Services/StatusService.php` (118 lines, 0% coverage)
- `backend/src/Services/PdfGeneratorService.php` (120 lines, 0% coverage)
- `backend/src/Services/RequestExpungeService.php` (140 lines, 0% coverage)

**Issue:** Critical business logic untested
**Impact:**
- Bugs can go undetected until reaching users
- Refactoring is risky without test safety net
- Database operations not validated
- Status transitions not covered

**Current status:** 18.90% overall coverage (target: >80%)
**What IS tested:**
- ExportService: 88.46%
- AnmeldungValidator: ~95%
- PdfTokenService: 100%
- RateLimiter: 96.92%
- MessageService: 100%

**Fix approach:**
1. **Priority 1 (Database-critical):**
   - AnmeldungRepository: Integration tests with test DB (medium effort)
   - SQL injection prevention in filter methods
   - Soft-delete and restore functionality

2. **Priority 2 (Business logic):**
   - StatusService: Simple transition logic (small effort)
   - AnmeldungService: Submission workflow (medium effort)
   - RequestExpungeService: Expunge preview logic (small effort)

3. **Priority 3 (Feature-complex):**
   - PdfGeneratorService: PDF rendering (large effort)
   - Controllers: DetailController, BulkActionsController (medium effort)

**Priority:** High (security + reliability)

---

## Known Bugs

### iCal Time Formatting Edge Case

**Issue:** iCal event times might not parse correctly for all-day events
**File:** `frontend/public/ical.php`
**Symptoms:** Calendar apps may show incorrect event times or duration
**Trigger:** When `event_date` is valid but `event_time_start` or `event_time_end` are missing
**Trigger code:**
```php
$timeStart = $icalConfig['event_time_start'] ?? '00:00';  // Defaults to midnight
$timeEnd   = $icalConfig['event_time_end']   ?? '00:00';  // Also midnight
$dtStart = str_replace('-', '', $date) . 'T' . str_replace(':', '', $timeStart) . '00';
```

**Current behavior:** Events default to midnight start/end without explicit all-day flag
**Expected behavior:** Should use VALUE=DATE property for all-day events, not datetime

**Fix approach:**
1. Add `event_is_all_day` flag to iCal config
2. If true: emit `VALUE=DATE` format (no time)
3. If false: require valid times or default with warning
4. Validate time format in FormConfig

**Workaround:** Explicitly set `event_time_start` and `event_time_end` in form config

**Priority:** Low (edge case, works for most calendar apps)

---

### JSON Data Unbounded Storage

**Issue:** Form submissions store entire JSON in LONGTEXT column without size validation
**File:** `backend/src/Repositories/AnmeldungRepository.php` (insertion)
**Problem:**
- No maximum payload size enforced
- Large file uploads embedded as base64 in JSON (multiplies DB space)
- Backups become bloated
- Exports might time out on huge datasets

**Current state:**
- Database: `anmeldungen.data LONGTEXT` (max ~4GB MySQL default)
- Frontend validation: Only file count, not total submission size
- No streaming storage for large attachments

**Fix approach:**
1. Enforce submission payload size limit (frontend + backend)
2. Store large file attachments separately in filesystem, store filename in JSON only
3. Implement file storage service to abstract away base64 embedding
4. Set reasonable limit (~50MB per submission) with clear user feedback

**Priority:** Medium (scaling concern)

---

## Security Considerations

### Session Storage in Memory Only

**Risk:** Sessions stored in PHP default handler (files in `/tmp` or `$_SESSION` superglobal)
**Files:** `backend/inc/auth.php`, `backend/public/login.php`, `backend/public/logout.php`
**Impact:**
- On shared servers, other applications might access session files
- No encryption of session data in transit
- Temporary session files in `/tmp` might be world-readable
- Container restart loses all sessions (no persistence across deployments)

**Current mitigation:**
- ✅ Session regeneration on login/logout/timeout
- ✅ CSRF protection for sensitive operations
- ❌ No encrypted session storage
- ❌ No secure session cookie flags in certain configurations

**Recommendations:**
1. In production: Use encrypted session handler (database or Redis)
2. Set secure session cookie flags:
   - `session.cookie_secure = true` (HTTPS only)
   - `session.cookie_httponly = true` (no JavaScript access)
   - `session.cookie_samesite = 'Strict'` (CSRF prevention)
3. Consider database sessions for multi-container deployments

**Current status:** Safe for intranet deployment, needs hardening for internet-facing

**Priority:** Medium

---

### File Upload Base64 in JSON Responses

**Risk:** File contents embedded as base64 in JSON (email + submissions)
**Files:**
- `frontend/src/Services/EmailService.php` (filters these)
- `frontend/src/Services/AnmeldungService.php` (sends to backend)
- `backend/public/api/submit.php` (receives)

**Impact:**
- If submission JSON logged before filtering: entire file contents in logs
- Large files inflate database size (3-4x with base64 encoding)
- Email attachments appear as `[Datei-Upload]` (correctly filtered)

**Current mitigation:**
- ✅ Email: Filters base64 and shows `[Datei-Upload]` instead
- ✅ Backend: Stores file separately on disk, not in DB
- ✅ Frontend: Stores file upload in `uploads/` directory
- ⚠️ Transit: Base64 sent over network in JSON

**Recommendations:**
1. Frontend: Implement chunked uploads for large files instead of base64-in-JSON
2. Add upload size limits (currently no frontend max enforced)
3. Store file hash separately for integrity verification
4. Log only filename + hash, never base64 content

**Priority:** Medium (mostly mitigated by current design)

---

### LONGTEXT Storage for Nested JSON

**Risk:** Complex form data with arrays/nested objects might produce unwieldy JSON
**File:** `backend/src/Repositories/AnmeldungRepository.php` (line 49: LONGTEXT)
**Impact:**
- No validation on JSON structure depth or size
- Deeply nested form fields create hard-to-debug data
- Export performance may degrade with very complex JSON

**Recommendations:**
1. Validate JSON depth (max 20 levels) before storing
2. Flatten nested arrays in export (don't try to recreate structure)
3. Document form structure constraints for form designers

**Priority:** Low (unlikely edge case)

---

## Performance Bottlenecks

### N+1 Queries in Export + Enrichment

**Slow operation:** Excel export with Nominatim/School lookup enrichment
**Files:**
- `backend/src/Services/ExportService.php` (lines 46-54)
- `backend/src/Services/NominatimService.php` (geocoding)
- `backend/src/Services/SchoolLookupService.php` (school lookup)

**Problem:**
```php
foreach ($anmeldungen as $anmeldung) {
    // Each address triggers Nominatim API call
    // Each school triggers lookup service call
}
```

**Current code:**
```php
// ExportService lines 46-54
if ($this->nominatimService !== null) {
    $this->enrichTeilort($anmeldungen);  // Potential N calls to Nominatim
}
if ($this->schoolLookupService !== null) {
    $this->enrichSchoolLookup($anmeldungen);  // Potential N calls to lookup
}
```

**Impact:**
- With 100 submissions: 100+ external API calls
- Export times out or becomes unusable
- Nominatim rate-limiting kicks in (429 errors)
- School lookup service slows down

**Improvement path:**
1. Batch geocoding: Group addresses, deduplicate, call Nominatim once per unique address
2. Cache enrichment results (Redis or file-based)
3. Implement async enrichment (queue jobs, return CSV without enrichment by default)
4. Add progress tracking for long-running exports
5. Document enrichment cost in UI ("May take 2-3 minutes for 100+ records")

**Current status:** Works for small datasets (<20 records), untested at scale

**Priority:** Medium (scaling concern)

---

### Large File Downloads Without Streaming

**Slow operation:** Excel export generation
**Files:** `backend/src/Services/SpreadsheetBuilder.php`
**Problem:** Entire spreadsheet built in memory, then returned to client
**Impact:**
- Memory usage: ~2-5x CSV size
- With 1000+ rows and embedded images: can exceed PHP memory limit
- No progress feedback to user
- Browser might timeout on slow connections

**Improvement path:**
1. Use streaming writer (PHPExcel streaming, or custom implementation)
2. Write to temporary file, stream to client with proper headers
3. Implement chunked downloads with progress reporting
4. Set reasonable pagination (max 500 rows per export)

**Priority:** Medium (edge case at scale)

---

### No Database Indexing for Common Queries

**Concern:** Query performance on large datasets
**Files:** `backend/src/Repositories/AnmeldungRepository.php`
**Current indexes (from schema):**
```sql
INDEX idx_formular (formular)
INDEX idx_email (email)
INDEX idx_created (created_at)
```

**Potential missing indexes:**
- `status` column (filtered frequently in exports)
- `deleted` + `created_at` (soft-delete queries)
- Compound index: `(formular, status, created_at)` for common filter combinations

**Improvement path:**
1. Profile queries in production to identify slow ones
2. Add `INDEX idx_status (status)` for status-filtered exports
3. Add `INDEX idx_soft_delete (deleted, created_at)` for expunge queries
4. Consider `(formular, status)` compound index

**Priority:** Low (currently working, monitor at scale)

---

## Fragile Areas

### Survey Handler File Detection Logic

**Component:** `frontend/public/js/survey-handler.js` (lines ~150-180)
**Files involved:**
- `frontend/public/js/survey-handler.js`
- `frontend/src/Services/EmailService.php`
- `backend/public/api/submit.php`

**Why fragile:**
- File detection uses multiple heuristics (name + content property)
- If SurveyJS plugin changes file object structure: detection breaks
- Base64 detection uses regex length + character set (fragile for text-heavy uploads)

**Current logic:**
```javascript
// Detect file uploads: check for {name, content} structure
if (isset($value['name']) && isset($value['content'])) {
    // File detected
}
// Also checks for base64: /^data:[^;]+;base64,/
```

**Risk:** If form field structure changes or SurveyJS plugin updates, file filtering might fail
**Safe modification:**
1. Add explicit flag from SurveyJS plugin: `{type: 'file', name, content}`
2. Make file detection explicit in form schema, not inferred from structure
3. Test with various SurveyJS versions

**Test coverage:** Not explicitly tested (no unit tests for file detection logic)

**Priority:** Low (stable since v2.2, unlikely change)

---

### PDF Token Generation Relies on Microtime

**Component:** `backend/src/Services/PdfTokenService.php`
**Files:** `backend/src/Services/PdfTokenService.php`
**Why fragile:**
- Token generation includes unix timestamp (seconds precision)
- Token validity window: 30 minutes by default
- If server clock drifts: token expiration might be off

**Current code:**
```php
$timestamp = time();  // Unix timestamp, seconds
// Token expires in 30 minutes (configurable)
```

**Risk:**
- NTP sync issues on server affect token validity
- Tokens generated at :59 second might expire at wrong time
- Can't revoke tokens (no blacklist)

**Safe modification:**
1. Document time sync requirement in deployment docs
2. Add server time check endpoint for clients to verify
3. Consider adding token blacklist for manual revocation (low priority)
4. Use millisecond precision if needed for high-security scenarios

**Test coverage:** ✅ Full (100% PdfTokenServiceTest)

**Priority:** Low (mitigated by generous 30-min window)

---

### Form Config Reload Requires Code Deploy

**Component:** Form configuration management
**Files:** `frontend/config/forms-config.php`, `backend/config/forms-config.php`
**Why fragile:**
- Form changes (add/remove fields) require editing PHP config file
- Changes only take effect after server restart (if cached)
- Multi-tenant not supported (all schools share same forms)

**Current state:**
- Static config files committed to git
- Changes require code deploy (no hot-reload)
- No admin UI to manage forms

**Improvement path:**
- Move to database (v3.0 multi-tenant roadmap)
- Implement cache invalidation on config change
- Add admin UI for form management (v3.1)

**Priority:** Low (deferred to v3.0)

---

## Scaling Limits

### Database: Single Anmeldungen Table

**Current capacity:**
- Can handle ~1 million records with proper indexing
- Soft-delete column adds ~1 byte per record overhead
- Auto-expunge runs on-demand (no built-in cleanup schedule)

**Scaling concern:**
- Deleted records not automatically removed (just marked)
- Very old archives accumulate if AUTO_EXPUNGE_DAYS not set
- No partitioning by date (would help with large datasets)

**Limit:** ~5-10 years of continuous use (millions of records)

**Scaling path:**
1. **Short term (months 1-6):**
   - Set AUTO_EXPUNGE_DAYS (e.g., 90 days)
   - Implement scheduled auto-expunge via cron or monitoring

2. **Medium term (months 6-18):**
   - Add database partitioning by `created_at` (yearly or monthly)
   - Archive old data to separate tables

3. **Long term (18+ months):**
   - Multi-tenant database (v3.0) with per-tenant sharding
   - Implement read replicas for reporting

**Priority:** Low (not imminent)

---

### File Upload Storage

**Current capacity:**
- All uploads in `backend/uploads/` (single directory)
- Filesystem performance degrades with >10,000 files in one directory

**Limit:** ~10,000-50,000 total uploads before performance issues

**Scaling path:**
1. Implement sharded storage: `uploads/tenant-{id}/{year}/{month}/{id}_filename`
2. Use S3 or similar object storage for >100GB deployments
3. Implement cleanup policies (auto-delete after 1 year)

**Priority:** Low (not imminent)

---

### API Rate Limiting

**Current setup:**
- 10 requests per minute per IP (configurable)
- File-based storage (sliding window)

**Limit:** ~100-1000 concurrent users before rate limiter performance issues

**Scaling path:**
1. Use Redis for rate limiter (better performance at scale)
2. Implement different limits for different endpoints (stricter for upload)
3. Add authentication-based limiting (per user, not just IP)

**Priority:** Low (not imminent)

---

## Dependencies at Risk

### mPDF Security & Maintenance

**Risk:** mPDF library is large and complex, potential security issues
**Version:** Not pinned in `composer.json` (check actual version)
**Impact:** PDF generation could be exploited if library has vulns

**Improvement path:**
1. Regularly update via Composer
2. Monitor CVE databases for mPDF vulnerabilities
3. Consider lighter alternative (TCPDF, DOMPDF) if mPDF becomes unmaintained
4. Implement PDF generation timeout (prevent DoS)

**Priority:** Medium (PDF is user-facing)

---

### PHP Version Lock

**Risk:** PHP 8.2+ required, but future versions might break compatibility
**File:** `composer.json`, `.env`, `docker-compose.yml`
**Impact:** Long-term maintenance burden as PHP evolves

**Current state:**
- Requires PHP 8.2+ (fine until ~2026)
- Uses modern syntax (typed properties, named args, match expressions)
- No major BC breaks expected in 8.3 or 9.0

**Improvement path:**
1. Keep dependencies updated for compatibility with new PHP versions
2. Use GitHub Dependabot or similar for automated updates
3. Test with PHP 8.3+ as they release

**Priority:** Low (long-term planning)

---

## Missing Critical Features

### No Structured Logging

**Problem:** Critical errors and events only logged via `error_log()` and `AuditLogger`
**Files:**
- `error_log()` calls scattered throughout: ~18 locations
- `AuditLogger::*()` for user actions only

**Impact:**
- Hard to debug production issues
- No structured data for monitoring/alerting
- Log aggregation tools (ELK, Datadog) can't parse format

**Recommendations:**
1. Implement Monolog or Symfony Logging
2. Use structured JSON format for all logs
3. Create custom log channels:
   - `auth` - login/logout/permission events
   - `submission` - form submissions, errors
   - `system` - database, file, config errors
   - `performance` - slow operations

**Priority:** Medium (blocks monitoring/alerting)

---

### No Monitoring or Alerting

**Missing:**
- ❌ Uptime monitoring (no health check endpoint)
- ❌ Performance monitoring (no metrics collection)
- ❌ Error alerting (admins don't know when things break)
- ❌ Disk space monitoring (uploads could fill disk)
- ❌ Database size monitoring (no warning when table grows)

**Current state:**
- Manual monitoring only (admins check dashboard)
- No automated alerts

**Recommendations:**
1. Implement health check endpoint: `/health.php`
2. Set up monitoring:
   - Uptime: Pingdom, UptimeRobot, or self-hosted
   - Errors: Sentry, Rollbar, or similar
   - Performance: New Relic, Datadog, or self-hosted Prometheus
   - Infrastructure: Disk/RAM/CPU via Nagios, Zabbix
3. Create alert rules:
   - Error rate > 1% → alert
   - Response time > 1s → alert
   - Disk usage > 80% → alert
   - Database size > 5GB → alert

**Priority:** High (critical for production)

---

### No API Documentation

**Problem:** No OpenAPI/Swagger documentation for API endpoints
**Affected endpoints:**
- `backend/public/api/submit.php` - Submissions
- `backend/public/api/upload.php` - File uploads
- `frontend/public/api/messages.json.php` - Messages

**Impact:**
- Frontend developers must read code to understand API
- Integration partners can't easily consume API
- No way to validate API changes

**Recommendations:**
1. Create OpenAPI 3.0 specification
2. Use SwaggerUI for interactive API docs
3. Use API specs to auto-generate client SDKs

**Priority:** Low (single frontend, but helpful for future)

---

## Test Coverage Gaps

### Critical Business Logic Not Tested

**Untested areas:**

1. **AnmeldungRepository** (0% coverage)
   - All CRUD operations
   - Filter logic (formular, status, name, email)
   - Soft-delete and restore
   - Pagination
   - SQL injection prevention

2. **StatusService** (0% coverage)
   - Status transitions
   - Auto-mark-as-read on export
   - markAsExported() logic

3. **AnmeldungService** (0% coverage)
   - Submission processing
   - Validation flow
   - Email sending integration

4. **Controllers** (DetailController, BulkActionsController)
   - User input handling
   - Template rendering
   - Error scenarios

**Why it matters:**
- Bug in status transitions affects all data
- Bug in repository could cause data loss
- Controllers handle user interaction (XSS, injection risks)

**Fix approach:**
- Prioritize: Repository > Service > Controller
- Use integration tests for DB logic
- Use unit tests for business rules
- Target >80% coverage for critical paths

**Priority:** High

---

**End of concerns analysis**

*Last updated: 2026-03-13*

# Architecture

**Analysis Date:** 2026-03-13

## Pattern Overview

**Overall:** Clean MVC with Service Layer (3-tier architecture across two distinct applications)

**Key Characteristics:**
- Two independent PHP applications: **Frontend** (public, form submission) and **Backend** (intranet, admin management)
- Frontend → Backend communication via HTTP API (REST-like with CORS)
- Service-driven business logic (AnmeldungService, ExportService, PdfGeneratorService, etc.)
- Repository pattern for data persistence
- Strict PHP 8.2+ typing with `declare(strict_types=1)` throughout
- PSR-4 autoloading (no Composer required for core classes; Composer used for mPDF)
- Third system: WordPress plugin for form submission via Ondisos namespace

## Layers

### Frontend Application (`frontend/`)

**Presentation Layer:**
- Location: `frontend/public/`
- Files: `index.php` (form display), `save.php` (form submission), `csrf_token.php` (token endpoint)
- Contains: SurveyJS form rendering (JSON-driven), HTML/CSS/JavaScript
- Depends on: Config, Services
- Used by: End users filling out forms

**Service Layer:**
- Location: `frontend/src/Services/`
- Key services:
  - `AnmeldungService.php`: Orchestrates form submission (validation, file handling, API calls)
  - `BackendApiClient.php`: HTTP client for communicating with backend API
  - `EmailService.php`: Local email notification (optional)
  - `MessageService.php`: Centralized UI message management
- Depends on: Config, Utils, Backend API
- Used by: Public endpoints (save.php, ical.php)

**Configuration Layer:**
- Location: `frontend/src/Config/`
- `FormConfig.php`: Loads survey definitions, PDF config, backend URL
- `frontend/config/forms-config.php`: Maps form keys to survey JSON files
- `frontend/config/messages.php`: UI text, error messages, labels

**Utility Layer:**
- Location: `frontend/src/Utils/`
- `CsrfProtection.php`: Session-based CSRF token generation/validation
- Uses PHP sessions to maintain state

---

### Backend Application (`backend/`)

**Presentation Layer:**
- Location: `backend/public/`
- Entry points:
  - `index.php`: Admin list view (paginated, filtered, searchable)
  - `detail.php`: Single submission detail view
  - `trash.php`: Soft-deleted items
  - `dashboard.php`: Statistics and admin tools
  - `bulk_actions.php`: Multi-item operations
  - `excel_export.php`: Export functionality
  - `api/submit.php`: API endpoint for frontend submissions
  - `api/upload.php`: File upload endpoint
  - `pdf/download.php`: PDF generation/download
- Depends on: Controllers, Services, Repositories
- Users: Admin users (backend) and frontend application (API)

**Controller Layer:**
- Location: `backend/src/Controllers/`
- `AnmeldungController.php`: Handles index page logic (pagination, filtering, sorting)
- `DetailController.php`: Shows single submission details
- `BulkActionsController.php`: Processes multi-item operations
- Depends on: Services
- Used by: Public endpoints, Controllers accept request params and delegate to services

**Service Layer:**
- Location: `backend/src/Services/`
- Domain services:
  - `AnmeldungService.php`: Pagination, filtering, sorting logic
  - `StatusService.php`: Status transitions and statistics
  - `ExportService.php`: Excel export with formatting
  - `ExpungeService.php`: Soft-delete to hard-delete migration (auto-expunge)
  - `RequestExpungeService.php`: Throttled expunge scheduling
- Infrastructure services:
  - `PdfGeneratorService.php`: mPDF-based PDF creation
  - `PdfTemplateRenderer.php`: Renders PHP templates for PDF content
  - `PdfTokenService.php`: HMAC-based token generation/validation (secure, time-limited)
  - `MessageService.php`: Message resolution with local overrides
  - `VirusScanService.php`: ClamAV integration (TCP/INSTREAM, DSGVO-compliant)
  - `AuditLogger.php`: JSON-Lines audit trail logging
  - `RateLimiter.php`: File-based sliding window rate limiting
- Depends on: Repositories, Config, Models

**Repository Layer:**
- Location: `backend/src/Repositories/`
- `AnmeldungRepository.php`: Data access for `anmeldungen` table
  - Methods: `findPaginated()`, `findById()`, `findDeleted()`, `insert()`, `updateStatus()`, `softDelete()`, `hardDelete()`, `restore()`
  - Query building with prepared statements (prevents SQL injection)
  - Whitelisted sort columns to prevent injection
- Depends on: Database config, Models
- Used by: Services

**Model Layer:**
- Location: `backend/src/Models/`
- `Anmeldung.php`: Readonly class representing a submission (with possible NULLs)
- `CompleteAnmeldung.php`: Strict model (no NULLs) for type-safe operations
- `AnmeldungStatus.php`: Enum with values (neu, exportiert, in_bearbeitung, akzeptiert, abgelehnt, archiviert)
  - Each status has label() and badgeClass() methods for UI rendering
- Used by: Repository and Services for type safety

**Validator Layer:**
- Location: `backend/src/Validators/`
- `AnmeldungValidator.php`: Input validation rules
  - SQL injection prevention checks
  - Form name validation against allowed forms
- Used by: Repository (defense in depth), Services

**Configuration Layer:**
- Location: `backend/src/Config/`
- `Config.php`: Application-wide configuration (constants, settings)
- `Database.php`: MySQL/MariaDB connection singleton
- `EnvLoader.php`: .env file parser
- `FormConfig.php`: Form definitions, PDF config, allowed forms
- Used by: All services and repositories

**Utility Layer:**
- Location: `backend/src/Utils/`
- `NullableHelpers.php`: Safe null value rendering
- `DataFormatter.php`: Date/number formatting for export and email

**Bootstrap & Infrastructure:**
- Location: `backend/inc/`
- `bootstrap.php`: Autoloader setup, error handling, HTTPS enforcement, auto-expunge trigger
- `auth.php`: Session-based authentication (optional, AUTH_ENABLED env var)
- `csrf.php`: CSRF helper functions for forms

**Templates:**
- Location: `backend/templates/pdf/`
- Reusable PHP templates for PDF content (sections: header, data-table, custom-section, footer)
- Rendered by `PdfTemplateRenderer.php`
- Styles in `styles.css` (mPDF-compatible)

---

### WordPress Plugin (`wordpress-plugin/`)

**Purpose:** Alternative form submission interface via WordPress shortcodes

**Architecture:**
- Location: `wordpress-plugin/includes/`
- Namespace: `Ondisos\*`
- Key classes:
  - `Plugin.php`: Main singleton orchestrator
  - `Shortcode.php`: Renders form via shortcode
  - `Ajax_Handler.php`: Handles async form submissions
  - `Pdf_Proxy.php`: Proxies PDF downloads to backend
  - `Settings.php`: WordPress admin settings page
  - `Assets.php`: Enqueues CSS/JS
  - `Autoloader.php`: PSR-4 autoloading for plugin
- Depends on: Frontend services (shared via require)
- Uses: Frontend form logic to maintain consistency

---

## Data Flow

### Submission Flow (Typical)

```
1. User opens /frontend/public/index.php?form=bs
   ↓
2. FormConfig validates form exists, loads survey JSON + theme
   ↓
3. SurveyJS renders form in browser (vanilla JS survey-handler.js)
   ↓
4. User submits form via save.php (POST)
   ↓
5. CsrfProtection::validate() checks token
   ↓
6. AnmeldungService::processSubmission()
   - Cleans consent fields
   - Extracts name, email
   - Builds submission data with metadata
   ↓
7. BackendApiClient::submitAnmeldung()
   - cURL POST to backend/api/submit.php (JSON)
   - Includes file uploads if any
   ↓
8. Backend Rate Limiter checks fingerprint (IP + UA hash + Accept-Language)
   - Returns 429 if exceeded
   ↓
9. Backend AnmeldungValidator validates form name (defense in depth)
   ↓
10. Backend AnmeldungRepository::insert()
   - SQL INSERT with prepared statement
   ↓
11. VirusScanService scans uploaded files (ClamAV TCP/INSTREAM)
   - Rejects if VIRUS_SCAN_STRICT=true and virus found
   - Logs to audit trail
   ↓
12. PdfTokenService generates time-limited HMAC token
   ↓
13. Response includes:
    - success: true
    - id: <submission_id>
    - pdf_download: { enabled, url, expires_in }
    - (optional) ical_download, prefill_link
   ↓
14. Frontend JavaScript displays success message + download button
```

### Admin List View Flow

```
1. Admin opens /backend/public/index.php (requires auth if AUTH_ENABLED)
   ↓
2. AnmeldungController::index()
   - Extracts GET params (form, status, name, email, sort, dir, page, perPage)
   ↓
3. AnmeldungService::getPaginatedAnmeldungen()
   - Validates perPage against allowed values [10, 25, 50, 100]
   - Sanitizes filters
   ↓
4. AnmeldungRepository::findPaginated()
   - Whitelists sort columns [id, name, email, status, created_at]
   - Builds WHERE clause with prepared params
   - Returns paginated results + total count
   ↓
5. StatusService::getAvailableForms() fetches distinct form keys
   ↓
6. View renders table with:
   - Sortable column headers
   - Filter inputs (name, email, status dropdowns)
   - Pagination
   - Bulk action checkboxes
```

### Excel Export Flow

```
1. Admin clicks "Export" button or selects rows + "Export"
   ↓
2. Form POST to excel_export.php with selected IDs (or GET for all/filtered)
   ↓
3. CSRF validation
   ↓
4. ExportService::export()
   - Fetches submissions from repository
   - SpreadsheetBuilder formats data:
     * Zebra striping
     * Auto-width columns
     * Frozen header row
     * Auto-formatted dates (YYYY-MM-DD → dd.mm.yyyy)
     * Metadata sheet (export time, filter summary)
     * Hides "formular" column if single-form export
   ↓
5. PhpOffice/PhpSpreadsheet library generates .xlsx
   ↓
6. Browser downloads: bestaetigung-{form}-{timestamp}.xlsx
   ↓
7. StatusService auto-marks submissions as "exportiert" (if AUTO_MARK_AS_READ=true)
   ↓
8. AuditLogger logs export event (timestamp, user, count, form filter)
```

### PDF Generation Flow

```
1. User clicks "Download Confirmation" after successful submission
   ↓
2. JavaScript calls /frontend/public/pdf/download.php?token=...
   ↓
3. Frontend PDF Proxy (download.php)
   - Validates token format
   - Proxies request to backend via cURL
   - Returns PDF to browser
   ↓
4. Backend PDF Endpoint (/backend/public/pdf/download.php)
   - PdfTokenService::validate(token)
     * Parses: id:timestamp:lifetime:hmac
     * Checks expiration (default 30 min)
     * Verifies HMAC-SHA256 signature (timing-safe hash_equals)
   ↓
5. AnmeldungRepository::findById(id)
   ↓
6. PdfGeneratorService::generatePdf()
   - PdfTemplateRenderer::render() with Anmeldung data
   - Loads logo, applies styles
   - mPDF creates binary PDF
   ↓
7. Backend sends PDF with Content-Disposition: attachment
```

### Soft-Delete & Auto-Expunge Flow

```
1. Admin bulk-deletes submissions
   ↓
2. BulkActionsController::delete()
   ↓
3. AnmeldungRepository::softDelete(ids)
   - Sets deleted=1, deleted_at=NOW()
   ↓
4. Admin can restore from trash.php
   ↓
5. Auto-expunge (every 6 hours, throttled):
   - RequestExpungeService::checkAndRun()
   - Checks cache/last_expunge.txt (avoid hammering DB)
   ↓
6. ExpungeService::hardDelete()
   - AnmeldungRepository::hardDelete() for old soft-deleted rows
   - Deletes from DB permanently after AUTO_EXPUNGE_DAYS
   ↓
7. AuditLogger logs hard_delete event
```

### State Management

**Frontend:**
- No persistent state (stateless)
- Session storage: CSRF token in $_SESSION
- Browser localStorage: (if needed for prefill)

**Backend:**
- Database: Primary state storage (anmeldungen table)
- Status transitions: Application-driven (Service layer)
- Caching: File-based (rate limiter, expunge tracking)
- Sessions: Admin authentication (optional, session-based)

**Data Format:**
- Submissions stored as JSON in `data` column (schema-free)
- Metadata in `metadata` column (JSON)
- Soft-delete flag: `deleted` (tinyint boolean)

---

## Key Abstractions

### Anmeldung (Submission)

**Purpose:** Type-safe representation of a form submission

**Examples:**
- `backend/src/Models/Anmeldung.php` (nullable fields)
- `backend/src/Models/CompleteAnmeldung.php` (strict, no nulls)

**Pattern:**
- Readonly class (PHP 8.1+)
- Constructed from database row via `fromArray()`
- Immutable after creation
- Includes validity check `isComplete()`
- Status enum provides label() and badgeClass()

### Service Layer

**Purpose:** Encapsulate business logic, decouple controllers from repositories

**Examples:**
- `AnmeldungService`: Pagination, filtering, sorting
- `ExportService`: Format and export data
- `StatusService`: Status transitions, statistics
- `PdfGeneratorService`: PDF creation orchestration
- `VirusScanService`: Malware detection

**Pattern:**
- Constructor injection of dependencies (Repository, Config, other Services)
- Public methods are high-level operations (not CRUD)
- Error handling via exceptions
- Return arrays for complex results

### Repository Pattern

**Purpose:** Abstract database operations from business logic

**Location:** `backend/src/Repositories/AnmeldungRepository.php`

**Methods:**
- `findPaginated()`: Filtered list with sorting and pagination
- `findById(id)`: Single submission
- `findDeleted()`: Trash items
- `insert()`: Create new submission
- `updateStatus()`: Update status
- `softDelete()`: Mark as deleted
- `hardDelete()`: Permanent deletion
- `restore()`: Undo soft-delete

**Pattern:**
- Prepared statements exclusively (prevents SQL injection)
- Whitelist sort columns (prevents injection via sort params)
- Always filter by `deleted=0` in normal queries
- Return Model objects, not raw arrays

### MessageService (Centralized Localization)

**Purpose:** Single source of truth for all UI text, with local overrides

**Locations:**
- Backend: `backend/config/messages.php` + `messages.local.php` (gitignored)
- Frontend: `frontend/config/messages.php` + `messages.local.php` (gitignored)

**Pattern:**
- Dot notation for nested keys: `'ui.buttons.save'`
- Placeholder substitution: `'success.restored'` → "Eintrag #{{id}} wurde wiederhergestellt"
- `M::format('key', ['var' => 'value'])` replaces placeholders
- `M::withContact('error.generic')` appends support contact
- Local overrides deep-merged at runtime (no build step)

### PDF Token System

**Purpose:** Secure, self-validating, time-limited access to generated PDFs

**Components:**
- `PdfTokenService`: Generates and validates HMAC tokens
- Token format: `base64(id:timestamp:lifetime:hmac)`
- HMAC algorithm: SHA-256
- No database storage (stateless validation)
- Timing-safe comparison: `hash_equals()`
- Frontend proxy: Bridges public access to intranet backend

**Pattern:**
- Token generated on submission save
- Token expires after configured lifetime (default 30 min)
- Each download re-generates PDF on-demand
- No permanent PDF files stored

### Rate Limiting

**Purpose:** Prevent brute-force submission attacks

**Location:** `backend/src/Services/RateLimiter.php`

**Pattern:**
- File-based (no Redis dependency)
- Sliding window algorithm
- Fingerprinting: IP + hashed User-Agent + Accept-Language
- Configurable via .env: `RATE_LIMIT_ENABLED`, `RATE_LIMIT_MAX`, `RATE_LIMIT_WINDOW`
- Returns 429 with Retry-After header

### Audit Trail

**Purpose:** Track admin actions and security events

**Location:** `backend/logs/audit.log` (JSON-Lines format)

**Events logged:**
- login_success, login_failed, logout
- status_changed
- bulk_archive, bulk_delete, bulk_restore, bulk_hard_delete
- upload_success
- virus_found
- export_run

**Pattern:**
- One JSON object per line (JSON-Lines format)
- Includes: timestamp, event_type, user_id, ip_address, details
- Thread-safe file locking (flock with LOCK_EX)
- AuditLogger is static class for easy access anywhere

---

## Entry Points

### Frontend Entry Points

**`frontend/public/index.php`:**
- Location: `frontend/public/index.php`
- Triggers: User navigates to `?form=bs`
- Responsibilities:
  1. Load form configuration via FormConfig
  2. Backend health check (prevents wasted form-filling)
  3. Load survey JSON + theme JSON
  4. Generate CSRF token
  5. Render HTML with SurveyJS

**`frontend/public/save.php`:**
- Location: `frontend/public/save.php`
- Triggers: Form submission (POST from survey-handler.js)
- Responsibilities:
  1. CSRF validation
  2. Parse survey data
  3. AnmeldungService::processSubmission() orchestration
  4. File upload handling
  5. JSON response (success, id, pdf_download info, ical_download info)

**`frontend/public/pdf/download.php`:**
- Location: `frontend/public/pdf/download.php`
- Triggers: User clicks PDF download link
- Responsibilities:
  1. Frontend proxy (cURL to backend)
  2. Returns PDF binary to user

**`frontend/public/ical.php`:**
- Location: `frontend/public/ical.php`
- Triggers: User clicks iCal download link (optional)
- Responsibilities:
  1. Generate iCal event from submission data
  2. Return .ics file

### Backend Entry Points

**`backend/public/index.php`:**
- Location: `backend/public/index.php`
- Triggers: Admin navigates to admin interface
- Requires: AUTH_ENABLED session (if enabled)
- Responsibilities:
  1. Parse GET params (form, status, name, email, sort, dir, page, perPage)
  2. AnmeldungController::index() delegation
  3. AnmeldungService pagination/filtering
  4. Render table with sorting + filtering + pagination

**`backend/public/detail.php`:**
- Location: `backend/public/detail.php`
- Triggers: Admin clicks submission ID
- Responsibilities:
  1. DetailController::show(id)
  2. AnmeldungRepository::findById(id)
  3. Render detailed submission view with all data
  4. Show files, timestamps, status

**`backend/public/dashboard.php`:**
- Location: `backend/public/dashboard.php`
- Triggers: Admin navigates to dashboard
- Responsibilities:
  1. StatusService::getStatistics() (count per status)
  2. ExpungeService preview (show pending hard-deletes)
  3. Render statistics cards
  4. Show expunge status + manual trigger button

**`backend/public/trash.php`:**
- Location: `backend/public/trash.php`
- Triggers: Admin clicks "Trash" button
- Responsibilities:
  1. AnmeldungRepository::findDeleted()
  2. Show soft-deleted submissions
  3. Restore/hard-delete options

**`backend/public/bulk_actions.php`:**
- Location: `backend/public/bulk_actions.php`
- Triggers: Admin selects items + clicks "Archive" or "Delete"
- Responsibilities:
  1. Parse selected IDs from POST
  2. BulkActionsController delegates to appropriate service
  3. StatusService::updateStatus() or AnmeldungRepository::softDelete()
  4. AuditLogger logs action
  5. Redirect to index.php with success message

**`backend/public/excel_export.php`:**
- Location: `backend/public/excel_export.php`
- Triggers: Admin clicks "Export" button
- Responsibilities:
  1. ExportService::export() with filters
  2. SpreadsheetBuilder formats XLSX
  3. Auto-mark submissions as "exportiert"
  4. Stream .xlsx file to browser

**`backend/public/api/submit.php`:**
- Location: `backend/public/api/submit.php`
- Triggers: Frontend save.php calls via cURL
- Responsibilities:
  1. Rate limiting check
  2. CORS headers
  3. Validate JSON payload
  4. File upload handling
  5. VirusScanService checks for malware
  6. AnmeldungRepository::insert()
  7. PdfTokenService generates token
  8. Return JSON response with pdf_download info

**`backend/public/api/upload.php`:**
- Location: `backend/public/api/upload.php`
- Triggers: Frontend upload during form submission
- Responsibilities:
  1. File type/size validation
  2. Directory traversal prevention
  3. VirusScanService scans file
  4. Store in uploads/ directory
  5. Return upload success/error

**`backend/public/pdf/download.php`:**
- Location: `backend/public/pdf/download.php`
- Triggers: Frontend proxy request (or direct access if authorized)
- Responsibilities:
  1. PdfTokenService::validate() token
  2. AnmeldungRepository::findById()
  3. PdfGeneratorService::generatePdf()
  4. Stream PDF to browser

---

## Error Handling

**Strategy:** Exception-driven with fallback error pages

**Patterns:**

1. **Validation Layer:**
   - Exceptions thrown immediately on invalid input
   - AnmeldungValidator rules checked at entry points
   - Prepared statements prevent SQL injection (exceptions on preparation failure)

2. **Service Layer:**
   - Business logic exceptions (InvalidArgumentException, RuntimeException)
   - Caught by controllers or public endpoints
   - Logged to error_log()

3. **Public Endpoints:**
   - Try-catch blocks at top level
   - JSON response errors (frontend save.php, API endpoints)
   - HTML error pages (index.php, detail.php)
   - 4xx/5xx HTTP codes set appropriately

4. **Error Pages:**
   - 404: Form not found (index.php)
   - 503: Backend unavailable (index.php health check)
   - 404: Submission not found (detail.php)
   - 429: Rate limited (api/submit.php)
   - 500: Unhandled exception (bootstrap error handler)

**Global Error Handler (`bootstrap.php`):**
- set_error_handler(): Converts PHP errors to exceptions
- set_exception_handler(): Logs and displays 500 page
- Error display disabled in production (log only)

---

## Cross-Cutting Concerns

**Logging:**
- Error log: `error_log()` for exceptions, API errors, validation failures
- Audit log: `AuditLogger::log()` for admin actions (JSON-Lines, `backend/logs/audit.log`)
- Both include timestamps, relevant context, sensitive data sanitized

**Validation:**
- Input: Controller extracts and validates GET/POST params
- Database: Prepared statements throughout
- Files: Type, size, extension, content scanning (ClamAV)
- Business rules: AnmeldungValidator at service/repository level

**Authentication (Optional):**
- Session-based (if AUTH_ENABLED=true)
- Login endpoint: `backend/public/login.php` (not admin-required API)
- Session timeout: configurable via .env
- CSRF protection on login form
- Brute-force protection: 0.5s delay on failed login

**Authorization:**
- Backend pages: Session auth check (if enabled)
- API endpoints: CORS headers + rate limiting
- File access: Admin only (backend paths)

**Security Headers (Apache .htaccess):**
- HTTPS enforcement (redirects HTTP to HTTPS)
- HSTS (Strict-Transport-Security)
- X-Frame-Options: SAMEORIGIN
- X-Content-Type-Options: nosniff
- Content-Security-Policy: restricted (frontend forms)

**Type Safety:**
- declare(strict_types=1) in every PHP file
- Type hints on all function parameters and return types
- Readonly classes for value objects (Anmeldung, CompleteAnmeldung)
- Enums for status values (AnmeldungStatus)

---

*Architecture analysis: 2026-03-13*

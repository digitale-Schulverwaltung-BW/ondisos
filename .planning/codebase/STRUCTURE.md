# Codebase Structure

**Analysis Date:** 2026-03-13

## Directory Layout

```
ondisos/
├── frontend/                   # Public form submission (user-facing)
│   ├── public/                 # Webroot entry points
│   │   ├── index.php          # Form display
│   │   ├── save.php           # Form submission handler
│   │   ├── csrf_token.php     # CSRF token API
│   │   ├── ical.php           # iCal download (optional)
│   │   ├── pdf/
│   │   │   └── download.php   # PDF proxy to backend
│   │   ├── api/
│   │   │   └── messages.json.php # Message API for JavaScript
│   │   ├── js/
│   │   │   └── survey-handler.js # SurveyJS integration
│   │   ├── assets/            # SurveyJS, fonts, third-party
│   │   └── uploads/           # Temporary file uploads
│   ├── src/                    # Application code
│   │   ├── Config/
│   │   │   └── FormConfig.php # Survey definitions, PDF config
│   │   ├── Services/
│   │   │   ├── AnmeldungService.php      # Submission orchestration
│   │   │   ├── BackendApiClient.php      # HTTP client to backend
│   │   │   ├── EmailService.php          # Email notifications
│   │   │   └── MessageService.php        # Message resolution
│   │   └── Utils/
│   │       └── CsrfProtection.php        # CSRF token management
│   ├── inc/
│   │   └── bootstrap.php       # Autoloader, env loader
│   ├── config/
│   │   ├── forms-config.php    # Form definitions (committed)
│   │   ├── forms-config-dist.php # Distribution template
│   │   ├── messages.php        # UI messages (committed)
│   │   ├── messages.local.php  # Local overrides (gitignored)
│   │   └── messages.example.php # Override template
│   ├── surveys/                # SurveyJS JSON definitions
│   │   ├── bs.json            # Berufsschule form
│   │   ├── bk.json            # Berufskolleg form
│   │   └── survey_theme.json   # SurveyJS theme
│   └── composer.json           # PHP dependencies (mPDF, etc.)
│
├── backend/                    # Admin management (intranet)
│   ├── public/                 # Webroot entry points
│   │   ├── index.php          # List view (paginated, filtered)
│   │   ├── detail.php         # Single submission detail
│   │   ├── trash.php          # Soft-deleted submissions
│   │   ├── dashboard.php      # Statistics & admin tools
│   │   ├── bulk_actions.php   # Multi-item operations
│   │   ├── excel_export.php   # Export to XLSX
│   │   ├── restore.php        # Restore from trash
│   │   ├── hard_delete.php    # Permanent delete
│   │   ├── login.php          # Admin login (optional)
│   │   ├── logout.php         # Admin logout
│   │   ├── api/
│   │   │   ├── submit.php     # Frontend API endpoint
│   │   │   └── upload.php     # File upload API
│   │   └── pdf/
│   │       └── download.php   # PDF generation
│   ├── src/                    # Application code
│   │   ├── Config/
│   │   │   ├── Config.php              # App constants
│   │   │   ├── Database.php            # MySQL connection
│   │   │   ├── EnvLoader.php           # .env parser
│   │   │   └── FormConfig.php          # Form configuration
│   │   ├── Controllers/
│   │   │   ├── AnmeldungController.php # Index page logic
│   │   │   ├── DetailController.php    # Detail page logic
│   │   │   └── BulkActionsController.php # Bulk operations
│   │   ├── Models/
│   │   │   ├── Anmeldung.php           # Submission model (nullable)
│   │   │   ├── CompleteAnmeldung.php   # Strict submission model
│   │   │   └── AnmeldungStatus.php     # Status enum
│   │   ├── Repositories/
│   │   │   └── AnmeldungRepository.php # Data access layer
│   │   ├── Services/
│   │   │   ├── AnmeldungService.php       # Pagination, filtering
│   │   │   ├── StatusService.php          # Status operations
│   │   │   ├── ExportService.php          # Excel export
│   │   │   ├── ExpungeService.php         # Auto-delete old records
│   │   │   ├── RequestExpungeService.php  # Throttle expunge
│   │   │   ├── PdfGeneratorService.php    # PDF creation
│   │   │   ├── PdfTemplateRenderer.php    # PDF template rendering
│   │   │   ├── PdfTokenService.php        # Token generation/validation
│   │   │   ├── VirusScanService.php       # ClamAV scanning
│   │   │   ├── AuditLogger.php            # Audit trail logging
│   │   │   ├── RateLimiter.php            # Rate limiting
│   │   │   ├── MessageService.php         # Message resolution
│   │   │   ├── SpreadsheetBuilder.php     # Excel formatting
│   │   │   ├── SchoolLookupService.php    # School data lookup
│   │   │   ├── NominatimService.php       # Nominatim geocoding
│   │   │   └── DataFormatter.php          # Date/number formatting
│   │   ├── Validators/
│   │   │   └── AnmeldungValidator.php     # Input validation
│   │   └── Utils/
│   │       ├── NullableHelpers.php        # Null-safe rendering
│   │       └── DataFormatter.php          # Format dates/numbers
│   ├── inc/
│   │   ├── bootstrap.php       # Autoloader, env loader, error handlers
│   │   ├── auth.php            # Session authentication
│   │   ├── csrf.php            # CSRF helper functions
│   │   ├── header.php          # HTML header (included in views)
│   │   └── footer.php          # HTML footer (included in views)
│   ├── config/
│   │   ├── messages.php        # UI messages (committed)
│   │   ├── messages.local.php  # Local overrides (gitignored)
│   │   └── messages.example.php # Override template
│   ├── templates/
│   │   └── pdf/
│   │       ├── base.php        # PDF main template
│   │       ├── styles.css      # mPDF-compatible styles
│   │       └── sections/
│   │           ├── header.php  # PDF header section
│   │           ├── footer.php  # PDF footer section
│   │           ├── data-table.php # PDF data table
│   │           ├── custom-section.php # Custom content area
│   │           └── assets/     # Images, logos
│   ├── logs/
│   │   └── audit.log           # Audit trail (JSON-Lines)
│   ├── tests/
│   │   ├── bootstrap.php       # Test setup
│   │   ├── Unit/               # Unit tests
│   │   │   ├── Services/       # Service tests
│   │   │   ├── Models/
│   │   │   ├── Controllers/
│   │   │   ├── Validators/
│   │   │   └── Utils/
│   │   └── Integration/        # Integration tests (with DB)
│   ├── scripts/
│   │   ├── hash_password.php   # Generate password hash for admin
│   │   └── generate_secret.php # Generate secrets
│   ├── docker/                 # Docker configuration
│   │   ├── apache/
│   │   │   ├── default.conf    # Apache VirtualHost
│   │   │   └── php.ini         # PHP settings
│   │   ├── php/
│   │   │   └── Dockerfile      # PHP image
│   │   └── test/
│   │       └── Dockerfile      # Test environment
│   ├── data/                   # SQL dumps, initial data
│   │   └── schema.sql          # Initial schema
│   ├── composer.json           # PHP dependencies
│   ├── phpunit.xml             # PHPUnit configuration
│   └── PDF_SETUP.md            # PDF system documentation
│
├── wordpress-plugin/           # WordPress integration
│   ├── ondisos.php            # Main plugin file
│   ├── includes/
│   │   ├── class-plugin.php           # Main orchestrator
│   │   ├── class-shortcode.php        # [ondisos-form] shortcode
│   │   ├── class-ajax-handler.php     # AJAX submission
│   │   ├── class-pdf-proxy.php        # PDF proxy
│   │   ├── class-settings.php         # Admin settings page
│   │   ├── class-assets.php           # Enqueue CSS/JS
│   │   └── class-autoloader.php       # PSR-4 autoloading
│   ├── assets/
│   │   ├── css/
│   │   │   └── ondisos.css    # Plugin styling
│   │   └── js/
│   │       └── ondisos.js     # Plugin JavaScript
│   ├── uninstall.php           # Cleanup on plugin removal
│   └── readme.txt              # Plugin documentation
│
├── database/                   # Database-related files
│   └── migrations/             # Future: schema versioning
│       └── schema.sql          # Initial database schema
│
├── docker-compose.yml          # Development setup
├── docker-compose.prod.yml     # Production overrides
├── .env.example                # Example configuration
├── .env                        # Configuration (gitignored)
├── README.md                   # Project documentation
├── DEPLOYMENT.md               # Deployment guide
├── DOCKER.md                   # Docker documentation
├── CI_CD.md                    # CI/CD pipeline docs
├── DISASTER_RECOVERY.md        # Emergency recovery procedures
└── CLAUDE.md                   # Project instructions (this file)
```

---

## Directory Purposes

### Frontend Directories

**`frontend/public/`:**
- Purpose: Web-accessible entry points
- Contains: HTML rendering, form submission handlers, API proxies
- Key files: `index.php` (form display), `save.php` (submit), `pdf/download.php` (proxy)

**`frontend/src/Config/`:**
- Purpose: Configuration loading
- Contains: FormConfig class that loads survey JSON, theme, backend URL
- Key responsibility: Single source of truth for which surveys are available

**`frontend/src/Services/`:**
- Purpose: Business logic for form submission
- Contains: AnmeldungService (orchestration), BackendApiClient (HTTP), EmailService, MessageService
- Key pattern: Services receive dependencies, return structured results

**`frontend/src/Utils/`:**
- Purpose: Reusable utilities
- Contains: CsrfProtection (session-based token management)
- Key responsibility: Cross-request state (tokens)

**`frontend/config/`:**
- Purpose: Application-level configuration
- Contains: forms-config.php (form definitions), messages.php (UI text)
- Key pattern: Committed files + gitignored .local.php overrides

**`frontend/surveys/`:**
- Purpose: SurveyJS form definitions
- Contains: *.json files (bs.json, bk.json), theme JSON
- Key responsibility: Form structure and appearance (no logic)

### Backend Directories

**`backend/public/`:**
- Purpose: Admin interface and APIs
- Contains: MVC endpoints (controllers dispatch to services)
- Key pattern: Each .php file is one route/handler

**`backend/src/Config/`:**
- Purpose: Configuration and setup
- Contains: Database connection, environment loader, form configuration
- Key responsibility: Singleton Database class ensures connection pooling

**`backend/src/Controllers/`:**
- Purpose: HTTP request handling
- Contains: Extract params, delegate to services, prepare view data
- Key pattern: Thin controllers (validation + delegation)

**`backend/src/Models/`:**
- Purpose: Data structures
- Contains: Readonly classes (Anmeldung, CompleteAnmeldung), Status enum
- Key pattern: Type-safe, immutable after construction

**`backend/src/Repositories/`:**
- Purpose: Database access abstraction
- Contains: Query building, prepared statements, Model instantiation
- Key pattern: All DB queries in one place, facilitates testing

**`backend/src/Services/`:**
- Purpose: Business logic and orchestration
- Contains: Pagination, export, PDF generation, virus scanning, auditing, rate limiting
- Key pattern: Services compose other services and repositories

**`backend/src/Validators/`:**
- Purpose: Input validation rules
- Contains: Form name validation, SQL injection checks (defense in depth)
- Key responsibility: Reusable validation across layers

**`backend/src/Utils/`:**
- Purpose: Utility functions and helpers
- Contains: Null-safe rendering (NullableHelpers), date/number formatting
- Key responsibility: Avoid duplication in controllers/views

**`backend/inc/`:**
- Purpose: Bootstrap and global includes
- Contains: Autoloader, error handlers, auth/CSRF helpers
- Key files: `bootstrap.php` (core setup), `auth.php` (session management), `csrf.php` (CSRF helpers)

**`backend/config/`:**
- Purpose: Application-level text and configuration
- Contains: messages.php (committed UI text), messages.local.php (gitignored overrides)
- Key pattern: MessageService loads both files, deep-merges at runtime

**`backend/templates/pdf/`:**
- Purpose: PDF template system
- Contains: PHP templates (base.php, sections/header.php, data-table.php, footer.php)
- Key pattern: PdfTemplateRenderer passes data to these templates

**`backend/logs/`:**
- Purpose: Audit trail storage
- Contains: audit.log (JSON-Lines format, one JSON per line)
- Key responsibility: Immutable append-only log of all admin actions

**`backend/tests/`:**
- Purpose: Unit and integration tests
- Contains: Test classes mirroring src/ structure
- Key pattern: Tests follow same namespace paths as code

### WordPress Plugin Directories

**`wordpress-plugin/includes/`:**
- Purpose: Plugin classes and logic
- Contains: Singleton Plugin class, Shortcode handler, AJAX handler, Settings page
- Key pattern: Each class has single responsibility

**`wordpress-plugin/assets/`:**
- Purpose: CSS and JavaScript
- Contains: Plugin-specific styling and scripts
- Key responsibility: Enqueued via Assets class (respects WordPress standards)

---

## Key File Locations

### Entry Points

**Frontend User-Facing:**
- `frontend/public/index.php`: Load and display form
- `frontend/public/save.php`: Handle form submission
- `frontend/public/ical.php`: Download event (optional)

**Backend Admin:**
- `backend/public/index.php`: List view (pagination, filtering)
- `backend/public/detail.php`: Single submission detail
- `backend/public/dashboard.php`: Statistics and tools
- `backend/public/trash.php`: Deleted submissions

**Backend API (called by frontend):**
- `backend/public/api/submit.php`: Form submission endpoint
- `backend/public/api/upload.php`: File upload endpoint

**PDF:**
- `frontend/public/pdf/download.php`: Frontend proxy
- `backend/public/pdf/download.php`: Backend generator

### Configuration

**Frontend:**
- `frontend/src/Config/FormConfig.php`: Form definitions, survey JSON paths, PDF config
- `frontend/config/forms-config.php`: Form key → survey mapping
- `frontend/config/messages.php`: UI messages
- `frontend/.env`: Backend URL, email config (if used)

**Backend:**
- `backend/src/Config/Config.php`: Application constants
- `backend/src/Config/Database.php`: MySQL connection singleton
- `backend/src/Config/FormConfig.php`: Form configuration (same as frontend)
- `backend/config/messages.php`: UI messages
- `backend/.env`: DB credentials, secrets, feature flags

**Shared:**
- `.env` (project root): Single source of truth for credentials (maps to both frontend/.env and backend/.env)

### Core Logic

**Form Submission (Frontend):**
- `frontend/src/Services/AnmeldungService.php`: Orchestrates submission process
- `frontend/src/Services/BackendApiClient.php`: HTTP client for backend API
- `frontend/public/js/survey-handler.js`: JavaScript form integration

**Form Submission (Backend):**
- `backend/public/api/submit.php`: Entry point for frontend API
- `backend/src/Repositories/AnmeldungRepository.php`: Insert records
- `backend/src/Services/VirusScanService.php`: Malware check

**List Management:**
- `backend/src/Controllers/AnmeldungController.php`: List page logic
- `backend/src/Services/AnmeldungService.php`: Pagination/filtering
- `backend/src/Repositories/AnmeldungRepository.php`: Data queries

**Export:**
- `backend/public/excel_export.php`: Entry point
- `backend/src/Services/ExportService.php`: Format and prepare data
- `backend/src/Services/SpreadsheetBuilder.php`: XLSX generation

**PDF Generation:**
- `backend/public/pdf/download.php`: Token validation, PDF generation
- `backend/src/Services/PdfGeneratorService.php`: PDF orchestration
- `backend/src/Services/PdfTemplateRenderer.php`: Render PHP templates to HTML
- `backend/src/Services/PdfTokenService.php`: HMAC token validation

**Auto-Cleanup:**
- `backend/src/Services/ExpungeService.php`: Soft-delete → hard-delete
- `backend/src/Services/RequestExpungeService.php`: Throttle expunge runs

### Testing

**Configuration:**
- `backend/phpunit.xml`: Test framework setup (Bootstrap, Test Suites, Coverage excludes)
- `backend/tests/bootstrap.php`: Test environment initialization

**Test Files:**
- `backend/tests/Unit/Services/RateLimiterTest.php`: Rate limiter tests
- `backend/tests/Unit/Services/PdfTokenServiceTest.php`: Token validation tests
- `backend/tests/Unit/Services/MessageServiceTest.php`: Message resolution tests
- `backend/tests/Unit/Services/VirusScanServiceTest.php`: Malware detection tests

---

## Naming Conventions

### Files

**PHP Classes:**
- Pattern: PascalCase
- Example: `Anmeldung.php`, `AnmeldungRepository.php`, `PdfGeneratorService.php`
- Location follows namespace: `src/Services/ExportService.php` is class `\App\Services\ExportService`

**PHP Views:**
- Pattern: kebab-case (some lowercase files in public/)
- Example: `index.php`, `detail.php`, `bulk_actions.php`, `csrf_token.php`

**Configuration Files:**
- Pattern: kebab-case or PascalCase
- Example: `forms-config.php`, `messages.php`, `messages.local.php`

**Survey JSON:**
- Pattern: kebab-case form identifier
- Example: `bs.json`, `bk.json`, `survey_theme.json`

**Test Files:**
- Pattern: PascalCase + Test suffix
- Example: `RateLimiterTest.php`, `PdfTokenServiceTest.php`

### Directories

**Source Code:**
- Pattern: PascalCase
- Example: `Config`, `Services`, `Models`, `Repositories`, `Controllers`

**Public/Entry Points:**
- Pattern: lowercase, sometimes kebab-case
- Example: `public`, `api`, `pdf`, `templates`

**Utilities:**
- Pattern: PascalCase or kebab-case
- Example: `Utils`, `src/Utils/`, `uploads/`

### PHP Naming

**Classes:**
- Pattern: PascalCase
- Example: `AnmeldungService`, `PdfGeneratorService`, `RateLimiter`

**Methods:**
- Pattern: camelCase
- Example: `processSubmission()`, `getPaginatedAnmeldungen()`, `isAllowed()`

**Constants:**
- Pattern: UPPER_SNAKE_CASE
- Example: `ALLOWED_SORT_COLUMNS`, `RATE_LIMIT_MAX`, `TOKEN_LIFETIME`

**Variables:**
- Pattern: camelCase
- Example: `$surveyData`, `$anmeldungId`, `$pdfConfig`

**Namespaces:**
- Frontend: `Frontend\*` (e.g., `Frontend\Services\AnmeldungService`)
- Backend: `App\*` (e.g., `App\Models\Anmeldung`)
- WordPress: `Ondisos\*` (e.g., `Ondisos\Plugin`)

---

## Where to Add New Code

### New Feature

**Primary code location:**
- Service: `backend/src/Services/{FeatureName}Service.php`
- If needs data: `backend/src/Repositories/AnmeldungRepository.php` (add method if needed)
- If needs models: `backend/src/Models/{NewModel}.php`

**Tests location:**
- `backend/tests/Unit/Services/{FeatureName}ServiceTest.php`
- Follow test pattern from existing tests (use setUp(), descriptive names)

**Entry point:**
- `backend/public/{feature_name}.php` if admin-facing
- `backend/public/api/{feature_name}.php` if API
- Use same pattern: require bootstrap, inject dependencies, call controller/service, extract view data

**Frontend (if form submission):**
- `frontend/src/Services/AnmeldungService.php` (add submission handling)
- `frontend/public/save.php` (already orchestrates submission)

### New Component/Module

**Implementation location:**
- Service class: `backend/src/Services/{ModuleName}Service.php`
- Models: `backend/src/Models/{EntityName}.php` + `{EntityName}Status.php` (if enum)
- Repository: `backend/src/Repositories/{EntityName}Repository.php`
- Validator: `backend/src/Validators/{EntityName}Validator.php`
- Controller: `backend/src/Controllers/{EntityName}Controller.php` (if MVC route)

**Views (if needed):**
- `backend/public/{entity_name}/{action}.php` (e.g., `users/create.php`, `users/list.php`)
- Follow same pattern as index.php (require bootstrap + auth, instantiate dependencies, extract view data)

**Tests:**
- `backend/tests/Unit/{EntityName}/` directory
- Include unit tests for service, repository, validator, and model

### Utilities

**Shared helpers:**
- `backend/src/Utils/{HelperName}.php` (static class or utility functions)
- Example: `NullableHelpers.php` for rendering null-safe values

**Middleware-like functions:**
- `backend/inc/{middleware_name}.php` (if used in multiple endpoints)
- Example: `auth.php`, `csrf.php`

**Frontend utilities:**
- `frontend/src/Utils/{HelperName}.php` (same pattern)

---

## Special Directories

### `backend/cache/`

**Purpose:** File-based caching
- Generated: Yes (at runtime)
- Committed: No (gitignored)
- Contents:
  - `ratelimit/`: Rate limiter sliding window state files
  - `last_expunge.txt`: Timestamp of last auto-expunge run (throttling)

### `backend/logs/`

**Purpose:** Application logging
- Generated: Yes (at runtime)
- Committed: No (gitignored)
- Contents:
  - `audit.log`: JSON-Lines audit trail (login, status changes, bulk actions, uploads, virus scans)

### `backend/uploads/`

**Purpose:** User-uploaded files
- Generated: Yes (at upload)
- Committed: No (gitignored)
- Contents: Temporary files from form submissions (scanned by ClamAV)

### `frontend/public/uploads/`

**Purpose:** Temporary frontend uploads
- Generated: Yes (at upload)
- Committed: No (gitignored)
- Contents: Temporary files before sending to backend

### `backend/coverage/` & `backend/.phpunit.cache/`

**Purpose:** PHPUnit testing artifacts
- Generated: Yes (during test runs)
- Committed: No (gitignored)
- Contents: Code coverage reports, test caches

### `docker/`

**Purpose:** Docker-specific configuration
- Generated: No
- Committed: Yes
- Contents:
  - `apache/`: Apache VirtualHost config, PHP settings
  - `php/`: PHP Dockerfile
  - `test/`: Test environment Dockerfile

### `database/migrations/`

**Purpose:** Database schema versioning (future)
- Generated: No
- Committed: Yes
- Current contents: schema.sql (initial schema)
- Future: Migration scripts for schema updates

---

## File Organization Patterns

### Controllers

Each controller handles one responsibility:
- `AnmeldungController.php`: List page (pagination, filtering)
- `DetailController.php`: Single submission detail
- `BulkActionsController.php`: Multi-item operations

Controllers are thin: extract params → call service → return view data

### Services

Each service encapsulates one domain or technical concern:
- Domain: `AnmeldungService` (submission operations), `StatusService` (status logic), `ExportService` (export logic)
- Technical: `PdfGeneratorService`, `VirusScanService`, `RateLimiter`, `AuditLogger`, `MessageService`

Services receive dependencies (repositories, config, other services) via constructor injection.

### Repositories

All database queries in one place:
- `AnmeldungRepository.php`: All queries on `anmeldungen` table
- Methods are CRUD operations + query helpers
- Return Model objects (not raw arrays)

### Models

Immutable value objects:
- `Anmeldung.php`: Readonly class, direct from DB (allows NULLs)
- `CompleteAnmeldung.php`: Readonly class, strict (no NULLs)
- `AnmeldungStatus.php`: Enum with helper methods (label(), badgeClass())

### Public Endpoints

Each .php file in public/ is a route handler:
1. Require bootstrap (autoloader, config, error handling)
2. Require auth (if needed)
3. Instantiate dependencies (Repository, Service, Controller)
4. Call controller method or service directly
5. Extract view data into scope
6. Include view template (inc/header.php, HTML, inc/footer.php)

---

*Structure analysis: 2026-03-13*

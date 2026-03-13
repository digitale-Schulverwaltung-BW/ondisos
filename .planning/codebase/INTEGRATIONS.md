# External Integrations

**Analysis Date:** 2026-03-13

## APIs & External Services

**OpenStreetMap Nominatim (Geocoding):**
- Service: Nominatim reverse geocoding API for determining district/suburb
- What it's used for: Auto-fill "Teilort" (district) field during Excel export based on address
- SDK/Client: cURL (PHP built-in)
- Implementation: `backend/src/Services/NominatimService.php`
- Auth: None (but requires contact email/URL in User-Agent per Nominatim ToS)
- Env var: `NOMINATIM_CONTACT` (contact email or website URL)
- Activation: Set `NOMINATIM_CONTACT` in `backend/.env`; feature disabled if unset
- Rate limit: 1 request/second (enforced internally via `usleep()`)
- Request pattern: HTTPS POST to `https://nominatim.openstreetmap.org/search` with address parameters
- Response: JSON with address details including `suburb` or `quarter`

**Backend-to-Backend HTTP API (Frontend → Backend):**
- Service: Internal API for form submission and file upload
- What it's used for: Frontend submissions POST to backend API endpoints
- SDK/Client: cURL (PHP built-in)
- Implementation: `frontend/src/Services/BackendApiClient.php`
- Auth: None (CORS-based origin validation only)
- Endpoints:
  - `{BACKEND_API_URL}/submit.php` - POST form data + files
  - `{BACKEND_API_URL}/upload.php` - POST multipart file uploads
  - `{BACKEND_API_URL}/health.php` - GET health check (3s timeout)
- Env var: `BACKEND_API_URL` (frontend .env)
- Request pattern: JSON payload with form data, metadata, PDF config
- Response: JSON with success status, submission ID, PDF download token (if enabled)
- Error handling: Logs HTTP status codes, retries not implemented (single attempt)

## Data Storage

**Databases:**
- Provider: MySQL 8.0 (or MariaDB 10.5+)
- Connection: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` (root .env)
- Client: mysqli (PHP built-in, prepared statements only)
- Connection management: Singleton pattern in `backend/src/Config/Database.php`
- Charset: UTF-8mb4 (supports emoji, German umlauts)
- Primary table: `anmeldungen` with soft-delete support
  - Columns: id, formular, formular_version, name, email, status, data (JSON), created_at, updated_at, deleted, deleted_at
  - Indexes: formular, email, created_at
- Schema initialization: `database/schema.sql` (mounted at `/docker-entrypoint-initdb.d/01-schema.sql`)

**File Storage:**
- Local filesystem only (no S3, no CDN)
- Upload directory: `backend/uploads/` (persisted volume, must be shared across instances)
- Cache directory: `backend/cache/` (transient files, thread-safe LOCK_EX locking)
- PDF generation: On-demand, not persisted (streamed to user, no storage)
- Audit log: `backend/logs/audit.log` (JSON-Lines format, auto-rotated every 6h)
- School lookup CSV: `backend/data/schulen.csv` (optional, gitignored, local file only)

**Caching:**
- Type: File-based (no Redis, no Memcached)
- Use cases:
  - Rate limiter state: `backend/cache/rate_limiter_*.txt` (per IP/identifier)
  - Last auto-expunge timestamp: `backend/cache/last_expunge.txt`
  - Nominatim address geocoding: In-memory during export (not persisted)
- Implementation: Direct file I/O with `fopen()`, `flock()` (LOCK_EX) for thread safety
- Cleanup: Probabilistic (on each write, 1% chance of cleanup cycle)

## Authentication & Identity

**Auth Provider:**
- Type: Custom (optional)
- Implementation: Session-based login system in `backend/public/login.php`
- Activation: `AUTH_ENABLED=true` in `backend/.env`
- Mechanism:
  - Username/password (`ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH`)
  - Password hashing: `password_hash()` with PASSWORD_DEFAULT (bcrypt)
  - Session management: PHP built-in `$_SESSION`
  - Session lifetime: `SESSION_LIFETIME` (seconds, default 3600)
  - Secure cookies: `SESSION_SECURE=true` (HTTPS only, recommended for production)
  - CSRF protection: Token-based on login form
- Brute-force protection: 0.5-second delay after failed login
- API endpoints: Remain public (no session requirement for submit.php, upload.php)

## Monitoring & Observability

**Error Tracking:**
- Type: None (no Sentry, no external service)
- Logging: PHP error log (configured in Dockerfile via `php.ini`)
- Approach: `error_log()` calls throughout codebase for debugging

**Logs:**
- Approach: Custom JSON-Lines format (one JSON object per line)
- Files:
  - Application errors: Captured by PHP (docker logs)
  - Audit trail: `backend/logs/audit.log` (login, status changes, bulk actions, uploads, virus scans)
- Implementation: `backend/src/Services/AuditLogger.php` (static class, thread-safe file locking)
- Retention: `AUDIT_LOG_RETENTION_DAYS` (auto-rotation every 6h, default 90 days)
- Events logged:
  - login_success, login_failed, logout
  - status_changed (tracking status transitions)
  - bulk_archive, bulk_delete, bulk_restore, bulk_hard_delete
  - upload_success, virus_found
  - export_run (Excel exports)
- IP detection: Reads `HTTP_X_FORWARDED_FOR` (reverse-proxy aware) or `REMOTE_ADDR`

## CI/CD & Deployment

**Hosting:**
- Primary: Docker Compose (orchestrated containers)
- Alternative: Manual Apache/Nginx + PHP + MySQL (fully supported)
- Reverse proxy (production): Nginx or Apache with Let's Encrypt SSL

**CI Pipeline:**
- Platform: GitLab CI/CD
- Pipeline file: `.gitlab-ci.yml` (at repository root)
- Stages: install → test → coverage → security
- Tests: PHPUnit (backend/tests/) with JUnit reporting
- Coverage: Xdebug-based HTML reports (artifact)
- Linting: PHP syntax checks (php -l)
- Security: GitLab Secret Detection, SAST

**Deployment:**
- Docker containers: `ondisos-backend`, `ondisos-mysql`, `ondisos-clamav` (optional)
- Persistence: Docker volumes (named: `mysql-data`, `backend-uploads`, `backend-cache`, `backend-logs`, `clamav-data`)
- Restart policy: `restart: unless-stopped` (auto-recovery after reboot)
- Environment: Loaded from root `.env` file (mapped to container env vars)
- Health checks: MySQL uses `mysqladmin ping` (5s interval, 10 retries)

## Environment Configuration

**Required env vars:**
- `DB_HOST` - MySQL hostname (docker: "mysql", manual: "localhost" or IP)
- `DB_PORT` - MySQL port (default 3306)
- `DB_NAME` - Database name (default "anmeldung")
- `DB_USER` - MySQL user (default "anmeldung")
- `DB_PASS` - MySQL password (change in production!)
- `PDF_TOKEN_SECRET` - Min 32 chars, generated via `openssl rand -hex 32`
- `ALLOWED_ORIGINS` - CORS whitelist (comma-separated frontend URLs)

**Optional env vars (backend/.env):**
- `AUTH_ENABLED` - Enable admin login (true/false, default false)
- `ADMIN_PASSWORD_HASH` - Bcrypt hash of admin password (required if AUTH_ENABLED=true)
- `VIRUS_SCAN_ENABLED` - Enable ClamAV scanning (true/false, default false)
- `CLAMAV_HOST` - ClamAV hostname (docker: "clamav", manual: IP/hostname)
- `CLAMAV_PORT` - ClamAV clamd port (default 3310)
- `NOMINATIM_CONTACT` - Contact email/URL for Nominatim (empty = feature disabled)
- `SCHOOL_LOOKUP_CSV` - Path to schulen.csv file for fuzzy school matching
- `AUTO_EXPUNGE_DAYS` - Auto-delete archived entries after N days (0 = disabled)
- `RATE_LIMIT_MAX` - Max requests per window (default 10)
- `RATE_LIMIT_WINDOW` - Time window in seconds (default 60)

**Secrets location:**
- Root `.env` - Database credentials, PDF_TOKEN_SECRET (single source of truth)
- Docker secrets: Passed via env_file or Docker secrets manager
- Never committed: `.env` and `.env.local` files are in `.gitignore`
- File permissions: `chmod 600 .env` recommended

## Webhooks & Callbacks

**Incoming:**
- None implemented (frontend doesn't accept webhooks from external services)

**Outgoing:**
- Email notifications: `frontend/src/Services/EmailService.php`
  - SMTP: None (uses PHP `mail()` function, delegates to system MTA)
  - Recipients: Configured per-form in `frontend/config/forms-config.php` (`notify_email` field)
  - Events: Form submission triggers email notification to admin
  - Format: MIME multipart (plain text + HTML, base64 + quoted-printable encoding)
  - Headers: RFC 2047 compliant (handles German umlauts in subject)
- PDF download token: Self-contained (HMAC-based, no external callback)
  - Token validity: 30 minutes (configurable per-form)
  - Token format: Base64(id:timestamp:lifetime:hmac-sha256)
  - Validation: Self-validating, no database lookup needed

---

*Integration audit: 2026-03-13*

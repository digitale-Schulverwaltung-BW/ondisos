# Technology Stack

**Analysis Date:** 2026-03-13

## Languages

**Primary:**
- PHP 8.2+ - Backend (admin interface, APIs), Frontend (form handling, email)
- JavaScript (Vanilla) - Frontend form interaction via SurveyJS
- HTML5/CSS3 (Bootstrap 5) - Frontend UI framework

**Secondary:**
- SQL - MySQL database queries via mysqli prepared statements
- JSON - API communication, form configuration, data serialization

## Runtime

**Environment:**
- PHP 8.2 (declared via `php: ">=8.2"` in `backend/composer.json`)
- Apache 2.4 (configured in both frontend and backend Dockerfiles)
- MySQL 8.0 (Docker image: `mysql:8.0`, supports MariaDB 10.5+)
- Node.js - Not used (no npm/yarn dependencies)

**Package Manager:**
- Composer 2.x - PHP dependency management
- Lockfile: `backend/composer.lock` (present, commited)

## Frameworks

**Core:**
- SurveyJS (JavaScript) - Form builder and renderer for dynamic forms
- No traditional PHP framework (clean custom MVC architecture)
- Bootstrap 5 - CSS framework for responsive UI

**Testing:**
- PHPUnit 10.5 - Unit and integration test framework
- Config: `backend/phpunit.xml`

**Build/Dev:**
- Docker / Docker Compose - Containerization and local development
  - `docker-compose.yml` - Development environment
  - `docker-compose.prod.yml` - Production overrides
  - `docker-compose.test.yml` - Testing (backend only)

## Key Dependencies

**Critical:**
- `mpdf/mpdf` ^8.2 - PDF generation (on-demand, no storage)
  - Used by: `PdfGeneratorService`, `PdfTemplateRenderer` in `backend/src/Services/`
  - Why it matters: Powers the PDF confirmation download system

- `phpoffice/phpspreadsheet` ^3.0 - Excel export
  - Used by: `SpreadsheetBuilder` in `backend/src/Services/ExportService.php`
  - Why it matters: Generates Excel reports with formatting, zebra-striping, frozen headers

**Infrastructure:**
- mysqli (PHP built-in, enabled via `docker-php-ext-install mysqli`)
  - Database access layer via prepared statements
  - Location: `backend/src/Config/Database.php` (singleton connection management)

- cURL (PHP built-in, enabled via `docker-php-ext-install pcntl`)
  - External API calls (OpenStreetMap Nominatim, backend-to-backend HTTP)
  - Used by: `NominatimService`, `BackendApiClient`, `VirusScanService`

- sockets (PHP built-in `fsockopen`)
  - TCP socket communication with ClamAV virus scanner
  - Used by: `VirusScanService` in `backend/src/Services/VirusScanService.php`

## Configuration

**Environment:**
- Configuration via `.env` files (root `.env.example`, `backend/.env.example`, `frontend/.env.example`)
- Loaded via: `EnvLoader::get()` in `backend/src/Config/EnvLoader.php`, `frontend/src/Config/FormConfig.php`
- Single source of truth: Root `.env` for database credentials (mapped to `MYSQL_*` variables for Docker)
- Backend-specific overrides: `backend/.env` (optional, only non-core settings)

**Key configs required:**
- Database: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- Secrets: `PDF_TOKEN_SECRET` (min 32 chars, HMAC-based), `API_SECRET_KEY`
- Features: `AUTO_EXPUNGE_DAYS`, `VIRUS_SCAN_ENABLED`, `AUTH_ENABLED`, `NOMINATIM_CONTACT`
- Email: `FROM_EMAIL`, `MAIL_HEAD`, `MAIL_FOOT`
- API: `BACKEND_API_URL` (frontend → backend), `ALLOWED_ORIGINS` (CORS)

**Build:**
- `docker-compose.yml` - Services: backend, frontend, mysql, clamav (optional), phpmyadmin (dev only)
- `backend/Dockerfile` - PHP 8.2-apache with Composer, mPDF, PHPSpreadsheet
- `frontend/Dockerfile` - PHP 8.2-apache with curl support
- Apache `.htaccess` templates - HTTPS redirect, security headers, GZIP compression

## Platform Requirements

**Development:**
- Docker & Docker Compose (v2+)
- 4GB RAM minimum (MySQL + PHP containers)
- ~1GB disk space (MySQL volume for test data)

**Production:**
- Deployment target: Docker containers (recommended) OR manual Apache/PHP/MySQL servers
- Docker Compose for orchestration (`docker-compose.yml` + `docker-compose.prod.yml`)
- Reverse proxy: Nginx or Apache (for HTTPS, load balancing)
- MySQL 8.0+ server (can be Docker or external)
- ClamAV daemon (optional, for virus scanning): `clamav/clamav:stable` Docker image
  - Freshclam updates signatures automatically every 2h
  - First startup: ~300MB download, 60-90 seconds initialization

**Scaling considerations:**
- Stateless PHP design (can run multiple instances behind load balancer)
- File uploads stored in `backend/uploads/` volume (must be shared if scaled)
- Cache files in `backend/cache/` volume (per-instance, thread-safe locking)
- Audit log: `backend/logs/audit.log` (JSON-Lines format, auto-rotated every 6h)
- Database: Shared MySQL instance (connection pooling via mysqli singleton)

---

*Stack analysis: 2026-03-13*

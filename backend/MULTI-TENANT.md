# Multi-tenant ondisos

The next milestone (v3.0.0) is to make ondisos a multi-tenant capable system. This involves major changes in the backend while maintaining minor impact on the frontend part. At the same time, ondisos should keep its current footprint for single-tenant use with no major administrative overhead and changes.

Multi-tenant operation can occur in two settings: first, several frontend servers (hosted for one school each) will feed their data into a single multi-tenant backend. The second setting is to simplify deployment for several schools in one municipality, a managed multi-tenant frontend to the multi-tenant backend.

## Architectural Decisions

The following decisions were made during the architectural review (2026-03-13):

### Database Strategy: Shared Schema with Always-Present Tenant Table

The tenant schema is **always created**, even in single-tenant mode. A default tenant (id=1) is seeded automatically. This provides:

- Consistent data model across all deployments
- Zero-friction upgrade path from single-tenant to multi-tenant (flip the `MULTI_TENANT_ENABLED` flag)
- No conditional schema logic — all queries always include `tenant_id`

**New tables:**

```sql
CREATE TABLE tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    api_secret VARCHAR(255) NOT NULL,       -- HMAC shared secret per tenant
    enabled BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE tenant_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_platform_admin BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (tenant_id, username),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE form_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    form_key VARCHAR(100) NOT NULL,
    config_json LONGTEXT NOT NULL,           -- Full form config (replaces forms-config.php)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (tenant_id, form_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
```

**Migration for existing `anmeldungen` table:**

```sql
ALTER TABLE anmeldungen ADD COLUMN tenant_id INT NOT NULL DEFAULT 1;
ALTER TABLE anmeldungen ADD INDEX idx_tenant (tenant_id);
ALTER TABLE anmeldungen ADD INDEX idx_tenant_formular (tenant_id, formular);
ALTER TABLE anmeldungen ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
```

### Frontend Scope: Scenario A First

**v3.0.0** targets Scenario A: multiple single-school frontends feeding into one multi-tenant backend. Each frontend knows its tenant ID and includes it in API calls. No tenant-switching UI is needed in the frontend.

Scenario B (managed multi-tenant frontend) is deferred to **v3.0.5** (see Technical Debt section).

### API Security: Per-Tenant HMAC Shared Secret

Each tenant receives an `api_secret` stored in the `tenants` table. The frontend signs API requests using HMAC-SHA256, consistent with the existing PDF token pattern. This is sufficient for the intranet/school deployment context.

### Form Config: DB Storage with Seed Scripts (v3.0), Admin UI (v3.1)

Form configurations move from `forms-config.php` to the `form_configs` database table. In v3.0, configs are managed via migration/seed scripts. A full CRUD admin UI for form configs is deferred to **v3.1.0** (aligned with the existing v3.1 plan for survey file upload).

### Tenant Admin Management: Backend UI

The platform admin creates and manages tenants and tenant admins through the backend web interface. This is essential for onboarding new schools. Tenant admins log in and see only their own data.

### Testing: Tests Alongside Features

Each v3.0 feature gets tests as it's built (TDD or test-with approach). New tenant-aware services, repository methods, and API endpoints are covered by unit and integration tests as they are implemented.

### Audit Trail: Single File with Tenant ID

The existing `audit.log` (JSON-Lines) remains a single file. Each entry gains a `tenant_id` field. This keeps the architecture simple and grep-friendly.

### Tenant Context Propagation

A `TenantContext` class (request-scoped singleton, similar to `Config`) is initialized once per request from the tenant parameter (API) or session (admin). All services and repositories read from `TenantContext::getTenantId()` rather than passing tenant IDs through every method signature.

---

## Backend

Multi-tenant operation shall be enabled by a boolean in the backend `.env` file. If enabled, an administrative password must be set in the `.env` which enables a login page where both the platform administrator and the tenant admins can log into the backend.

On first login, the platform admin will create an initial tenant and can add more later on. Full CRUD functionality for tenants will be required.

### Authentication & Roles

- **Platform admin**: Credentials set in `.env` (`PLATFORM_ADMIN_USERNAME`, `PLATFORM_ADMIN_PASSWORD_HASH`). Can manage all tenants, create tenant admins, see all data.
- **Tenant admin**: Credentials stored in `tenant_admins` table. Created by platform admin via backend UI. Can only access their own tenant's data.
- **Session**: Stores `admin_id`, `is_platform_admin`, and `allowed_tenant_ids`. All data access is scoped accordingly.

### Repository Layer Changes

All `AnmeldungRepository` methods (~15+) gain `WHERE tenant_id = ?` filtering. In single-tenant mode this is always `1` (transparent). The `TenantContext` provides the value.

### File Upload Isolation

Uploads move to tenant-scoped directories:

```
backend/uploads/
├── tenant-1/
│   ├── 123_document.pdf
│   └── 124_passport.jpg
├── tenant-5/
│   └── 200_certificate.pdf
```

### Environment Configuration

```bash
# New .env additions
MULTI_TENANT_ENABLED=false          # Enable multi-tenant mode
PLATFORM_ADMIN_USERNAME=admin       # Platform admin (only used when multi-tenant)
PLATFORM_ADMIN_PASSWORD_HASH=...    # Platform admin password hash
```

## Frontend

For the frontend, the multi-tenant operation will incorporate an additional parameter that specifies the tenant. This can be a simple, human-readable numeric id which enriches the WordPress shortcode:
```
[ondisos form="bs"]
```
will become
```
[ondisos form="bs" tenant=5]
```
For the standalone version, this will become `index.php?form=bs&tenant=5`. The `frontend/surveys` directory will get subdirectories for all tenants so that conflicting file names on a single frontend server will be avoided.

### Form config per tenant

The `frontend/config/forms-config.php` will be replaced by a backend form configuration for a more user-friendly operation. All forms belonging to a tenant can be configured in the tenant-backend and persisted into the database.

When the frontend accesses a form, it will contact the backend (which can then replace the `$health = (new BackendApiClient())->healthCheck();`) and get the form config for the requested form.

v3.1.0 will present a mechanism to upload the json survey files from the backend to the frontend, making the frontend a zero-maintenance zone for tenant admins. As an alternative approach, the form config can be pulled from the backend on form invocation. This should be considered carefully since this will increase network overhead for a form request.

### Security

Each tenant has a unique `api_secret` (HMAC-SHA256 shared secret). The frontend signs API submissions with the tenant's secret. The backend validates the signature and rejects unauthorized requests.

In single-tenant mode, the default tenant's `api_secret` serves as the shared secret (functionally equivalent to the current `API_SECRET_KEY`).

## Technical Debt / Deferred Items

### v3.0.5 — Managed Multi-Tenant Frontend (Scenario B)

A single frontend instance serves multiple tenants with tenant selection/routing in the UI. This requires:
- Tenant switching mechanism in the frontend
- Shared secret between the multi-tenant frontend and backend (per-tenant secrets offer no additional security when the frontend is shared)
- Frontend UI for tenant context (login or selection page)

### v3.1.0 — Form Config Admin UI & Survey Upload

- Full CRUD UI for form configurations in the backend admin
- Mechanism to upload/pull JSON survey files from backend to frontend
- Frontend becomes a zero-maintenance zone for tenant admins

## Impact Assessment

### High Impact (Core Changes)
- `AnmeldungRepository` — all queries gain tenant filtering
- `Database` schema — new tables, migration for existing data
- Authentication system — multi-role, multi-tenant session
- `bootstrap.php` — TenantContext initialization
- API endpoints (`submit.php`, `upload.php`) — tenant routing and HMAC validation

### Medium Impact (Adaptation)
- `AnmeldungService`, `ExportService`, `StatusService` — read from TenantContext
- `DetailController` — file access scoped to tenant directory
- `FormConfig` — loads from DB instead of file
- `AuditLogger` — adds tenant_id to all entries
- Upload handling — tenant-scoped directories

### Low Impact (Minimal or No Changes)
- `PdfTokenService` — stateless HMAC, works as-is
- `RateLimiter` — per-IP, tenant-agnostic
- `VirusScanService` — file scanning, tenant-agnostic
- `MessageService` — UI strings, tenant-agnostic
- `PdfGeneratorService` — generates from data, no tenant awareness needed
- Frontend SurveyJS rendering — just adds tenant parameter to API calls

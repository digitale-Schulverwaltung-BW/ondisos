# Ondisos — School Registration System

## What This Is

A web-based school registration system with a SurveyJS frontend for public form submission and a PHP backend for administrative management. Schools deploy a public-facing frontend that collects registrations via configurable survey forms and sends them to an intranet backend where administrators review, export, and manage submissions. Currently at v2.6 with single-tenant operation.

## Core Value

Schools can collect, manage, and process student registrations through a secure, DSGVO-compliant system that requires minimal technical administration.

## Requirements

### Validated

<!-- Shipped and confirmed valuable through v2.6. -->

- ✓ SurveyJS form rendering with configurable surveys — v2.0
- ✓ Frontend-to-backend API submission with CSRF protection — v2.0
- ✓ Admin overview with pagination, filtering, status system — v2.0
- ✓ Excel export with auto-formatting, zebra-striping, frozen headers — v2.0
- ✓ Soft-delete with trash/restore and auto-expunge — v2.0
- ✓ Bulk actions (archive, delete, restore) — v2.0
- ✓ Centralized message management with local overrides — v2.1
- ✓ PDF download with HMAC-based tokens and frontend proxy — v2.2
- ✓ Optional admin authentication with brute-force protection — v2.3
- ✓ Rate limiting (file-based, sliding window) — v2.3
- ✓ HTTPS enforcement — v2.3
- ✓ ClamAV virus scanning for uploads — v2.6
- ✓ Audit trail (JSON-Lines logging) — v2.6
- ✓ Docker production deployment — v2.5
- ✓ PHPUnit test suite (RateLimiter, PdfTokenService, MessageService, VirusScanService) — v2.4/v2.6

### Active

<!-- v3.0.0 scope: Multi-tenant capability -->

- [ ] Multi-tenant database schema (tenants, tenant_admins, form_configs tables)
- [ ] TenantContext request-scoped singleton for tenant propagation
- [ ] Tenant-aware repository queries (all AnmeldungRepository methods)
- [ ] Platform admin and tenant admin roles with scoped access
- [ ] Tenant CRUD management in backend UI
- [ ] Tenant admin management in backend UI
- [ ] Per-tenant HMAC API authentication
- [ ] DB-driven form configuration (replacing forms-config.php)
- [ ] Tenant-scoped file upload directories
- [ ] Frontend tenant parameter support (Scenario A: dedicated frontends)
- [ ] Tenant-aware audit trail (tenant_id in log entries)
- [ ] Database migration from single-tenant to multi-tenant schema
- [ ] Tests alongside all new features

### Out of Scope

<!-- Explicit boundaries for v3.0.0 -->

- Managed multi-tenant frontend (Scenario B) — deferred to v3.0.5, requires tenant-switching UI
- Form config admin UI — deferred to v3.1.0, seed scripts sufficient for v3.0
- Survey file upload from backend to frontend — deferred to v3.1.0
- Per-tenant message customization — not needed, MessageService is tenant-agnostic
- Per-tenant rate limiting — current per-IP approach is sufficient
- Separate databases per tenant — shared schema with tenant_id is simpler and sufficient

## Current Milestone: v3.0.0 Multi-Tenant

**Goal:** Make ondisos a multi-tenant capable system while maintaining zero-overhead single-tenant operation.

**Target features:**
- Tenant isolation at database, file, and API levels
- Platform admin + tenant admin role-based access
- DB-driven form configuration per tenant
- Per-tenant HMAC API security
- Frontend tenant parameter support (Scenario A)

## Context

- Existing codebase is at v2.6 with clean MVC architecture, service layer, and repository pattern
- PHP 8.2+ with strict typing throughout, PSR-4 autoloading
- Current Database.php uses a singleton pattern — works fine for shared-schema multi-tenancy
- AnmeldungRepository has ~15+ methods that all need tenant_id filtering
- Authentication system exists but only supports a single admin via .env credentials
- Frontend and backend are two independent PHP applications communicating via HTTP API
- Deployment via Docker (recommended) or manual Apache/PHP/MySQL
- Codebase map available at `.planning/codebase/`
- Architectural decisions documented in `backend/MULTI-TENANT.md`

## Constraints

- **Backward compatibility**: Single-tenant deployments must work with zero config changes (default tenant_id=1)
- **Tech stack**: PHP 8.2+, MySQL/MariaDB, no new framework dependencies
- **Security**: DSGVO-compliant, tenant data must be strictly isolated
- **Architecture**: Extend existing patterns (services, repository, singleton config) rather than rewrite
- **Testing**: Each feature must include tests (TDD or test-with approach)

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Always-present tenant schema | Consistent data model, zero-friction upgrade path | — Pending |
| Shared DB with tenant_id column | Simpler than isolated DBs, sufficient for school context | — Pending |
| TenantContext singleton | Avoids passing tenant_id through every method signature | — Pending |
| Per-tenant HMAC API secret | Consistent with existing PDF token pattern, sufficient for intranet | — Pending |
| Scenario A first (dedicated frontends) | Simpler, no tenant-switching UI needed | — Pending |
| DB + seed scripts for form config | Keeps v3.0 focused, admin UI deferred to v3.1 | — Pending |
| Platform admin manages tenant admins via UI | Essential for onboarding schools | — Pending |
| Single audit log with tenant_id field | Simple, grep-friendly, matches current architecture | — Pending |

---
*Last updated: 2026-03-13 after milestone v3.0.0 initialization*

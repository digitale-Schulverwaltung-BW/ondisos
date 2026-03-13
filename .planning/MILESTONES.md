# Milestones

## Completed (Pre-GSD)

### v2.0–v2.6 (January–February 2026)

Shipped before GSD adoption. Features validated through production use:

- v2.0: Clean architecture refactor (MVC + services), soft-delete, auto-expunge, Excel export, status system, bulk actions
- v2.1: Centralized message management (MessageService), local override system
- v2.2: PDF download system (HMAC tokens, mPDF, frontend proxy)
- v2.3: Admin authentication, rate limiting, HTTPS enforcement
- v2.4: PHPUnit test suite (RateLimiter, PdfTokenService, MessageService)
- v2.5: Docker production deployment, CI/CD pipeline, disaster recovery
- v2.6: ClamAV virus scanning, audit trail, simplified credentials, Docker optimizations

**Last phase number:** 0 (no GSD phases — numbering starts at 1 for v3.0.0)

## In Progress

### v3.0.0: Multi-Tenant

**Goal:** Make ondisos multi-tenant capable while maintaining zero-overhead single-tenant operation.

**Started:** 2026-03-13

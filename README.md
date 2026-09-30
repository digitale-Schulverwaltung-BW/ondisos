# 🎓 Ondisos - Digital Souveräne Schulanmeldung

> **On**boarding - **Di**gital **S**ouverän und **O**pen **S**ource

Eine moderne, Open Source Lösung für digitale (Schul-)anmeldungen mit professionellem Admin-Backend.

Download der eingegangenen Anmeldungen als Excel-Datei für den Import in [ASV-BW](ASV.md) möglich.

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![License](https://img.shields.io/badge/license-open_source-green)](LICENSE)
[![Status](https://img.shields.io/badge/status-production_ready-brightgreen)](https://github.com)
[![Pipeline Status](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/badges/main/pipeline.svg)](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/commits/main)
[![Coverage](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/badges/main/coverage.svg)](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/commits/main)
---

## 📋 Inhaltsverzeichnis

- [Features](#-features)
- [Screenshots](#-screenshots)
- [Formulare anpassen/erstellen](#-formulare-erstellenanpassen)
- [Quick Start](#-quick-start)
- [Architektur](#-architektur)
- [Systemvoraussetzungen](#-systemvoraussetzungen)
- [Installation](#-installation)
- [Dokumentation](#-dokumentation)
- [Sicherheit](#-sicherheit)
- [Beitragen](#-beitragen)
- [Lizenz](#-lizenz)

---

## ✨ Features

### 👨‍💻 Frontend (Öffentlich)
- **Interaktive Formulare** mit SurveyJS
- **Modernes UI** mit Bootstrap 5
- **Mobile-responsive** Design
- **CSRF-Protection** für sichere Übermittlung
- **PDF-Bestätigung** nach Anmeldung (optional)
- **File-Upload** Support (mit Virenscan)
- **WordPress-Plugin** — Formulare per Shortcode `[ondisos form="…"]` in bestehende Seiten einbetten ([Anleitung](wordpress-plugin/INSTALL.md))
- **DSGVO-konformer Betrieb** möglich (lokale Fonts, keine Google-CDN, saubere Trennung des Backends auf einen Server, der nicht über das Internet erreichbar ist)

### 👩‍💼 Backend (Admin-Bereich)
- **Übersichtliche Verwaltung** aller Anmeldungen
- **Filterung & Suche** mit DataTables
- **Excel-Export** mit Auto-Formatierung. Bei geeigneten Feld-Bezeichnern ist ein direkter Import in [ASV](ASV.md) möglich.
- **Dashboard** mit Statistiken
- **Status-System** (neu, exportiert, in Bearbeitung, akzeptiert, abgelehnt, archiviert)
- **Soft-Delete** mit Papierkorb
- **Bulk-Actions** (Archivieren, Löschen, Wiederherstellen)
- **Optionale Authentifizierung** (session-basiert)
- **Multi-Tenant** — mehrere Schulen auf einer Backend-Instanz, mit Platform-Admin, Tenant-Admins und vollständiger Datenisolierung ([MULTI-TENANT.md](MULTI-TENANT.md))
- **Auto-Expunge** (automatisches Löschen archivierter Einträge)

### ⚙️ Technische Features
- **Clean Architecture** (MVC + Service Layer)
- **Security First** (Prepared Statements, XSS-Protection, Input Validation)
- **PDF-System** mit Token-Authentifizierung
- **Rate Limiting** gegen API-Abuse
- **Signierte API** — Frontend→Backend-Anfragen sind pro Tenant per HMAC-SHA256 authentifiziert
- **Formular-Konfiguration in der Datenbank** — pro Tenant, vom Frontend per API abgerufen
- **Mehrere Formulare** pro Installation
- **Email-Benachrichtigungen** bei neuen Anmeldungen
- **Anpassbare Messages** (zentrale Message-Verwaltung)
- **Konfigurierbar** via `.env`

---

## 📸 Screenshots

### Frontend - Anmeldeformular
![SurveyJS-Formular](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/wikis/uploads/b32a146edec9929f742809cd87546c7c/Formular.png){width=900 height=563}

> Modernes, interaktives Formular mit Validierung und File-Upload

### Backend - Übersicht
![Screenshot der Admin-Übersicht mit DataTables](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/wikis/uploads/6834d5b418cda1b8c5635958d6eaee58/Backend.png){width=900 height=422}
> Übersichtliche Verwaltung aller Anmeldungen mit Filterung und Status

### Backend - Dashboard
![Screenshot des Dashboards mit Statistiken](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/wikis/uploads/c5689efe4273b33d30ccd1cd9ec70d09/Dashboard.png){width=861 height=600}

> Statistiken und Übersicht über alle Anmeldungen

### PDF-Bestätigung
![Screenshot einer generierten PDF-Bestätigung](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/wikis/uploads/510cb1e6bff83f66fdc9b72acbff8e28/PDF.png){width=490 height=600}

> Automatisch generierte PDF-Bestätigung mit Schul-Logo

---

## 📋 Formulare erstellen/anpassen

Die Formulare, welche ondisos verwalten kann, lassen sich (fast) komplett frei entwerfen und anpassen. 
Die einzigen Einschränkungen sind: jedes Formular **muss** eine E-Mail-Adresse und einen Namen erfassen.

### Formular-Designer
SurveyJS, die Engine, welche die Frontend-Formulare bereitstellt, beinhaltet einen Drag-and-Drop-Formular-
Designer. Dieser ist nicht Bestandteil von ondisos, kann aber einfach über die Projektseite unter
https://surveyjs.io/create-free-survey erreicht werden. 

Eine ausführliche Anleitung findet sich in **[SURVEYJS.md](SURVEYJS.md)**.

---

## 🚀 Quick Start

### 1. Repository klonen
Dies muss auf dem Frontend- und dem Backend-System erfolgen!

```bash
git clone https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos.git
cd ondisos
```

### 2. Backend: Docker Setup (Empfohlen)

```bash
# Root .env konfigurieren (Single Source of Truth)
cp .env.example .env

# Secrets generieren (direkt in .env eintragen)
sed -i.bak "s/^PDF_TOKEN_SECRET=.*/PDF_TOKEN_SECRET=$(openssl rand -hex 32)/" .env
sed -i.bak "s/^API_SECRET_KEY=.*/API_SECRET_KEY=$(openssl rand -hex 32)/" .env

nano .env  # DB-Passwörter anpassen, Secrets überprüfen

# Optional: Backend-spezifische Overrides (Rate Limits, Virenscan, ...)
# cp backend/.env.example backend/.env

# Container starten (Backend + MySQL + ClamAV) — die Datenbank-Migration läuft automatisch
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# Formular-Konfiguration einspielen (einmalig)
cp frontend/config/forms-config-dist.php backend/config/forms-config.php
nano backend/config/forms-config.php
docker compose exec backend php seed-forms.php

# Passwort-Hash generieren (optional, wenn AUTH_ENABLED=true)
docker compose exec backend php scripts/generate-password-hash.php "dein-passwort"
```

**Credentials-Struktur:**
- ✅ `/.env` - Alle Core-Credentials (DB_USER, DB_PASS, Secrets)
- ✅ `/backend/.env` - Optional, nur für Backend-Overrides
- ✅ `API_SECRET_KEY` ist das Secret des ersten Tenants; das Frontend signiert damit seine Anfragen

### 3. Frontend Setup

Das Frontend läuft auf dem öffentlichen Server (Apache/Nginx + PHP). Für WordPress: siehe
[wordpress-plugin/INSTALL.md](wordpress-plugin/INSTALL.md).

```bash
cd frontend

# Environment konfigurieren
cp .env.example .env
chmod 600 .env
nano .env
#   BACKEND_API_URL=http://backend.example.com:9080/api
#   TENANT_SLUG=default                       # Slug des Tenants
#   TENANT_API_SECRET=<API_SECRET_KEY des Backends>
```

Eine `forms-config.php` im Frontend ist nicht nötig: Die Formular-Konfiguration kommt aus dem Backend.

### 4. Backend ohne Docker

```bash
mysql -u root -p < database/schema.sql       # Schema
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env                          # dann Secrets anhängen, siehe DEPLOYMENT.md
php migrate.php                               # Pflicht: setzt das Secret von Tenant 1
cp ../frontend/config/forms-config-dist.php ../frontend/config/forms-config.php
php seed-forms.php                            # Formular-Konfiguration übernehmen
```

**Von 2.x?** Nicht diese Anleitung, sondern [MIGRATION-3.0.md](MIGRATION-3.0.md) verwenden.

### 5. Fertig! 🎉

- **Frontend:** http://anmeldung.example.com
- **Backend:** http://backend.example.com (nur Intranet)

**Detaillierte Anleitung:** Siehe [DEPLOYMENT.md](DEPLOYMENT.md)

---

## 🏗️ Architektur

```
┌─────────────────────────────────────────────────────────────┐
│                    Internet (Öffentlich)                    │
│  ┌────────────────────────────────────────────────────────┐ │
│  │  Frontend (SurveyJS + Vanilla JS)                      │ │
│  │  • Formulare anzeigen                                  │ │
│  │  • Daten sammeln                                       │ │
│  │  • PDF-Download-Proxy                                  │ │
│  └─────────────────┬──────────────────────────────────────┘ │
└────────────────────┼────────────────────────────────────────┘
                     │ HTTP POST /api/submit.php?tenant=<slug>
                     │ (HMAC-signiert, Rate Limited)
                     ▼
┌─────────────────────────────────────────────────────────────┐
│              Intranet (Nur für Admins/Verwaltung)           │
│                                                             │
│  ┌────────────────────────────────────────────────────────┐ │
│  │  Backend (PHP 8.2+ MVC) [🐳 Docker-Container]          │  │
│  │  • API-Endpoint (submit.php)                           │ │
│  │  • Admin-Interface (optional Login)                    │ │
│  │  • PDF-Generator (Token-basiert)                       │ │
│  │  • Excel-Export                                        │ │
│  │  • Audit Trail (logs/audit.log)                        │ │
│  └──────┬──────────────────────────────────┬──────────────┘ │
│         │                                  │ TCP :3310      │
│         │                                  ▼                │
│  ┌──────▼──────────────────┐  ┌────────────────────────┐    │
│  │  MySQL/MariaDB          │  │  ClamAV Daemon         │    │
│  │  [🐳 Docker-Container]  │  │  [🐳 Docker-Container] │    │
│  │  • Anmeldungen          │  │  • Virus-Signaturen    │    │
│  │  • Tenants, Formular-   │  │  • freshclam (auto 2h) │    │
│  │    Konfiguration        │  │                        │    │
│  └─────────────────────────┘  └────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

**Zwei-Server-Architektur:**
- **Frontend-Server:** Öffentlich zugänglich (Internet) — Apache/Nginx, PHP
- **Backend-Server:** Nur im Intranet erreichbar — empfohlen als Docker-Stack
- **Kommunikation:** Frontend → Backend API (`submit.php`, `upload.php`, `form-config.php`); Anfragen tragen den Tenant-Slug und sind mit dem Tenant-Secret signiert

**Intranet-Docker-Stack (empfohlen):**
- **Backend-Container:** PHP 8.2+, MVC, Admin-Interface, Audit-Logging
- **MySQL-Container:** Persistente Datenbank mit automatischem Schema-Import
- **ClamAV-Container:** Virus-Scanner mit täglichen Signatur-Updates (freshclam)

**Vorteile:**
- ✅ Backend nicht direkt aus dem Internet erreichbar
- ✅ Datenbank komplett geschützt im Intranet
- ✅ API mit Rate Limiting, CORS-Protection und Tenant-Signatur (HMAC)
- ✅ Admins greifen nur intern auf Daten zu
- ✅ ClamAV scannt Uploads lokal — keine Schülerdaten an externe APIs

---

## 💻 Systemvoraussetzungen

### Backend
- **Docker**

oder:
- **PHP:** 8.2 oder höher
- **Webserver:** Apache/Nginx
- **Datenbank:** MySQL 8.0+ / MariaDB 10.5+
- **Composer:** Für Dependency Management
- **Extensions:**
  - `pdo_mysql`
  - `mbstring`
  - `gd` (für Logo-Optimierung)
  - `json`

### Frontend
- **Webserver:** Apache/Nginx
- **PHP:** 8.0+ (für Proxy-Scripts)

### Optional
- **Redis/Memcached:** Für besseres Rate Limiting (aktuell file-based)

---

## 📦 Installation

Siehe [Quick Start](#-quick-start) für eine Schnellanleitung oder [CLAUDE.md § Deployment](CLAUDE.md#-deployment) für die ausführliche Dokumentation.

### Apache Virtual Host Beispiel

**Frontend (öffentlich):**
```apache
<VirtualHost *:80>
    ServerName anmeldung.example.com
    DocumentRoot /var/www/ondisos/frontend/public

    <Directory /var/www/ondisos/frontend/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Backend (Intranet):**
```apache
<VirtualHost *:80>
    ServerName backend.example.com
    DocumentRoot /var/www/ondisos/backend/public

    <Directory /var/www/ondisos/backend/public>
        AllowOverride All
        Require ip 192.168.0.0/16  # Nur Intranet
    </Directory>
</VirtualHost>
```

---

## 📚 Dokumentation

### Haupt-Dokumentation
- **[CLAUDE.md](CLAUDE.md)** - 📖 Komplette Projekt-Dokumentation
  - Architektur-Details
  - Feature-Liste
  - Konfiguration
  - API-Dokumentation
  - Deployment-Guide (3 Optionen!)
  - Code-Konventionen
  - Troubleshooting

### Upgrade & Mehrschul-Betrieb
- **[MIGRATION-3.0.md](MIGRATION-3.0.md)** - ⬆️ Upgrade von 2.x auf 3.0 (Schritte, Rollback, Fehlersuche)
- **[MULTI-TENANT.md](MULTI-TENANT.md)** - 🏫 Mehrere Schulen auf einem Backend (Betrieb, Tenants, Sicherheit)

### Deployment & Operations
- **[DEPLOYMENT.md](DEPLOYMENT.md)** - 🚀 Production Deployment Guide
  - 3 Deployment-Optionen (Docker Backend ✅, Komplett Manuell, Komplett Docker)
  - Quick Start für Docker Production
  - Credentials & Secrets (Root .env, API-Secret)
  - Wartung & Updates
  - Backup-Strategien
  - HTTPS Enforcement
  - Production Checkliste
- **[DOCKER.md](DOCKER.md)** - 🐳 Docker Deep Dive (Dev/Testing)
  - Development Environment
  - Docker Compose Details
  - Volume Management
  - Monitoring & Logging
- **[CI_CD.md](CI_CD.md)** - 🚀 Automated Deployment Pipeline
  - GitLab CI/CD Setup
  - Automated Tests & Deployments
  - Staging & Production Workflows
  - Rollback-Strategien
- **[DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)** - 🆘 Notfall-Playbook
  - 8 Notfall-Szenarien mit Recovery-Steps
  - Complete Outage, Data Loss, Security Breach, etc.
  - Schritt-für-Schritt Anleitungen
  - Prevention Best Practices

### Spezial-Dokumentation
- **[wordpress-plugin/INSTALL.md](wordpress-plugin/INSTALL.md)** - 🔌 WordPress-Plugin installieren & konfigurieren
- **[backend/MULTI-TENANT.md](backend/MULTI-TENANT.md)** - 🏗️ Multi-Tenant-Architektur (Design, Schema)
- **[SURVEYJS.md](SURVEYJS.md)** - 📝 Formulardefinitionen (SurveyJS) erstellen
- **[PDF_SETUP.md](backend/PDF_SETUP.md)** - 📄 PDF-System Setup & Testing
- **[UPLOADS.md](backend/src/UPLOADS.md)** - 📎 File-Upload Dokumentation

### Configuration Files
- **[docker-compose.yml](docker-compose.yml)** - Dev/Testing Docker Setup
- **[docker-compose.prod.yml](docker-compose.prod.yml)** - Production Docker Overrides
- **[.env.example](.env.example)** - Root Environment Template (Core Credentials)
- **[backend/.env.example](backend/.env.example)** - Backend-Specific Overrides (Optional)
- **[frontend/.env.example](frontend/.env.example)** - Frontend Environment Template

### Code-Übersicht

```
ondisos/
├── frontend/              # Öffentliches Frontend
│   ├── public/           # Web-Root
│   │   ├── index.php    # Formular-Anzeige
│   │   ├── save.php     # Submit-Handler
│   │   └── pdf/         # PDF-Download-Proxy
│   ├── src/             # PHP Klassen (BackendApiClient, FormConfigLoader, ...)
│   ├── surveys/         # SurveyJS JSON-Definitionen
│   └── config/          # Vorlagen (forms-config-dist.php = Quelle für seed-forms.php)
│
├── wordpress-plugin/     # WordPress-Plugin (Shortcode [ondisos form="…"])
│
├── backend/              # Admin-Backend (Intranet)
│   ├── public/          # Web-Root
│   │   ├── index.php   # Übersicht
│   │   ├── detail.php  # Detail-Ansicht
│   │   ├── login.php   # Login (optional; bei Multi-Tenant Pflicht)
│   │   ├── tenants.php # Tenant-Verwaltung (Platform-Admin)
│   │   ├── api/        # API-Endpoints (submit, upload, form-config, health)
│   │   └── pdf/        # PDF-Generator
│   ├── src/            # MVC Struktur
│   │   ├── Models/
│   │   ├── Controllers/
│   │   ├── Services/
│   │   ├── Repositories/
│   │   └── Validators/
│   ├── templates/      # PDF-Templates
│   ├── config/         # Konfiguration
│   ├── migrate.php     # Datenbank-Migration (idempotent)
│   ├── seed-forms.php  # Formular-Konfiguration → Datenbank
│   └── scripts/        # Helper-Scripts
│
├── database/           # SQL Schemas & Migrationen
├── MIGRATION-3.0.md   # Upgrade-Anleitung
├── CLAUDE.md          # Haupt-Dokumentation
└── README.md          # Diese Datei
```

---

## 🔐 Sicherheit

### Implementierte Security Features

✅ **Input Validation** - Alle Eingaben werden validiert
✅ **SQL Injection Prevention** - Prepared Statements überall
✅ **XSS Protection** - HTML-Escaping mit `htmlspecialchars()`
✅ **CSRF Protection** - Token-basiert für Formulare
✅ **Rate Limiting** - API-Schutz gegen Abuse (10 req/min)
✅ **File Upload Validation** - Type, Size, Extension-Checks
✅ **Virus Scanning** - ClamAV-Integration, EICAR-getestet, DSGVO-konform (lokal)
✅ **Audit Trail** - JSON-Lines-Log aller sicherheitsrelevanten Aktionen
✅ **PDF Token Security** - HMAC-SHA256, zeitlich begrenzt (30 Min)
✅ **Tenant-Isolierung** - Alle Abfragen nach `tenant_id` gefiltert, IDOR-Versuche im Audit-Log
✅ **Signierte API** - Pro-Tenant-HMAC für Submit und Upload; bekannte Standard-Secrets werden abgelehnt
✅ **Session Security** - Regeneration, Timeout, Secure Cookies
✅ **Admin Auth** - Optional, session-basiert mit Brute-Force-Protection
✅ **Directory Traversal Prevention** - Path-Validierung
✅ **Error Handling** - Keine sensitiven Daten in Errors

### Security Best Practices

**Production Setup:**
1. ✅ HTTPS erzwingen (via Apache/Nginx)
2. ✅ `AUTH_ENABLED=true` für Backend (wenn nicht im gesicherten Netz)
3. ✅ Starke Secrets in `.env` (`openssl rand -hex 32`); `API_SECRET_KEY` darf kein Standardwert sein, `APP_ENV=production`
4. ✅ `display_errors=Off` in PHP
5. ✅ Regelmäßige Updates (Composer, PHP, OS)
6. ✅ Firewall für Backend-Server (nur Intranet-Zugriff)

**Bekannte Einschränkungen:**
- Email-Service nutzt PHP `mail()` (ggf. auf SMTP umstellen)
- Rate Limiting ist file-based (für Multi-Server: Redis empfohlen)

Siehe [CLAUDE.md § Sicherheit](CLAUDE.md#-sicherheit) für Details.

---

## 🤝 Beitragen

Wir freuen uns über Beiträge!

### Mitmachen

- 🐛 **Bug Reports:** Issues auf GitHub/Codeberg öffnen
- 💡 **Feature Requests:** Ideen und Vorschläge willkommen
- 🔧 **Pull Requests:** Code-Beiträge gerne gesehen
- 📖 **Dokumentation:** Verbesserungen und Ergänzungen

### Development Setup

```bash
# Repository klonen
git clone https://github.com/your-org/ondisos.git
cd ondisos

# Root .env konfigurieren (Core Credentials)
cp .env.example .env
nano .env  # DB-Credentials, Secrets

# Docker Dev Stack starten (Migration läuft automatisch)
docker compose --profile dev up -d  # Backend + MySQL + Frontend (+ phpMyAdmin)

# Formular-Konfiguration einspielen (einmalig)
cp frontend/config/forms-config-dist.php backend/config/forms-config.php
docker compose exec backend php seed-forms.php

# Oder: Manuelles Setup
cd backend
composer install
php migrate.php

cd ../frontend
cp .env.example .env   # BACKEND_API_URL, TENANT_SLUG, TENANT_API_SECRET eintragen
```

Tests: `make test` bzw. `docker compose exec backend composer test` (siehe [backend/UNITTESTS.md](backend/UNITTESTS.md)).

### Code-Konventionen

- **PHP:** PSR-4, PSR-12, strict types
- **Namespaces:** `App\*` (Backend), `Frontend\*` (Frontend)
- **Type Hints:** Immer verwenden
- **Dokumentation:** PHPDoc für alle public methods

Siehe [CLAUDE.md § Code-Konventionen](CLAUDE.md#-code-konventionen) für Details.

---

## 🙏 Danksagungen

Dieses Projekt nutzt folgende Open Source Libraries:

- **[SurveyJS](https://surveyjs.io/)** - Formular-Framework
- **[Bootstrap 5](https://getbootstrap.com/)** - UI-Framework
- **[DataTables](https://datatables.net/)** - Tabellen-Plugin
- **[PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet)** - Excel-Export
- **[mPDF](https://mpdf.github.io/)** - PDF-Generierung

---

## 📄 Lizenz

Open source, [MIT](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/blob/main/LICENSE). 

---

## 📊 Projekt-Status

**Version:** 3.0
**Status:** ✅ Production Ready

### Was ist neu in 3.0?

- ✅ **Multi-Tenant** — mehrere Schulen, eine Backend-Instanz ([MULTI-TENANT.md](MULTI-TENANT.md))
- ✅ **Platform-Admin** mit Tenant-Switcher und Tenant-Verwaltung
- ✅ **Signierte API** — pro Tenant HMAC-SHA256 für Submit und Upload; Upload-Isolierung pro Tenant
- ✅ **Formular-Konfiguration in der Datenbank** statt `forms-config.php` (Migration + `seed-forms.php`)
- ✅ **WordPress-Plugin 2.1** — lädt die Konfiguration vom Backend, Tenant-Einstellungen
- ✅ **Härtung** — bekannte Platzhalter-/Standard-Secrets authentifizieren nichts

**Upgrade von 2.x:** [MIGRATION-3.0.md](MIGRATION-3.0.md). Release-Historie: [CLAUDE.md § Änderungshistorie](CLAUDE.md#-änderungshistorie).

---

## 📞 Support & Kontakt

**Entwicklung:** Open Source Community
**Issue Tracker:** GitHub/Codeberg Issues
**Dokumentation:** [CLAUDE.md](CLAUDE.md)

---

## 🎯 Roadmap

### Completed (3.0)
- [x] Multi-Tenant Support (✅ Datenisolierung, Platform-Admin, Tenant-Verwaltung)
- [x] Per-Tenant HMAC API-Authentifizierung (✅ `api_secret` pro Tenant)
- [x] Form-Config in Datenbank (✅ `form_configs`-Tabelle, `seed-forms.php`)
- [x] Upload-Isolierung pro Tenant (✅ `uploads/tenant-<id>/`)
- [x] WordPress-Plugin (✅ Shortcode, Tenant-Einstellungen)

Siehe **[MULTI-TENANT.md](MULTI-TENANT.md)** und **[MIGRATION-3.0.md](MIGRATION-3.0.md)**.

### In Planung
- [ ] Weitere Unit Tests (Services, Repositories, Validators)
- [ ] Integration Tests mit Test-Datenbank
- [ ] Logging verbessern (strukturiertes Logging)
- [ ] Monitoring Setup (z.B. Sentry, Prometheus)
- [ ] API Documentation (OpenAPI/Swagger)
- [ ] SMTP-Support für Email-Service

### Geplant (3.0.5+)
- [ ] Managed Multi-Frontend (Szenario B: ein Frontend, mehrere Tenants)
- [ ] Form-Config Admin-UI (CRUD im Browser, 3.1)
- [ ] Survey-JSON-Upload vom Backend-Admin (3.1)

### Ideen
- [ ] Workflow-System (z.B. Freigabe-Prozess)
- [ ] Import-Funktion (z.B. aus SchoolSIS)
- [ ] REST API für Integrationen

Vorschläge? → [Issue erstellen](https://github.com/digitale-Schulverwaltung-BW/ondisos/)!

---

<p align="center">
  Made with ❤️ for digital education
</p>

<p align="center">
  <a href="CLAUDE.md">📖 Dokumentation</a> •
  <a href="https://github.com/digitale-Schulverwaltung-BW/ondisos/">🐙 GitHub</a> •
  <a href="https://github.com/digitale-Schulverwaltung-BW/ondisos/issues">🐛 Issues</a>
</p>

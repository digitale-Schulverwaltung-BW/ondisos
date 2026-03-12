# 🎓 Ondisos - Digital Souveräne Schulanmeldung

> **On**boarding - **Di**gital **S**ouverän und **O**pen **S**ource

Eine moderne, Open Source Lösung für digitale (Schul-)anmeldungen mit professionellem Admin-Backend.

Download der eingegangenen Anmeldungen als Excel-Datei für den Import in [ASV-BW](ASV.md) möglich.

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![License](https://img.shields.io/badge/license-open_source-green)](LICENSE)
[![Status](https://img.shields.io/badge/status-production_ready-brightgreen)](https://github.com)
[![Pipeline Status](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/badges/main/pipeline.svg)](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/openbrowsersignage/-/commits/main)
[![Coverage](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/badges/main/coverage.svg)](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/openbrowsersignage/-/commits/main)
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
- **File-Upload** Support
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
- **Auto-Expunge** (automatisches Löschen archivierter Einträge)

### ⚙️ Technische Features
- **Clean Architecture** (MVC + Service Layer)
- **Security First** (Prepared Statements, XSS-Protection, Input Validation)
- **PDF-System** mit Token-Authentifizierung
- **Rate Limiting** gegen API-Abuse
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

### 2. Docker Setup (Empfohlen)

```bash
# Root .env konfigurieren (Single Source of Truth)
cp .env.example .env

# Secrets generieren (direkt in .env eintragen)
sed -i.bak "s/^PDF_TOKEN_SECRET=.*/PDF_TOKEN_SECRET=$(openssl rand -hex 32)/" .env
sed -i.bak "s/^API_SECRET_KEY=.*/API_SECRET_KEY=$(openssl rand -hex 32)/" .env

nano .env  # DB-Credentials anpassen, Secrets überprüfen

# Jetzt wäre der perfekte Zeitpunkt, das Backend zu konfigurieren. Siehe unten.
cd backend
cp .env.example .env
# ... .env anpassen
cd ..

# Container starten (Backend + MySQL + ClamAV)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# Passwort-Hash generieren (optional, wenn AUTH_ENABLED=true)
docker compose exec backend php scripts/generate-password-hash.php "dein-passwort"

# Das Frontend-Setup (wie die Anmeldung auf der Schul-Webseite angezeigt wird)
# ist unten beschrieben.

```

**WICHTIG:** Credentials-Struktur seit v2.6
- ✅ `/.env` - Alle Core-Credentials (DB_USER, DB_PASS, Secrets)
- ✅ `/backend/.env` - Optional, nur für Backend-Overrides
- ✅ Keine Duplikation mehr zwischen DB_USER und MYSQL_USER!

### 3. Frontend Setup

```bash
cd frontend

# Environment konfigurieren
cp .env.example .env
nano .env  # Backend-API-URL eintragen

# Formulare konfigurieren
cp config/forms-config-dist.php config/forms-config.php
nano config/forms-config.php
```

### 4. Datenbank erstellen
(Backend, falls kein Docker-Setup!)

```bash
mysql -u root -p < database/schema.sql
```

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
                     │ HTTP POST /api/submit.php
                     │ (Rate Limited, CORS-Protected)
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
│  │  • Soft-Delete Support  │  │  • freshclam (auto 2h) │    │
│  └─────────────────────────┘  └────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

**Zwei-Server-Architektur:**
- **Frontend-Server:** Öffentlich zugänglich (Internet) — Apache/Nginx, PHP
- **Backend-Server:** Nur im Intranet erreichbar — empfohlen als Docker-Stack
- **Kommunikation:** Frontend → Backend API (submit.php)

**Intranet-Docker-Stack (empfohlen):**
- **Backend-Container:** PHP 8.2+, MVC, Admin-Interface, Audit-Logging
- **MySQL-Container:** Persistente Datenbank mit automatischem Schema-Import
- **ClamAV-Container:** Virus-Scanner mit täglichen Signatur-Updates (freshclam)

**Vorteile:**
- ✅ Backend nicht direkt aus dem Internet erreichbar
- ✅ Datenbank komplett geschützt im Intranet
- ✅ API mit Rate Limiting und CORS-Protection
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

### Deployment & Operations
- **[DEPLOYMENT.md](DEPLOYMENT.md)** - 🚀 Production Deployment Guide
  - 3 Deployment-Optionen (Docker Backend ✅, Komplett Manuell, Komplett Docker)
  - Quick Start für Docker Production
  - Credentials Management (Root .env, v2.6)
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
- **[PDF_SETUP.md](backend/PDF_SETUP.md)** - 📄 PDF-System Setup & Testing
- **[UPLOADS.md](backend/src/UPLOADS.md)** - 📎 File-Upload Dokumentation

### Configuration Files
- **[docker-compose.yml](docker-compose.yml)** - Dev/Testing Docker Setup
- **[docker-compose.prod.yml](docker-compose.prod.yml)** - Production Docker Overrides
- **[.env.example](.env.example)** - Root Environment Template (Core Credentials) ⭐ NEW
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
│   ├── src/             # PHP Klassen
│   ├── surveys/         # SurveyJS JSON-Definitionen
│   └── config/          # Formulare-Config
│
├── backend/              # Admin-Backend (Intranet)
│   ├── public/          # Web-Root
│   │   ├── index.php   # Übersicht
│   │   ├── detail.php  # Detail-Ansicht
│   │   ├── login.php   # Login (optional)
│   │   ├── api/        # API-Endpoints
│   │   └── pdf/        # PDF-Generator
│   ├── src/            # MVC Struktur
│   │   ├── Models/
│   │   ├── Controllers/
│   │   ├── Services/
│   │   ├── Repositories/
│   │   └── Validators/
│   ├── templates/      # PDF-Templates
│   ├── config/         # Konfiguration
│   └── scripts/        # Helper-Scripts
│
├── database/           # SQL Schemas
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
✅ **Session Security** - Regeneration, Timeout, Secure Cookies
✅ **Admin Auth** - Optional, session-basiert mit Brute-Force-Protection
✅ **Directory Traversal Prevention** - Path-Validierung
✅ **Error Handling** - Keine sensitiven Daten in Errors

### Security Best Practices

**Production Setup:**
1. ✅ HTTPS erzwingen (via Apache/Nginx)
2. ✅ `AUTH_ENABLED=true` für Backend (wenn nicht im gesicherten Netz)
3. ✅ Starke Secrets in `.env` (min. 32 Zeichen)
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

# Docker Dev Stack starten
docker compose up -d  # Backend + MySQL + ClamAV + Frontend

# Oder: Manuelles Setup
cd backend
composer install

cd ../frontend
cp .env.example .env
cp config/forms-config-dist.php config/forms-config.php
```

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

**Version:** v2.6 (Februar 2026)
**Status:** ✅ Production Ready

### Changelog

Siehe [CLAUDE.md § Änderungshistorie](CLAUDE.md#-änderungshistorie) für vollständige Release Notes.

**Aktuelles Release (v2.6):**
- ✅ ClamAV Virus Scanning (TCP/INSTREAM, Docker-Service, DSGVO-konform)
- ✅ Audit Trail (JSON-Lines-Log: Login, Status-Änderungen, Uploads, Bulk-Actions)
- ✅ Unit Tests für VirusScanService (10 Tests, 376 Tests gesamt)
- ✅ Simplified Credentials Management (Root .env als Single Source of Truth)
- ✅ No more DB_USER/MYSQL_USER duplication (automatic mapping)

---

## 📞 Support & Kontakt

**Entwicklung:** Open Source Community
**Issue Tracker:** GitHub/Codeberg Issues
**Dokumentation:** [CLAUDE.md](CLAUDE.md)

---

## 🎯 Roadmap

### Completed (v2.6 - Februar 2026)
- [x] ClamAV Virus Scanning (✅ VirusScanService, Docker-Service, DSGVO-konform)
- [x] Audit Trail / AuditLogger (✅ JSON-Lines, Login/Status/Upload/Bulk-Events)
- [x] Unit Tests: VirusScanService (✅ 10 Tests, 376 Tests gesamt)

### Completed (v2.5 - Februar 2026)
- [x] Docker Production Deployment (✅ DOCKER.md erweitert)
- [x] docker-compose.prod.yml (✅ Production Overrides)
- [x] CI/CD Pipeline (✅ GitLab CI/CD.md)
- [x] Disaster Recovery Playbook (✅ DISASTER_RECOVERY.md)
- [x] Improved .env.example files (✅ Backend + Frontend)

### Completed (v2.4 - Januar 2026)
- [x] PHPUnit Tests schreiben (✅ RateLimiter, PdfTokenService, MessageService)
- [x] GitLab CI/CD Pipeline (✅ Automated Tests, Coverage, Security)
- [x] Admin Authentication (✅ Optional, session-basiert)
- [x] Rate Limiting (✅ File-based, 10 req/min)
- [x] HTTPS Enforcement (✅ Apache .htaccess + PHP Fallback)

### In Planung
- [ ] Weitere Unit Tests (Services, Repositories, Validators)
- [ ] Integration Tests mit Test-Datenbank
- [ ] Logging verbessern (strukturiertes Logging)
- [ ] Monitoring Setup (z.B. Sentry, Prometheus)
- [ ] API Documentation (OpenAPI/Swagger)
- [ ] SMTP-Support für Email-Service

### Ideen
- [ ] Multi-Tenant Support (mehrere Schulen)
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

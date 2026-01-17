# Schulanmeldungs-System - Projekt-Dokumentation

## 📋 Projekt-Übersicht

**Zweck:** Webbasiertes System für Schulanmeldungen mit SurveyJS-Frontend und PHP-Backend

**Stack:**
- **Frontend:** SurveyJS, Vanilla JavaScript, Bootstrap 5
- **Backend:** PHP 8.1+, MySQL/MariaDB
- **Architecture:** Clean MVC mit Service Layer

**Deployment:**
- Frontend-Server: Öffentlich zugänglich, zeigt SurveyJS-Formulare
- Backend-Server: Intranet, Admin-Interface für Anmeldungsverwaltung

---

## 🏗️ Architektur

### Gesamtstruktur

```
projekt/
├── frontend/              # Öffentlich zugänglich
│   ├── public/
│   │   ├── index.php     # Formular-Anzeige
│   │   ├── save.php      # API-Endpoint für Submissions
│   │   ├── csrf_token.php
│   │   └── js/
│   │       └── survey-handler.js
│   ├── src/
│   │   ├── Config/
│   │   │   └── FormConfig.php
│   │   ├── Services/
│   │   │   ├── AnmeldungService.php
│   │   │   ├── BackendApiClient.php
│   │   │   └── EmailService.php
│   │   └── Utils/
│   │       └── CsrfProtection.php
│   ├── config/
│   │   └── forms-config.php
│   └── surveys/
│       ├── bs.json
│       ├── bk.json
│       └── survey_theme.json
│
└── backend/               # Intranet-Admin
    ├── public/
    │   ├── index.php     # Übersicht
    │   ├── detail.php    # Detail-Ansicht
    │   ├── trash.php     # Papierkorb
    │   ├── dashboard.php # Dashboard
    │   ├── excel_export.php
    │   ├── bulk_actions.php
    │   ├── restore.php
    │   ├── hard_delete.php
    │   └── api/
    │       ├── submit.php   # API für Frontend
    │       └── upload.php   # File-Upload API
    ├── src/
    │   ├── Config/
    │   │   ├── Database.php
    │   │   ├── Config.php
    │   │   └── EnvLoader.php
    │   ├── Models/
    │   │   ├── Anmeldung.php
    │   │   └── AnmeldungStatus.php (Enum)
    │   ├── Repositories/
    │   │   └── AnmeldungRepository.php
    │   ├── Services/
    │   │   ├── AnmeldungService.php
    │   │   ├── StatusService.php
    │   │   ├── ExportService.php
    │   │   ├── ExpungeService.php
    │   │   ├── RequestExpungeService.php
    │   │   └── SpreadsheetBuilder.php
    │   ├── Controllers/
    │   │   ├── AnmeldungController.php
    │   │   ├── DetailController.php
    │   │   └── BulkActionsController.php
    │   ├── Validators/
    │   │   └── AnmeldungValidator.php
    │   └── Utils/
    │       └── NullableHelpers.php
    ├── inc/
    │   ├── bootstrap.php
    │   ├── header.php
    │   └── footer.php
    ├── uploads/
    └── cache/
```

---

## 🔄 Datenfluss

### Submission Flow (Neue Anmeldung)

```
1. User füllt Formular aus (frontend/public/index.php?form=bs)
   ↓
2. JavaScript (survey-handler.js) sammelt Daten
   ↓
3. POST an frontend/public/save.php
   ↓
4. AnmeldungService validiert & verarbeitet
   ↓
5. BackendApiClient sendet JSON an backend/api/submit.php
   ↓
6. Backend AnmeldungRepository speichert in DB
   ↓
7. EmailService sendet Benachrichtigung
   ↓
8. Success-Meldung an User
```

### Admin Workflow

```
1. Admin öffnet backend/public/index.php
   ↓
2. AnmeldungController holt Daten via Repository
   ↓
3. Status wird automatisch "neu" → "exportiert" gesetzt (bei Excel-Export)
   ↓
4. Admin kann:
   - Einzeln ansehen (detail.php)
   - Excel exportieren (excel_export.php)
   - Bulk-Actions (archivieren/löschen)
   - Papierkorb verwalten (trash.php)
```

---

## 🗄️ Datenbank-Schema

```sql
CREATE TABLE anmeldungen (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    formular VARCHAR(100) NOT NULL,
    formular_version VARCHAR(50) NULL,
    name VARCHAR(255) NULL,
    email VARCHAR(255) NULL,
    status VARCHAR(30) DEFAULT 'neu',
    data LONGTEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted TINYINT(1) DEFAULT 0,
    deleted_at DATETIME NULL,
    INDEX idx_formular (formular),
    INDEX idx_email (email),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Wichtige Felder:**
- `data`: JSON mit allen Formulardaten
- `status`: neu, exportiert, in_bearbeitung, akzeptiert, abgelehnt, archiviert
- `deleted`: Soft-delete Flag
- `deleted_at`: Timestamp für Soft-delete

---

## ⚙️ Konfiguration

### Backend (.env)

```bash
# Application
APP_ENV=production
APP_DEBUG=false

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=anmeldung
DB_USER=admin
DB_PASS=secret

# Auto-Expunge (Tage nach denen archivierte Einträge gelöscht werden)
AUTO_EXPUNGE_DAYS=90

# Auto-Mark as Read (bei Ansicht/Export)
AUTO_MARK_AS_READ=true

# Session
SESSION_LIFETIME=3600
SESSION_SECURE=true
```

### Frontend (.env)

```bash
# Backend API
BACKEND_API_URL=http://intranet.example.com/backend/api

# Email
FROM_EMAIL=noreply@example.com
MAIL_HEAD=Eine neue Anmeldung ist eingegangen.

# CORS
ALLOWED_ORIGINS=http://anmeldung.example.com

# File Upload
UPLOAD_MAX_SIZE=10485760
UPLOAD_ALLOWED_TYPES=pdf,jpg,jpeg,png
```

### Formular-Konfiguration (forms-config.php)

```php
return [
    'bs' => [
        'db' => true,
        'form' => 'bs.json',
        'theme' => 'survey_theme.json',
        'notify_email' => 'sekretariat@example.com',
    ],
    'bk' => [
        'db' => true,
        'form' => 'bk.json',
        'theme' => 'survey_theme.json',
        'notify_email' => 'berufskolleg@example.com',
    ],
];
```

---

## 🎯 Feature-Liste

### ✅ Implementiert

**Frontend:**
- SurveyJS-Integration mit lokalen Fonts (DSGVO-konform)
- CSRF-Protection
- File-Upload Support
- Automatische Consent-Feld-Filterung
- Clean JavaScript (Class-based)

**Backend Admin:**
- Übersicht mit Pagination & Filterung
- Status-System mit Auto-Status-Update
- Bulk-Actions (Archivieren, Löschen)
- Soft-Delete mit Papierkorb
- Wiederherstellen aus Papierkorb
- Excel-Export mit:
  - Auto-Formatierung (Dates: YYYY-MM-DD → dd.mm.yyyy)
  - Zebra-Striping
  - Auto-Width
  - Frozen Header
  - Metadata-Sheet
  - Formular-Spalte verstecken bei Einzelformular-Export
- Detail-Ansicht mit:
  - Smart Value Detection (URLs, Emails, Dates)
  - File-Download
  - Auto-Mark as Read
- Dashboard mit Statistiken
- Auto-Expunge (request-based, alle 6h)

**Architecture:**
- Clean MVC mit Service Layer
- Type-Safe PHP 8.1+ (strict_types, typed properties)
- PSR-4 Autoloading
- Dependency Injection vorbereitet
- Exception Handling
- Environment-basierte Config

---

## 📊 Status-Flow

```
neu (User submitted)
  ↓ (beim Excel-Export wenn AUTO_MARK_AS_READ=true)
exportiert
  ↓ (manuell)
in_bearbeitung
  ↓ (manuell)
akzeptiert / abgelehnt
  ↓ (manuell via Bulk-Action)
archiviert
  ↓ (nach AUTO_EXPUNGE_DAYS)
[soft deleted] → [hard deleted]
```

---

## 🔐 Sicherheit

**Implementiert:**
- ✅ CSRF-Protection (Token-basiert)
- ✅ SQL Injection Prevention (Prepared Statements)
- ✅ XSS Protection (htmlspecialchars überall)
- ✅ File Upload Validation (Type, Size, Extension)
- ✅ Directory Traversal Prevention
- ✅ Input Validation (AnmeldungValidator)
- ✅ Type Safety (declare(strict_types=1))
- ✅ Error Handling (keine sensitive Daten in Errors)

**TODO:**
- ⚠️ Admin Authentication aktivieren (aktuell auskommentiert in auth.php)
- ⚠️ Rate Limiting für API-Endpoints
- ⚠️ HTTPS erzwingen in Production

---

## 🚀 Deployment

### Setup

1. **Backend:**
```bash
cd backend
cp .env.example .env
# Edit .env
mkdir -p cache uploads logs
chmod 755 cache uploads logs
```

2. **Frontend:**
```bash
cd frontend
cp .env.example .env
# Edit .env
```

3. **Database:**
```bash
mysql -u root -p < database/schema.sql
```

### Apache Configuration

```apache
# Frontend (public)
<VirtualHost *:80>
    ServerName anmeldung.example.com
    DocumentRoot /var/www/frontend/public
    
    <Directory /var/www/frontend/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

# Backend (intranet)
<VirtualHost *:80>
    ServerName intranet.example.com
    DocumentRoot /var/www/backend/public
    
    <Directory /var/www/backend/public>
        AllowOverride All
        Require ip 192.168.0.0/16  # Nur Intranet
    </Directory>
</VirtualHost>
```

---

## 🧪 Testing

### Manual Tests

**Frontend Submission:**
```bash
# 1. Formular öffnen
http://anmeldung.example.com/index.php?form=bs

# 2. Ausfüllen und absenden
# 3. Check Backend: sollte als "neu" erscheinen
```

**Backend Admin:**
```bash
# 1. Übersicht
http://intranet.example.com/backend/

# 2. Excel Export testen (Status sollte → "exportiert")
# 3. Detail ansehen
# 4. Bulk-Action: Archivieren
# 5. Papierkorb prüfen
```

**Auto-Expunge:**
```bash
# Dashboard öffnen
http://intranet.example.com/backend/dashboard.php

# Check "Auto-Expunge Status"
# Sollte zeigen: Letzter Lauf, Nächster Lauf, Anzahl bereit
```

---

## 🐛 Known Issues & TODOs

### Known Issues
- ⚠️ Auth ist auskommentiert (auth.php) → muss aktiviert werden
- ⚠️ Email-Service nutzt PHP mail() → ggf. auf SMTP umstellen
- ⚠️ Keine automatischen Tests vorhanden

### TODOs
1. **Admin Authentication** implementieren
2. **PHPUnit Tests** schreiben
3. **Rate Limiting** für API-Endpoints
4. **Logging** verbessern (strukturiertes Logging)
5. **Monitoring** Setup (z.B. Sentry)
6. **API Documentation** (OpenAPI/Swagger)
7. **Docker Setup** für einfaches Deployment

---

## 📚 Code-Konventionen

**PHP:**
- `declare(strict_types=1)` in jeder Datei
- Type Hints für alle Parameter
- Return Types dokumentieren
- PSR-4 Namespaces
- camelCase für Methoden, PascalCase für Klassen

**Namespaces:**
- Frontend: `Frontend\*`
- Backend: `App\*`

**Dateinamen:**
- Klassen: `PascalCase.php`
- Views: `kebab-case.php`

**Datenbank:**
- snake_case für Tabellen/Spalten
- Prepared Statements IMMER

---

## 🆘 Häufige Probleme

### "Class not found"
→ Check Autoloader in bootstrap.php
→ Verify namespace declaration

### "CORS error" im Frontend
→ Backend .env: ALLOWED_ORIGINS anpassen
→ Check api/submit.php CORS Headers

### "Permission denied" für uploads/cache
→ `chmod 755 uploads cache`
→ `chown www-data:www-data uploads cache`

### Excel-Export zeigt Formular-Spalte
→ Check dass Filter gesetzt ist: `?form=bs`
→ Metadata['filter'] muss nicht-leer sein

### Auto-Expunge läuft nicht
→ Check .env: AUTO_EXPUNGE_DAYS > 0
→ Prüfe cache/last_expunge.txt Permissions
→ Dashboard zeigt Status

### Datumsfelder falsch formatiert
→ ExportService formatiert automatisch YYYY-MM-DD → dd.mm.yyyy
→ Check dass Feld ISO-Format hat (RegEx: `^\d{4}-\d{2}-\d{2}`)

---

## 📞 Support & Kontakt

**Entwickler:** [Name]
**Stand:** Januar 2026
**PHP Version:** 8.1+
**Database:** MySQL 8.0+ / MariaDB 10.5+

---

## 🔄 Änderungshistorie

### v2.0 (Januar 2026)
- ✅ Komplett refactored (Frontend + Backend)
- ✅ Clean Architecture (MVC + Services)
- ✅ Soft-Delete System
- ✅ Auto-Expunge
- ✅ Excel-Export mit Auto-Formatierung
- ✅ Status-System
- ✅ Bulk-Actions
- ✅ Type-Safety (PHP 8.1+)

### v1.0 (Original)
- Legacy Spaghetti Code
- Direkte DB-Verbindungen
- Keine Struktur

---

**Ende der Dokumentation**

*Für Code-Details siehe die entsprechenden Klassen in `src/`*
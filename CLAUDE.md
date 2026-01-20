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
    │   ├── pdf/
    │   │   └── download.php  # PDF Download Endpoint
    │   └── api/
    │       ├── submit.php    # API für Frontend (mit PDF Token)
    │       └── upload.php    # File-Upload API
    ├── src/
    │   ├── Config/
    │   │   ├── Database.php
    │   │   ├── Config.php
    │   │   ├── FormConfig.php
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
    │   │   ├── SpreadsheetBuilder.php
    │   │   ├── PdfGeneratorService.php
    │   │   ├── PdfTemplateRenderer.php
    │   │   ├── PdfTokenService.php
    │   │   └── MessageService.php
    │   ├── Controllers/
    │   │   ├── AnmeldungController.php
    │   │   ├── DetailController.php
    │   │   └── BulkActionsController.php
    │   ├── Validators/
    │   │   └── AnmeldungValidator.php
    │   └── Utils/
    │       ├── NullableHelpers.php
    │       └── DataFormatter.php
    ├── templates/
    │   └── pdf/
    │       ├── base.php
    │       ├── styles.css
    │       └── sections/
    │           ├── header.php
    │           ├── data-table.php
    │           ├── custom-section.php
    │           └── footer.php
    ├── config/
    │   ├── messages.php
    │   └── messages.example.php
    ├── inc/
    │   ├── bootstrap.php
    │   ├── header.php
    │   └── footer.php
    ├── uploads/
    ├── cache/
    ├── composer.json
    ├── composer.lock (after install)
    ├── vendor/ (after install)
    └── PDF_SETUP.md
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
- PDF Download nach Submission:
  - Token-basiert (HMAC-SHA256, selbstvalidierend)
  - Konfigurierbar per Formular
  - Automatische Anzeige nach erfolgreicher Anmeldung

**PDF System:**
- On-Demand PDF-Generierung (kein permanenter Storage)
- HMAC-basierte Tokens (30 Min Gültigkeit, konfigurierbar)
- Logo-Support mit automatischer Optimierung
- Custom Sections (Pre/Post Data-Table)
- Field-Filtering (Include/Exclude)
- Form-Feld-Reihenfolge wird beibehalten
- mPDF-Integration (DejaVu Sans für deutsche Umlaute)
- Error Pages mit User-Friendly Design

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

## 📄 PDF Download System

### Übersicht

Nach erfolgreicher Formularübermittlung können Benutzer eine PDF-Bestätigung herunterladen. Das System verwendet HMAC-basierte Tokens für sichere, zeitlich begrenzte Downloads ohne Datenbank-Storage.

### Architektur

```
User submits form
  ↓
Frontend (save.php) → Backend API (submit.php)
  ↓
Backend generiert PDF-Token (HMAC-SHA256)
  ↓
Response mit pdf_download Object
  ↓
Frontend (survey-handler.js) zeigt Download-Button
  ↓
User klickt Download → backend/public/pdf/download.php?token=...
  ↓
Token validieren → Anmeldung laden → PDF generieren → Download
```

### Token-Format

```
base64(id:timestamp:lifetime:hmac)
```

- **id**: Anmeldungs-ID
- **timestamp**: Unix-Timestamp der Token-Generierung
- **lifetime**: Gültigkeitsdauer in Sekunden
- **hmac**: HMAC-SHA256 Signatur über id:timestamp:lifetime

**Sicherheit:**
- Self-validating (keine DB-Abfrage nötig)
- Timing-safe Vergleich (hash_equals)
- Kann nicht gefälscht werden ohne PDF_TOKEN_SECRET
- Automatische Expiration

### Konfiguration

**Backend .env:**
```bash
# Min 32 Zeichen, generieren mit: openssl rand -hex 32
PDF_TOKEN_SECRET=your-secret-key-here
```

**forms-config.php:**
```php
'bs' => [
    'pdf' => [
        'enabled' => true,
        'required' => false,
        'token_lifetime' => 1800,  // 30 Min
        'logo' => '/path/to/logo.png',
        'header_title' => 'Anmeldebestätigung',
        'intro_text' => 'Vielen Dank...',
        'footer_text' => 'Bei Fragen: ...',
        'include_fields' => 'all',
        'exclude_fields' => ['consent_datenschutz'],
        'pre_sections' => [],   // Vor Daten-Tabelle
        'post_sections' => [],  // Nach Daten-Tabelle
    ],
],
```

### Komponenten

**Backend:**
- **PdfTokenService**: Token-Generierung & Validierung
- **PdfGeneratorService**: PDF-Erstellung mit mPDF
- **PdfTemplateRenderer**: Template-System für PDFs
- **DataFormatter**: Daten-Formatierung (shared mit Email)
- **FormConfig**: PDF-Konfiguration laden

**Frontend:**
- **survey-handler.js**: PDF-Download-Button anzeigen
- **AnmeldungService.php**: pdf_download weitergeben
- **messages.php**: PDF-UI-Texte

**Templates:**
- `backend/templates/pdf/base.php`: Haupt-Template
- `backend/templates/pdf/styles.css`: mPDF-kompatible Styles
- `backend/templates/pdf/sections/`: Header, Footer, Data-Table, Custom-Section

### API Response

**Mit PDF:**
```json
{
  "success": true,
  "id": 123,
  "pdf_download": {
    "enabled": true,
    "required": false,
    "url": "/backend/public/pdf/download.php?token=abc...",
    "title": "Bestätigung herunterladen",
    "expires_in": 1800
  }
}
```

**Ohne PDF:**
```json
{
  "success": true,
  "id": 123
}
```

### Dateiname-Format

```
bestaetigung-{formularname}-{id}.pdf
```

Beispiel: `bestaetigung-bs-123.pdf`

### Logo-Optimierung

Logos werden automatisch:
- Auf max 150px Breite skaliert
- In JPEG konvertiert (kleinere Dateigröße)
- Als Base64 in PDF eingebettet

### Field-Ordering

Die Reihenfolge der Felder im PDF entspricht der SurveyJS-Formular-Reihenfolge.
Metadaten `_fieldTypes` werden von survey-handler.js extrahiert und zur Sortierung verwendet.

### Testing

Siehe `backend/PDF_SETUP.md` für:
- Setup-Anleitung
- Test-Szenarien
- Debugging
- Troubleshooting

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
- ✅ PDF Token Security (HMAC-SHA256, selbstvalidierend, zeitlich begrenzt)
- ✅ Secret Key Management (PDF_TOKEN_SECRET in .env, min 32 Zeichen)

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

# Install Composer dependencies
composer install

# Configure environment
cp .env.example .env
# Edit .env - add DB credentials and PDF_TOKEN_SECRET

# Generate PDF token secret
openssl rand -hex 32
# Add to .env: PDF_TOKEN_SECRET=<generated-key>

# Create directories
mkdir -p cache uploads logs
chmod 755 cache uploads logs
```

2. **Frontend:**
```bash
cd frontend

# Configure environment
cp .env.example .env
# Edit .env - add backend API URL

# Configure forms (copy from dist)
cp config/forms-config-dist.php config/forms-config.php
# Edit config/forms-config.php - add PDF configuration per form
```

3. **Database:**
```bash
mysql -u root -p < database/schema.sql
```

4. **PDF System (optional):**
```bash
# Add logo (optional)
cp your-logo.png backend/templates/pdf/logo.png

# Test PDF generation
# See backend/PDF_SETUP.md for detailed testing guide
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

## 🌐 Zentrale Message-Verwaltung

Das System verwendet einen zentralen MessageService für alle UI-Texte, Fehlermeldungen und Labels.
Dies ermöglicht lokale Anpassungen ohne git-Konflikte.

### Architektur

```
Standard Messages (Git)     Local Overrides (.gitignored)
     ↓                              ↓
messages.php                 messages.local.php
     ↓                              ↓
         → Merged at runtime →
                ↓
         MessageService
                ↓
    Placeholder Replacement ({{variable}})
                ↓
         Rendered Output
```

### Dateien

**Backend:**
- `backend/config/messages.php` - Standard-Messages (committed)
- `backend/config/messages.local.php` - Lokale Overrides (gitignored)
- `backend/config/messages.example.php` - Template für lokale Anpassungen
- `backend/src/Services/MessageService.php` - Message Manager

**Frontend:**
- `frontend/config/messages.php` - Standard-Messages (committed)
- `frontend/config/messages.local.php` - Lokale Overrides (gitignored)
- `frontend/config/messages.example.php` - Template für lokale Anpassungen
- `frontend/src/Services/MessageService.php` - Message Manager
- `frontend/public/api/messages.json.php` - JSON API für JavaScript

### PHP Usage

```php
use App\Services\MessageService as M;

// Einfacher Zugriff
echo M::get('ui.buttons.save');  // → "Speichern"

// Mit Fallback
echo M::get('ui.custom_label', 'Default Text');

// Mit Platzhaltern
echo M::format('success.restored', ['id' => 42]);
// → "Eintrag #42 wurde wiederhergestellt"

// Mit automatischem Contact-Info
echo M::withContact('errors.generic_error');
// → "Ein Fehler ist aufgetreten. Bei Problemen: sekretariat@example.com"
```

### JavaScript Usage

```javascript
// Messages werden beim init() geladen
class SurveyHandler {
    async init() {
        await this.loadMessages();  // Lädt von /api/messages.json.php
        // ...
    }

    // Zugriff auf Messages
    const errorMsg = this.msg('errors.submission_failed');

    // Mit Platzhaltern
    const formatted = this.formatMsg('success.count', {count: 5});
}
```

### Lokale Anpassungen

**1. Backend Custom Messages erstellen:**

```bash
cd backend/config
cp messages.example.php messages.local.php
# Edit messages.local.php
```

**2. Beispiel `messages.local.php`:**

```php
<?php
return [
    'contact' => [
        'support_email' => 'sekretariat@meineschule.de',
        'support_text' => 'Bei Problemen: sekretariat@meineschule.de',
    ],

    'ui' => [
        'anmeldungen' => 'Bewerbungen',  // Umbenennen
    ],

    'status' => [
        'neu' => 'Unbearbeitet',  // Custom Label
    ],
];
```

**3. Frontend analog:**

```bash
cd frontend/config
cp messages.example.php messages.local.php
# Edit messages.local.php
```

### Vorteile

✅ **Git-safe**: Lokale Anpassungen in `.local.php` (gitignored)
✅ **Kein Build-Step**: Alles zur Runtime, keine Generierung nötig
✅ **Native PHP**: PHP Arrays statt JSON
✅ **Runtime API**: JavaScript lädt Messages dynamisch via API
✅ **Placeholder-System**: `{{variable}}` für flexible Werte
✅ **Contact-Helper**: Automatische Support-Kontakte in Fehlermeldungen

### Message-Kategorien

**Backend (`backend/config/messages.php`):**
- `validation.*` - Validierungsfehler
- `errors.*` - Fehlermeldungen
- `success.*` - Erfolgsmeldungen
- `ui.*` - UI-Labels, Buttons, Tabellen-Header
- `status.*` - Status-Labels
- `bulk_actions.*` - Bulk-Action-Labels
- `excel.*` - Excel-Export-Metadaten
- `contact.*` - Kontakt-Informationen
- `api.*` - API-Error-Messages

**Frontend (`frontend/config/messages.php`):**
- `errors.*` - Fehlermeldungen
- `success.*` - Erfolgsmeldungen
- `ui.*` - UI-Labels
- `templates.*` - HTML-Templates mit Platzhaltern
- `email.*` - Email-Templates
- `validation.*` - Validierungsmeldungen
- `contact.*` - Kontakt-Informationen
- `forms.*` - Formular-spezifische Messages

### Troubleshooting

**Messages werden nicht geladen (JavaScript):**
```bash
# API testen
curl http://localhost/frontend/api/messages.json.php | jq .

# Browser Console prüfen
# Sollte keine Fehler beim fetch() zeigen
```

**Lokale Overrides werden ignoriert:**
```bash
# Prüfen ob .local.php existiert und nicht leer ist
ls -la backend/config/messages.local.php

# PHP Syntax prüfen
php -l backend/config/messages.local.php
```

**[missing: key] erscheint:**
→ Message-Key existiert nicht in messages.php
→ Check Schreibweise (case-sensitive!)
→ Oder add Fallback: `M::get('my.key', 'Fallback Text')`

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

### v2.2 (Januar 2026)
- ✅ PDF Download System
  - HMAC-basierte Token-Authentifizierung (selbstvalidierend)
  - On-Demand PDF-Generierung (mPDF)
  - Konfigurierbar per Formular
  - Logo-Support mit automatischer Optimierung
  - Custom Sections (Pre/Post Data-Table)
  - Field-Filtering und Ordering
  - User-Friendly Error Pages
  - Composer-Integration
  - Umfassende Dokumentation (PDF_SETUP.md)

### v2.1 (Januar 2026)
- ✅ Zentrale Message-Verwaltung (MessageService)
- ✅ Local Override System (messages.local.php)
- ✅ JavaScript Message Loader
- ✅ Placeholder-Unterstützung ({{variable}})
- ✅ Git-safe lokale Anpassungen
- ✅ ~90+ Messages zentralisiert

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
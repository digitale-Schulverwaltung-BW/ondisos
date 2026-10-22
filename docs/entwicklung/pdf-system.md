# PDF-Download-System

Aufbau des PDF-Downloads (Token, Proxy, Komponenten). Bedienung für Schulen: [PDF-Bestätigung gestalten](../redaktion/pdf-bestaetigung.md); Einrichtung und Tests: [backend/PDF_SETUP.md](../../backend/PDF_SETUP.md).


## Übersicht

Nach erfolgreicher Formularübermittlung können Benutzer eine PDF-Bestätigung herunterladen. Das System verwendet HMAC-basierte Tokens für sichere, zeitlich begrenzte Downloads ohne Datenbank-Storage.

## Architektur

```
User submits form
  ↓
Frontend (save.php) → Backend API (submit.php)
  ↓
Backend generiert PDF-Token (HMAC-SHA256)
  ↓
Response mit pdf_download Object (URL: /pdf/download.php?token=...)
  ↓
Frontend (survey-handler.js) zeigt Download-Button
  ↓
User klickt Download → Frontend Proxy (frontend/public/pdf/download.php)
  ↓
Frontend Proxy leitet Anfrage weiter → Backend (backend/public/pdf/download.php)
  ↓
Backend: Token validieren → Tenant der Anmeldung ermitteln → Anmeldung laden → PDF generieren
  ↓
Backend sendet PDF → Frontend Proxy → User
```

**Wichtig:** Der Frontend-Proxy ist notwendig, weil:
- Frontend ist öffentlich erreichbar (Internet)
- Backend ist nur im Intranet erreichbar
- User können das Backend nicht direkt ansprechen
- Der Proxy leitet die Anfrage intern vom Frontend zum Backend weiter

## Token-Format

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

## Konfiguration

**Backend .env:**
```bash
# Min 32 Zeichen, generieren mit: openssl rand -hex 32
PDF_TOKEN_SECRET=your-secret-key-here
```

**Formular-Konfiguration** (`form_configs.config_json`, Quelle: `forms-config.php`):
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
        'section_headings' => true, // Seitentitel als Überschriften (Standard: true)
        'include_fields' => 'all',
        'exclude_fields' => ['consent_datenschutz'],
        'pre_sections' => [],   // Vor Daten-Tabelle
        'post_sections' => [],  // Nach Daten-Tabelle
    ],
],
```

## Komponenten

**Backend:**
- **PdfTokenService**: Token-Generierung & Validierung
- **PdfGeneratorService**: PDF-Erstellung mit mPDF
- **PdfTemplateRenderer**: Template-System für PDFs
- **DataFormatter**: Daten-Formatierung (shared mit Email)
- **FormConfig**: PDF-Konfiguration laden

**Frontend:**
- **pdf/download.php**: Proxy für PDF-Downloads (leitet Anfragen an Backend weiter)
- **survey-handler.js**: PDF-Download-Button anzeigen
- **AnmeldungService.php**: pdf_download weitergeben
- **messages.php**: PDF-UI-Texte und Error-Messages

**Templates:**
- `backend/templates/pdf/base.php`: Haupt-Template
- `backend/templates/pdf/styles.css`: mPDF-kompatible Styles
- `backend/templates/pdf/sections/`: Header, Footer, Data-Table, Custom-Section

## API Response

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

## Dateiname-Format

```
bestaetigung-{formularname}-{id}.pdf
```

Beispiel: `bestaetigung-bs-123.pdf`

## Logo-Optimierung

Logos werden automatisch:
- Auf max 150px Breite skaliert
- In JPEG konvertiert (kleinere Dateigröße)
- Als Base64 in PDF eingebettet

## Field-Ordering

Die Reihenfolge der Felder im PDF entspricht der SurveyJS-Formular-Reihenfolge.
Metadaten `_fieldTypes` werden von `survey-handler-base.js` (`extractFieldTypes()`) beim Absenden extrahiert und zur Sortierung verwendet.
Je Frage stehen dort `type` (bei Textfeldern `inputType`), `title` (verarbeiteter Fragetitel) sowie `page` und `pageTitle` der Survey-Seite, z. B.:

```json
"_fieldTypes": {"Vorname": {"type": "text", "title": "Vorname", "page": "schueler", "pageTitle": "Angaben Schüler"}}
```

- `DataFormatter::fieldLabel()` beschriftet ein Feld mit `title` (HTML entfernt, höchstens 300 Zeichen), sonst mit dem Feldnamen. Der Excel-Export behält die Feldnamen (ASV).
- `DataFormatter::groupByPage()` teilt die gefilterten Felder an jedem Seitenwechsel; jede Gruppe beginnt in `sections/data-table.php` mit einer Zeile `tr.page-heading`, die Zweierpaare beginnen je Seite neu.
  Alles bleibt eine Tabelle (einheitliche Spaltenbreiten); `page-break-after: avoid` auf `tr.page-heading` verhindert, dass ein Balken allein am Seitenende steht.
  Nur eine Ebene: Panels ergeben keine Überschrift. Hat keine Gruppe einen Titel (Anmeldungen vor diesem Update, Seiten ohne Titel) oder ist `pdf.section_headings` aus, bleibt es bei einer Tabelle unter „Formulardaten“.
- Die PDF-Vorschau (`PdfPreviewData::fromSurvey()`) erzeugt `_fieldTypes` in derselben Form aus der Survey-JSON.

## Testing

Siehe `backend/PDF_SETUP.md` für:
- Setup-Anleitung
- Test-Szenarien
- Debugging
- Troubleshooting

---

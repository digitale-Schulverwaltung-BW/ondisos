# Konfiguration (Referenz)

Einstellungen von Backend und Frontend und die Formular-Konfiguration. Installation und Betrieb: [installation.md](../betreiber/installation.md), [betrieb.md](../betreiber/betrieb.md).


Docker: die **Root-`.env`** ist die Single Source of Truth (DB-Credentials, Secrets). `backend/.env` ist optional; **Werte in `backend/.env` überschreiben die Container-Umgebung** (`EnvLoader::load()`). Im Docker-Betrieb erzeugt der Entrypoint die Datei aus der Container-Umgebung und schreibt sie bei **jedem Start** neu (Marker `# GENERATED-BY-ENTRYPOINT` in Zeile 1; nicht verwaltete Zusatz-Schlüssel bleiben erhalten, eine Datei ohne Marker wird nie angefasst). Geänderte Compose-Variablen brauchen `docker compose up -d backend` (nicht `restart`). Ohne Docker stehen alle Backend-Werte in `backend/.env`.

## Backend (.env)

```bash
# Application
APP_ENV=production            # bei "production" werden bekannte Standard-Secrets abgelehnt
APP_DEBUG=false

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=anmeldung
DB_USER=admin
DB_PASS=secret

# Secrets (openssl rand -hex 32). Docker: in der Root-.env; manuell: hier anhängen
PDF_TOKEN_SECRET=...          # min. 32 Zeichen, signiert PDF-Download-Tokens
API_SECRET_KEY=...            # wird beim Migrieren das Secret von Tenant 1

# Multi-Tenant (Default: false)
MULTI_TENANT_ENABLED=false    # true ⇒ Login erzwungen, Tenant-Verwaltung, Tenant-Switcher
ADMIN_USERNAME=               # Platform-Admin (bei MULTI_TENANT_ENABLED=true erforderlich)
ADMIN_PASSWORD_HASH=''        # password_hash(), in EINFACHE Anführungszeichen (Compose-Interpolation von $)

# Auto-Expunge (Tage nach denen archivierte Einträge gelöscht werden)
AUTO_EXPUNGE_DAYS=90

# Auto-Mark as Read (bei Ansicht/Export)
AUTO_MARK_AS_READ=true

# Session / Auth
SESSION_LIFETIME=3600
SESSION_SECURE=true
AUTH_ENABLED=false

# Reverse-Proxy (kommagetrennte IPs/CIDR; leer = X-Forwarded-For wird ignoriert)
TRUSTED_PROXIES=

# Rate Limiting, Virenscan, PDF-Logo, ...: siehe backend/.env.example
```

## Frontend (.env)

```bash
# Backend API
BACKEND_API_URL=http://intranet.example.com/backend/api

# Tenant (Single-Tenant: default)
TENANT_SLUG=default
TENANT_API_SECRET=...         # Secret des Tenants; signiert Submit/Upload. Nur serverseitig!

# Email
FROM_EMAIL=noreply@example.com
MAIL_HEAD=Eine neue Anmeldung ist eingegangen.

# Reverse-Proxy vor dem Frontend (IPs/CIDR; leer = X-Forwarded-For wird ignoriert)
TRUSTED_PROXIES=

# CORS
ALLOWED_ORIGINS=http://anmeldung.example.com

# File Upload
UPLOAD_MAX_SIZE=10485760
UPLOAD_ALLOWED_TYPES=pdf,jpg,jpeg,png
```

WordPress: `Tenant-Slug` und `Tenant-API-Secret` unter *Einstellungen → Ondisos* (haben Vorrang vor der `.env`).

## Formular-Konfiguration (Tabelle `form_configs`)

Die Konfiguration eines Formulars ist ein JSON-Objekt in `form_configs.config_json` (Tenant + `form_key`).
`frontend/config/forms-config-dist.php` dokumentiert die möglichen Schlüssel und dient als Quelle für
`backend/seed-forms.php` (`--tenant=<slug>`, Standard Tenant 1; neue Formular-Keys werden hinzugefügt, vorhandene nie überschrieben, ungültige Einträge übersprungen; Quelle ohne Argument `../frontend/config/forms-config.php` bzw. `config/forms-config.php`, sonst eine Datei oder `-` für STDIN — im Docker-Betrieb `docker compose exec -T backend php seed-forms.php - < frontend/config/forms-config.php`; das Skript warnt vor `@example.com`-Platzhaltern). Seit 3.1 werden bestehende Formulare im Backend bearbeitet (*Formulare*, `form_edit.php`; Felder und Regeln stehen einmal in `FormConfigSchema`);
`copy-forms.php` kopiert Formulare zwischen Tenants. Per SQL geht es weiterhin.

```php
// Beispiel: Inhalt einer forms-config.php (wird beim Seed zu config_json)
return [
    'bs' => [
        'db' => true,
        'form' => 'bs.json',
        'theme' => 'survey_theme.json',
        'version' => '2026-01-v1',
        'notify_email' => 'sekretariat@example.com',
        'prefill_fields' => ['Ausbildungsbetrieb', 'Ausbilder'],
        'pdf' => [ 'enabled' => true, /* siehe PDF Download System */ ],
        // optional: 'email' => ['intro_template' => …], 'ical' => […]
    ],
];
```

---

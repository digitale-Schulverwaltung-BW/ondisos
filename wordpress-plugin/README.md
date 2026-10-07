# ondisos — WordPress-Plugin

Bindet die Ondisos-Anmeldeformulare (SurveyJS) per Shortcode in WordPress-Seiten ein. Die Daten werden serverseitig
und signiert an das Ondisos-Backend übertragen — WordPress speichert keine Anmeldedaten.

```
[ondisos form="bs"]
```

**Version 3.1.1** · benötigt Ondisos-Backend **3.0+** (Funktionen von 3.1 ab Backend 3.1) · WordPress 5.8+ · PHP 8.1+

## Schnellstart

1. Die fertige **ZIP** von der [Releases-Seite](https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/releases) in WordPress hochladen (*Plugins → Installieren → Plugin hochladen*): [INSTALL.md](INSTALL.md).
   Für Entwicklung und eigene Server alternativ per Git-Clone mit Symlink: [INSTALL-AUS-GIT.md](INSTALL-AUS-GIT.md)
2. Plugin aktivieren
3. *Einstellungen → Ondisos*: **Backend API URL**, **Tenant-Slug**, **Tenant-API-Secret** eintragen
4. Shortcode `[ondisos form="<formular-key>"]` in eine Seite einfügen

## Funktionen

- ✅ SurveyJS-Formulare, mehrere Formulare pro Installation (auch mehrere Shortcodes pro Seite)
- ✅ Formular-Konfiguration kommt aus dem Backend (pro Tenant, `form-config.php`) — kein Kopieren von Config-Dateien
- ✅ Signierte Backend-Anfragen (HMAC-SHA256 mit dem Tenant-Secret), CSRF-Schutz über WordPress-Nonces
- ✅ Datei-Upload (Virenscan im Backend), PDF-Bestätigung (Download über einen Proxy im Plugin), iCal-Download
- ✅ Prefill per `?prefill=<base64>` (Link aus der Bestätigung) und per einfachen Query-Parametern (`?Klasse=5a`)
- ✅ DSGVO-konform: lokale Fonts/Bibliotheken, keine externen CDNs
- ✅ Installation und Update ohne Shell per ZIP; alternativ per `git pull` (siehe [INSTALL-AUS-GIT.md](INSTALL-AUS-GIT.md))

## Verwendung

```
[ondisos form="bs"]
```

`form` ist **Pflicht** und muss ein Formular-Key sein, der im Backend für den konfigurierten Tenant existiert.
Ein Attribut für den Tenant gibt es nicht: Der Tenant gehört zur **Installation** (Einstellungen bzw. `.env`), nicht zur Seite.

Prefill:

```
https://example.org/anmeldung/?prefill=<base64-JSON>      # Link, den das System nach einer Anmeldung erzeugt
https://example.org/anmeldung/?Vorname=Erika&Klasse=5a    # einfache Parameter; nur bekannte Feldnamen werden übernommen
```

## Verzeichnisstruktur

```
wordpress-plugin/
├── ondisos.php                  # Plugin-Bootstrap (Header, Konstanten, Layout-Erkennung)
├── uninstall.php                # räumt die Optionen auf
├── readme.txt                   # WordPress-Format
├── INSTALL.md · INSTALL-AUS-GIT.md · README.md
├── SYMLINK-SETUP.sh             # Hilfsskript für die Symlink-Installation
├── build-zip.sh                 # baut die Release-ZIP (Layout C: Plugin + Frontend-Teile), `make plugin-zip`
├── frontend-assets -> ../frontend/public   # Symlink auf die Frontend-Assets (Layout A)
├── includes/
│   ├── class-plugin.php         # Orchestrierung, lädt .env und WordPress-Optionen in die Umgebung
│   ├── class-autoloader.php     # PSR-4 für Ondisos\* und Frontend\*
│   ├── class-shortcode.php      # [ondisos form="…"]
│   ├── class-form-config-loader.php  # lädt die Formular-Config vom Backend (Tenant-Slug)
│   ├── class-ajax-handler.php   # Submit (ondisos_submit), iCal (ondisos_ical)
│   ├── class-pdf-proxy.php      # PDF-Download (ondisos_pdf_download)
│   ├── class-assets.php         # Scripts/Styles
│   └── class-settings.php       # Einstellungsseite
└── assets/
    ├── js/survey-handler-wp.js  # WordPress-Variante des JS-Handlers (erbt SurveyHandlerBase)
    └── css/ondisos.css
```

## Architektur

**Zwei Namespaces** über den eigenen Autoloader:

1. `Ondisos\*` — die Plugin-Klassen (`wordpress-plugin/includes/`)
2. `Frontend\*` — die gemeinsam genutzten Frontend-Klassen (`frontend/src/`), **unverändert** wiederverwendet:
   `AnmeldungService`, `BackendApiClient` (signiert die Requests), `EmailService`, `FormConfig`/`FormConfigLoader`

**Unterschiede zum Standalone-Frontend**

| | Standalone | WordPress |
|---|---|---|
| CSRF | Session-Token | WP-Nonce |
| Submit | `save.php` | `admin-ajax.php?action=ondisos_submit` |
| PDF-Download | `pdf/download.php` (Proxy) | `admin-ajax.php?action=ondisos_pdf_download` |
| Konfiguration | `frontend/.env` | WP-Einstellungen, sonst `.env` |
| JS | `survey-handler.js` | `survey-handler-wp.js` (beide erben `survey-handler-base.js`) |

**Datenfluss**

```
Seite mit Shortcode
   ↓ Form_Config_Loader::ensure('bs') → GET {Backend}/form-config.php?form=bs&tenant={slug}
Survey wird gerendert (Definition aus frontend/surveys/)
   ↓ Nutzer sendet ab
POST admin-ajax.php?action=ondisos_submit   (Nonce geprüft)
   ↓ AnmeldungService → BackendApiClient
POST {Backend}/submit.php?tenant={slug}     Header X-Signature (HMAC mit Tenant-Secret)
   ↓ Uploads: {Backend}/upload.php?tenant={slug}, je Datei signiert
Antwort mit PDF-Link / Prefill-Link an den Browser
```

## Konfiguration

*Einstellungen → Ondisos*: Backend API URL, Tenant-Slug, Tenant-API-Secret (write-only), Von E-Mail.

**Priorität:** 1. WordPress-Optionen · 2. `.env` im Frontend-Verzeichnis · 3. Standardwerte (Slug `default`).
Details und Sicherheitshinweise: [INSTALL.md](INSTALL.md); zur `.env` (im Web-Verzeichnis sperren!): [INSTALL-AUS-GIT.md](INSTALL-AUS-GIT.md#konfiguration-per-env).

## Hooks und AJAX-Endpoints

- Action `ondisos_enqueue_assets` — wird beim Rendern des Shortcodes ausgelöst und lädt Scripts/Styles
- `wp_ajax[_nopriv]_ondisos_submit` — Formular absenden
- `wp_ajax[_nopriv]_ondisos_pdf_download` — PDF-Proxy (`token`-Parameter)
- `wp_ajax[_nopriv]_ondisos_ical` — iCal-Download (`form`-Parameter, nur wenn für das Formular aktiviert)

## Sicherheit

- CSRF über WordPress-Nonces (an das Formular gebunden), Nonce-Prüfung bei jedem Submit
- Ausgaben werden mit `esc_html()`/`esc_attr()`/`esc_url()` escaped; Eingaben sanitisiert
- Signierte Backend-Anfragen (HMAC-SHA256, Tenant-Secret serverseitig); Upload-Validierung und Virenscan im Backend
- Keine eigenen Datenbankabfragen; das Secret liegt in `wp_options` (Zugriff auf die WordPress-Datenbank schützen)

## Test-Checkliste

Siehe [INSTALL.md § Testen](INSTALL.md#testen).

## Fehlersuche

- **„The form is currently unavailable" / als Administrator „Error (shown to administrators only): …"** — Die Meldung für angemeldete Administratoren nennt die Ursache (Backend nicht erreichbar, Tenant abgelehnt); *Einstellungen → Ondisos* zeigt den Verbindungsstatus. In Docker ist `localhost` der Container selbst: `http://host.docker.internal:9080/api` bzw. der Dienstname ([INSTALL.md](INSTALL.md#einstellungen))
- **„Unknown form …"** — Das Backend ist erreichbar, kennt das Formular aber nicht für den Tenant: `seed-forms.php`, Formular-Key und Tenant-Slug prüfen
- **„Unauthorized" beim Absenden** — Tenant-API-Secret fehlt oder passt nicht zum Backend
- **Assets 404** — Layout A: `frontend-assets`-Symlink und `FollowSymLinks`; Layout B: `plugins/ondisos-frontend/public/assets/`

Vollständige Liste: [INSTALL.md § Fehlersuche](INSTALL.md#fehlersuche).

## Entwicklung

Änderungen in `wordpress-plugin/` oder `frontend/` sind bei Symlink-/Volume-Betrieb sofort in WordPress wirksam. Die
Plugin-Version (`ondisos.php`, Konstante `ONDISOS_PLUGIN_VERSION`) bei Änderungen an JS/CSS erhöhen — sie ist Teil der
Asset-URLs und umgeht Browser-Caches.

Die PHP-Klassen des Frontends werden im Backend-Projekt getestet (`backend/tests`, siehe [../backend/UNITTESTS.md](../backend/UNITTESTS.md)).

## Lizenz

MIT — siehe [../LICENSE](../LICENSE)

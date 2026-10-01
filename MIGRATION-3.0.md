# Migration auf Version 3.0

Diese Anleitung beschreibt das Upgrade einer bestehenden 2.x-Installation auf 3.0.
Neuinstallationen brauchen sie nicht — dafür genügt [DEPLOYMENT.md](DEPLOYMENT.md).

**Was sich mit 3.0 ändert — in einem Satz:** Backend und Frontend sprechen jetzt
mandantenfähig (Multi-Tenant) miteinander: Jede Schule ist ein *Tenant*, Anfragen des
Frontends werden mit dem API-Secret des Tenants signiert, und die Formular-Konfiguration
liegt in der Datenbank statt in `forms-config.php`.

> Auch wer nur **eine** Schule betreibt, muss migrieren. Der Betrieb bleibt
> Single-Tenant (kein Login-Zwang, keine Oberflächenänderung) — aber Schema, Konfiguration
> und die Frontend↔Backend-Kommunikation haben sich geändert.

---

## Inhalt

1. [Überblick: Was ist neu, was ist inkompatibel?](#1-überblick)
2. [Vorbereitung](#2-vorbereitung)
3. [Migration Schritt für Schritt](#3-migration-schritt-für-schritt)
4. [WordPress-Plugin](#4-wordpress-plugin)
5. [Prüfen](#5-prüfen)
6. [Danach: Formular-Konfiguration ändern](#6-danach-formular-konfiguration-ändern)
7. [Eigene API-Clients](#7-eigene-api-clients)
8. [Rollback](#8-rollback)
9. [Fehlersuche](#9-fehlersuche)

---

## 1. Überblick

### Inkompatible Änderungen

| Bereich | 2.x | 3.0 | Aufwand |
|---|---|---|---|
| **Datenbank** | Eine Tabelle `anmeldungen` | Neue Tabellen `tenants`, `tenant_admins`, `form_configs`; Spalte `anmeldungen.tenant_id` | `migrate.php` (automatisch im Docker-Backend) |
| **Formular-Konfiguration** | `frontend/config/forms-config.php` | Tabelle `form_configs`; das Frontend holt sie per API (`/api/form-config.php`) | `seed-forms.php` |
| **Frontend → Backend** | Unsignierte Requests | Jeder Request trägt `?tenant=<slug>` und `X-Signature` (HMAC-SHA256) | Zwei neue Variablen in der Frontend-`.env` |
| **API-Secret** | `API_SECRET_KEY` war praktisch unbenutzt | Wird das Secret von Tenant 1; bekannte Standardwerte werden abgelehnt | Echtes Secret erzeugen |
| **Uploads** | `uploads/<datei>` | `uploads/tenant-<id>/<datei>` | automatisch durch `migrate.php` |
| **Docker-Entrypoint** | Migration gab es nicht | `migrate.php` läuft bei **jedem** Containerstart | nichts |
| **WordPress-Plugin** | Config aus `forms-config.php` | Config vom Backend; neue Einstellungen *Tenant-Slug* und *Tenant-API-Secret* (Plugin ≥ 2.1.0) | Plugin aktualisieren + 2 Einstellungen |
| **Compose-Aufruf** | `docker-compose` | `docker compose` (Compose-Plugin) | Skripte/Cronjobs anpassen |

### Unverändert

- Formulardefinitionen (`frontend/surveys/*.json`) und Themes
- Status-Workflow, Excel-Export, Papierkorb, Auto-Expunge
- PDF-Download-Tokens (`PDF_TOKEN_SECRET`) — bestehende Links bleiben bis zum Ablauf gültig
- Admin-Login-Verhalten im Single-Tenant-Betrieb (`AUTH_ENABLED` wie bisher)
- Shortcode `[ondisos form="…"]`

### Frontend und Backend gemeinsam aktualisieren

Ein 3.0-Backend weist unsignierte Anfragen eines 2.x-Frontends mit `401` ab, und ein
3.0-Frontend kann nicht gegen ein 2.x-Backend arbeiten. Plane daher ein kurzes
Wartungsfenster, in dem **beide** Seiten umgestellt werden (typischerweise wenige Minuten).

---

## 2. Vorbereitung

### 2.1 Backup (Pflicht)

Die Migration erweitert das Schema (ändert aber keine Anmeldedaten) und verschiebt
Upload-Dateien. Sichere vorher Datenbank **und** Uploads:

```bash
# Credentials aus der Root-.env laden
source /pfad/zu/ondisos/.env

# Datenbank
docker compose exec mysql mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > backup-vor-3.0-$(date +%Y%m%d).sql

# Uploads (Docker-Volume)
docker run --rm -v backend_backend-uploads:/data -v "$(pwd)":/backup \
  alpine tar czf /backup/uploads-vor-3.0-$(date +%Y%m%d).tar.gz -C /data .
```

Manuelle Installation: `mysqldump` wie gewohnt und `tar czf uploads-vor-3.0.tar.gz backend/uploads`.

Weitere Hinweise zu Backups: [DEPLOYMENT.md § Backup](DEPLOYMENT.md) und [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md).

### 2.2 Voraussetzungen prüfen

- PHP 8.2+ (unverändert), MySQL 8.0+ / MariaDB 10.5+
- Docker: Compose-Plugin **≥ 2.24** (`docker compose version`), nicht mehr das alte `docker-compose`
- Vorhandene `frontend/config/forms-config.php` — sie wird für den Seed gebraucht (siehe 3.5)

### 2.3 Spalte `pdf_config` vorhanden?

Die Spalte `anmeldungen.pdf_config` (PDF-Einstellungen pro Anmeldung) wird **nicht** von
`migrate.php` angelegt. Installationen, die vor dieser Funktion aufgesetzt wurden, müssen sie
einmalig ergänzen:

```bash
docker compose exec mysql mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW COLUMNS FROM anmeldungen LIKE 'pdf_config'"
```

Erscheint keine Zeile, führe `database/migrations/add_pdf_config_column.sql` aus:

```bash
docker compose exec -T mysql mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < database/migrations/add_pdf_config_column.sql
```

### 2.4 Secrets erzeugen

```bash
openssl rand -hex 32   # → API_SECRET_KEY  (neu, siehe 3.2)
```

`PDF_TOKEN_SECRET` bleibt unverändert.

---

## 3. Migration Schritt für Schritt

### 3.1 Code aktualisieren

```bash
cd /pfad/zu/ondisos
git fetch --tags
git checkout v3.0.0        # Release-Tag (sobald veröffentlicht), sonst: git pull auf dem Release-Branch
```

### 3.2 Root-`.env` anpassen

```bash
# Pflicht: echtes Secret (ersetzt jeden bisherigen Wert / Standardwert)
API_SECRET_KEY=<ausgabe von openssl rand -hex 32>

# Bleibt wie bisher
PDF_TOKEN_SECRET=...
```

`API_SECRET_KEY` wird zum Secret von **Tenant 1 ("Default")**. Das Frontend muss exakt dieses
Secret verwenden (3.4).

> **Sicherheitsregel:** Bekannte Platzhalter und Standardwerte (`CHANGE_ME_IN_PRODUCTION`,
> `dev-api-key-replace-in-production`) authentifizieren nichts. In Production
> (`APP_ENV=production`) bricht `migrate.php` mit einer Fehlermeldung ab, wenn
> `API_SECRET_KEY` so ein Wert ist. Siehe [SecretPolicy](backend/src/Services/SecretPolicy.php).

Optional, nur für Mehrschul-Betrieb: `MULTI_TENANT_ENABLED=true` — siehe
[MULTI-TENANT.md](MULTI-TENANT.md). Für den Single-Tenant-Betrieb **nicht** setzen.

### 3.3 Backend starten / migrieren

**Docker (empfohlen):** Der Container führt `migrate.php` bei jedem Start aus.

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build backend
docker compose logs backend | grep -E "Step|Migration|Error"
```

**Manuell:**

```bash
cd backend
composer install --no-dev --optimize-autoloader
php migrate.php
```

Erwartete Ausgabe bei einem Upgrade (gekürzt, Wortlaut im Detail abweichend; die Schritte sind
idempotent und dürfen mehrfach laufen — bereits erledigte melden `SKIPPED`):

```
Step 1: Create tenants table... OK
Step 2: Create tenant_admins table... OK
Step 3: Create form_configs table... OK
Step 4: Seed default tenant... OK
Step 4b: Add slug column to tenants... ADDED
Step 4d: Move existing uploads to uploads/tenant-1/... File migration: 3 moved, 0 already in place.
Step 5: Add tenant_id column to anmeldungen... OK
Step 7: Add foreign key fk_anmeldung_tenant... OK
Migration complete.
```

(Nur wenn `schema.sql` den Platzhalter-Tenant bereits angelegt hatte, erscheint zusätzlich
`Step 4a: Replace placeholder secret of default tenant... OK`.)

Was passiert: Die Tabellen entstehen, Tenant 1 (`slug = default`) wird angelegt und erhält
`API_SECRET_KEY`, alle bestehenden Anmeldungen werden Tenant 1 zugeordnet, und vorhandene
Uploads wandern nach `uploads/tenant-1/`. Anmeldedaten selbst werden nicht verändert.

> Steht bei Step 4 bereits ein Tenant mit anderem Secret (z. B. nach einem früheren
> Testlauf), bleibt dieses erhalten. Dann in `tenants.php` (Multi-Tenant) bzw. per SQL
> `UPDATE tenants SET api_secret = '…' WHERE id = 1` auf den Wert aus der `.env` setzen.

### 3.4 Formular-Konfiguration übernehmen (`seed-forms.php`)

Das Frontend liest `forms-config.php` nicht mehr, sondern fragt die Konfiguration beim Backend
ab. Übernimm die bestehende Datei einmalig in die Datenbank:

```bash
# Manuelle Installation: liest ../frontend/config/forms-config.php
cd backend && php seed-forms.php
```

```bash
# Docker: das Backend-Image sieht das Frontend-Verzeichnis nicht. Lege die Datei dort ab,
# wo seed-forms.php als Fallback sucht (backend/config/forms-config.php):
cp frontend/config/forms-config.php backend/config/forms-config.php
docker compose exec backend php seed-forms.php
```

Ausgabe: `Seeded: bs`, `Seeded: bk`, … Das Skript schreibt für **Tenant 1** und verwendet
`INSERT IGNORE`: neue Formular-Keys werden hinzugefügt, bereits vorhandene Einträge **nie** überschrieben
(Änderungen an bestehenden: Abschnitt 6).

Kontrolle:

```bash
docker compose exec mysql mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  -e "SELECT tenant_id, form_key FROM form_configs"
```

Erst wenn alle Formulare auftauchen, können `frontend/config/forms-config.php` und
`backend/config/forms-config.php` gelöscht werden.

### 3.5 Frontend aktualisieren

Standalone-Frontend (Apache/Nginx): Code aktualisieren (`git pull`) und in `frontend/.env`
**zwei Variablen ergänzen**:

```bash
BACKEND_API_URL=http://intranet.example.com:9080/api   # unverändert
TENANT_SLUG=default                                    # Slug von Tenant 1
TENANT_API_SECRET=<derselbe Wert wie API_SECRET_KEY im Backend>
```

Die Datei enthält ein Secret: `chmod 600 frontend/.env`, nicht ins Repository, nie in den Browser.
Der Wert wird nur serverseitig zum Signieren benutzt und nie an den Browser gesendet.

Docker-Dev-Setup (`--profile dev`): `docker-compose.yml` setzt `TENANT_SLUG=default` und
`TENANT_API_SECRET=${API_SECRET_KEY}` bereits selbst — nichts zu tun.

---

## 4. WordPress-Plugin

Plugin-Version **2.1.0** oder neuer. Es lädt die Formular-Konfiguration jetzt vom Backend und
signiert Anfragen wie das Standalone-Frontend.

1. Plugin-Code aktualisieren (Git-Pull im verlinkten Repository bzw. Dateien ersetzen).
   Erwartetes Layout: `plugins/ondisos/` (Plugin) und `plugins/ondisos-frontend/`
   (Frontend-Assets) — Details in [wordpress-plugin/INSTALL.md](wordpress-plugin/INSTALL.md).
2. WordPress → *Einstellungen → Ondisos*:
   - **Backend API URL:** wie bisher
   - **Tenant-Slug:** `default` (oder leer lassen)
   - **Tenant-API-Secret:** Secret von Tenant 1 (= `API_SECRET_KEY`). Das Feld wird nie
     angezeigt; leer lassen bedeutet „unverändert".
   - Alternativ: `TENANT_SLUG` / `TENANT_API_SECRET` in `plugins/ondisos-frontend/.env`. Die
     WordPress-Einstellungen haben Vorrang.
3. Die Shortcodes bleiben gleich: `[ondisos form="bs"]`. Ein Attribut `tenant="…"` gibt es nicht —
   der Tenant gehört zur Installation, nicht zur Seite.

---

## 5. Prüfen

```bash
# Backend gesund?
curl -s http://backend.example.com/api/health.php

# Formular-Konfiguration erreichbar? (Slug = default)
curl -s "http://backend.example.com/api/form-config.php?form=bs&tenant=default"
#   → {"success":true,"config":{...}}

# Datenbank: Tenant, Zuordnung, Formulare
docker compose exec mysql mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  SELECT id, name, slug FROM tenants;
  SELECT tenant_id, COUNT(*) AS anmeldungen FROM anmeldungen GROUP BY tenant_id;
  SELECT tenant_id, form_key FROM form_configs;"
```

Dann im Browser eine **Testanmeldung** durchführen und prüfen:

- [ ] Formular lädt (Standalone `index.php?form=bs` bzw. WordPress-Seite)
- [ ] Absenden erfolgreich, Eintrag erscheint im Backend mit Status `neu`
- [ ] PDF-Bestätigung lässt sich herunterladen (falls aktiviert)
- [ ] Datei-Upload funktioniert; die Datei liegt unter `uploads/tenant-1/`
- [ ] Bestehende Anmeldungen und ihre Upload-Dateien sind im Backend weiterhin sichtbar
- [ ] `backend/logs/audit.log` enthält Einträge mit `"tenant_id":1`

---

## 6. Danach: Formular-Konfiguration ändern

Die Konfiguration liegt jetzt in `form_configs.config_json` (Inhalt wie ein Eintrag in
`forms-config.php`, als JSON). Eine Admin-Oberfläche dafür ist für 3.1 geplant; bis dahin per SQL:

```sql
-- Konfiguration ansehen
SELECT config_json FROM form_configs WHERE tenant_id = 1 AND form_key = 'bs';

-- Konfiguration ersetzen
UPDATE form_configs SET config_json = '{"db": true, "form": "bs.json", "theme": "survey_theme.json", ...}'
WHERE tenant_id = 1 AND form_key = 'bs';
```

Das Frontend holt die Konfiguration bei jedem Aufruf neu — Änderungen wirken sofort. Ein erneutes
`seed-forms.php` fügt **neue** Formulare hinzu, überschreibt vorhandene Einträge aber **nicht**.

Die Survey-Definitionen (`frontend/surveys/*.json`) liegen weiterhin im Frontend-Verzeichnis.

> **Datenschutzhinweis:** `/api/form-config.php` liefert die Konfiguration eines Formulars an
> jeden, der den Tenant-Slug kennt, ohne Signatur. Sie enthält z. B. `notify_email`. Lege keine
> Geheimnisse in die Formular-Konfiguration.

---

## 7. Eigene API-Clients

Wer `submit.php` oder `upload.php` direkt anspricht (eigene Skripte, andere Frontends), muss
sie anpassen:

- Jede Anfrage trägt den Tenant: `…/api/submit.php?tenant=<slug>`.
- Jede Anfrage trägt den Header `X-Signature` = `HMAC-SHA256(<Nachricht>, <api_secret des Tenants>)`
  als Hex-String (Kleinbuchstaben).

| Endpoint | Signierte Nachricht |
|---|---|
| `submit.php` | der **exakte Raw-Body** (JSON), so wie gesendet |
| `upload.php` | `"<anmeldung_id>:<fieldname>:<dateiname>"` (Dateiname ohne Pfad) |

```php
$body = json_encode($payload);
$sig  = hash_hmac('sha256', $body, $tenantSecret);
// POST https://backend/api/submit.php?tenant=default   Header: X-Signature: $sig
```

```bash
BODY='{"form_key":"bs","data":{"Name":"Muster, Max"},"metadata":{}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$TENANT_SECRET" | sed 's/^.* //')
curl -X POST "https://backend/api/submit.php?tenant=default" -H "X-Signature: $SIG" -d "$BODY"
```

Ohne gültige Signatur antwortet das Backend mit `401 Unauthorized`; ein Upload für einen
Eintrag, der nicht zum Tenant gehört, mit `404` (und einem `idor_attempt`-Eintrag im Audit-Log).

---

## 8. Rollback

Die Migration ist **vorwärts gerichtet** (sie fügt Tabellen/Spalten hinzu und verschiebt Uploads).
Ein Rollback bedeutet deshalb: Zustand aus dem Backup zurückspielen.

```bash
# 1. Container stoppen, alten Code auschecken
docker compose down
git checkout <vorheriger-2.x-commit>

# 2. Datenbank aus dem Backup von 2.1 wiederherstellen
source .env
docker compose up -d mysql
docker compose exec -T mysql mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < backup-vor-3.0-JJJJMMTT.sql

# 3. Uploads zurückspielen (falls verschoben)
docker run --rm -v backend_backend-uploads:/data -v "$(pwd)":/backup \
  alpine sh -c "rm -rf /data/* && tar xzf /backup/uploads-vor-3.0-JJJJMMTT.tar.gz -C /data"

# 4. Backend/Frontend mit dem alten Stand starten
docker compose up -d --build
```

Anmeldungen, die **nach** der Migration eingegangen sind, fehlen im Backup-Stand — exportiere sie
vorher (Excel-Export) oder sichere sie zusätzlich.

---

## 9. Fehlersuche

| Symptom | Ursache | Lösung |
|---|---|---|
| Absenden: „Unauthorized" / `401` im Backend-Log | `TENANT_API_SECRET` im Frontend ≠ `tenants.api_secret`; oder Secret ist Platzhalter/Standardwert | Secrets angleichen. Log-Hinweis: `tenant api_secret is a known placeholder/default` → echtes Secret setzen, `migrate.php` erneut ausführen |
| Absenden: „Backend-Zugang nicht konfiguriert" | `TENANT_API_SECRET` fehlt im Frontend | `frontend/.env` bzw. WP-Einstellung setzen |
| `migrate.php`: „API_SECRET_KEY is a known default" | Production mit Standard-Secret | `openssl rand -hex 32` in die Root-`.env` |
| Seite zeigt „Wartungsmodus" / `503` | Backend nicht erreichbar, oder Formular/Tenant unbekannt | `BACKEND_API_URL` prüfen, `form-config.php?form=…&tenant=…` aufrufen, `form_configs` seeden |
| WordPress: `Unknown form "bs" (or backend unavailable)` | wie oben, oder Plugin < 2.1.0 | Plugin aktualisieren, Backend-URL/Tenant-Slug prüfen |
| `Unknown column 'tenant_id'` im Backend-Log | Migration nicht gelaufen (manuelle Installation) | `php migrate.php` |
| PDF-Link liefert „TenantContext not initialized" | Backend älter als die Fix-Version | Backend auf aktuellen 3.0-Stand bringen |
| Alte Uploads im Backend nicht zu öffnen | Dateien nicht nach `uploads/tenant-1/` verschoben | `php migrate.php` erneut (Step 4d); Schreibrechte auf `uploads/` prüfen |
| `docker-compose: command not found` | Altes Kommando | `docker compose …` (Compose-Plugin installieren) |
| Admin-Login wird abgelehnt, obwohl das Passwort stimmt; `echo ${#ADMIN_PASSWORD_HASH}` im Container zeigt nicht 60 | `ADMIN_PASSWORD_HASH` steht in der Root-`.env` ohne einfache Anführungszeichen — Compose hat den Hash zerstört | `ADMIN_PASSWORD_HASH='$2y$10$…'` (einfache Quotes), dann `docker compose up -d backend`; Warnung im Log: `ADMIN_PASSWORD_HASH looks damaged` |
| Änderung der Root-`.env` (Secret, `ADMIN_*`) kommt im Backend nicht an | Container wurde nur neu gestartet, nicht neu erstellt; oder `backend/.env` ist von Hand angelegt (ohne Marker `# GENERATED-BY-ENTRYPOINT`) und hat Vorrang | `docker compose up -d backend`; bei handgeschriebener Datei dort ändern oder die Datei entfernen |
| `env file …/backend/.env not found` | Compose < 2.24 | Compose-Plugin aktualisieren, oder `touch backend/.env` |

Weitere Hilfe: [MULTI-TENANT.md](MULTI-TENANT.md) (Mehrschul-Betrieb),
[DEPLOYMENT.md](DEPLOYMENT.md) (Betrieb), [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) (Notfälle).

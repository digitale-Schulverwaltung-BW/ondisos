# Betrieb: Updates, Sicherung, HTTPS, Überwachung

Dieses Dokument gilt für ein laufendes Backend. Die Befehle gehen vom Docker-Stack aus ([installation.md](installation.md)) und stehen im Projektverzeichnis
(`export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml` spart die `-f`-Optionen). Notfälle: [notfall.md](notfall.md).

## Updates

### Docker

```bash
# 1. Sichern (siehe unten), vor allem die Datenbank
# 2. Neuen Stand holen und Container neu bauen
git pull origin main                       # oder: git fetch --tags && git checkout <version>
docker compose up -d --build backend
# 3. Prüfen: die Migration läuft beim Start mit
docker compose logs backend | grep "Migration complete"
curl http://localhost:9080/api/health.php
```

Das Backend zuerst, danach die Frontends ([Betriebsmodell](betriebsmodell.md#betrieb-im-alltag)). Hinweise zu einzelnen Versionen: [MIGRATION-3.1.md](MIGRATION-3.1.md).
Ändert sich das Compose-File, genügt `docker compose up -d` (nicht `restart`). Nach einem Update des Basis-Images oder von PHP: `docker compose build --no-cache backend`.

**Zurückrollen:** `git checkout <vorherige-version>` und `docker compose up -d --build backend`. Die Migrationen sind **nur vorwärts**: Hat die neue Version das Schema verändert,
muss zum alten Code auch der Datenbank-Stand von vor dem Update zurückgespielt werden. Deshalb: vor jedem Update sichern.

### Ohne Docker

```bash
cd /var/www/ondisos && git pull origin main
cd backend && composer install --no-dev --optimize-autoloader
php migrate.php                     # idempotent
sudo systemctl reload apache2       # bzw. php8.2-fpm: leert OPcache
```

Frontend (Standalone): `git pull`, sonst nichts. Das WordPress-Plugin aktualisiert man über die neue ZIP bzw. wie in der [Plugin-Anleitung](../../wordpress-plugin/INSTALL.md#aktualisieren) beschrieben.

## Sicherung und Wiederherstellung

**Was gesichert werden muss**

| Was | Wo | Hinweis |
|---|---|---|
| **Datenbank** | Container `mysql` | enthält Anmeldungen, Schulen, Admin-Zugänge (Hashes), Formulare, Entwürfe und Verlauf |
| **Uploads** | Volume `backend-uploads` (`uploads/tenant-<id>/…`) | Anhänge der Anmeldungen, Logos, angehängte PDFs |
| **`.env`-Dateien** | Projektverzeichnis | enthalten die Secrets, in ein **separates, verschlüsseltes** Backup |

**Sichern (Docker)**

```bash
set -a; . ./.env; set +a                                    # DB_USER, DB_PASS, DB_NAME aus der Root-.env

docker compose exec -T mysql mysqldump -u "$DB_USER" -p"$DB_PASS" --no-tablespaces --single-transaction "$DB_NAME" \
  > backup/db-$(date +%Y%m%d).sql

docker compose cp backend:/var/www/html/uploads backup/uploads-$(date +%Y%m%d)
```

Ohne `--no-tablespaces` meldet `mysqldump` den Fehler „you need the PROCESS privilege"; der Dump ist trotzdem brauchbar, die Meldung stört aber Skripte.

**Wiederherstellen (Docker)**

```bash
# Datenbank (in eine leere oder bestehende Datenbank einspielen)
docker compose exec -T mysql mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < backup/db-20261006.sql

# Uploads zurückkopieren; danach Besitzer korrigieren, sonst kann der Webserver dort nicht schreiben
docker compose cp backup/uploads-20261006/. backend:/var/www/html/uploads
docker compose exec -u root backend chown -R www-data:www-data /var/www/html/uploads
```

Ein Dump enthält alle Tabellen und lässt sich so auch auf einem neuen Server einspielen: Stack starten (legt das Schema an und migriert), Dump einspielen, Uploads zurückkopieren.
**Machen Sie regelmäßig einen Probe-Restore**, z. B. in eine Testdatenbank: `CREATE DATABASE restoretest;` und den Dump dort einspielen.

**Täglich per Cron** (Beispiel `/etc/cron.daily/ondisos-backup`, ausführbar machen):

```bash
#!/bin/bash
set -euo pipefail
cd /opt/ondisos                                  # ← Projektverzeichnis
export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml
set -a; . ./.env; set +a
mkdir -p /var/backups/ondisos
docker compose exec -T mysql mysqldump -u "$DB_USER" -p"$DB_PASS" --no-tablespaces --single-transaction "$DB_NAME" \
  > "/var/backups/ondisos/db-$(date +%Y%m%d).sql"
find /var/backups/ondisos -name 'db-*.sql' -mtime +30 -delete        # Aufbewahrung 30 Tage
```

Uploads gehören in dieselbe Routine, z. B. mit `docker compose cp` oder per `rsync` aus dem Volume-Verzeichnis. **Bewahren Sie Sicherungen nicht länger auf, als die Löschfristen erlauben:**
Gelöschte und abgelaufene Anmeldungen sind in alten Dumps noch enthalten.

**Ohne Docker:** `mysqldump -u "$DB_USER" -p"$DB_PASS" --no-tablespaces "$DB_NAME" > db.sql`, `tar czf uploads.tar.gz backend/uploads`; Zugangsdaten stehen in `backend/.env`.

## HTTPS

Zwischen Frontend und Backend ist **HTTPS Pflicht**: Die API-Signaturen enthalten keinen Zeitstempel und schützen allein nicht vor Wiederholung, und die Admin-Oberfläche überträgt Passwörter.

**Docker-Stack: TLS am Reverse-Proxy.** Der Container spricht HTTP (Port 9080); davor steht Nginx, Caddy oder Traefik. Beispiel Nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name backend.example.org;
    ssl_certificate     /etc/letsencrypt/live/backend.example.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/backend.example.org/privkey.pem;

    client_max_body_size 10M;                    # mindestens UPLOAD_MAX_SIZE, siehe unten

    location / {
        proxy_pass http://127.0.0.1:9080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
server { listen 80; server_name backend.example.org; return 301 https://$host$request_uri; }
```

Zertifikat: `sudo certbot --nginx -d backend.example.org`. Stehen Frontends außerhalb des Netzes, reicht der Proxy nur die [benötigten Pfade](betriebsmodell.md#netzwerk-was-muss-erreichbar-sein) durch.

Danach in der **Root-`.env`** setzen und `docker compose up -d backend` ausführen:

```bash
SESSION_SECURE=true     # Sitzungs-Cookies nur über HTTPS
FORCE_HTTPS=true        # leitet HTTP um (beachtet X-Forwarded-Proto)
```

`FORCE_HTTPS=true` leitet **alle** Anfragen um, auch API-Aufrufe. Ein Frontend, das das Backend noch über `http://` anspricht, bekommt dann 301 statt Antworten:
erst `BACKEND_API_URL` auf `https://` umstellen.

**HSTS** erst aktivieren, wenn HTTPS durchgängig funktioniert; es lässt sich kaum zurücknehmen:
`add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;` (Nginx).

**Ohne Docker (Apache):** `cp backend/public/.htaccess.example backend/public/.htaccess` und die auskommentierten Zeilen für den HTTPS-Redirect und die Security-Header
(HSTS, X-Frame-Options, CSP) aktivieren; alternativ `FORCE_HTTPS=true` in `backend/.env`. Das Frontend ebenso (`frontend/public/.htaccess.example`).
Die Vorschau im Formular-Editor braucht `X-Frame-Options: SAMEORIGIN` (nicht `DENY`); das mitgelieferte Docker-Image setzt es.

## Reverse-Proxy: Client-IP und Rate-Limit

Steht ein Reverse-Proxy vor dem Backend (siehe [HTTPS](#https)), sieht das Backend als Absender jeder Anfrage die Adresse des Proxys. Ohne Gegenmaßnahme teilen sich dann **alle Schulen ein gemeinsames Rate-Limit**
(`RATE_LIMIT_MAX` je `RATE_LIMIT_WINDOW`, auch Formular-API, Downloads und Formular-Editor), und das Audit-Log zeigt überall dieselbe IP.

Tragen Sie die Adresse(n) des Proxys in `TRUSTED_PROXIES` ein (Root-`.env` bei Docker, sonst `backend/.env`; kommagetrennte IPs oder CIDR-Bereiche, IPv4 und IPv6):

```bash
TRUSTED_PROXIES=127.0.0.1,172.16.0.0/12     # Beispiel: Proxy auf dem Host und im Docker-Netz
```

Danach `docker compose up -d backend`. Regeln:

- **Leer (Standard):** `X-Forwarded-For` wird ignoriert; maßgeblich ist die Adresse der Verbindung.
- **Nur wenn die Verbindung von einem vertrauenswürdigen Proxy kommt**, wertet das Backend `X-Forwarded-For` aus, von rechts nach links: der erste Eintrag, der selbst kein vertrauenswürdiger Proxy ist, gilt als Client. Mehrere Proxys hintereinander tragen Sie alle ein.
- Ist der Header ungültig, gilt die Adresse des Proxys.
- Tragen Sie **nur Ihre eigenen Proxys** ein, nie `0.0.0.0/0`: Sonst kann jeder seine Adresse fälschen und das Rate-Limit umgehen.
- Der Proxy muss `X-Forwarded-For` setzen und dabei den vorhandenen Wert **anhängen** (Nginx: `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`, wie im Beispiel oben).

Dasselbe gilt für das **Frontend**: Steht dort ein Proxy davor (Docker: Root-`.env`, sonst `frontend/.env` bzw. Umgebung des Webservers; im WordPress-Plugin die Umgebung des Webservers), speichert es die Client-IP der Anmeldung nach derselben Regel. Es trägt dieselbe Einstellung `TRUSTED_PROXIES` ein; ohne sie steht die Adresse der Verbindung in der Anmeldung (frühere Versionen übernahmen `X-Forwarded-For` und ähnliche Header ungeprüft).

Prüfen: Ein Eintrag im Audit-Log (`backend/logs/audit.log`) zeigt im Feld `ip` die Adresse des Clients statt der des Proxys.

## Upload-Limits

Damit Uploads durchgehen, müssen **vier Grenzen** zusammenpassen; die kleinste gilt:

| Ebene | Einstellung | Empfohlen |
|---|---|---|
| Anwendung (Backend und Frontend) | `UPLOAD_MAX_SIZE` (Bytes) | `10485760` (10 MB) |
| PHP (Backend und Frontend) | `upload_max_filesize`, `post_max_size` | 10 MB, 12 MB (größer als die Dateigröße) |
| Webserver / Reverse-Proxy | Nginx `client_max_body_size`, Apache `LimitRequestBody` | 10–20 MB |
| Erlaubte Typen | `UPLOAD_ALLOWED_TYPES` (Backend **und** Frontend) | nur, was nötig ist |

**Im Docker-Backend** stehen die PHP-Werte fest in `backend/docker/php/php.ini` (10 MB / 12 MB). Zum Ändern eine eigene Datei einbinden, die nach der `php.ini` geladen wird:

```bash
printf 'upload_max_filesize = 20M\npost_max_size = 22M\n' > custom-uploads.ini
```

```yaml
# custom-uploads.yml
services:
  backend:
    volumes:
      - ./custom-uploads.ini:/usr/local/etc/php/conf.d/zz-uploads.ini:ro
```

Diese Datei beim Start als weitere Compose-Datei angeben (`COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml:custom-uploads.yml`).

Anschließend `docker compose up -d backend` und mit `docker compose exec backend php -i | grep -E 'upload_max_filesize|post_max_size'` prüfen.
Das **Frontend** hat eigene PHP- und Webserver-Limits: Besucher laden zuerst dort hoch, erst danach reicht das Frontend die Datei ans Backend weiter.

**Symptome:** `HTTP 413 Request Entity Too Large` → Webserver-Limit; Fehler im Formular ohne 413 oder „exceeds the upload_max_filesize" → PHP-Limit; „Dateityp nicht erlaubt" → Typ bzw. echter Inhalt passt nicht zu `UPLOAD_ALLOWED_TYPES`.

## Überwachung und Protokolle

| Was | Wo |
|---|---|
| Container-Status | `docker compose ps` (Backend: *healthy*) |
| Gesundheitsprüfung | `curl https://backend.example.org/api/health.php` → `{"status":"ok",…}` |
| Backend-Ausgabe, Migration | `docker compose logs -f backend` |
| PHP-Fehler | `docker compose exec backend tail -f /var/www/html/logs/php_errors.log` |
| **Audit-Log** (Anmeldungen, Statuswechsel, Uploads, Löschungen, fremde IDs) | `docker compose exec backend tail -f /var/www/html/logs/audit.log` (JSON-Zeilen mit Schule) |
| ClamAV | `docker compose logs -f clamav` |

`logs/` und `cache/` liegen in eigenen Docker-Volumes. Docker-Logs begrenzen Sie in `/etc/docker/daemon.json` (`{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"}}`, danach Docker neu starten).
Eine Überwachung (z. B. Uptime-Check auf `/api/health.php`) ist sinnvoll; sie gehört zum Betreiber, nicht zum Projekt.

**Automatisches Aufräumen:** Archivierte Anmeldungen werden nach `AUTO_EXPUNGE_DAYS` (Standard 90) **samt Uploads endgültig gelöscht**. Der Lauf passiert „bei Gelegenheit" (alle 6 Stunden, ausgelöst durch Seitenaufrufe);
Status und nächster Lauf stehen im Dashboard. Dieselbe Frist gilt für alle Schulen; stimmen Sie sie mit den Schulen ab, bevor Sie sie ändern.

## Docker aufräumen

```bash
docker image prune            # ungenutzte Images
docker compose down           # Container stoppen und entfernen; Volumes (Daten!) bleiben erhalten
```

**Vorsicht:** `docker compose down -v` und `docker system prune --volumes` löschen auch die Volumes, also Datenbank und Uploads.

## Weitere Fehlerbilder

| Beobachtung | Ursache und Abhilfe |
|---|---|
| `Unknown column 'tenant_id'` | Die Migration ist nicht gelaufen: `php backend/migrate.php` (Docker: läuft bei jedem Start, `docker compose logs backend` prüfen) |
| `Class not found` | Abhängigkeiten fehlen: ohne Docker `composer install --no-dev --optimize-autoloader` im `backend/` |
| Auto-Expunge läuft nicht | `AUTO_EXPUNGE_DAYS` muss größer 0 sein, `cache/` beschreibbar (dort liegt `last_expunge.txt`); das Dashboard zeigt Status und nächsten Lauf |
| Excel-Export zeigt die Spalte „Formular“ | Der Export war nicht auf ein Formular gefiltert (`?form=bs`); beim Export eines einzelnen Formulars entfällt die Spalte |
| Datumsfelder im Export falsch | Der Export wandelt Werte der Form `YYYY-MM-DD` in `dd.mm.yyyy` um; das Feld muss ISO-Format enthalten |
| Browser meldet CORS-Fehler | Betrifft nur Browser-Aufrufe direkt am Backend (normalerweise ruft das Frontend serverseitig auf): `origin` der Schule unter *Tenants* bzw. `ALLOWED_ORIGINS` prüfen |
| Anmeldung „erfolgreich“, aber nichts im Backend | Das Formular ist mit `db: false` konfiguriert und sendet nur E-Mail; unter *Formulare → Bearbeiten* „Anmeldungen im Backend speichern“ einschalten |

## Checkliste für den Produktivbetrieb

- [ ] **Secrets:** `DB_PASS`, `MYSQL_ROOT_PASSWORD`, `PDF_TOKEN_SECRET`, `API_SECRET_KEY` selbst erzeugt (`openssl rand -hex 32`), kein Standardwert
- [ ] **Admin-Zugang:** `ADMIN_PASSWORD_HASH` in einfachen Anführungszeichen, Länge 60 geprüft; starkes Passwort
- [ ] **Migration** gelaufen (`Migration complete` im Log), Formulare eingespielt, Testanmeldung durchgespielt (Eintrag, PDF-Download, Upload)
- [ ] **HTTPS** für Backend und Frontend; `SESSION_SECURE=true`; HSTS erst nach erfolgreichem Test
- [ ] **Netz:** MySQL und ClamAV nicht veröffentlicht (`docker compose ps`: nur 9080 des Backends); Backend-Oberfläche nur im Intranet, nach außen nur die API-Pfade
- [ ] **Sicherung:** täglicher Dump, Uploads, `.env`-Dateien separat verschlüsselt; Probe-Restore durchgeführt
- [ ] **Updates:** Verfahren und Zuständigkeit festgelegt; Docker-Logs begrenzt
- [ ] **Upload-Limits** auf allen vier Ebenen abgestimmt
- [ ] **Berechtigungen:** `.env`-Dateien `chmod 600`, nicht im Repository; ohne Docker `uploads`, `cache`, `logs` für den Webserver beschreibbar
- [ ] **Frontend-Cache** (`frontend/cache`, WordPress: `wp-content/uploads`) beschreibbar
- [ ] **Firewall:** nur 80/443 (und SSH) von außen

Gegenproben für die Sicherheits-Header: `curl -I https://backend.example.org/login.php` und `curl -I http://backend.example.org/` (erwartet 301 auf `https://`).

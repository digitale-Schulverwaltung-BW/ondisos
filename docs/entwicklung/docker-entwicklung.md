# Docker für die Entwicklung

Entwicklungs- und Testumgebung mit Docker: Backend, MySQL, Frontend und optional phpMyAdmin und ClamAV. Für den **Produktivbetrieb** gilt [installation.md](../betreiber/installation.md);
diese Seite beschreibt den Entwicklungsstack aus `docker-compose.yml` ohne das Produktions-Overlay.

## Start

```bash
git clone https://github.com/digitale-Schulverwaltung-BW/ondisos.git && cd ondisos
cp .env.example .env                                   # Beispielwerte genügen für die Entwicklung
docker compose --profile dev up -d                     # Backend + MySQL + Frontend + phpMyAdmin

# Formulare einspielen (ohne sie gibt es nichts anzuzeigen)
cp frontend/config/forms-config-dist.php frontend/config/forms-config.php
docker compose exec -T backend php seed-forms.php - < frontend/config/forms-config.php
```

Das Schema `database/schema.sql` wird beim ersten Start der Datenbank importiert, die Migration (`migrate.php`) läuft bei **jedem** Start des Backends.
Das Frontend bekommt `TENANT_SLUG=default` und das Secret von Tenant 1 automatisch aus der Compose-Datei.

| Dienst | Adresse | Hinweis |
|---|---|---|
| Backend | http://localhost:9080 | ohne Login (`AUTH_ENABLED=false`); API unter `/api/` |
| Frontend | http://localhost:8081/index.php?form=bs | nur mit `--profile dev` |
| phpMyAdmin | http://localhost:8082 | nur mit `--profile dev`, nie in Produktion |
| MySQL | `localhost:3306` | in der Entwicklung veröffentlicht; Zugang `anmeldung` / `secret123`, Root `rootpass123` (Standardwerte der `.env.example`) |
| ClamAV | – | nur mit `--profile clamav` |

`APP_ENV=development` und `APP_DEBUG=true` sind gesetzt; bekannte Standard-Secrets werden hier akzeptiert (in Produktion nicht).

## Code ändern

`./backend` und `./frontend` sind in die Container eingehängt: PHP-Änderungen wirken sofort, ohne Neustart. Nach Änderungen am `Dockerfile` oder an Abhängigkeiten:
`docker compose build backend` bzw. `docker compose exec backend composer require vendor/paket`.

> **Mehrere Stacks parallel?** Alle Stacks, die dasselbe Repository einhängen, teilen sich `backend/.env`: Der Entrypoint **schreibt sie bei jedem Start neu**
> (Marker `# GENERATED-BY-ENTRYPOINT`), und diese Datei hat Vorrang vor der Container-Umgebung. Startet ein zweiter Stack, verliert der erste seine Datenbank-Zugangsdaten
> („Access denied", HTTP 500) – bis sein Container neu erstellt wird. Für parallele Stacks je ein eigenes Arbeitsverzeichnis (Clone/Worktree) verwenden.

## Tests

```bash
cd backend
composer install
composer test                          # alle Tests (Unit + Integration)
composer test -- --testsuite=Unit      # nur Unit-Tests, brauchen keine Datenbank
composer test:filter RateLimiterTest   # eine Klasse
composer test:coverage                 # Coverage-Bericht nach backend/coverage/
```

Die Integration-Tests brauchen eine MySQL-Datenbank mit `database/schema.sql` (siehe [UNITTESTS.md](../../backend/UNITTESTS.md)).

**Tests im Container:** Einige Tests lesen Dateien außerhalb von `backend/` (`frontend/`, `docs/`, `README.md`, …). `docker-compose.override.yml`
hängt sie nur lesend unter `/var/www/` ein; Compose lädt sie bei `docker compose up` automatisch mit, damit laufen `make test` und
`docker compose exec backend composer test` vollständig. Im Produktivbetrieb (`-f docker-compose.yml -f docker-compose.prod.yml`) wird die Datei
nicht geladen, der Backend-Container bleibt dort ohne diese Verzeichnisse. Nach dem Aktualisieren die Container neu anlegen: `docker compose --profile dev up -d`.

## Alltag

```bash
docker compose logs -f backend                      # Ausgabe; mysql, frontend, clamav analog
docker compose exec backend bash                    # Shell im Backend
docker compose exec mysql mysql -u anmeldung -psecret123 anmeldung       # MySQL-Konsole

docker compose exec backend tail -f /var/www/html/logs/php_errors.log    # PHP-Fehler
docker compose exec backend tail -f /var/www/html/logs/audit.log         # Audit-Log (JSON-Zeilen)
```

**Volumes** (`<Verzeichnisname>_<Volume>`, z. B. `ondisos_mysql-data`): `mysql-data` (Datenbank), `backend-uploads` (Uploads, `tenant-<id>/…`), `backend-cache`, `backend-logs`, `clamav-data` (Signaturen).
Anzeigen mit `docker volume ls`.

```bash
docker compose down                       # Container weg, Daten bleiben
docker compose down -v                    # ⚠️ löscht auch Datenbank und Uploads
docker compose build --no-cache backend   # Image neu bauen
```

## Virenscan ausprobieren

```bash
docker compose --profile clamav up -d      # lädt beim ersten Start ca. 300 MB Signaturen (60–90 s); VIRUS_SCAN_ENABLED=true in der .env
```

Auf Apple-Silicon/ARM gibt es kein ClamAV-Image; für einen Test in der Compose-Datei beim Dienst `clamav` `platform: linux/amd64` setzen (Emulation).
Eine Funktionsprobe mit der EICAR-Testdatei steht in [installation.md](../betreiber/installation.md#virenscan-optional).

## Fehlersuche

| Beobachtung | Abhilfe |
|---|---|
| `port is already allocated` | Port in `docker-compose.yml` ändern oder den Prozess beenden (`lsof -i :9080`) |
| Backend startet nicht, MySQL-Fehler | `docker compose logs mysql`; Zugangsdaten gelten nur beim ersten Start des Volumes ([installation.md](../betreiber/installation.md#datenbank-zugriff-verweigert)) |
| `vendor/autoload.php` fehlt | `docker compose exec backend composer install` |
| Berechtigungen auf `uploads`/`cache` | `docker compose exec -u root backend chown -R www-data:www-data /var/www/html/uploads /var/www/html/cache` |
| Änderung ohne Wirkung | OPcache: `docker compose restart backend` |

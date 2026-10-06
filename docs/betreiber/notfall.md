# Notfall-Handbuch

Für den Fall, dass etwas ausfällt, Daten fehlen oder ein Sicherheitsvorfall droht. Ruhe bewahren und der Reihe nach vorgehen.
Alle Befehle gehen vom Docker-Stack aus ([installation.md](installation.md)) und stehen im Projektverzeichnis
(`export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml`). Sicherung und Wiederherstellung im Normalbetrieb: [betrieb.md](betrieb.md#sicherung-und-wiederherstellung).

**Tragen Sie vorab Ihre Ansprechpartner ein** (diese Tabelle ist eine Vorlage):

| Rolle | Name, Kontakt | Erreichbar |
|---|---|---|
| Administration des Backends | | |
| Schul-IT der angebundenen Schulen | | |
| Datenschutz (bei Verdacht auf Datenabfluss) | | |
| Entwicklung / Hersteller | | |

## Erste Hilfe: Überblick verschaffen

```bash
docker compose ps                                    # laufen backend und mysql? healthy?
docker compose logs --tail=100 backend
docker compose logs --tail=100 mysql
curl -s http://localhost:9080/api/health.php         # {"status":"ok",…}
df -h; free -h                                       # Speicher und Arbeitsspeicher
```

Was die Besucher merken: Fällt das **Backend** aus, bleiben Formulare aus dem Cache der Frontends bis zu 7 Tage sichtbar, **Absenden schlägt fehl**.
Das Sekretariat kommt nicht mehr in die Oberfläche. Bereits gespeicherte Daten sind dadurch nicht gefährdet.

## 1. Das Backend ist nicht erreichbar

1. **Container neu starten:** `docker compose restart`, 30 Sekunden warten, `docker compose ps`.
2. **Neu erstellen** (übernimmt auch geänderte Einstellungen): `docker compose up -d --force-recreate backend`.
3. **Docker selbst prüfen:** `sudo systemctl status docker`, bei Bedarf `sudo systemctl restart docker`, danach `docker compose up -d`.
4. Startet der Container immer wieder neu (`Restarting` in `docker compose ps`)? Das Log nennt die Ursache. Häufig:

| Meldung im Log | Ursache und Abhilfe |
|---|---|
| `API_SECRET_KEY is a known default/placeholder` | `API_SECRET_KEY` in der Root-`.env` ist ein Standardwert: echten Wert setzen (`openssl rand -hex 32`) |
| `Access denied for user 'anmeldung'` | Datenbank-Zugangsdaten passen nicht zum MySQL-Volume: [installation.md](installation.md#datenbank-zugriff-verweigert) |
| `ADMIN_PASSWORD_HASH looks damaged` | Hash nicht in einfachen Anführungszeichen: [installation.md](installation.md#login-abgelehnt-obwohl-das-passwort-stimmt) |
| `Permission denied` bei `uploads`/`cache` | `docker compose exec -u root backend chown -R www-data:www-data /var/www/html/uploads /var/www/html/cache` |
| `bind: address already in use` | Port 9080 belegt: Prozess ermitteln (`sudo lsof -i :9080`) oder anderen Host-Port wählen |
| `Killed`, Speichermangel (`dmesg`) | Arbeitsspeicher erhöhen oder das Limit in `docker-compose.prod.yml` (`deploy.resources.limits.memory`) anheben |

Beim Start wartet der Backend-Container auf MySQL (Healthcheck); ein „Connection refused" kurz nach dem Start legt sich meist von selbst.

## 2. Datenbank defekt oder leer

Ondisos verwendet InnoDB. Die früher übliche „Reparatur" mit `mysqlcheck --auto-repair` greift bei InnoDB nicht; der sichere Weg ist die **Wiederherstellung aus dem Dump**.

```bash
set -a; . ./.env; set +a
docker compose stop backend                                     # keine Schreibzugriffe während der Wiederherstellung
docker compose exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" \
  -e "DROP DATABASE IF EXISTS anmeldung; CREATE DATABASE anmeldung CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
docker compose exec -T mysql mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < backup/db-20261006.sql
docker compose start backend                                    # die Migration läuft beim Start und bringt auch ältere Dumps auf den aktuellen Stand
curl -s http://localhost:9080/api/health.php
```

(Haben Sie `DB_NAME` geändert, den Namen oben anpassen.) Der Dump enthält Anmeldungen, Schulen mit ihren API-Schlüsseln, Admin-Zugänge, Formulare, Entwürfe und Verlauf;
der Ablauf ist in der Testumgebung durchgespielt. **Nicht** enthalten: die `.env`-Dateien (separat sichern) und die Uploads.

Lässt sich MySQL gar nicht mehr starten und gibt es **keine** Sicherung, bleibt als letzter Ausweg das Sichern des Datenverzeichnisses
(`docker volume ls`, dann das Volume `…_mysql-data` mit einem Hilfs-Container als Archiv ablegen) und der Neuaufbau nach [installation.md](installation.md#datenbank-zugriff-verweigert), Punkt 3.

## 3. Daten fehlen

1. **Backend stoppen**, damit nichts überschrieben wird: `docker compose stop backend`. Erst klären, dann starten.
2. **Wirklich weg?** In der Oberfläche den **Papierkorb** prüfen (↩️ Wiederherstellen); archivierte Anmeldungen werden nach `AUTO_EXPUNGE_DAYS` endgültig gelöscht ([Hinweis](betrieb.md#überwachung-und-protokolle)).
   Das **Audit-Log** (`logs/audit.log`) zeigt, wer wann gelöscht oder den Status geändert hat.
3. **Aus der Sicherung wiederherstellen:** Datenbank wie in Abschnitt 2, Uploads wie in [betrieb.md](betrieb.md#sicherung-und-wiederherstellung) (Besitzer korrigieren!).
   Wählen Sie die jüngste Sicherung **vor** dem Verlust. Uploads liegen pro Schule unter `uploads/tenant-<id>/`, das Volume immer als Ganzes zurückspielen.
4. **Secrets angleichen:** Weicht der Schlüssel einer Schule nach dem Restore vom Frontend ab, antwortet das Backend mit `401`. Den Schlüssel unter *Tenants → Schule → API-Schlüssel erneuern*
   neu erzeugen und im Frontend eintragen.

## 4. Verdacht auf Kompromittierung

Anzeichen: unbekannte Anmeldungen im Backend, verdächtige Uploads, `login_failed` oder `idor_attempt` im Audit-Log, Warnungen von Firewall oder Provider.

**Sofort (in den ersten Minuten):**

1. **Isolieren:** Zugriff von außen sperren (Firewall bzw. Reverse-Proxy), Backend bei Bedarf stoppen (`docker compose stop backend`).
2. **Spuren sichern, bevor Sie etwas ändern:** `docker compose logs > incident-$(date +%Y%m%d_%H%M).log`, das Audit-Log und die Logs des Reverse-Proxys kopieren.
3. **Datenschutz informieren.** Bei Verdacht auf Abfluss personenbezogener Daten gelten Meldefristen; entscheiden Sie das nicht allein.

**Untersuchen (nur lesen):**

```bash
grep -E 'login_failed|idor_attempt|upload' logs/audit.log                # im Container: /var/www/html/logs/audit.log
docker compose exec backend find /var/www/html/uploads -type f \( -name '*.php' -o -perm -u+x \)   # sollte leer sein
docker compose exec backend ps aux
```

**Secrets erneuern.** Alle Secrets gelten als bekannt, sobald Server, `.env`-Dateien oder Sicherungen in falsche Hände geraten sein könnten:

| Secret | Wo | Erneuern |
|---|---|---|
| **API-Schlüssel einer Schule** | Backend-Datenbank **und** Frontend (`TENANT_API_SECRET` bzw. WordPress-Einstellung) | *Tenants → Schule → API-Schlüssel erneuern* (der alte ist sofort ungültig, geprüft); **sofort** den neuen Wert im Frontend eintragen, bis dahin lehnt das Backend dessen Anfragen mit 401 ab. `API_SECRET_KEY` in der `.env` ist nur der Startwert von Tenant 1 und danach unerheblich (muss aber ein echter Wert bleiben) |
| `PDF_TOKEN_SECRET` | Root-`.env` | neu erzeugen; offene PDF-Links werden ungültig |
| Datenbank-Passwörter | Root-`.env` **und** MySQL | im MySQL ändern (`ALTER USER`, siehe [installation.md](installation.md#datenbank-zugriff-verweigert)) und in der `.env` angleichen |
| Admin-Zugang | `ADMIN_PASSWORD_HASH` | neuen Hash erzeugen (`scripts/generate-password-hash.php`), in einfachen Anführungszeichen eintragen, `docker compose up -d backend` |
| Tenant-Admins | Oberfläche | *Tenants → Schule → Administratoren → Passwort zurücksetzen* oder deaktivieren |
| Server-Zugänge (SSH-Schlüssel, Sudo-Passwörter) | Server | durch den Systemadministrator |

**Neu aufsetzen** (wenn der Server selbst als kompromittiert gilt): neuen Server nach [installation.md](installation.md) aufbauen, **nur die Datenbank** aus einer Sicherung vor dem Vorfall einspielen
und Uploads erst nach einer Prüfung zurückkopieren (ClamAV-Scan, keine ausführbaren Dateien). Code frisch aus dem Repository holen, keine Dateien vom alten System übernehmen.

## 5. Speicher voll

```bash
df -h
docker system df
```

1. Ungenutzte Images und Build-Cache entfernen: `docker image prune -a` und `docker builder prune` (ohne `--volumes`: Volumes enthalten Ihre Daten).
2. Alte Sicherungen und Logs begrenzen: Aufbewahrung im Backup-Skript verkürzen, Docker-Logs rotieren (`/etc/docker/daemon.json`, siehe [betrieb.md](betrieb.md#überwachung-und-protokolle)).
3. Größte Verursacher suchen: `du -sh /var/lib/docker/volumes/* | sort -hr | head`.

Danach prüfen: `docker compose ps`, ein Test-Upload und eine Testanmeldung. Ein Alarm bei 80 % Belegung verhindert das nächste Mal den Ausfall.

## 6. Das System ist langsam

`docker stats` zeigt CPU- und Speicherverbrauch je Container. Prüfen Sie der Reihe nach: Last des Servers (`uptime`, `top`), laufende Datenbank-Abfragen
(`docker compose exec mysql mysql -uroot -p… -e 'SHOW PROCESSLIST'`), große Datei-Uploads, volle Platte. Ein Neustart des Backends (`docker compose restart backend`) behebt kurzfristig Speicherprobleme;
dauerhaft helfen mehr RAM oder höhere Limits in `docker-compose.prod.yml`. Das Backend begrenzt Schreibaktionen im Formular-Editor und API-Anfragen per Rate-Limit
(`RATE_LIMIT_*`, `EDITOR_RATE_LIMIT_*` in der `backend/.env`).

## 7. Update ging schief

1. Logs ansehen: `docker compose logs --tail=200 backend`.
2. **Zurückrollen:** `git checkout <vorherige-version>`, `docker compose up -d --build backend`.
3. Hat das Update das **Schema verändert**, muss auch die Datenbank auf den Stand von vor dem Update zurück (Abschnitt 2). Die Migrationen laufen nur vorwärts. Deshalb: **vor jedem Update sichern.**
   Das Zurückrollen auf 2.x beschreibt [MIGRATION-3.0.md](MIGRATION-3.0.md#8-rollback).
4. Danach prüfen: Anmeldung im Backend, ein Formular im Frontend, Testanmeldung, Excel-Export.

## Nach dem Vorfall

Halten Sie fest, was passiert ist (Zeitverlauf, Ursache, Auswirkung, Maßnahmen) und was künftig verhindert werden soll: fehlende Überwachung, zu seltene Sicherung, zu kleine Limits.
Eine Vorlage in drei Zeilen reicht: **Was geschah? Warum? Was ändern wir?**

## Übungen (empfohlen, quartalsweise)

- **Datenbank-Restore:** Dump in eine Testdatenbank einspielen und Zeilenzahlen prüfen (`CREATE DATABASE restoretest;` …).
- **Neuaufbau:** Stack auf einem Testserver aus Repository, `.env`-Sicherung, Datenbank-Dump und Uploads aufbauen; Anmeldung und PDF testen.
- **Schlüsselwechsel:** API-Schlüssel einer Testschule erneuern und im Frontend nachziehen; Dauer und Stolperstellen notieren.

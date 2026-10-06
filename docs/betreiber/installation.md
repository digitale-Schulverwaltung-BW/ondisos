# Backend installieren (Docker)

Diese Anleitung richtet das **Backend** in Produktion ein: Verwaltungsoberfläche, API, Datenbank und optional Virenscan, als Docker-Stack.
Das Frontend (Formulare für Besucher) läuft getrennt; wie beide zusammenspielen, beschreibt das [Betriebsmodell](betriebsmodell.md).
Ohne Docker: [installation-ohne-docker.md](installation-ohne-docker.md).

> **Von 2.x oder 3.0?** Nicht diese Anleitung, sondern [MIGRATION-3.0.md](MIGRATION-3.0.md) bzw. [MIGRATION-3.1.md](MIGRATION-3.1.md).

## Voraussetzungen

| | |
|---|---|
| **Server** | Linux (Ubuntu 22.04 LTS oder Debian 11+ empfohlen), mindestens 2 GB RAM (empfohlen 4 GB), 20 GB freier Speicher |
| **Docker** | Docker Engine 20.10+ mit **Compose-Plugin ≥ 2.24** (`docker compose version`) |
| **Netz** | Port 9080 (Backend) für das Frontend bzw. den vorgeschalteten Reverse-Proxy; MySQL wird **nicht** nach außen veröffentlicht |
| **Trennung** | Backend und Frontend nicht auf derselben Maschine betreiben: Die Docker-Volumes sind auf dem Host lesbar, bei einem Einbruch wären die Daten offen |

Docker installieren (Ubuntu/Debian, Skript vorher ansehen; Alternativen: [Docker-Dokumentation](https://docs.docker.com/engine/install/)):

```bash
curl -fsSL https://get.docker.com -o get-docker.sh && sudo sh get-docker.sh
sudo usermod -aG docker $USER              # optional: Docker ohne sudo; danach neu anmelden
docker compose version                     # Compose-Plugin ≥ 2.24 (sonst: sudo apt-get install docker-compose-plugin)
```

## 1. Code holen

```bash
git clone https://github.com/digitale-Schulverwaltung-BW/ondisos.git
cd ondisos
```

Für ein Update später siehe [betrieb.md](betrieb.md#updates).

## 2. Konfigurieren

Die **Root-`.env`** ist die einzige Stelle für Zugangsdaten und Secrets:

```bash
cp .env.example .env
sed -i.bak "s/^PDF_TOKEN_SECRET=.*/PDF_TOKEN_SECRET=$(openssl rand -hex 32)/" .env
sed -i.bak "s/^API_SECRET_KEY=.*/API_SECRET_KEY=$(openssl rand -hex 32)/"   .env
nano .env       # DB_PASS und MYSQL_ROOT_PASSWORD setzen
```

| Schlüssel | Bedeutung |
|---|---|
| `DB_PASS`, `MYSQL_ROOT_PASSWORD` | Passwörter der Datenbank. **Vor dem ersten Start setzen** (siehe [Fehlersuche](#datenbank-zugriff-verweigert)) |
| `PDF_TOKEN_SECRET` | signiert die PDF-Download-Links, mindestens 32 Zeichen |
| `API_SECRET_KEY` | wird beim Migrieren das Secret der ersten Schule (Tenant 1); das Frontend signiert damit seine Anfragen |
| `ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH` | Zugang des Plattform-Admins (Schritt 4) |
| `MULTI_TENANT_ENABLED` | `true` für mehrere Schulen, siehe [MULTI-TENANT.md](MULTI-TENANT.md) |

`DB_USER` und `DB_NAME` müssen Sie nicht ändern; Docker Compose übernimmt sie automatisch für die Datenbank (`MYSQL_USER` usw.).
**Die `.env` gehört nicht ins Repository** (sie steht in der `.gitignore`).

**Wichtig:** Bekannte Platzhalter (`CHANGE_ME_IN_PRODUCTION`, `dev-api-key-replace-in-production`) werden im Produktionsbetrieb nicht akzeptiert.
Mit einem solchen `API_SECRET_KEY` bricht die Migration ab, und der Container startet in einer Schleife neu; im Log steht
`Error: API_SECRET_KEY is a known default/placeholder`.

Weitere Einstellungen (Rate-Limits, Virenscan, Logo) kommen in die optionale `backend/.env`, siehe [backend/.env im Docker-Betrieb](#backendenv-im-docker-betrieb).

## 3. Starten

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
docker compose logs -f backend          # Strg+C beendet die Anzeige
```

Das Produktions-Overlay (`docker-compose.prod.yml`) setzt `APP_ENV=production`, `AUTH_ENABLED=true`, Ressourcen-Limits, einen Healthcheck und startet
bei jedem Neustart des Servers automatisch (`restart: unless-stopped`). Beim Start läuft die Datenbank-Migration; im Log erscheint **`Migration complete`**.
Ein Frontend-Container wird in Produktion nicht gestartet.

Alle weiteren `docker compose`-Befehle dieser Anleitung beziehen sich auf diesen Stack. Praktisch: einmal `export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml`
setzen, dann genügt `docker compose …` ohne die `-f`-Optionen.

## 4. Admin-Zugang einrichten

Mit `AUTH_ENABLED=true` (Standard in Produktion) ist ohne Zugang keine Anmeldung möglich.

```bash
docker compose exec backend php scripts/generate-password-hash.php "Ihr-Passwort"
```

Die Ausgabe enthält den Hash und die fertige Zeile `ADMIN_PASSWORD_HASH='…'`. Tragen Sie sie mit dem Benutzernamen in die **Root-`.env`** ein,
**mit den einfachen Anführungszeichen** (sonst deutet Docker Compose das `$` als Variable und beschädigt den Hash):

```bash
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH='$2y$…'
```

Container neu erstellen, damit die Werte ankommen (ein `restart` übernimmt geänderte Compose-Variablen **nicht**), und prüfen:

```bash
docker compose up -d backend
docker compose exec backend printenv ADMIN_PASSWORD_HASH | tr -d '\n' | wc -c     # erwartet: 60
```

Weitere Zugänge für einzelne Schulen legen Sie später im Backend an ([MULTI-TENANT.md](MULTI-TENANT.md)).

## 5. Formulare einspielen

Ohne Formular-Konfiguration gibt es nichts anzuzeigen. Die Vorlage `frontend/config/forms-config-dist.php` enthält Beispielformulare:

```bash
cp frontend/config/forms-config-dist.php frontend/config/forms-config.php
nano frontend/config/forms-config.php        # Formulare und Empfänger anpassen (Vorlage: @example.com-Adressen!)
docker compose exec -T backend php seed-forms.php - < frontend/config/forms-config.php
```

Der Container sieht das Verzeichnis `frontend/` nicht, deshalb wird die Datei über STDIN hineingereicht (`-T` ist nötig). Das Skript fügt neue Formulare hinzu
und **überschreibt nie** Vorhandenes. Danach lässt sich `forms-config.php` löschen.

**Surveys übernehmen.** Die Formulare selbst (Fragen, Seiten) liegen zunächst als Dateien in `frontend/surveys/`. Damit Schulen sie im Backend bearbeiten können und die Frontends
sie von dort beziehen, importieren Sie sie einmal in die Datenbank (der Container braucht dafür eine Kopie des Verzeichnisses):

```bash
docker compose cp frontend/surveys backend:/tmp/surveys
docker compose exec backend php import-surveys.php /tmp/surveys        # Option --dry-run prüft nur
```

Danach steht in der Spalte *Survey* unter *Formulare* statt „Datei im Frontend" der Wert „Backend“, und die Datenbank-Fassung hat Vorrang vor der Datei. Formulare ohne passende Datei im Verzeichnis (hier z. B. `bk`) bleiben bei „Datei im Frontend“. Der Import ist wiederholbar. Bestehende Formulare pflegen die Schulen im Backend unter **Formulare**
([Redaktion](../redaktion/SURVEYJS.md)). Neue Schulen starten mit den Formularen einer anderen Schule ([MULTI-TENANT.md](MULTI-TENANT.md)).

## 6. Prüfen

```bash
curl http://localhost:9080/api/health.php
# {"status":"ok","timestamp":…}

curl -i -X POST "http://localhost:9080/api/submit.php?tenant=default" -d '{}'
# HTTP/1.1 401 – {"error":"Unauthorized"}: unsignierte Anfragen werden abgewiesen

curl "http://localhost:9080/api/form-config.php?form=bs&tenant=default"
# {"success":true,"config":{…}}

curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://localhost:9080/index.php
# 302 …/login.php: ohne Anmeldung kommt man nicht ins Backend

docker compose ps          # backend: healthy
```

Öffnen Sie dann `http://<server>:9080/login.php` und melden Sie sich mit dem Zugang aus Schritt 4 an. Ab hier geht es mit dem
[Betriebsmodell](betriebsmodell.md) weiter: HTTPS einrichten, die API für das Frontend erreichbar machen, die erste Schule anbinden.

## Virenscan (optional)

Hochgeladene Dateien lassen sich vor dem Speichern mit ClamAV prüfen. Der Scan läuft lokal im Docker-Stack; Dateien verlassen Ihr Netz nicht.
Der Dienst ist standardmäßig aus.

1. In die Root-`.env`: `VIRUS_SCAN_ENABLED=true`
2. Stack mit dem Profil `clamav` starten:

   ```bash
   docker compose --profile clamav up -d
   docker compose logs -f clamav       # beim ersten Start lädt ClamAV ca. 300 MB Signaturen (60–90 s)
   ```

3. Optional in `backend/.env`: `VIRUS_SCAN_STRICT=true` lehnt Uploads ab, solange ClamAV nicht antwortet (Standard `false`: Upload erlaubt, Warnung im Log).
   `CLAMAV_HOST` (Standard `clamav`) und `CLAMAV_PORT` (3310) gelten für den mitgelieferten Dienst.

**Funktionsprobe** (EICAR ist eine harmlose Standard-Testdatei, die jedes Antivirenprogramm als „Virus" meldet):

```bash
printf '%s' 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' > /tmp/eicar.txt
docker cp /tmp/eicar.txt "$(docker compose ps -q backend)":/tmp/eicar.txt
docker compose exec backend php -r 'require "vendor/autoload.php"; print_r(App\Services\VirusScanService::fromEnv()->scanFile("/tmp/eicar.txt"));'
# erwartet: [virus] => Eicar-Test-Signature
```

Signatur-Updates laufen automatisch alle 2 Stunden. Auf ARM-Servern (arm64) gibt es kein ClamAV-Image; dort Emulation (`platform: linux/amd64`) oder einen ClamAV-Dienst auf anderer Hardware verwenden.

## backend/.env im Docker-Betrieb

Das Backend liest im Container die Datei `backend/.env`, und sie **gewinnt** gegen die Container-Umgebung. Der Entrypoint erzeugt sie bei **jedem Start** aus der Umgebung neu
(Marker `# GENERATED-BY-ENTRYPOINT` in Zeile 1). Daraus folgt:

- Änderungen der Root-`.env` wirken nach `docker compose up -d backend` (nicht nach `restart`).
- **Eigene Zusatz-Einstellungen** (PDF-Logo-Pfade, Rate-Limits, `VIRUS_SCAN_STRICT`, …) hängen Sie an diese Datei an: Schlüssel, die der Entrypoint nicht selbst verwaltet,
  bleiben beim Neuschreiben erhalten. Die verwalteten Schlüssel (Datenbank, Secrets, `ADMIN_*`, `AUTH_ENABLED`, …) kommen immer aus der Umgebung.
- Wer die Datei komplett selbst pflegen will, löscht die Marker-Zeile oder legt `backend/.env` aus `backend/.env.example` an. Sie wird dann nie angefasst, hat aber Vorrang vor der Root-`.env`;
  alle Werte, auch Datenbank und Admin, pflegen Sie dann dort.
- Dateien aus 2.x (ohne Marker) werden beim ersten Start gesichert (`backend/.env.bak`) und neu erzeugt; eigene Zeilen bleiben erhalten.

## Fehlersuche

### Datenbank-Zugriff verweigert

```
Error: Cannot connect to database — Access denied for user 'anmeldung'@'172.24.0.3' (using password: YES)
```

**Ursache:** Das MySQL-Daten-Volume behält die Zugangsdaten, mit denen es **angelegt** wurde. `DB_USER`, `DB_PASS`, `MYSQL_ROOT_PASSWORD` und `DB_NAME` gelten nur beim **ersten Start**.
Wer sie später ändert (oder den Stack einmal mit den Beispielwerten gestartet hat), hat ein Volume mit alten und eine `.env` mit neuen Passwörtern. (`Unknown database 'anmeldung'` hat dieselbe Ursache bei geändertem `DB_NAME`.)

**Lösungen, in dieser Reihenfolge:**

1. **Alte Werte wiederherstellen** (auch in `.env.bak`, das `sed -i.bak` oben angelegt hat), dann `docker compose up -d`. Kein Datenverlust.
2. **Passwort in MySQL nachziehen** (verlangt das *alte* Root-Passwort, Daten bleiben erhalten):

   ```bash
   docker compose exec mysql mysql -uroot -p            # altes Root-Passwort eingeben
   mysql> ALTER USER 'anmeldung'@'%' IDENTIFIED BY '<neues DB_PASS>';
   mysql> ALTER USER 'root'@'%' IDENTIFIED BY '<neues MYSQL_ROOT_PASSWORD>';
   mysql> ALTER USER 'root'@'localhost' IDENTIFIED BY '<neues MYSQL_ROOT_PASSWORD>';
   ```

   Passwörter mit `#` (nach Leerzeichen), `$` oder Anführungszeichen in der Root-`.env` in einfache Anführungszeichen setzen.
3. **Von vorn beginnen: löscht alle Daten dieser Datenbank** (Anmeldungen, Schulen, Formulare). Nur das MySQL-Volume entfernen, **nicht** `down -v`:

   ```bash
   docker compose stop backend mysql && docker compose rm -f mysql
   docker volume ls | grep mysql-data           # Namen ablesen, z. B. ondisos_mysql-data
   docker volume rm <name>
   docker compose up -d
   ```

   Danach Formulare (Schritt 5) und Admin-Zugang (Schritt 4) wie bei einer Neuinstallation einrichten.

### Login abgelehnt, obwohl das Passwort stimmt

Fast immer ist der Hash beschädigt: In der Root-`.env` zerlegt Docker Compose jedes `$…` eines **nicht** in einfache Anführungszeichen gesetzten Hashes (aus 60 Zeichen werden etwa 35).
Sonderzeichen im *Passwort* sind unkritisch. Länge wie in Schritt 4 prüfen; bei einem Wert ≠ 60 den Hash in einfache Anführungszeichen setzen und `docker compose up -d backend` wiederholen.
Das Backend meldet den Fall beim Start (`⚠️ ADMIN_PASSWORD_HASH looks damaged`, `docker compose logs backend`).

### Container startet immer wieder neu

`docker compose logs backend` zeigt die Ursache. Häufig: `API_SECRET_KEY` ist ein Standardwert (siehe Schritt 2) oder die Datenbank-Zugangsdaten passen nicht (siehe oben).

### Port 9080 ist belegt

`Bind for 0.0.0.0:9080 failed: port is already allocated`: Prozess ermitteln (`lsof -i :9080`) oder in `docker-compose.prod.yml` einen anderen Host-Port eintragen (z. B. `"9081:80"`).

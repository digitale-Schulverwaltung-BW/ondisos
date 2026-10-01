## 🚀 Deployment

### Übersicht

## 📋 Übersicht

> **Von 2.x kommend?** Lies zuerst **[MIGRATION-3.0.md](MIGRATION-3.0.md)** — das Upgrade betrifft
> Datenbank, Frontend-Konfiguration und die Frontend↔Backend-Kommunikation.
> **Mehrere Schulen auf einem Backend?** → **[MULTI-TENANT.md](MULTI-TENANT.md)**.
> **WordPress-Einbindung?** → **[wordpress-plugin/INSTALL.md](../wordpress-plugin/INSTALL.md)**.

Das System besteht aus zwei Servern:

| Server | Komponente | Zweck | Zugriff |
|--------|-----------|-------|---------|
| **Backend Server** | Docker Container + MySQL | Admin-Interface, API | Intranet |
| **Frontend Server** | Apache/Nginx + PHP | Öffentliche Formulare | Internet |

**Hinweis:** Beide Server sollten nicht auf derselben Maschine laufen (Docker isoliert zwar das Backend, die Volumes sind jedoch auf der Host-Maschine einsehbar, bei einem Security-Incident mit root privilege escalation wären die gespeicherten Daten kompromittiert und öffentlich).

### Voraussetzungen

- ✅ **OS:** Ubuntu 22.04 LTS oder Debian 11+ (empfohlen)
- ✅ **RAM:** Minimum 2 GB (empfohlen 4 GB)
- ✅ **Disk:** Minimum 20 GB freier Speicher
- ✅ **Network:** Zugriff auf Frontend-Server (Formular-Submissions)
- ✅ **Ports:** 9080 (Backend), 3306 (MySQL, nur localhost)

### Setup-Varianten

Für Production stehen verschiedene Setup-Varianten zur Verfügung:

| Komponente | Option 1: Docker Backend | Option 2: Komplett Manuell | Option 3: Komplett Docker |
|------------|--------------------------|----------------------------|---------------------------|
| **Backend** | 🐳 Docker Container | 📄 Apache/PHP | 🐳 Docker Container |
| **Frontend** | 📄 Apache/PHP | 📄 Apache/PHP | 🐳 Docker Container |
| **MySQL** | 🐳 Docker oder bestehend | 📄 MySQL Server | 🐳 Docker Container |
| **Empfehlung** | ✅ **Empfohlen** | Einfachstes Setup | Dev/Testing |

#### Warum ist die Option 1 (Docker Backend) empfohlen?

**Vorteile:**
- ✅ **Vereinfachte Dependencies** - Composer, mPDF, PHP 8.2+, Tests automatisch installiert
- ✅ **Einfache Updates** - `git pull && docker compose up -d --build`
- ✅ **Konsistente Umgebung** - Dev = Prod, keine "works on my machine"
- ✅ **Automatische Backups** - Volume-basierte Backups für DB und Uploads
- ✅ **Frontend flexibel** - Läuft auf bestehendem Webserver (kann mit Wordpress koexistieren)

**Wann Option 2 (Komplett Manuell)?**
- Umgebungen ohne Docker
- Volle Kontrolle über alle Komponenten
- Bewährte Apache/PHP-Infrastruktur

**Wann Option 3 (Komplett Docker)?**
- Primär für Entwicklung und Testing
- Alle Services in Containern
- Siehe **[DOCKER.md](DOCKER.md)** für Details

---

### Option 1: Docker Backend + Manuelles Frontend (✅ Empfohlen)

#### 1. Backend als Docker Container

**Voraussetzungen:**
- Docker Engine 20.10+ oder Docker Desktop (inkl. Compose Plugin **≥ 2.24**, für das optionale `backend/.env`)

**Setup:**

```bash
# 1. Root .env konfigurieren (Single Source of Truth)
cp .env.example .env
nano .env

# 2. Secrets generieren (direkt in .env eintragen)
sed -i.bak "s/^PDF_TOKEN_SECRET=.*/PDF_TOKEN_SECRET=$(openssl rand -hex 32)/" .env
sed -i.bak "s/^API_SECRET_KEY=.*/API_SECRET_KEY=$(openssl rand -hex 32)/" .env
# Passwörter ändern: DB_PASS, MYSQL_ROOT_PASSWORD

# 3. Container starten (führt bei jedem Start die Datenbank-Migration aus)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# 4. Logs prüfen (Migration: "Migration complete")
docker compose logs -f backend

# 5. Formular-Konfiguration einspielen (siehe unten)
cp frontend/config/forms-config-dist.php frontend/config/forms-config.php   # eigene Datei (gitignored)
nano frontend/config/forms-config.php       # Formulare/Empfänger anpassen — die Vorlage enthält Beispielformulare und @example.com-Adressen
docker compose exec -T backend php seed-forms.php - < frontend/config/forms-config.php

# 6. Testen
curl http://localhost:9080/api/health.php
curl "http://localhost:9080/api/form-config.php?form=bs&tenant=default"
```

**Formular-Konfiguration:** Die Konfiguration der Formulare (`bs`, `bk`, …) liegt in der Datenbank
(Tabelle `form_configs`), nicht mehr in einer Datei. `seed-forms.php` übernimmt eine
`forms-config.php` für Tenant 1: neue Formulare werden hinzugefügt, vorhandene nie überschrieben. Der Backend-Container sieht `frontend/` nicht — deshalb
wird die Datei über STDIN hineingereicht (`… php seed-forms.php - < datei`, `exec -T` ist dafür nötig); eine Kopie ins Backend ist nicht mehr nötig. Das Frontend holt die Konfiguration bei jedem Aufruf über
`/api/form-config.php`. Spätere Änderungen erfolgen per SQL (Admin-Oberfläche: geplant für 3.1) —
siehe [MIGRATION-3.0.md § 6](MIGRATION-3.0.md#6-danach-formular-konfiguration-ändern). Nach dem Seed
können die `forms-config.php`-Dateien gelöscht werden.

**Wichtig - Credentials-Struktur:**

Das Projekt verwendet eine **Root-`.env`** als Single Source of Truth:
- `/.env` - Core-Credentials (DB_USER, DB_PASS, Secrets) ← **HIER ALLES WICHTIGE**
- `/backend/.env` - Optional; im Docker-Betrieb vom Entrypoint erzeugt (siehe [unten](#backendenv-im-docker-betrieb))

Dadurch **keine Duplikation** zwischen `DB_USER` und `MYSQL_USER` — beide Werte kommen aus den gleichen Variablen in der Root-`.env`.

**Docker-Setup (verwende existierende Files):**

Das Projekt kommt mit vorkonfigurierten Compose-Files:
- `docker-compose.yml` - Basis-Config (Dev + Prod)
- `docker-compose.prod.yml` - Production-Overrides (Secrets, Resource-Limits, HTTPS)

**Wichtige Features:**
- ✅ **Credentials aus Root `.env`** - Keine Duplikation zwischen DB_USER/MYSQL_USER
- ✅ **Named Volumes** - uploads, cache, logs isoliert von Host-Filesystem
- ✅ **Kein MySQL Host-Port** - Nur interne Docker-Kommunikation (sicherer)
- ✅ **Variable Substitution** - `${DB_USER}` → `MYSQL_USER` automatisch gemapped
- ✅ **Health Checks** - Backend startet erst wenn MySQL ready ist
- ✅ **Restart Policy** - `unless-stopped` für Auto-Start nach Reboot

**Beispiel Root `.env`:**
```bash
# Core Credentials (automatisch von Docker Compose geladen)
DB_HOST=mysql
DB_NAME=anmeldung
DB_USER=anmeldung
DB_PASS=DeinSicheresPasswort123!

MYSQL_ROOT_PASSWORD=RootPasswort456!
PDF_TOKEN_SECRET=generiert-mit-openssl-rand-hex-32
API_SECRET_KEY=generiert-mit-openssl-rand-hex-32   # Secret von Tenant 1; Frontend signiert damit
```

Docker Compose mapped automatisch:
- `DB_USER` → `MYSQL_USER` (für MySQL Container Init)
- `DB_PASS` → `MYSQL_PASSWORD`
- Keine manuellen Duplikate nötig!

**Persistenz über Reboots:**

Die `restart: unless-stopped` Policy sorgt dafür, dass Container automatisch nach Reboots starten.

**Alternative: Systemd Service** (optional, für mehr Kontrolle)

Erstelle `/etc/systemd/system/ondisos-backend.service`:

```ini
[Unit]
Description=Ondisos Backend Docker Compose
Requires=docker.service
After=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/path/to/ondisos/backend
ExecStart=/usr/bin/docker compose up -d
ExecStop=/usr/bin/docker compose down
TimeoutStartSec=0

[Install]
WantedBy=multi-user.target
```

```bash
# Aktivieren
sudo systemctl enable ondisos-backend
sudo systemctl start ondisos-backend

# Status prüfen
sudo systemctl status ondisos-backend
```

**Secrets Management:**

```bash
# WICHTIG: Root .env NICHT in Git committen!
# .gitignore prüfen:
grep -q "^\.env$" .gitignore || echo ".env" >> .gitignore

# Credentials in ROOT .env ändern (nicht backend/.env!):
# - DB_PASS (wird automatisch zu MYSQL_PASSWORD gemapped)
# - MYSQL_ROOT_PASSWORD
# - PDF_TOKEN_SECRET (32+ Zeichen: openssl rand -hex 32)
# - API_SECRET_KEY   (openssl rand -hex 32) — siehe unten

# Struktur (kein backend/.env nötig für Credentials):
# /.env                  ← Alle Secrets HIER
# /backend/.env          ← Optional; im Docker-Betrieb vom Entrypoint erzeugt, eigene Zusätze bleiben erhalten
```

#### backend/.env im Docker-Betrieb

 Das Backend liest im Container `backend/.env` — und diese Datei gewinnt gegen die
Container-Umgebung. Der Entrypoint **erzeugt** sie beim Start aus der Container-Umgebung (Root-`.env` → `docker-compose`) und schreibt sie bei
**jedem Start neu** (Marker `# GENERATED-BY-ENTRYPOINT` in Zeile 1). Daraus folgt:

- Änderungen in der Root-`.env` wirken nach `docker compose up -d backend` (der Container wird dabei neu erstellt; ein bloßes `docker compose restart backend`
  übernimmt geänderte Compose-Variablen **nicht**).
- Eigene Zusatz-Einstellungen (PDF-Logo, Rate-Limits, Virenscan, …) kannst du an die Datei anhängen: Schlüssel, die der Entrypoint nicht selbst
  verwaltet, bleiben beim Neuschreiben erhalten. Die verwalteten Schlüssel (DB, Secrets, `ADMIN_*`, `AUTH_ENABLED`, …) kommen immer aus der Umgebung.
- Wer die Datei selbst pflegen will, löscht die Marker-Zeile oder legt `backend/.env` aus `backend/.env.example` an: Sie wird dann nie angefasst, hat aber
  Vorrang vor der Root-`.env` — alle Werte (auch DB und Admin) pflegst du dann dort.
- Dateien aus 2.x (erste Zeile `# Application`, ohne Marker) werden beim ersten Start nach dem Update gesichert (`backend/.env.bak`) und neu erzeugt;
  eigene Zusatz-Zeilen bleiben erhalten.


**API-Secret (`API_SECRET_KEY`):** Wird beim Migrieren das Secret von Tenant 1 ("Default"). Das
Frontend signiert alle Anfragen an das Backend mit diesem Secret (`TENANT_API_SECRET` in der
Frontend-`.env`) — beide Werte müssen übereinstimmen. Bekannte Platzhalter und Standardwerte
(`CHANGE_ME_IN_PRODUCTION`, `dev-api-key-replace-in-production`) authentifizieren **nichts**:
In Production (`APP_ENV=production`) bricht die Migration ab, wenn `API_SECRET_KEY` so ein Wert ist,
und das Backend weist Anfragen mit einem solchen Tenant-Secret mit `401` ab (Log: `tenant api_secret
is a known placeholder/default`). Weitere Tenants und ihre Secrets: [MULTI-TENANT.md](MULTI-TENANT.md).

#### Datenbank-Zugriff verweigert

```
Error: Cannot connect to database — Access denied for user 'anmeldung'@'172.24.0.3' (using password: YES)
```

**Ursache:** Das MySQL-Daten-Volume behält die Zugangsdaten, mit denen es **angelegt** wurde. `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` und `MYSQL_DATABASE`
(aus `DB_USER`, `DB_PASS`, `MYSQL_ROOT_PASSWORD`, `DB_NAME` der Root-`.env`) gelten **nur beim ersten Start**. Wer die Werte später ändert — oder den Stack
einmal mit den Beispielwerten gestartet hat, bevor er die Passwörter angepasst hat —, hat danach ein Volume mit alten und eine `.env` mit neuen Passwörtern.
(`Unknown database 'anmeldung'` hat dieselbe Ursache bei einem geänderten `DB_NAME`.) `migrate.php` und `seed-forms.php` geben dafür einen Hinweis aus.

**Vorbeugen:** `DB_PASS` und `MYSQL_ROOT_PASSWORD` **vor** dem ersten `up` setzen. Ein späteres Ändern ist nicht möglich, ohne es auch in MySQL nachzuziehen (Weg 2).

**Lösungen — in dieser Reihenfolge prüfen:**

1. **Alte Werte wiederherstellen.** Du kennst sie noch (oder findest sie in `.env.bak`, das `sed -i.bak` beim Erzeugen der Secrets anlegt)? Die Root-`.env` zurücksetzen und
   `docker compose up -d` — kein Datenverlust.
2. **Passwort in MySQL nachziehen** (verlangt das *alte* Root-Passwort, die Daten bleiben erhalten):
   ```bash
   docker compose exec mysql mysql -uroot -p            # altes Root-Passwort eingeben
   mysql> ALTER USER 'anmeldung'@'%' IDENTIFIED BY '<neues DB_PASS>';
   mysql> ALTER USER 'root'@'%' IDENTIFIED BY '<neues MYSQL_ROOT_PASSWORD>';
   mysql> ALTER USER 'root'@'localhost' IDENTIFIED BY '<neues MYSQL_ROOT_PASSWORD>';
   ```
   Danach `docker compose up -d`. (Bei Passwörtern mit `#` nach einem Leerzeichen, `$` oder Anführungszeichen in der Root-`.env` den Wert in einfache Quotes setzen.)
3. **Von vorn beginnen — löscht alle Daten dieser Datenbank** (Anmeldungen, Tenants, Formular-Konfiguration). Nur das MySQL-Volume entfernen, nicht `down -v`:
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.prod.yml stop backend mysql
   docker compose -f docker-compose.yml -f docker-compose.prod.yml rm -f mysql
   docker volume ls | grep mysql-data                      # Namen ablesen, z. B. <projekt>_mysql-data
   docker volume rm <projekt>_mysql-data
   docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d    # legt die Datenbank mit den aktuellen Werten neu an
   ```
   Danach Formular-Konfiguration (`seed-forms.php`) und Admin-Zugang wie bei einer Neuinstallation einrichten. `docker compose down -v` würde zusätzlich
   die Volumes für Uploads, Logs und alles andere im Projekt löschen — hier **nicht** verwenden.

**Admin Authentication Setup:**

```bash
# 1. In docker-compose.prod.yml ist AUTH_ENABLED=true bereits gesetzt

# 2. Passwort-Hash generieren
docker compose exec backend php scripts/generate-password-hash.php "dein-passwort"

# 3. Benutzername und Hash in die ROOT-.env eintragen — den Hash in EINFACHE Anführungszeichen,
#    sonst interpretiert Docker Compose das `$` als Variable
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH='$2y$10$abc123...'

# 4. Container NEU ERSTELLEN (ein bloßes `restart` übernimmt geänderte Compose-Variablen nicht)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d backend

# 5. Prüfen, ob der Hash vollständig angekommen ist (erwartet: 60)
docker compose exec backend sh -c 'echo ${#ADMIN_PASSWORD_HASH}'
```

> **Login wird abgelehnt, obwohl das Passwort stimmt?** Fast immer ist der Hash beschädigt: In der Root-`.env` expandiert Docker Compose jedes
> `$…` eines **unquotierten** Wertes und schneidet den Hash dabei zusammen (aus 60 Zeichen werden etwa 35). Sonderzeichen im *Passwort* (`#`, `'`, `$`, …)
> sind unkritisch — sie stecken nur im Hash. Prüfung wie in Schritt 5; bei einem Wert ≠ 60 den Hash in einfache Anführungszeichen setzen und
> `up -d backend` wiederholen. Das Backend meldet den Fall beim Start (`⚠️ ADMIN_PASSWORD_HASH looks damaged`, `docker compose logs backend`) und beim
> Login-Versuch im PHP-Log.

---

#### 2. Frontend auf Apache/Nginx (Manuell)

Das Frontend läuft auf einem klassischen Webserver (kann auf bestehendem Server mit Wordpress etc. laufen).

**Setup:**

```bash
cd frontend

# 1. Environment konfigurieren
cp .env.example .env
chmod 600 .env          # enthält das Tenant-Secret
nano .env
# BACKEND_API_URL=http://your-backend-server.com:9080/api
# TENANT_SLUG=default                       # Slug des Tenants (Tenant 1 = default)
# TENANT_API_SECRET=<API_SECRET_KEY des Backends>   # signiert alle Anfragen ans Backend

# 2. Verzeichnisse anlegen (falls nötig)
mkdir -p cache
chmod 755 cache
```

**Formular-Cache (ab 3.1):** Das Frontend legt die vom Backend gelieferte Formular-Fassung unter `frontend/cache/forms/`
ab (anderer Ort: `FORM_CACHE_DIR` in der `.env`). Der Webserver-Benutzer muss dort schreiben dürfen (`chown www-data:www-data cache`).
Damit holt das Frontend bei jedem Aufruf nur noch ein „304 Not Modified" und liefert das Formular weiter aus, wenn das Backend
kurz nicht erreichbar ist (bis zu 7 Tage alte Fassung). Ist das Verzeichnis nicht beschreibbar, funktioniert alles weiter,
nur ohne Cache und ohne diesen Ausfallschutz. Die Dateien beginnen mit einer PHP-Schutzzeile und sind nicht als Text abrufbar.

Eine `forms-config.php` ist im Frontend **nicht mehr nötig**: Die Formular-Konfiguration kommt aus
dem Backend (siehe oben). Die Survey-Definitionen liegen weiter in `frontend/surveys/`.

**Prüfen:** `https://anmeldung.example.com/index.php?form=bs` lädt das Formular. Erscheint die
Wartungsseite (503), ist das Backend nicht erreichbar oder lehnt den Tenant ab (Ursache im PHP-Log, `BACKEND_API_URL`/`TENANT_SLUG` prüfen); ein 404
„Formular nicht gefunden" heißt, dass die Formular-Konfiguration fehlt (`seed-forms.php`).

**Apache VirtualHost:**

```apache
<VirtualHost *:80>
    ServerName anmeldung.example.com
    DocumentRoot /var/www/frontend/public

    <Directory /var/www/frontend/public>
        AllowOverride All
        Require all granted
    </Directory>

    # Optional: HTTPS Redirect (siehe HTTPS-Section unten)
</VirtualHost>
```

**HTTPS Setup (Empfohlen):**

```bash
# Let's Encrypt Zertifikat
sudo certbot --apache -d anmeldung.example.com

# Oder .htaccess aktivieren (siehe HTTPS-Section)
cp public/.htaccess.example public/.htaccess
# Uncomment HTTPS redirect lines
```

---

### Option 2: Komplett Manuell

Für Umgebungen ohne Docker oder bei Präferenz für klassisches Setup.

#### 1. Backend Manuell

```bash
cd backend

# Install Composer dependencies
composer install --no-dev --optimize-autoloader

# Configure environment
cp .env.example .env

# Secrets anhängen (backend/.env.example enthält sie nur als Kommentar)
echo "PDF_TOKEN_SECRET=$(openssl rand -hex 32)" >> .env
echo "API_SECRET_KEY=$(openssl rand -hex 32)"   >> .env

nano .env
# APP_ENV=production
# DB_HOST=127.0.0.1 (oder DB-Server)
# DB_PORT=3306
# DB_NAME=anmeldung
# DB_USER=anmeldung
# DB_PASS=secret

# Create directories
mkdir -p cache uploads logs
chmod 755 cache uploads logs
```

Schema, Migration und Formular-Konfiguration folgen in Schritt 3 (Database).

#### 2. Frontend Manuell

```bash
cd frontend

# Configure environment
cp .env.example .env
chmod 600 .env
nano .env
# BACKEND_API_URL=http://intranet.example.com/backend/api
# TENANT_SLUG=default
# TENANT_API_SECRET=<API_SECRET_KEY aus backend/.env>
```

Die Formular-Konfiguration kommt aus dem Backend (Schritt 3); im Frontend ist keine
`forms-config.php` mehr nötig.

#### 3. Database

```bash
# 1. Schema einspielen (Neuinstallation)
mysql -u root -p < database/schema.sql

# 2. Migration ausführen — Pflicht auch bei Neuinstallation: sie ersetzt den Platzhalter-Secret
#    von Tenant 1 durch API_SECRET_KEY (ohne diesen Schritt lehnt das Backend alle Anfragen ab)
cd backend && php migrate.php

# 3. Formular-Konfiguration einspielen
cp ../frontend/config/forms-config-dist.php ../frontend/config/forms-config.php
nano ../frontend/config/forms-config.php      # anpassen
php seed-forms.php                            # liest ../frontend/config/forms-config.php
```

`migrate.php` ist idempotent und kann bei jedem Update erneut ausgeführt werden.

#### 4. Apache Configuration

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

#### 5. Admin Authentication (Optional)

```bash
# In backend/.env
AUTH_ENABLED=true
ADMIN_USERNAME=admin

# Passwort-Hash generieren
cd backend
php scripts/generate-password-hash.php "dein-sicheres-passwort"

# Hash in .env eintragen (in einfachen Anführungszeichen)
ADMIN_PASSWORD_HASH='$2y$10$abc123...'
```

---

### Option 3: Komplett Docker

Beide Services (Frontend + Backend) als Container. Primär für Entwicklung und Testing.

Siehe **[DOCKER.md](DOCKER.md)** für vollständige Dokumentation.

**Quick Start:**

```bash
# Container starten
docker compose up -d

# Tests ausführen
docker compose exec backend composer test

# Services
# Backend:  http://localhost:9080
# Frontend: http://localhost:8081
# MySQL:    localhost:3306
```

---

### PDF Logo konfigurieren

Das Backend generiert PDF-Bestätigungen mit einem Schullogo. Die Logo-Datei liegt auf dem **Backend-Server** (nicht im Frontend), da nur das Backend PDFs erzeugt.

#### Ablage

Logos gehören in:

```
backend/templates/pdf/assets/
```

Dieser Ordner ist `.gitignored` (alle Dateien außer `Schullogo-example.png`), sodass schulspezifische Logos **nicht** versehentlich ins Repository gelangen.

Eine Beispiel-Datei liegt bereits bereit:

```
backend/templates/pdf/assets/Schullogo-example.png
```

#### Pfad in der .env konfigurieren

**Docker (Option 1 & 3):**

Der `backend/`-Ordner wird als `/var/www/html` in den Container gemountet:

```bash
# Fallback für alle Formulare
PDF_LOGO_PATH=/var/www/html/templates/pdf/assets/logo.png

# Oder formular-spezifisch (hat Vorrang):
PDF_LOGO_BS=/var/www/html/templates/pdf/assets/logo-bs.png
PDF_LOGO_BK=/var/www/html/templates/pdf/assets/logo-bk.png
```

**Manuell (Option 2):**

```bash
PDF_LOGO_PATH=/var/www/backend/templates/pdf/assets/logo.png
```

Pfad anpassen falls der Backend-Code woanders liegt.

#### Logo einrichten (Docker)

```bash
# 1. Logo in den assets-Ordner kopieren
cp /pfad/zu/deinem/logo.png backend/templates/pdf/assets/logo.png

# 2. In backend/.env eintragen (kein Container-Rebuild nötig)
PDF_LOGO_PATH=/var/www/html/templates/pdf/assets/logo.png

# 3. Backend neu starten damit .env neu eingelesen wird
docker compose restart backend
```

#### Logo einrichten (Manuell)

```bash
# 1. Logo kopieren
cp /pfad/zu/deinem/logo.png backend/templates/pdf/assets/logo.png

# 2. In backend/.env eintragen
PDF_LOGO_PATH=/var/www/backend/templates/pdf/assets/logo.png

# 3. PHP-Cache leeren (falls OPcache aktiv)
sudo systemctl reload apache2  # oder php8.2-fpm
```

#### Hinweise

- Unterstützte Formate: PNG, JPG (PNG mit Transparenz empfohlen)
- Das Logo wird automatisch auf max. 150px Breite skaliert und als Base64 ins PDF eingebettet
- Ohne konfiguriertes Logo erscheint kein Logo im PDF (kein Fehler)

---

### Wartung & Updates

#### Docker-Backend updaten

```bash
cd backend

# 1. Code aktualisieren
git pull origin main

# 2. Container neu bauen
docker compose build

# 3. Container neu starten (Zero-Downtime mit --no-deps möglich)
docker compose up -d --build backend

# 4. Logs prüfen (die Migration läuft beim Containerstart automatisch mit)
docker compose logs -f backend

# 5. Health Check
curl http://your-server.com:9080/api/health.php
```

**Rollback bei Problemen:**

```bash
# Zu vorheriger Git-Version
git checkout <previous-commit>
docker compose up -d --build backend
```

#### Manuelles Frontend/Backend updaten

```bash
cd frontend  # oder backend

# 1. Code aktualisieren
git pull origin main

# 2. Dependencies aktualisieren (nur Backend)
composer install  # Backend only

# 3. Datenbank-Migration (nur Backend; idempotent)
php migrate.php       # Backend only

# 4. Cache löschen
rm -rf cache/*

# 5. Apache neu laden (optional)
sudo systemctl reload apache2
```

Beim Update von 2.x auf 3.0 gilt stattdessen die Anleitung in [MIGRATION-3.0.md](MIGRATION-3.0.md).

#### Backups

Der MySQL-Dump enthält auch Tenants, Tenant-Admins (Passwort-Hashes) und die Formular-Konfiguration (`form_configs`). Uploads liegen unter `uploads/tenant-<id>/` — das Volume bzw. `backend/uploads` als Ganzes sichern. Die `.env`-Dateien (Secrets!) gehören in ein **separates, verschlüsseltes** Backup.

**Docker-Volumes sichern:**

```bash
# Credentials aus Root-.env laden
source /path/to/ondisos/.env

# MySQL Backup (empfohlen: täglich via Cron)
docker compose exec mysql mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > backup-$(date +%Y%m%d).sql

# Uploads-Volume sichern
docker run --rm -v backend_backend-uploads:/data -v $(pwd):/backup \
  alpine tar czf /backup/uploads-backup-$(date +%Y%m%d).tar.gz -C /data .

# Restore MySQL
source /path/to/ondisos/.env
docker compose exec -T mysql mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < backup-20260205.sql

# Restore Uploads
docker run --rm -v backend_backend-uploads:/data -v $(pwd):/backup \
  alpine tar xzf /backup/uploads-backup-20260205.tar.gz -C /data
```

**Manuelle Backups:**

```bash
# Credentials aus .env laden
source backend/.env

# Database
mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > backup-$(date +%Y%m%d).sql

# Uploads
tar czf uploads-backup-$(date +%Y%m%d).tar.gz backend/uploads

# Restore
source backend/.env
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < backup-20260205.sql
tar xzf uploads-backup-20260205.tar.gz
```

**Backup-Cron (Beispiel):**

```bash
# /etc/cron.daily/ondisos-backup.sh
#!/bin/bash
BACKUP_DIR="/var/backups/ondisos"
DATE=$(date +%Y%m%d)
ONDISOS_DIR="/path/to/ondisos"   # ← anpassen

# Credentials aus Root-.env laden
source "$ONDISOS_DIR/.env"

# DB Backup
docker compose -f "$ONDISOS_DIR/docker-compose.yml" -f "$ONDISOS_DIR/docker-compose.prod.yml" \
  exec -T mysql mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_DIR/db-$DATE.sql"

# Alte Backups löschen (älter als 30 Tage)
find "$BACKUP_DIR" -name "db-*.sql" -mtime +30 -delete

# Ausführbar machen:
# chmod +x /etc/cron.daily/ondisos-backup.sh
```

---

### HTTPS Enforcement (Production)

Für Production sollte HTTPS erzwungen werden. Das System bietet **zwei Ebenen** der Absicherung.

#### Für Manuelles Frontend/Backend

**Apache .htaccess (Primary):**

```bash
cd frontend/public  # oder backend/public
cp .htaccess.example .htaccess

# Uncomment HTTPS redirect lines (10-19) in .htaccess
nano .htaccess
```

Die `.htaccess`-Dateien enthalten:
- ✅ HTTPS Redirect (301 Permanent)
- ✅ Security Headers (HSTS, X-Frame-Options, CSP, etc.)
- ✅ Cache Control für Assets
- ✅ Compression (gzip)
- ✅ File Access Restrictions

**PHP Fallback (Secondary):**

```bash
# In .env
FORCE_HTTPS=true
```

#### Für Docker-Backend

Docker-Container laufen typischerweise hinter einem Reverse Proxy (Nginx, Traefik, Caddy) für HTTPS.

**Option A: Nginx Reverse Proxy (Empfohlen)**

```nginx
# /etc/nginx/sites-available/backend
server {
    listen 443 ssl http2;
    server_name intranet.example.com;

    ssl_certificate /etc/letsencrypt/live/intranet.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/intranet.example.com/privkey.pem;

    # WICHTIG: Upload-Limit erhöhen (Standard: 1M)
    # Sollte größer sein als UPLOAD_MAX_SIZE in .env (default: 10M)
    client_max_body_size 10M;

    location / {
        proxy_pass http://localhost:9080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

# HTTP -> HTTPS Redirect
server {
    listen 80;
    server_name intranet.example.com;
    return 301 https://$server_name$request_uri;
}
```

**Option B: Let's Encrypt direkt auf Host**

```bash
# Certbot mit Nginx
sudo certbot --nginx -d intranet.example.com

# Oder Apache (falls Frontend + Backend auf gleichem Host)
sudo certbot --apache -d anmeldung.example.com -d intranet.example.com
```

#### HSTS aktivieren (Nach HTTPS-Test!)

**WICHTIG:** Nur aktivieren, wenn HTTPS zu 100% funktioniert!

```apache
# In .htaccess uncomment:
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
```

**Oder Nginx:**

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
```

HSTS zwingt Browser, **immer** HTTPS zu verwenden. Rückgängig machen ist schwierig!

---

### File Upload Configuration

Das System erlaubt File-Uploads in Formularen. Damit Uploads funktionieren, müssen **drei Ebenen** konfiguriert werden:

1. **Application Level** (.env): `UPLOAD_MAX_SIZE=10485760` (10MB)
2. **PHP Level** (php.ini): `upload_max_filesize` & `post_max_size`
3. **Web Server Level** (nginx/Apache): `client_max_body_size` / `LimitRequestBody`

**Wichtig:** Alle drei Limits müssen aufeinander abgestimmt sein!

#### Nginx Upload-Limits

**Problem:** Nginx blockiert standardmäßig Uploads über 1MB mit `HTTP 413 Request Entity Too Large`.

**Lösung:**

```nginx
# Globale Einstellung (http-Block in /etc/nginx/nginx.conf)
http {
    client_max_body_size 10M;
    # ...
}

# Oder pro Server-Block
server {
    listen 80;
    server_name anmeldung.example.com;

    # Upload-Limit erhöhen
    client_max_body_size 10M;

    # ...
}

# Oder nur für spezifische Location (API-Endpoints)
location /api/upload.php {
    client_max_body_size 10M;
    # ...
}
```

**Nach Änderungen:**

```bash
# Syntax prüfen
sudo nginx -t

# Neu laden
sudo systemctl reload nginx
```

#### Apache Upload-Limits

Apache hat standardmäßig keine harten Upload-Limits, aber PHP-Limits gelten trotzdem.

**Optional (zusätzliche Sicherheit):**

```apache
<Directory /var/www/frontend/public>
    # Max Request Body Size (in Bytes)
    LimitRequestBody 10485760
</Directory>
```

#### PHP Upload-Limits

PHP hat eigene Upload-Limits, die **unabhängig** vom Webserver gelten.

**Limits prüfen:**

```bash
php -i | grep -E 'upload_max_filesize|post_max_size'
```

**Konfiguration (php.ini oder .htaccess):**

```ini
# /etc/php/8.2/fpm/php.ini (oder /etc/php/8.2/apache2/php.ini)
upload_max_filesize = 10M
post_max_size = 12M       # Sollte größer sein als upload_max_filesize
max_file_uploads = 20     # Max Anzahl Files pro Request
```

**Oder per .htaccess (wenn AllowOverride aktiv):**

```apache
php_value upload_max_filesize 10M
php_value post_max_size 12M
```

**Nach Änderungen:**

```bash
# PHP-FPM neu laden
sudo systemctl reload php8.2-fpm

# Oder Apache (wenn mod_php)
sudo systemctl reload apache2
```

#### Docker-Umgebungen

Bei Docker-Deployments sind die PHP-Limits bereits im Container konfiguriert (`php.ini`).

**Webserver-Limits anpassen:**

- **Nginx Reverse Proxy:** Siehe "HTTPS Enforcement" → Nginx Config (bereits `client_max_body_size 10M` gesetzt)
- **Apache Frontend:** Siehe oben (Apache Upload-Limits)

**Custom PHP-Limits im Container:**

```yaml
# docker-compose.yml
services:
  backend:
    environment:
      - PHP_UPLOAD_MAX_FILESIZE=20M
      - PHP_POST_MAX_SIZE=22M
```

Oder custom `php.ini` einbinden:

```yaml
services:
  backend:
    volumes:
      - ./custom-php.ini:/usr/local/etc/php/conf.d/uploads.ini
```

#### Troubleshooting

**Symptom: HTTP 413 "Request Entity Too Large"**
→ **Ursache:** Nginx `client_max_body_size` zu klein
→ **Fix:** Siehe "Nginx Upload-Limits" oben

**Symptom: Upload-Form zeigt Fehler, keine HTTP 413**
→ **Ursache:** PHP `upload_max_filesize` oder `post_max_size` zu klein
→ **Fix:** Siehe "PHP Upload-Limits" oben

**Symptom: "The uploaded file exceeds the upload_max_filesize directive in php.ini"**
→ **Ursache:** PHP-Limit erreicht
→ **Fix:** `upload_max_filesize` in php.ini erhöhen

**Limits verifizieren:**

```bash
# Nginx Config testen
sudo nginx -t

# PHP-Limits anzeigen
php -i | grep -E 'upload_max_filesize|post_max_size|client_max_body_size'

# Curl-Test (10MB Dummy-File)
dd if=/dev/zero of=test.bin bs=1M count=10
curl -F "file=@test.bin" https://anmeldung.example.com/api/upload.php
```

**Empfohlene Werte:**

| Limit | Empfehlung | Begründung |
|-------|------------|------------|
| `client_max_body_size` (nginx) | 10M-20M | Formular + mehrere Dateien |
| `upload_max_filesize` (PHP) | 10M | Einzelne Datei |
| `post_max_size` (PHP) | 12M | Größer als upload_max_filesize |
| `UPLOAD_MAX_SIZE` (.env) | 10485760 (10M) | Application-Level Validierung |

---

### Production Checkliste

#### Alle Deployment-Optionen

- [ ] **Secrets:** PDF_TOKEN_SECRET (32+ Zeichen), DB-Passwörter geändert
- [ ] **API-Secret:** `API_SECRET_KEY` mit `openssl rand -hex 32` erzeugt (kein Standardwert — sonst Abbruch/`401`)
- [ ] **Frontend-Secret:** `TENANT_API_SECRET` (und `TENANT_SLUG`) im Frontend bzw. WordPress-Plugin gesetzt und identisch zum Tenant-Secret im Backend
- [ ] **Migration:** `migrate.php` gelaufen (Docker: automatisch) und Formular-Konfiguration eingespielt (`seed-forms.php`, `form_configs` gefüllt)
- [ ] **APP_ENV:** `production` (aktiviert die Ablehnung bekannter Standard-Secrets)
- [ ] **Debugging:** `APP_DEBUG=false` in Production
- [ ] **HTTPS:** SSL-Zertifikat installiert und aktiviert
- [ ] **Backups:** Automatische DB-Backups konfiguriert (Cron)
- [ ] **Admin Auth:** `AUTH_ENABLED=true` und starkes Passwort (optional; bei `MULTI_TENANT_ENABLED=true` immer aktiv)
- [ ] **Env-Dateien:** `frontend/.env` und `backend/.env` mit `chmod 600`, nicht im Repository
- [ ] **Security Headers:** HSTS, CSP, X-Frame-Options aktiv
- [ ] **Firewall:** Unnötige Ports geschlossen (nur 80, 443, ggf. 22)
- [ ] **Git:** `.env` nicht committed, `.gitignore` geprüft
- [ ] **Upload-Limits:** Nginx `client_max_body_size` (10M+), PHP `upload_max_filesize` (10M+), `post_max_size` (12M+) konfiguriert

#### Ab 3.1 (Formular-Editor)

- [ ] **Migration** gelaufen (neue Tabellen `form_resources`, `form_drafts`, `form_revisions`); Details: [MIGRATION-3.1.md](MIGRATION-3.1.md)
- [ ] **Frontend-Cache:** `frontend/cache/` (bzw. `FORM_CACHE_DIR`; WordPress: `wp-content/uploads`) für den Webserver-Benutzer beschreibbar
- [ ] **Backend-Image neu gebaut** (`docker compose build backend`): Apache sendet `X-Frame-Options: SAMEORIGIN`, sonst bleibt die Vorschau leer. Eigene Apache/Nginx-Konfiguration: ebenfalls `SAMEORIGIN` (fremdes Einbetten bleibt verboten)
- [ ] **Surveys** ins Backend übernommen (`import-surveys.php`) oder bewusst als Dateien im Frontend belassen
- [ ] **Neue Schulen:** Tenant anlegen → Formulare übernehmen (`copy-forms.php` oder Oberfläche) → Empfänger eintragen ([MULTI-TENANT.md](MULTI-TENANT.md))
- [ ] **Rate-Limit** für Schreibaktionen passt (`EDITOR_RATE_LIMIT_MAX`/`_WINDOW`, Standard 60/min)
- [ ] **Backup** umfasst die neuen Tabellen (Verlauf und Entwürfe liegen in der Datenbank; ein DB-Dump enthält sie)
- [ ] **WordPress-Plugin:** Verbindungsstatus unter *Einstellungen → Ondisos* zeigt „Secret passt zum Tenant" und die Formularzahl

#### Docker-spezifisch (Option 1 & 3)

- [ ] **Restart Policy:** `restart: unless-stopped` gesetzt
- [ ] **Volumes:** Persistente Volumes für mysql-data, uploads
- [ ] **Secrets:** Keine Secrets in docker-compose.yml hardcoded (use .env)
- [ ] **Updates:** Update-Strategie dokumentiert
- [ ] **Monitoring:** Docker-Logs rotieren (`/etc/docker/daemon.json`)
- [ ] **Network:** Backend-Container nicht öffentlich exponiert
- [ ] **Resource Limits:** Memory/CPU-Limits gesetzt (optional)

#### Manuell-spezifisch (Option 2)

- [ ] **PHP Version:** PHP 8.2+ installiert
- [ ] **Composer:** Dependencies installiert (`composer install`)
- [ ] **Permissions:** `uploads`, `cache`, `logs` beschreibbar (755)
- [ ] **Apache/Nginx:** VirtualHosts konfiguriert und aktiviert
- [ ] **MySQL:** Datenbank erstellt, User angelegt, schema.sql importiert

#### Testing

```bash
# Security Headers testen
curl -I https://anmeldung.example.com

# Oder online:
# https://securityheaders.com/

# HTTPS Redirect testen
curl -I http://anmeldung.example.com
# Sollte: 301 Moved Permanently -> https://

# Docker Health Check
docker compose ps
# Sollte: State: Up (healthy)

# Backend API testen
curl http://your-backend:9080/api/health.php
# Sollte: JSON mit Status ok

curl -i -X POST "http://your-backend:9080/api/submit.php?tenant=default" -d '{}'
# Sollte: 401 {"error":"Unauthorized"} — unsignierte Anfragen werden abgewiesen

curl "http://your-backend:9080/api/form-config.php?form=bs&tenant=default"
# Sollte: {"success":true,"config":{...}}
```

Anschließend eine Testanmeldung im Browser absenden (Eintrag im Backend, PDF-Download, Upload).

---


## 🚀 Deployment

### Übersicht

Für Production stehen verschiedene Setup-Varianten zur Verfügung:

| Komponente | Option 1: Docker Backend | Option 2: Komplett Manuell | Option 3: Komplett Docker |
|------------|--------------------------|----------------------------|---------------------------|
| **Backend** | 🐳 Docker Container | 📄 Apache/PHP | 🐳 Docker Container |
| **Frontend** | 📄 Apache/PHP | 📄 Apache/PHP | 🐳 Docker Container |
| **MySQL** | 🐳 Docker oder bestehend | 📄 MySQL Server | 🐳 Docker Container |
| **Empfehlung** | ✅ **Empfohlen** | Einfachstes Setup | Dev/Testing |

#### Warum Option 1 (Docker Backend)?

**Vorteile:**
- ✅ **Vereinfachte Dependencies** - Composer, mPDF, PHP 8.2+, Tests automatisch installiert
- ✅ **Einfache Updates** - `git pull && docker-compose up -d --build`
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
- Docker Engine 20.10+ oder Docker Desktop
- docker-compose 2.0+

**Setup:**

```bash
# 1. Root .env konfigurieren (Single Source of Truth)
cp .env.example .env
nano .env

# 2. Secrets generieren
openssl rand -hex 32  # → PDF_TOKEN_SECRET
# Passwörter ändern: DB_PASS, MYSQL_ROOT_PASSWORD

# 3. Container starten
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# 4. Logs prüfen
docker compose logs -f backend

# 5. Testen
curl http://your-server.com:8080/index.php
```

**Wichtig - Neue Credentials-Struktur:**

Das Projekt verwendet jetzt eine **Root-`.env`** als Single Source of Truth:
- `/.env` - Core-Credentials (DB_USER, DB_PASS, Secrets) ← **HIER ALLES WICHTIGE**
- `/backend/.env` - Optional, nur für Backend-spezifische Overrides

Dadurch **keine Duplikation** mehr zwischen `DB_USER` und `MYSQL_USER` — beide Werte kommen aus den gleichen Variablen in der Root-`.env`.

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
# Core Credentials (automatisch von docker-compose geladen)
DB_HOST=mysql
DB_NAME=anmeldung
DB_USER=anmeldung
DB_PASS=DeinSicheresPasswort123!

MYSQL_ROOT_PASSWORD=RootPasswort456!
PDF_TOKEN_SECRET=generiert-mit-openssl-rand-hex-32
```

docker-compose mapped automatisch:
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
ExecStart=/usr/bin/docker-compose up -d
ExecStop=/usr/bin/docker-compose down
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
# - API_SECRET_KEY

# Neue Struktur (kein backend/.env nötig für Credentials):
# /.env                  ← Alle Secrets HIER
# /backend/.env          ← Optional, nur für Overrides (Rate Limits, etc.)
```

**Admin Authentication Setup:**

```bash
# 1. In docker-compose.prod.yml ist AUTH_ENABLED=true bereits gesetzt

# 2. Passwort-Hash generieren
docker compose exec backend php scripts/generate-password-hash.php "dein-passwort"

# 3. Hash in backend/.env (oder Root .env) eintragen
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=$2y$10$abc123...

# 4. Container neu starten
docker compose restart backend
```

---

#### 2. Frontend auf Apache/Nginx (Manuell)

Das Frontend läuft auf einem klassischen Webserver (kann auf bestehendem Server mit Wordpress etc. laufen).

**Setup:**

```bash
cd frontend

# 1. Environment konfigurieren
cp .env.example .env
nano .env
# BACKEND_API_URL=http://your-backend-server.com:8080/api

# 2. Forms-Config kopieren
cp config/forms-config-dist.php config/forms-config.php
nano config/forms-config.php

# 3. Verzeichnisse anlegen (falls nötig)
mkdir -p cache
chmod 755 cache
```

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
composer install

# Configure environment
cp .env.example .env
nano .env
# DB_HOST=127.0.0.1 (oder DB-Server)
# DB_PORT=3306
# DB_NAME=anmeldung
# DB_USER=anmeldung
# DB_PASS=secret

# Generate PDF token secret
openssl rand -hex 32
# Add to .env: PDF_TOKEN_SECRET=<generated-key>

# Create directories
mkdir -p cache uploads logs
chmod 755 cache uploads logs
```

#### 2. Frontend Manuell

```bash
cd frontend

# Configure environment
cp .env.example .env
nano .env
# BACKEND_API_URL=http://intranet.example.com/backend/api

# Configure forms
cp config/forms-config-dist.php config/forms-config.php
nano config/forms-config.php
```

#### 3. Database

```bash
mysql -u root -p < database/schema.sql
```

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

# Hash in .env eintragen
ADMIN_PASSWORD_HASH=$2y$10$abc123...
```

---

### Option 3: Komplett Docker

Beide Services (Frontend + Backend) als Container. Primär für Entwicklung und Testing.

Siehe **[DOCKER.md](DOCKER.md)** für vollständige Dokumentation.

**Quick Start:**

```bash
# Container starten
docker-compose up -d

# Tests ausführen
docker-compose exec backend composer test

# Services
# Backend:  http://localhost:8080
# Frontend: http://localhost:8081
# MySQL:    localhost:3306
```

---

### Wartung & Updates

#### Docker-Backend updaten

```bash
cd backend

# 1. Code aktualisieren
git pull origin main

# 2. Container neu bauen
docker-compose build

# 3. Container neu starten (Zero-Downtime mit --no-deps möglich)
docker-compose up -d --build backend

# 4. Logs prüfen
docker-compose logs -f backend

# 5. Health Check
curl http://your-server.com:8080/index.php
```

**Rollback bei Problemen:**

```bash
# Zu vorheriger Git-Version
git checkout <previous-commit>
docker-compose up -d --build backend
```

#### Manuelles Frontend/Backend updaten

```bash
cd frontend  # oder backend

# 1. Code aktualisieren
git pull origin main

# 2. Dependencies aktualisieren (nur Backend)
composer install  # Backend only

# 3. Cache löschen
rm -rf cache/*

# 4. Apache neu laden (optional)
sudo systemctl reload apache2
```

#### Backups

**Docker-Volumes sichern:**

```bash
# MySQL Backup (empfohlen: täglich via Cron)
docker-compose exec mysql mysqldump -u anmeldung -p anmeldung > backup-$(date +%Y%m%d).sql

# Uploads-Volume sichern
docker run --rm -v backend_backend-uploads:/data -v $(pwd):/backup \
  alpine tar czf /backup/uploads-backup-$(date +%Y%m%d).tar.gz -C /data .

# Restore MySQL
docker-compose exec -T mysql mysql -u anmeldung -p anmeldung < backup-20260205.sql

# Restore Uploads
docker run --rm -v backend_backend-uploads:/data -v $(pwd):/backup \
  alpine tar xzf /backup/uploads-backup-20260205.tar.gz -C /data
```

**Manuelle Backups:**

```bash
# Database
mysqldump -u anmeldung -p anmeldung > backup-$(date +%Y%m%d).sql

# Uploads
tar czf uploads-backup-$(date +%Y%m%d).tar.gz backend/uploads

# Restore
mysql -u anmeldung -p anmeldung < backup-20260205.sql
tar xzf uploads-backup-20260205.tar.gz
```

**Backup-Cron (Beispiel):**

```bash
# /etc/cron.daily/ondisos-backup.sh
#!/bin/bash
BACKUP_DIR="/var/backups/ondisos"
DATE=$(date +%Y%m%d)

# DB Backup
docker-compose -f /path/to/backend/docker-compose.yml exec -T mysql \
  mysqldump -u anmeldung -psecret123 anmeldung > "$BACKUP_DIR/db-$DATE.sql"

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
        proxy_pass http://localhost:8080;
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
- [ ] **Debugging:** `APP_DEBUG=false` in Production
- [ ] **HTTPS:** SSL-Zertifikat installiert und aktiviert
- [ ] **Backups:** Automatische DB-Backups konfiguriert (Cron)
- [ ] **Admin Auth:** `AUTH_ENABLED=true` und starkes Passwort (optional)
- [ ] **Security Headers:** HSTS, CSP, X-Frame-Options aktiv
- [ ] **Firewall:** Unnötige Ports geschlossen (nur 80, 443, ggf. 22)
- [ ] **Git:** `.env` nicht committed, `.gitignore` geprüft
- [ ] **Upload-Limits:** Nginx `client_max_body_size` (10M+), PHP `upload_max_filesize` (10M+), `post_max_size` (12M+) konfiguriert

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
docker-compose ps
# Sollte: State: Up (healthy)

# Backend API testen
curl http://your-backend:8080/api/submit.php
# Sollte: JSON Response (auch wenn Fehler wg. fehlender Daten)
```

---


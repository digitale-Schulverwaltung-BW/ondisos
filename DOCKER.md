# Docker Setup Guide

Vollständiges Docker-Setup für das Schulanmeldungs-System mit Backend, Frontend und MySQL.

## 🚀 Quick Start

### 1. Voraussetzungen

- Docker Desktop (Mac/Windows) oder Docker Engine + Docker Compose (Linux)
- Git

### 2. Setup

```bash
# Repository klonen (falls noch nicht geschehen)
cd /path/to/ondisos

# Datenbank-Schema erstellen (falls noch nicht vorhanden)
mkdir -p database
# Kopiere database/schema.sql aus dem Backend-Verzeichnis

# Container starten
docker-compose up -d

# Logs anschauen
docker-compose logs -f
```

### 3. Zugriff

Nach erfolgreichem Start sind folgende Services verfügbar:

| Service | URL | Beschreibung |
|---------|-----|--------------|
| **Backend** | http://localhost:8080 | Admin-Interface |
| **Frontend** | http://localhost:8081 | Öffentliche Formulare |
| **MySQL** | localhost:3306 | Datenbank (user: anmeldung, pass: secret123) |
| **PHPMyAdmin** | http://localhost:8082 | Datenbank-Verwaltung (dev only) |

### 4. Tests ausführen

```bash
# Im Backend-Container
docker-compose exec backend composer test

# Mit Code Coverage
docker-compose exec backend composer test:coverage

# Nur Unit Tests
docker-compose exec backend composer test -- --testsuite=Unit

# Spezifische Test-Klasse
docker-compose exec backend composer test:filter AnmeldungValidatorTest
```

## 📦 Services

### Backend (Admin Interface)

**Container:** `ondisos-backend`
**Port:** 8080
**Image:** PHP 8.2-Apache mit Composer, Xdebug, MySQL-Extensions

**Features:**
- Auto-Installation von Composer-Dependencies
- Automatische .env-Erstellung
- Volumes für Uploads/Cache/Logs
- Hot-Reload bei Code-Änderungen (Volume-Mount)

**Wichtige Pfade:**
- Admin: http://localhost:8080/index.php
- API: http://localhost:8080/api/submit.php
- Excel Export: http://localhost:8080/excel_export.php
- Dashboard: http://localhost:8080/dashboard.php

### Frontend (Öffentliche Formulare)

**Container:** `ondisos-frontend`
**Port:** 8081
**Image:** PHP 8.2-Apache

**Features:**
- SurveyJS-Formulare
- API-Integration mit Backend
- CORS-konfiguriert für lokale Entwicklung

**Wichtige Pfade:**
- Formular BS: http://localhost:8081/index.php?form=bs
- Formular BK: http://localhost:8081/index.php?form=bk
- API Save: http://localhost:8081/save.php

### MySQL

**Container:** `ondisos-mysql`
**Port:** 3306
**Image:** MySQL 8.0

**Credentials:**
- Root: root / rootpass123
- User: anmeldung / secret123
- Database: anmeldung

**Features:**
- Persistente Daten (Volume: mysql-data)
- Auto-Import von database/schema.sql beim ersten Start
- Healthcheck für abhängige Services

### PHPMyAdmin (Optional)

**Container:** `ondisos-phpmyadmin`
**Port:** 8082
**Image:** PHPMyAdmin Latest

**Starten:**
```bash
# Mit PHPMyAdmin
docker-compose --profile dev up -d

# Ohne PHPMyAdmin (Standard)
docker-compose up -d
```

## 🛠️ Entwicklung

### Code-Änderungen

Alle Änderungen am Code werden sofort reflektiert (Hot-Reload):
- Backend: Volume-Mount von `./backend` → `/var/www/html`
- Frontend: Volume-Mount von `./frontend` → `/var/www/html`

**Keine Restarts nötig** für PHP-Code-Änderungen!

### Composer-Pakete hinzufügen

```bash
# Im Backend-Container
docker-compose exec backend composer require vendor/package

# Composer-Cache löschen
docker-compose exec backend composer clear-cache
```

### Tests debuggen

```bash
# Mit verbose Output
docker-compose exec backend composer test -- --testdox

# Mit Debug-Ausgabe
docker-compose exec backend composer test -- --debug

# Einzelner Test
docker-compose exec backend ./vendor/bin/phpunit tests/Unit/Validators/AnmeldungValidatorTest.php::testValidateFormularNameRejectsSqlInjection
```

### Logs anschauen

```bash
# Alle Services
docker-compose logs -f

# Nur Backend
docker-compose logs -f backend

# Nur MySQL
docker-compose logs -f mysql

# PHP Error Log
docker-compose exec backend tail -f /var/www/html/logs/php_errors.log

# Apache Error Log
docker-compose exec backend tail -f /var/log/apache2/error.log
```

### Datenbank-Zugriff

```bash
# MySQL CLI
docker-compose exec mysql mysql -u anmeldung -psecret123 anmeldung

# Datenbank-Dump erstellen
docker-compose exec mysql mysqldump -u anmeldung -psecret123 anmeldung > backup.sql

# Datenbank-Dump importieren
docker-compose exec -T mysql mysql -u anmeldung -psecret123 anmeldung < backup.sql
```

### Shell-Zugriff

```bash
# Backend
docker-compose exec backend bash

# Frontend
docker-compose exec frontend bash

# MySQL
docker-compose exec mysql bash
```

## 🔧 Konfiguration

### Environment Variables

**Backend (.env):**
```bash
APP_ENV=development
APP_DEBUG=true
DB_HOST=mysql
DB_PORT=3306
DB_NAME=anmeldung
DB_USER=anmeldung
DB_PASS=secret123
PDF_TOKEN_SECRET=dev-secret-key-replace-in-production
```

**Frontend (.env):**
```bash
BACKEND_API_URL=http://backend/api
FROM_EMAIL=noreply@example.com
ALLOWED_ORIGINS=http://localhost:8081
```

### Ports ändern

In `docker-compose.yml`:
```yaml
services:
  backend:
    ports:
      - "9000:80"  # Statt 8080

  frontend:
    ports:
      - "9001:80"  # Statt 8081
```

### Memory Limits

```yaml
services:
  backend:
    deploy:
      resources:
        limits:
          memory: 512M
```

## 📊 Volumes

| Volume | Zweck | Persistenz |
|--------|-------|------------|
| `mysql-data` | MySQL Datenbank | ✅ Persistent |
| `backend-uploads` | Hochgeladene Dateien | ✅ Persistent |
| `backend-cache` | App-Cache | ❌ Temporär |
| `backend-logs` | Logs | ❌ Temporär |

### Volume-Verwaltung

```bash
# Volumes anzeigen
docker volume ls

# Volume inspizieren
docker volume inspect ondisos_mysql-data

# Volume-Daten löschen (⚠️ VORSICHT!)
docker-compose down -v

# Nur Cache löschen (sicher)
docker volume rm ondisos_backend-cache
```

## 🧹 Wartung

### Container neu bauen

```bash
# Alle Container
docker-compose build

# Nur Backend
docker-compose build backend

# Build ohne Cache
docker-compose build --no-cache
```

### Container neu starten

```bash
# Alle Services
docker-compose restart

# Nur Backend
docker-compose restart backend
```

### Alles löschen und neu starten

```bash
# Container stoppen und löschen
docker-compose down

# Mit Volumes (⚠️ Datenbank wird gelöscht!)
docker-compose down -v

# Neu bauen und starten
docker-compose build
docker-compose up -d
```

### Docker aufräumen

```bash
# Ungenutzte Container/Images/Netzwerke löschen
docker system prune

# Mit Volumes (⚠️ Alle ungenutzten Volumes!)
docker system prune --volumes

# Images aufräumen
docker image prune -a
```

## 🐛 Troubleshooting

### Port bereits belegt

**Problem:** `Bind for 0.0.0.0:8080 failed: port is already allocated`

**Lösung:**
```bash
# Prozess auf Port finden
lsof -i :8080  # Mac/Linux
netstat -ano | findstr :8080  # Windows

# Port in docker-compose.yml ändern oder Prozess beenden
```

### MySQL startet nicht

**Problem:** Backend kann nicht auf MySQL zugreifen

**Lösung:**
```bash
# MySQL-Logs prüfen
docker-compose logs mysql

# MySQL Healthcheck prüfen
docker-compose ps

# Datenbank-Volume neu erstellen
docker-compose down -v
docker-compose up -d
```

### Composer-Dependencies fehlen

**Problem:** `Class not found`

**Lösung:**
```bash
# Composer install ausführen
docker-compose exec backend composer install

# Autoloader neu generieren
docker-compose exec backend composer dump-autoload
```

### Permissions-Fehler

**Problem:** `Permission denied` für uploads/cache/logs

**Lösung:**
```bash
# Im Container
docker-compose exec backend chown -R www-data:www-data uploads cache logs
docker-compose exec backend chmod -R 755 uploads cache logs

# Auf Host-System (wenn Volume-Mount)
sudo chown -R $(id -u):$(id -g) backend/uploads backend/cache backend/logs
```

### Code-Änderungen werden nicht übernommen

**Problem:** Änderungen am PHP-Code erscheinen nicht

**Lösung:**
```bash
# OPcache leeren (oder Container restart)
docker-compose restart backend

# Cache-Verzeichnis löschen
docker-compose exec backend rm -rf cache/*
```

## 🚀 Production Deployment

### Empfohlenes Setup

**Für Production empfehlen wir:**
- ✅ **Backend:** Docker Container (dieser Guide)
- ⚪ **Frontend:** Manuell auf Apache/Nginx (siehe CLAUDE.md)
- ✅ **MySQL:** Docker Container (oder bestehende DB)

**Warum nur Backend als Container?**
- Backend profitiert von Docker (komplexe Dependencies: Composer, mPDF, Tests)
- Frontend ist einfach (PHP + JS), kann auf bestehendem Webserver laufen
- Frontend kann mit Wordpress etc. koexistieren

**Für Dev/Testing:** Beide Container verwenden (siehe oben)

---

### Production docker-compose.yml

Vollständiges Beispiel für Backend-only Production Deployment:

```yaml
version: '3.8'

services:
  backend:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: ondisos-backend-prod
    restart: unless-stopped  # Automatischer Start nach Reboot
    ports:
      - "8080:80"  # Port anpassen nach Bedarf
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - SESSION_SECURE=true
      - AUTH_ENABLED=true
      - FORCE_HTTPS=true  # Falls hinter HTTPS Proxy
    env_file:
      - .env  # Secrets aus .env laden
    volumes:
      - ./:/var/www/html
      - backend-uploads:/var/www/html/uploads  # Persistent
      - backend-cache:/var/www/html/cache      # Optional persistent
      - backend-logs:/var/www/html/logs        # Logs persistent
    depends_on:
      mysql:
        condition: service_healthy
    networks:
      - backend-network
    deploy:
      resources:
        limits:
          memory: 512M
          cpus: '1.0'
        reservations:
          memory: 256M
          cpus: '0.5'
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/index.php"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s

  mysql:
    image: mysql:8.0
    container_name: ondisos-mysql-prod
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD_FILE: /run/secrets/mysql_root_password
      MYSQL_DATABASE: anmeldung
      MYSQL_USER: anmeldung
      MYSQL_PASSWORD_FILE: /run/secrets/mysql_password
    secrets:
      - mysql_root_password
      - mysql_password
    volumes:
      - mysql-data:/var/lib/mysql
      - ../database/schema.sql:/docker-entrypoint-initdb.d/schema.sql:ro
    ports:
      - "127.0.0.1:3306:3306"  # Nur localhost-Zugriff
    networks:
      - backend-network
    deploy:
      resources:
        limits:
          memory: 1G
          cpus: '1.0'
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  mysql-data:
    driver: local
  backend-uploads:
    driver: local
  backend-cache:
    driver: local
  backend-logs:
    driver: local

networks:
  backend-network:
    driver: bridge

secrets:
  mysql_root_password:
    file: ./secrets/mysql_root_password.txt
  mysql_password:
    file: ./secrets/mysql_password.txt
```

---

### Setup & Deployment

#### 1. Persistenz über Reboots

**restart: unless-stopped** sorgt für automatischen Start nach Reboots.

**Alternative: Systemd Service** (für mehr Kontrolle)

```bash
# /etc/systemd/system/ondisos-backend.service
sudo nano /etc/systemd/system/ondisos-backend.service
```

```ini
[Unit]
Description=Ondisos Backend Docker Compose
Requires=docker.service
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/path/to/ondisos/backend
ExecStart=/usr/bin/docker-compose up -d
ExecStop=/usr/bin/docker-compose down
ExecReload=/usr/bin/docker-compose restart
TimeoutStartSec=0
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

```bash
# Aktivieren
sudo systemctl daemon-reload
sudo systemctl enable ondisos-backend
sudo systemctl start ondisos-backend

# Status prüfen
sudo systemctl status ondisos-backend

# Neu starten
sudo systemctl restart ondisos-backend
```

---

#### 2. Secrets Management

**WICHTIG:** Keine Secrets in `docker-compose.yml` hardcoden!

**Methode 1: .env-Datei (Einfach)**

```bash
# backend/.env
PDF_TOKEN_SECRET=<generiert mit: openssl rand -hex 32>
MYSQL_ROOT_PASSWORD=secure-root-password-here
MYSQL_PASSWORD=secure-user-password-here
ADMIN_PASSWORD_HASH=<generiert mit: docker-compose exec backend php scripts/generate-password-hash.php>

# In .gitignore sicherstellen
echo ".env" >> .gitignore
chmod 600 .env  # Nur owner kann lesen
```

**Methode 2: Docker Secrets (Sicherer)**

```bash
# Secrets-Verzeichnis erstellen
mkdir -p backend/secrets
chmod 700 backend/secrets

# Secrets anlegen
echo "your-secure-root-password" > backend/secrets/mysql_root_password.txt
echo "your-secure-user-password" > backend/secrets/mysql_password.txt
chmod 600 backend/secrets/*.txt

# In .gitignore
echo "secrets/" >> backend/.gitignore

# In docker-compose.yml (siehe Beispiel oben)
# secrets: verwenden statt environment:
```

---

#### 3. Starten mit Production-Config

```bash
cd backend

# 1. Secrets vorbereiten (siehe oben)

# 2. Environment prüfen
cat .env | grep -v "PASSWORD\|SECRET"  # Zeigt config ohne Secrets

# 3. Container bauen und starten
docker-compose up -d --build

# 4. Logs prüfen
docker-compose logs -f

# 5. Health Check
docker-compose ps
# Sollte: State: Up (healthy)

# 6. Backend testen
curl http://localhost:8080/index.php
```

---

### Backups & Recovery

#### Automatische Backups

**Daily Backup Script:**

```bash
# /usr/local/bin/ondisos-backup.sh
#!/bin/bash
set -e

BACKUP_DIR="/var/backups/ondisos"
PROJECT_DIR="/path/to/ondisos/backend"
DATE=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=30

# Verzeichnis erstellen
mkdir -p "$BACKUP_DIR"

# MySQL Backup
echo "Backing up MySQL..."
docker-compose -f "$PROJECT_DIR/docker-compose.yml" exec -T mysql \
  mysqldump -u anmeldung -p"$MYSQL_PASSWORD" anmeldung \
  > "$BACKUP_DIR/mysql-$DATE.sql"

# Uploads-Volume Backup
echo "Backing up uploads..."
docker run --rm \
  -v backend_backend-uploads:/data \
  -v "$BACKUP_DIR":/backup \
  alpine tar czf "/backup/uploads-$DATE.tar.gz" -C /data .

# Alte Backups löschen (älter als RETENTION_DAYS)
echo "Cleaning old backups..."
find "$BACKUP_DIR" -name "mysql-*.sql" -mtime +$RETENTION_DAYS -delete
find "$BACKUP_DIR" -name "uploads-*.tar.gz" -mtime +$RETENTION_DAYS -delete

echo "Backup completed: $DATE"
```

```bash
# Ausführbar machen
chmod +x /usr/local/bin/ondisos-backup.sh

# Cron einrichten (täglich um 2 Uhr)
sudo crontab -e
# Zeile hinzufügen:
0 2 * * * /usr/local/bin/ondisos-backup.sh >> /var/log/ondisos-backup.log 2>&1
```

#### Manuelle Backups

```bash
# MySQL Backup
docker-compose exec mysql mysqldump -u anmeldung -psecret123 anmeldung > backup-$(date +%Y%m%d).sql

# Uploads-Volume Backup
docker run --rm -v backend_backend-uploads:/data -v $(pwd):/backup \
  alpine tar czf /backup/uploads-backup-$(date +%Y%m%d).tar.gz -C /data .

# Komplettes Volume-Backup
docker run --rm -v backend_mysql-data:/data -v $(pwd):/backup \
  alpine tar czf /backup/mysql-data-backup-$(date +%Y%m%d).tar.gz -C /data .
```

#### Recovery

```bash
# MySQL Restore
docker-compose exec -T mysql mysql -u anmeldung -psecret123 anmeldung < backup-20260205.sql

# Uploads-Volume Restore
docker run --rm -v backend_backend-uploads:/data -v $(pwd):/backup \
  alpine sh -c "rm -rf /data/* && tar xzf /backup/uploads-backup-20260205.tar.gz -C /data"

# Komplettes Volume Restore
docker run --rm -v backend_mysql-data:/data -v $(pwd):/backup \
  alpine sh -c "rm -rf /data/* && tar xzf /backup/mysql-data-backup-20260205.tar.gz -C /data"
docker-compose restart mysql
```

---

### Updates & Rollbacks

#### Update-Prozedur

```bash
cd backend

# 1. Backup erstellen (sicherheitshalber)
/usr/local/bin/ondisos-backup.sh

# 2. Code aktualisieren
git fetch origin
git pull origin main

# 3. Container neu bauen
docker-compose build --no-cache

# 4. Container neu starten (Rolling Update)
docker-compose up -d --no-deps backend

# 5. Logs prüfen
docker-compose logs -f backend

# 6. Health Check
docker-compose ps
curl http://localhost:8080/index.php

# 7. Bei Erfolg: Alte Images aufräumen
docker image prune -f
```

#### Rollback bei Problemen

```bash
# Methode 1: Git Rollback
git log --oneline -5  # Letzten Commit finden
git checkout <previous-commit-hash>
docker-compose up -d --build backend

# Methode 2: DB Restore (falls DB-Änderungen)
docker-compose exec -T mysql mysql -u anmeldung -p anmeldung < backup-20260205.sql
docker-compose restart backend

# Methode 3: Kompletter Rollback
docker-compose down
git checkout <previous-commit-hash>
# Restore Volumes (siehe Recovery oben)
docker-compose up -d
```

#### Zero-Downtime Updates (Advanced)

```bash
# 1. Neue Version als separaten Service starten
docker-compose up -d --scale backend=2 --no-recreate

# 2. Health Check der neuen Instanz
docker-compose ps

# 3. Load Balancer umschalten (z.B. Nginx)

# 4. Alte Instanz stoppen
docker-compose up -d --scale backend=1
```

---

### Monitoring & Logging

#### Log-Rotation einrichten

```bash
# /etc/docker/daemon.json
sudo nano /etc/docker/daemon.json
```

```json
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3",
    "compress": "true"
  }
}
```

```bash
# Docker neu starten
sudo systemctl restart docker

# Container neu starten (um neue Log-Config zu übernehmen)
docker-compose restart
```

#### Logs anschauen

```bash
# Live-Logs (alle Services)
docker-compose logs -f

# Nur Backend
docker-compose logs -f backend

# Nur MySQL
docker-compose logs -f mysql

# Letzte 100 Zeilen
docker-compose logs --tail=100 backend

# Mit Timestamps
docker-compose logs -t backend

# PHP Error Log (im Container)
docker-compose exec backend tail -f /var/log/apache2/error.log
```

#### Health Checks

```bash
# Container-Status
docker-compose ps

# Health-Status prüfen
docker inspect --format='{{.State.Health.Status}}' ondisos-backend-prod

# Health-Log anschauen
docker inspect --format='{{range .State.Health.Log}}{{.Output}}{{end}}' ondisos-backend-prod

# Backend-API testen
curl -I http://localhost:8080/index.php

# MySQL-Verbindung testen
docker-compose exec mysql mysqladmin ping -h localhost
```

#### Monitoring mit Prometheus (Optional)

```yaml
# docker-compose.monitoring.yml
version: '3.8'

services:
  prometheus:
    image: prom/prometheus
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml
      - prometheus-data:/prometheus
    ports:
      - "9090:9090"

  grafana:
    image: grafana/grafana
    ports:
      - "3000:3000"
    volumes:
      - grafana-data:/var/lib/grafana

volumes:
  prometheus-data:
  grafana-data:
```

---

### Security Checklist

#### Basis-Security

- [ ] `APP_DEBUG=false` in Production (.env)
- [ ] `PDF_TOKEN_SECRET` mit mindestens 32 Zeichen (openssl rand -hex 32)
- [ ] `ADMIN_PASSWORD_HASH` generiert und gesetzt (wenn AUTH_ENABLED=true)
- [ ] `SESSION_SECURE=true` (nur mit HTTPS)
- [ ] MySQL Root-Passwort geändert (nicht "rootpass123"!)
- [ ] MySQL User-Passwort geändert (nicht "secret123"!)
- [ ] `.env` nicht in Git committet (.gitignore prüfen)
- [ ] `.env` Permissions: `chmod 600 .env`
- [ ] Secrets-Verzeichnis: `chmod 700 secrets/`

#### Docker-Security

- [ ] Container laufen als non-root User (Dockerfile: `USER www-data`)
- [ ] Volumes mit richtigen Permissions (uploads, cache, logs)
- [ ] MySQL Port nur localhost: `127.0.0.1:3306:3306`
- [ ] Keine unnötigen Ports exponiert
- [ ] Resource Limits gesetzt (memory, cpu)
- [ ] Health Checks aktiv
- [ ] Log-Rotation konfiguriert
- [ ] Docker Image Updates regelmäßig (`docker-compose pull`)

#### Network-Security

- [ ] HTTPS eingerichtet (Reverse Proxy: Nginx, Traefik, Caddy)
- [ ] Firewall konfiguriert (ufw, iptables)
  ```bash
  sudo ufw allow 22/tcp    # SSH
  sudo ufw allow 80/tcp    # HTTP
  sudo ufw allow 443/tcp   # HTTPS
  sudo ufw deny 3306/tcp   # MySQL (intern only)
  sudo ufw deny 8080/tcp   # Backend (hinter Proxy)
  sudo ufw enable
  ```
- [ ] Reverse Proxy Headers gesetzt (X-Forwarded-Proto, X-Real-IP)
- [ ] HSTS Header aktiv
- [ ] Security Headers (CSP, X-Frame-Options, etc.)

#### Backup & Recovery

- [ ] Automatische Backups konfiguriert (Cron)
- [ ] Backup-Retention definiert (z.B. 30 Tage)
- [ ] Restore-Prozedur getestet
- [ ] Offsite-Backups eingerichtet (optional)

#### Monitoring

- [ ] Logs werden rotiert (max-size, max-file)
- [ ] Health Checks aktiv und funktionieren
- [ ] Monitoring-Alerts konfiguriert (optional)
- [ ] Uptime-Monitoring (z.B. UptimeRobot, Pingdom)

#### Updates

- [ ] Update-Strategie dokumentiert
- [ ] Rollback-Prozedur getestet
- [ ] Security-Updates regelmäßig (Image-Updates)
- [ ] Composer-Dependencies aktuell (`composer outdated`)

---

### Reverse Proxy Setup (HTTPS)

Für Production sollte der Backend-Container hinter einem Reverse Proxy mit HTTPS laufen.

#### Nginx Reverse Proxy

```nginx
# /etc/nginx/sites-available/ondisos-backend
upstream backend {
    server localhost:8080;
}

server {
    listen 443 ssl http2;
    server_name intranet.example.com;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/intranet.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/intranet.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Security Headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Proxy to Backend
    location / {
        proxy_pass http://backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_redirect off;
    }

    # Upload Size
    client_max_body_size 10M;
}

# HTTP -> HTTPS Redirect
server {
    listen 80;
    server_name intranet.example.com;
    return 301 https://$server_name$request_uri;
}
```

```bash
# Aktivieren
sudo ln -s /etc/nginx/sites-available/ondisos-backend /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx

# Let's Encrypt
sudo certbot --nginx -d intranet.example.com
```

#### Traefik (Docker-native)

```yaml
# docker-compose.yml (mit Traefik Labels)
services:
  backend:
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.backend.rule=Host(`intranet.example.com`)"
      - "traefik.http.routers.backend.entrypoints=websecure"
      - "traefik.http.routers.backend.tls.certresolver=letsencrypt"
      - "traefik.http.services.backend.loadbalancer.server.port=80"
    networks:
      - traefik-network

networks:
  traefik-network:
    external: true
```

---

### Testing Production Setup

```bash
# 1. Container-Status
docker-compose ps
# Sollte: State: Up (healthy)

# 2. Logs prüfen
docker-compose logs --tail=50

# 3. Backend erreichbar
curl -I http://localhost:8080/index.php
# Sollte: 200 OK

# 4. MySQL-Verbindung
docker-compose exec backend php -r "new PDO('mysql:host=mysql;dbname=anmeldung', 'anmeldung', 'secret123');"
# Sollte: Keine Fehler

# 5. Health Check
curl http://localhost:8080/index.php | grep -i "anmeldungen"

# 6. HTTPS testen (wenn Reverse Proxy)
curl -I https://intranet.example.com
# Sollte: 200 OK + Security Headers

# 7. Security Headers
curl -I https://intranet.example.com | grep -i "strict-transport"

# 8. Backup-Script testen
/usr/local/bin/ondisos-backup.sh
ls -lh /var/backups/ondisos/

# 9. Auto-Start testen (Reboot simulieren)
docker-compose restart
sleep 10
docker-compose ps
```

## 📚 Weitere Informationen

- Backend-Dokumentation: `backend/CLAUDE.md`
- Upload-Sicherheit: `backend/UPLOAD_SECURITY.md`
- PDF-System: `backend/PDF_SETUP.md`
- Testing: PHPUnit in `backend/tests/`

## 🆘 Support

Bei Problemen:
1. Logs prüfen: `docker-compose logs`
2. Container-Status: `docker-compose ps`
3. Container neu starten: `docker-compose restart`
4. Issue auf GitHub erstellen

---

**Version:** 1.0
**Last Updated:** Februar 2026

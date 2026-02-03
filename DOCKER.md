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

### Environment für Production

```yaml
# docker-compose.prod.yml
services:
  backend:
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - SESSION_SECURE=true
      - AUTH_ENABLED=true
    deploy:
      replicas: 2
      resources:
        limits:
          memory: 512M
```

### Starten mit Production-Config

```bash
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

### Security Checklist

- [ ] `APP_DEBUG=false` in Production
- [ ] `PDF_TOKEN_SECRET` mit mindestens 32 Zeichen
- [ ] `ADMIN_PASSWORD_HASH` generiert und gesetzt
- [ ] `SESSION_SECURE=true` (nur mit HTTPS)
- [ ] MySQL Root-Passwort geändert
- [ ] MySQL User-Passwort geändert
- [ ] `.env` nicht in Git committet
- [ ] Uploads-Verzeichnis mit `.htaccess` geschützt
- [ ] HTTPS eingerichtet (Reverse Proxy)
- [ ] Firewall-Regeln konfiguriert

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

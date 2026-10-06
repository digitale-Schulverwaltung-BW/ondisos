# Backend ohne Docker installieren

Für Umgebungen ohne Docker: Backend (und bei Bedarf Frontend) auf einem klassischen Apache/PHP-Server mit MySQL oder MariaDB.
Der empfohlene Weg ist der [Docker-Stack](installation.md); er liefert PHP, Composer, alle Erweiterungen und die Migration beim Start gleich mit.

> Diese Anleitung ist aus dem Code und dem Docker-Image abgeleitet; der Weg ohne Docker wird im Projekt weniger oft ausgeführt.
> Der Docker-Weg ist Schritt für Schritt geprüft, dieser nicht auf einem frischen Server. Rückmeldungen sind willkommen.

## Voraussetzungen

| | |
|---|---|
| **PHP** | 8.2 oder höher |
| **PHP-Erweiterungen** | `mysqli`, `mbstring`, `gd` (mit JPEG-Unterstützung: Logos), `zip`, `xml`/`dom`, `fileinfo`, `zlib`, `ctype`; empfohlen `curl`, `bcmath` |
| **Datenbank** | MySQL 8.0+ oder MariaDB 10.5+ |
| **Webserver** | Apache oder Nginx mit PHP-FPM (für die mitgelieferte `.htaccess` Apache mit `mod_rewrite`, `mod_headers` und `AllowOverride All`) |
| **Composer** | zum Installieren der Abhängigkeiten (mPDF, PhpSpreadsheet) |

Das Backend benötigt `mysqli`, nicht `pdo_mysql`.

## 1. Code und Abhängigkeiten

```bash
git clone https://github.com/digitale-Schulverwaltung-BW/ondisos.git /var/www/ondisos
cd /var/www/ondisos/backend
composer install --no-dev --optimize-autoloader
mkdir -p cache uploads logs && chmod 755 cache uploads logs     # Webserver-Benutzer muss schreiben dürfen
```

## 2. Datenbank

```bash
mysql -u root -p -e "CREATE DATABASE anmeldung CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
  CREATE USER 'anmeldung'@'localhost' IDENTIFIED BY '<passwort>'; \
  GRANT ALL ON anmeldung.* TO 'anmeldung'@'localhost';"
mysql -u root -p anmeldung < ../database/schema.sql
```

## 3. Konfiguration

```bash
cp .env.example .env
chmod 600 .env
echo "PDF_TOKEN_SECRET=$(openssl rand -hex 32)" >> .env      # .env.example enthält die Secrets nur als Kommentar
echo "API_SECRET_KEY=$(openssl rand -hex 32)"   >> .env
nano .env
```

Mindestens setzen: `APP_ENV=production`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.
Die Bedeutung der Secrets steht in der [Docker-Anleitung](installation.md#2-konfigurieren); die Regel zu Platzhalter-Secrets gilt hier genauso.

**Admin-Zugang** (in Produktion Pflicht):

```bash
php scripts/generate-password-hash.php "Ihr-Passwort"
```

```bash
# in backend/.env
AUTH_ENABLED=true
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH='$2y$10$…'      # in einfachen Anführungszeichen
```

## 4. Migration und Formulare

```bash
php migrate.php            # Pflicht, auch bei Neuinstallation: setzt das Secret von Tenant 1 auf API_SECRET_KEY
cp ../frontend/config/forms-config-dist.php ../frontend/config/forms-config.php
nano ../frontend/config/forms-config.php      # Formulare und Empfänger anpassen
php seed-forms.php         # liest ../frontend/config/forms-config.php
```

`migrate.php` ist idempotent und läuft bei jedem Update erneut. Ohne diesen Schritt trägt Tenant 1 ein Platzhalter-Secret, und das Backend lehnt alle API-Anfragen ab.
`seed-forms.php` fügt nur neue Formulare hinzu und überschreibt nie.

## 5. Webserver

Das Backend liegt unter `backend/public`. Beispiel Apache:

```apache
<VirtualHost *:80>
    ServerName backend.example.org
    DocumentRoot /var/www/ondisos/backend/public

    <Directory /var/www/ondisos/backend/public>
        AllowOverride All
        Require ip 192.168.0.0/16        # nur Intranet; bei Frontend außerhalb: siehe Betriebsmodell
    </Directory>
</VirtualHost>
```

**HTTPS ist Pflicht** (Zertifikat, Redirect, HSTS): [betrieb.md](betrieb.md#https). Wie das Frontend das Backend erreicht und welche Pfade es braucht:
[Betriebsmodell](betriebsmodell.md#netzwerk-was-muss-erreichbar-sein).

## 6. Prüfen

```bash
curl https://backend.example.org/api/health.php             # {"status":"ok",…}
curl -i -X POST "https://backend.example.org/api/submit.php?tenant=default" -d '{}'     # 401 Unauthorized
curl "https://backend.example.org/api/form-config.php?form=bs&tenant=default"           # {"success":true,…}
```

## Frontend auf demselben oder einem anderen Server

Siehe [Standalone-Frontend](../schul-it/standalone-frontend.md) bzw. das [WordPress-Plugin](../../wordpress-plugin/INSTALL.md). `TENANT_API_SECRET` ist der `API_SECRET_KEY` aus `backend/.env`.

## Updates

Siehe [betrieb.md](betrieb.md#ohne-docker).

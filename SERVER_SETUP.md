# Server Setup Guide

Dieser Guide beschreibt die Vorbereitung der Server für das Ondisos Schulanmeldungs-System.

---

## 📋 Übersicht

Das System besteht aus zwei Servern:

| Server | Komponente | Zweck | Zugriff |
|--------|-----------|-------|---------|
| **Backend Server** | Docker Container + MySQL | Admin-Interface, API | Intranet |
| **Frontend Server** | Apache/Nginx + PHP | Öffentliche Formulare | Internet |

**Hinweis:** Beide Server können auch auf derselben Maschine laufen (Docker isoliert Backend).

---

## 🎯 Backend Server Setup

### Voraussetzungen

- ✅ **OS:** Ubuntu 22.04 LTS oder Debian 11+ (empfohlen)
- ✅ **RAM:** Minimum 2 GB (empfohlen 4 GB)
- ✅ **Disk:** Minimum 20 GB freier Speicher
- ✅ **Network:** Zugriff auf Frontend-Server (Formular-Submissions)
- ✅ **Ports:** 8080 (Backend), 3306 (MySQL, nur localhost)

### Schritt 1: Docker installieren

```bash
# Update System
sudo apt-get update
sudo apt-get upgrade -y

# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Add user to docker group (optional, avoid sudo)
sudo usermod -aG docker $USER
# Log out and back in for group changes to take effect

# Verify Docker installation
docker --version
docker run hello-world
```

### Schritt 2: Docker Compose installieren

```bash
# Install Docker Compose v2 (plugin)
sudo apt-get install docker-compose-plugin -y

# Verify installation
docker compose version
```

### Schritt 3: Git installieren

```bash
sudo apt-get install git -y
git --version
```

### Schritt 4: Repository klonen

```bash
# Create project directory
sudo mkdir -p /var/www/ondisos
sudo chown $USER:$USER /var/www/ondisos

# Clone repository
cd /var/www/ondisos
git clone https://your-git-repo-url.git .

# Checkout production branch (if applicable)
git checkout main
```

### Schritt 5: Backend .env konfigurieren

```bash
# Copy .env template
cd /var/www/ondisos/backend
cp .env.example .env

# Generate secrets
# PDF Token Secret (min 32 chars)
echo "PDF_TOKEN_SECRET=$(openssl rand -hex 32)" >> .env

# MySQL Root Password
echo "MYSQL_ROOT_PASSWORD=$(openssl rand -base64 32)" >> .env

# MySQL User Password
echo "MYSQL_PASSWORD=$(openssl rand -base64 32)" >> .env

# Edit .env with your settings
nano .env
```

**Wichtige Variablen:**

```bash
# Application
APP_ENV=production
APP_DEBUG=false

# Database (Docker)
DB_HOST=mysql                    # Container name!
DB_PORT=3306
DB_NAME=anmeldung
DB_USER=anmeldung
DB_PASS=<MYSQL_PASSWORD>         # Same as MYSQL_PASSWORD

# Docker MySQL
MYSQL_ROOT_PASSWORD=<generated>  # From above
MYSQL_PASSWORD=<generated>       # From above

# PDF Token Secret
PDF_TOKEN_SECRET=<generated>     # From above

# Session
SESSION_LIFETIME=3600
SESSION_SECURE=true

# Auth (optional)
AUTH_ENABLED=false               # Set true if needed
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=            # Generate with scripts/generate-password-hash.php

# Rate Limiting
RATE_LIMIT_ENABLED=true
RATE_LIMIT_MAX_REQUESTS=10
RATE_LIMIT_WINDOW=60

# HTTPS
FORCE_HTTPS=false                # Set true if behind HTTPS proxy

# Virus Scanning (ClamAV) — aktivieren nach erstem Container-Start
# ClamAV lädt beim ersten Start ~300 MB Signaturen (60-90 Sek.)
# Prüfen: docker compose logs clamav | grep "daemon started"
VIRUS_SCAN_ENABLED=false         # Nach erstem ClamAV-Start auf true setzen
CLAMAV_HOST=clamav               # Docker-Service-Name (nicht ändern)
CLAMAV_PORT=3310
VIRUS_SCAN_STRICT=false          # false = soft fail, true = ablehnen wenn ClamAV nicht erreichbar
```

**Secure .env:**

```bash
chmod 600 .env
```

### Schritt 6: Datenbank initialisieren

```bash
# Start MySQL container only (first time)
cd /var/www/ondisos
docker compose up -d mysql

# Wait for MySQL to be ready (30 seconds)
sleep 30

# Import database schema
docker compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" anmeldung < database/schema.sql

# Verify tables exist
docker compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" anmeldung -e "SHOW TABLES;"
```

### Schritt 7: Container starten

```bash
cd /var/www/ondisos

# Start Backend (production mode)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d backend

# Check container status
docker compose ps

# Check logs
docker compose logs -f backend
```

### Schritt 8: Health Check

```bash
# Test Backend availability
curl http://localhost:8080/index.php

# Should return HTML with "Anmeldungen" title
```

### Schritt 9: Backup Script installieren

```bash
# Copy backup script
sudo cp scripts/ondisos-backup.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/ondisos-backup.sh

# Create backup directory
sudo mkdir -p /var/backups/ondisos
sudo chown $USER:$USER /var/backups/ondisos

# Test backup
/usr/local/bin/ondisos-backup.sh

# Verify backup files
ls -lh /var/backups/ondisos/

# Setup cron job (daily at 2 AM)
(crontab -l 2>/dev/null; echo "0 2 * * * /usr/local/bin/ondisos-backup.sh") | crontab -

# Verify cron job
crontab -l
```

### Schritt 10: Persistence über Reboots (Optional)

**Option A: Docker Restart Policy (empfohlen)**

Bereits konfiguriert in `docker-compose.prod.yml`:

```yaml
restart: unless-stopped
```

Container starten automatisch nach Reboot.

**Option B: Systemd Service**

Falls Docker nicht automatisch startet:

```bash
# Enable Docker to start on boot
sudo systemctl enable docker

# Create systemd service
sudo nano /etc/systemd/system/ondisos-backend.service
```

Inhalt:

```ini
[Unit]
Description=Ondisos Backend Docker Container
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/var/www/ondisos
ExecStart=/usr/bin/docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d backend
ExecStop=/usr/bin/docker compose -f docker-compose.yml -f docker-compose.prod.yml down
User=www-data
Group=www-data

[Install]
WantedBy=multi-user.target
```

Enable Service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable ondisos-backend.service
sudo systemctl start ondisos-backend.service

# Verify status
sudo systemctl status ondisos-backend.service

# Test reboot persistence
sudo reboot
# After reboot, check if container is running
docker compose ps
```

### Schritt 11: Monitoring (Optional)

```bash
# Install monitoring tools
sudo apt-get install htop iotop -y

# Monitor Docker resources
docker stats

# Monitor logs in real-time
docker compose logs -f backend

# Setup log rotation (if not already configured)
sudo nano /etc/docker/daemon.json
```

Docker Log-Rotation Config:

```json
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
```

Restart Docker:

```bash
sudo systemctl restart docker
```

---

## 🌐 Frontend Server Setup

### Voraussetzungen

- ✅ **OS:** Ubuntu 22.04 LTS oder Debian 11+
- ✅ **RAM:** Minimum 1 GB
- ✅ **Disk:** Minimum 10 GB freier Speicher
- ✅ **Network:** Öffentlich erreichbar (Port 80/443)
- ✅ **Domain:** z.B. anmeldung.example.com

### Schritt 1: Apache und PHP installieren

```bash
# Update System
sudo apt-get update
sudo apt-get upgrade -y

# Install Apache
sudo apt-get install apache2 -y

# Install PHP 8.2 with extensions
sudo apt-get install software-properties-common -y
sudo add-apt-repository ppa:ondrej/php -y
sudo apt-get update

sudo apt-get install php8.2 php8.2-cli php8.2-common php8.2-curl php8.2-mbstring php8.2-xml -y

# Enable Apache modules
sudo a2enmod rewrite
sudo a2enmod headers
sudo a2enmod ssl

# Restart Apache
sudo systemctl restart apache2

# Verify installation
php -v
apache2 -v
```

### Schritt 2: Git installieren

```bash
sudo apt-get install git -y
```

### Schritt 3: Repository klonen

```bash
# Create project directory
sudo mkdir -p /var/www/ondisos
sudo chown www-data:www-data /var/www/ondisos

# Clone as www-data user (recommended)
sudo -u www-data git clone https://your-git-repo-url.git /var/www/ondisos

# Or clone as current user and fix permissions
cd /var/www/ondisos
git clone https://your-git-repo-url.git .
sudo chown -R www-data:www-data /var/www/ondisos
```

### Schritt 4: Frontend .env konfigurieren

```bash
# Copy .env template
cd /var/www/ondisos/frontend
sudo -u www-data cp .env.example .env

# Edit .env
sudo -u www-data nano .env
```

**Wichtige Variablen:**

```bash
# Backend API (adjust to your backend server URL)
BACKEND_API_URL=http://intranet.example.com:8080/api

# Email
FROM_EMAIL=noreply@example.com
MAIL_HEAD=Eine neue Anmeldung ist eingegangen.
MAIL_FOOT=Mit freundlichen Grüßen

# CORS (adjust to your frontend domain)
ALLOWED_ORIGINS=https://anmeldung.example.com

# File Upload
UPLOAD_MAX_SIZE=10485760
UPLOAD_ALLOWED_TYPES=pdf,jpg,jpeg,png,gif,doc,docx
```

**Secure .env:**

```bash
sudo chmod 600 .env
sudo chown www-data:www-data .env
```

### Schritt 5: Apache VirtualHost konfigurieren

```bash
# Create VirtualHost config
sudo nano /etc/apache2/sites-available/anmeldung.conf
```

**HTTP Config (Testing):**

```apache
<VirtualHost *:80>
    ServerName anmeldung.example.com
    ServerAdmin admin@example.com

    DocumentRoot /var/www/ondisos/frontend/public

    <Directory /var/www/ondisos/frontend/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # Security Headers
        Header set X-Content-Type-Options "nosniff"
        Header set X-Frame-Options "DENY"
        Header set X-XSS-Protection "1; mode=block"
        Header set Referrer-Policy "strict-origin-when-cross-origin"
    </Directory>

    # Logs
    ErrorLog ${APACHE_LOG_DIR}/anmeldung-error.log
    CustomLog ${APACHE_LOG_DIR}/anmeldung-access.log combined
</VirtualHost>
```

**HTTPS Config (Production):**

```apache
<VirtualHost *:80>
    ServerName anmeldung.example.com
    Redirect permanent / https://anmeldung.example.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName anmeldung.example.com
    ServerAdmin admin@example.com

    DocumentRoot /var/www/ondisos/frontend/public

    <Directory /var/www/ondisos/frontend/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # Security Headers
        Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
        Header set X-Content-Type-Options "nosniff"
        Header set X-Frame-Options "DENY"
        Header set X-XSS-Protection "1; mode=block"
        Header set Referrer-Policy "strict-origin-when-cross-origin"
        Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';"
    </Directory>

    # SSL Configuration
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/anmeldung.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/anmeldung.example.com/privkey.pem

    # Logs
    ErrorLog ${APACHE_LOG_DIR}/anmeldung-ssl-error.log
    CustomLog ${APACHE_LOG_DIR}/anmeldung-ssl-access.log combined
</VirtualHost>
```

### Schritt 6: SSL-Zertifikat installieren (Let's Encrypt)

```bash
# Install Certbot
sudo apt-get install certbot python3-certbot-apache -y

# Obtain certificate (interactive)
sudo certbot --apache -d anmeldung.example.com

# Verify auto-renewal
sudo certbot renew --dry-run

# Auto-renewal cron job (already installed by certbot)
sudo systemctl status certbot.timer
```

### Schritt 7: VirtualHost aktivieren

```bash
# Enable site
sudo a2ensite anmeldung.conf

# Disable default site (optional)
sudo a2dissite 000-default.conf

# Test Apache config
sudo apache2ctl configtest

# Reload Apache
sudo systemctl reload apache2
```

### Schritt 8: .htaccess aktivieren

```bash
cd /var/www/ondisos/frontend/public

# Copy .htaccess template
sudo -u www-data cp .htaccess.example .htaccess

# Uncomment HTTPS redirect lines (if using HTTPS)
sudo -u www-data nano .htaccess
# Uncomment lines 10-19 for HTTPS redirect
```

### Schritt 9: Health Check

```bash
# Test HTTP
curl http://anmeldung.example.com/index.php?form=bs

# Test HTTPS
curl https://anmeldung.example.com/index.php?form=bs

# Should return HTML with SurveyJS form
```

### Schritt 10: Firewall konfigurieren

```bash
# Allow HTTP/HTTPS
sudo ufw allow 'Apache Full'

# Enable firewall
sudo ufw enable

# Verify rules
sudo ufw status
```

---

## 🔄 Updates & Maintenance

### Backend Updates

```bash
cd /var/www/ondisos

# Pull latest code
git pull origin main

# Restart containers
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build backend

# Check logs
docker compose logs -f backend
```

### Frontend Updates

```bash
cd /var/www/ondisos

# Pull latest code
sudo -u www-data git pull origin main

# No restart needed (PHP files are read on each request)

# Clear cache if needed
sudo -u www-data rm -rf frontend/cache/*
```

### Database Migrations

```bash
# Backup first!
/usr/local/bin/ondisos-backup.sh

# Apply migration
cd /var/www/ondisos
docker compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" anmeldung < database/migrations/001_add_column.sql

# Verify
docker compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" anmeldung -e "DESCRIBE anmeldungen;"
```

---

## 🧪 Verification Checklist

### Backend Checklist

- [ ] Docker installed (`docker --version`)
- [ ] Docker Compose installed (`docker compose version`)
- [ ] Repository cloned in `/var/www/ondisos`
- [ ] Backend `.env` configured with secrets
- [ ] Database schema imported
- [ ] Backend container running (`docker compose ps`)
- [ ] Backend accessible on port 8080 (`curl http://localhost:8080/index.php`)
- [ ] Backup script installed and tested
- [ ] Cron job for backups configured
- [ ] Container restarts after reboot (test with `sudo reboot`)
- [ ] ClamAV started and signatures loaded (`docker compose logs clamav | grep "daemon started"`)
- [ ] `VIRUS_SCAN_ENABLED=true` set in backend `.env` (after ClamAV is ready)
- [ ] Audit log writable (`ls -la /var/www/ondisos/backend/logs/audit.log` or created on first event)

### Frontend Checklist

- [ ] Apache installed (`apache2 -v`)
- [ ] PHP 8.2 installed (`php -v`)
- [ ] Repository cloned in `/var/www/ondisos`
- [ ] Frontend `.env` configured with backend URL
- [ ] VirtualHost configured and enabled
- [ ] SSL certificate installed (Let's Encrypt)
- [ ] `.htaccess` copied and configured
- [ ] Site accessible via domain (`curl https://anmeldung.example.com/`)
- [ ] HTTPS redirect working (`curl -I http://anmeldung.example.com/`)
- [ ] Form loads correctly (`curl https://anmeldung.example.com/index.php?form=bs`)

---

## 🛠 Troubleshooting

### Backend Issues

**Problem: Docker Compose not found**

```bash
# Install Docker Compose v2 plugin
sudo apt-get install docker-compose-plugin -y
```

**Problem: Permission denied for Docker**

```bash
# Add user to docker group
sudo usermod -aG docker $USER
# Log out and back in
```

**Problem: Container won't start**

```bash
# Check logs
docker compose logs backend

# Check .env file
cat backend/.env | grep -v "PASSWORD\|SECRET"

# Restart container
docker compose down
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d backend
```

**Problem: Database connection failed**

```bash
# Verify MySQL container is running
docker compose ps

# Check MySQL logs
docker compose logs mysql

# Test MySQL connection
docker compose exec mysql mysql -u anmeldung -p"$MYSQL_PASSWORD" anmeldung -e "SELECT 1;"
```

**Problem: Port 8080 already in use**

```bash
# Check what's using port 8080
sudo lsof -i :8080

# Change port in docker-compose.yml
# ports: - "8081:80"  # Use 8081 instead
```

### Frontend Issues

**Problem: Apache not starting**

```bash
# Check Apache error log
sudo tail -f /var/log/apache2/error.log

# Test config
sudo apache2ctl configtest

# Check syntax errors in VirtualHost
sudo apache2ctl -S
```

**Problem: 403 Forbidden**

```bash
# Fix permissions
sudo chown -R www-data:www-data /var/www/ondisos/frontend
sudo chmod -R 755 /var/www/ondisos/frontend/public

# Check .htaccess
cat /var/www/ondisos/frontend/public/.htaccess
```

**Problem: PHP not executing (downloading instead)**

```bash
# Install PHP module for Apache
sudo apt-get install libapache2-mod-php8.2 -y

# Enable PHP module
sudo a2enmod php8.2

# Restart Apache
sudo systemctl restart apache2
```

**Problem: Let's Encrypt fails**

```bash
# Ensure port 80 is open
sudo ufw allow 80

# Ensure DNS points to server
dig anmeldung.example.com +short

# Try manual certificate
sudo certbot certonly --standalone -d anmeldung.example.com
```

**Problem: Backend API not reachable from frontend**

```bash
# Test from frontend server
curl http://intranet.example.com:8080/api/submit.php

# Check BACKEND_API_URL in frontend/.env
grep BACKEND_API_URL /var/www/ondisos/frontend/.env

# Check firewall on backend server
sudo ufw status
# If needed: sudo ufw allow from <frontend-ip> to any port 8080
```

---

## 🚀 Automated Setup

Für eine schnellere Installation stehen Setup-Scripts zur Verfügung:

### Backend Setup Script

```bash
cd /var/www/ondisos
sudo bash scripts/setup-backend-server.sh
```

Führt automatisch aus:
- Docker Installation
- Repository Clone
- .env Konfiguration mit generierten Secrets
- Datenbank-Initialisierung
- Container Start
- Backup Script Installation

### Frontend Setup Script

```bash
cd /var/www/ondisos
sudo bash scripts/setup-frontend-server.sh anmeldung.example.com
```

Führt automatisch aus:
- Apache + PHP Installation
- Repository Clone
- .env Konfiguration
- VirtualHost Erstellung
- Let's Encrypt SSL-Zertifikat
- .htaccess Setup

---

## 📞 Support

Bei Problemen:

1. Check Logs:
   - Backend: `docker compose logs backend`
   - Frontend: `sudo tail -f /var/log/apache2/anmeldung-error.log`

2. Verify Configuration:
   - Backend: `cat backend/.env` (check DB credentials)
   - Frontend: `cat frontend/.env` (check BACKEND_API_URL)

3. Test Connectivity:
   - Frontend → Backend: `curl http://intranet.example.com:8080/api/submit.php`
   - Internet → Frontend: `curl https://anmeldung.example.com/`

4. Check Documentation:
   - [DOCKER.md](DOCKER.md) - Docker Details
   - [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) - Emergency Procedures
   - [CI_CD.md](CI_CD.md) - Deployment Pipeline

---

**Stand:** Januar 2026
**Version:** 2.5

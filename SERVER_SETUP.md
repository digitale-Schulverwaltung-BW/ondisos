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
- ✅ **Ports:** 9080 (Backend), 3306 (MySQL, nur localhost)

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

Ab hier siehe [DEPLOYMENT.md](DEPLOYMENT.md).


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

**Problem: Port 9080 already in use**

```bash
# Check what's using port 9080
sudo lsof -i :9080

# Change port in docker-compose.yml
# ports: - "9081:80"  # Use 9081 instead
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

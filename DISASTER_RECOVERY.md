# Disaster Recovery Playbook

## 🆘 Notfall-Kontakte

**Vor dem Start:** Dieses Dokument ist für Notfälle gedacht. Ruhe bewahren und Schritt für Schritt vorgehen!

| Rolle | Kontakt | Verfügbarkeit |
|-------|---------|---------------|
| **System Admin** | admin@example.com / +49 123 456789 | 24/7 |
| **Entwickler** | dev@example.com / +49 987 654321 | Mo-Fr 9-17 |
| **Schulleitung** | leitung@example.com / +49 111 222333 | Mo-Fr 8-16 |

---

## 📋 Severity Levels

| Level | Beschreibung | Response Time |
|-------|--------------|---------------|
| **P0 - Critical** | System komplett down, keine Anmeldungen möglich | < 15 Min |
| **P1 - High** | Teilausfall, wichtige Funktionen betroffen | < 1h |
| **P2 - Medium** | Performance-Probleme, Workaround möglich | < 4h |
| **P3 - Low** | Kleinere Bugs, kein Impact auf Kernfunktionalität | < 24h |

---

## 🔥 Notfall-Szenarien

### Inhaltsverzeichnis

1. [Complete Outage - System komplett down](#1-complete-outage)
2. [Database Corruption - Datenbank beschädigt](#2-database-corruption)
3. [Container Crashes - Container starten nicht](#3-container-crashes)
4. [Data Loss - Daten gelöscht](#4-data-loss)
5. [Security Breach - System kompromittiert](#5-security-breach)
6. [Disk Full - Speicher voll](#6-disk-full)
7. [Performance Issues - System langsam](#7-performance-issues)
8. [Bad Deployment - Fehlerhafte Updates](#8-bad-deployment)

---

## 1. Complete Outage

**Severity:** P0 - Critical

### Symptoms
- ❌ Backend nicht erreichbar (HTTP 502/503/504)
- ❌ Frontend zeigt "Service Unavailable"
- ❌ Anmeldungen können nicht abgeschickt werden

### Immediate Actions (< 5 Min)

```bash
# 1. Check: Sind Container am laufen?
docker-compose ps

# 2. Check: System-Resources
df -h  # Disk Space
free -h  # Memory
top  # CPU Load

# 3. Check: Logs für Fehler
docker-compose logs --tail=100 backend
docker-compose logs --tail=100 mysql
```

### Recovery Steps

#### Step 1: Container neustarten

```bash
cd /var/www/ondisos/backend

# Restart all containers
docker-compose restart

# Wait 30 seconds
sleep 30

# Check status
docker-compose ps

# Test backend
curl -I http://localhost:8080/index.php
```

#### Step 2: Falls Step 1 nicht hilft - Kompletter Neustart

```bash
# Stop all containers
docker-compose down

# Check for orphaned containers
docker ps -a

# Start fresh
docker-compose up -d

# Monitor logs
docker-compose logs -f
```

#### Step 3: Falls immer noch down - System Check

```bash
# Check Docker daemon
sudo systemctl status docker

# Restart Docker daemon
sudo systemctl restart docker

# Start containers
docker-compose up -d
```

#### Step 4: Database Recovery (falls DB korrupt)

Siehe [Scenario 2: Database Corruption](#2-database-corruption)

### Verification

```bash
# 1. Backend erreichbar
curl http://localhost:8080/index.php | grep -i "anmeldungen"

# 2. Database connection
docker-compose exec backend php -r "new PDO('mysql:host=mysql;dbname=anmeldung', 'anmeldung', 'secret123');"

# 3. Frontend erreichbar
curl http://anmeldung.example.com | grep -i "formular"

# 4. Test submission (optional)
# → Testformular ausfüllen und absenden
```

### Prevention

- ✅ Monitoring einrichten (Uptime Robot, Pingdom)
- ✅ Auto-restart Policy: `restart: unless-stopped`
- ✅ Health Checks in docker-compose.yml
- ✅ Resource Limits setzen
- ✅ Regelmäßige Backups (täglich)

---

## 2. Database Corruption

**Severity:** P0 - Critical

### Symptoms
- ❌ Backend zeigt "Database connection failed"
- ❌ MySQL Container crasht wiederholt
- ❌ Logs zeigen "Table is marked as crashed"

### Immediate Actions

```bash
# 1. Check MySQL status
docker-compose ps mysql

# 2. Check MySQL logs
docker-compose logs --tail=200 mysql | grep -i error

# 3. Try MySQL repair
docker-compose exec mysql mysqlcheck -u root -p --auto-repair --all-databases
```

### Recovery Steps

#### Option A: Automatische Reparatur

```bash
# 1. Stop backend (prevent writes)
docker-compose stop backend

# 2. Repair database
docker-compose exec mysql mysqlcheck -u root -p --auto-repair anmeldung

# 3. Restart MySQL
docker-compose restart mysql

# 4. Verify
docker-compose exec mysql mysql -u root -p -e "SELECT COUNT(*) FROM anmeldung.anmeldungen;"

# 5. Start backend
docker-compose start backend
```

#### Option B: Restore from Backup

```bash
# 1. Stop all containers
docker-compose down

# 2. Find latest backup
ls -lht /var/backups/ondisos/mysql-*.sql | head -5

# 3. Restore from backup
docker-compose up -d mysql
sleep 10

docker-compose exec -T mysql mysql -u root -p anmeldung < /var/backups/ondisos/mysql-20260205_020000.sql

# 4. Verify data
docker-compose exec mysql mysql -u root -p -e "SELECT COUNT(*) FROM anmeldung.anmeldungen;"

# 5. Start backend
docker-compose up -d backend
```

#### Option C: Emergency - MySQL Volume Reset (⚠️ DATA LOSS!)

**NUR wenn keine Backups und DB komplett kaputt!**

```bash
# 1. Backup current state (attempt)
docker run --rm -v backend_mysql-data:/data -v $(pwd):/backup \
  alpine tar czf /backup/mysql-emergency-$(date +%Y%m%d_%H%M%S).tar.gz -C /data .

# 2. Stop and remove containers
docker-compose down

# 3. Remove MySQL volume
docker volume rm backend_mysql-data

# 4. Start fresh (will reinit from schema.sql)
docker-compose up -d

# 5. Import latest backup if available
docker-compose exec -T mysql mysql -u root -p anmeldung < /var/backups/ondisos/mysql-latest.sql
```

### Verification

```bash
# 1. Database accessible
docker-compose exec mysql mysql -u root -p -e "SHOW DATABASES;"

# 2. Tables intact
docker-compose exec mysql mysql -u root -p -e "SHOW TABLES FROM anmeldung;"

# 3. Data count
docker-compose exec mysql mysql -u root -p -e "SELECT COUNT(*) FROM anmeldung.anmeldungen;"

# 4. Backend connection
curl http://localhost:8080/index.php | grep -i "anmeldungen"
```

### Prevention

- ✅ Tägliche Backups (Cron)
- ✅ Regelmäßige mysqlcheck (wöchentlich)
- ✅ RAID für Disk-Redundanz
- ✅ MySQL slow query log aktivieren
- ✅ Offsite-Backups (Cloud)

---

## 3. Container Crashes

**Severity:** P1 - High

### Symptoms
- ❌ `docker-compose ps` zeigt "Restarting" oder "Exited"
- ❌ Container crasht nach Start sofort
- ❌ Logs zeigen Fatal Errors

### Immediate Actions

```bash
# 1. Check container status
docker-compose ps

# 2. Check logs for crash reason
docker-compose logs --tail=200 backend
docker-compose logs --tail=200 mysql

# 3. Check system resources
df -h
free -h
```

### Recovery Steps

#### Common Causes & Fixes

**1. Permission Errors**

```bash
# Symptom: "Permission denied" in logs
# Fix: Correct permissions
docker-compose exec backend chown -R www-data:www-data /var/www/html/uploads
docker-compose exec backend chown -R www-data:www-data /var/www/html/cache
docker-compose exec backend chmod -R 755 /var/www/html/uploads
docker-compose exec backend chmod -R 755 /var/www/html/cache
```

**2. Missing .env File**

```bash
# Symptom: "DB_HOST not set" in logs
# Fix: Create .env from example
cd /var/www/ondisos/backend
cp .env.example .env
nano .env  # Edit values
docker-compose restart backend
```

**3. Port Already in Use**

```bash
# Symptom: "bind: address already in use"
# Fix: Find process using port
sudo lsof -i :8080

# Kill process or change port in docker-compose.yml
nano docker-compose.yml  # Change "8080:80" to "8081:80"
docker-compose up -d
```

**4. Out of Memory**

```bash
# Symptom: "Killed" in logs, OOM in dmesg
# Fix: Increase memory limits
nano docker-compose.yml
# Add under backend service:
#   deploy:
#     resources:
#       limits:
#         memory: 1G

docker-compose up -d
```

**5. Database Connection Failed**

```bash
# Symptom: "Connection refused" to MySQL
# Fix: Wait for MySQL to be healthy
docker-compose up -d mysql
sleep 30  # Wait for MySQL init
docker-compose up -d backend
```

### Verification

```bash
# All containers should be "Up (healthy)"
docker-compose ps

# No crash loops in logs
docker-compose logs --tail=50 backend | grep -i error

# Health checks passing
docker inspect --format='{{.State.Health.Status}}' ondisos-backend
```

### Prevention

- ✅ Health checks in docker-compose.yml
- ✅ Restart policy: `unless-stopped`
- ✅ Resource limits (memory, CPU)
- ✅ Proper dependency order (`depends_on`)
- ✅ Log monitoring (alerts on errors)

---

## 4. Data Loss

**Severity:** P0 - Critical

### Symptoms
- ❌ Anmeldungen verschwunden aus Admin-Interface
- ❌ Uploads fehlen
- ❌ Database leer oder teilweise leer

### Immediate Actions

```bash
# 1. STOP SYSTEM IMMEDIATELY!
docker-compose stop

# 2. DO NOT START UNTIL INVESTIGATION COMPLETE!

# 3. Check if data really lost
docker-compose start mysql
docker-compose exec mysql mysql -u root -p -e "SELECT COUNT(*) FROM anmeldung.anmeldungen;"

# 4. Check backups availability
ls -lh /var/backups/ondisos/
```

### Recovery Steps

#### Step 1: Determine Extent of Loss

```bash
# Check database
docker-compose exec mysql mysql -u root -p anmeldung << 'EOF'
SELECT COUNT(*) as total FROM anmeldungen;
SELECT MAX(created_at) as latest_entry FROM anmeldungen;
SELECT COUNT(*) as deleted FROM anmeldungen WHERE deleted = 1;
EOF

# Check uploads
docker run --rm -v backend_backend-uploads:/data alpine ls -lh /data
```

#### Step 2: Restore from Backup

**Database Restore:**

```bash
# 1. Find latest backup before data loss
ls -lht /var/backups/ondisos/mysql-*.sql

# 2. Stop backend
docker-compose stop backend

# 3. Restore database
docker-compose exec -T mysql mysql -u root -p anmeldung < /var/backups/ondisos/mysql-20260205_020000.sql

# 4. Verify restored data
docker-compose exec mysql mysql -u root -p -e "SELECT COUNT(*) FROM anmeldung.anmeldungen;"

# 5. Start backend
docker-compose start backend
```

**Uploads Restore:**

```bash
# 1. Find latest uploads backup
ls -lht /var/backups/ondisos/uploads-*.tar.gz

# 2. Restore uploads volume
docker run --rm -v backend_backend-uploads:/data -v /var/backups/ondisos:/backup \
  alpine tar xzf /backup/uploads-20260205_020000.tar.gz -C /data

# 3. Fix permissions
docker-compose exec backend chown -R www-data:www-data /var/www/html/uploads
```

#### Step 3: Investigation

```bash
# Check who/what deleted data
# - git log for code changes
# - docker-compose logs for suspicious activity
# - MySQL audit log (if enabled)

# Check for:
# - Manual deletion via admin interface
# - Bulk action gone wrong
# - Auto-expunge misconfiguration
# - SQL injection attack
```

### Verification

```bash
# 1. Data count matches expectations
docker-compose exec mysql mysql -u root -p -e "SELECT COUNT(*) FROM anmeldung.anmeldungen;"

# 2. Recent entries present
docker-compose exec mysql mysql -u root -p -e "SELECT id, name, created_at FROM anmeldung.anmeldungen ORDER BY created_at DESC LIMIT 10;"

# 3. Uploads accessible
curl http://localhost:8080/uploads/test.pdf -I

# 4. Full system test
# → Admin-Login
# → Anmeldungen sichtbar
# → Exports funktionieren
```

### Prevention

- ✅ **Multiple Backup Strategies:**
  - Täglich: Automatische DB-Backups (Cron)
  - Wöchentlich: Vollständige Volume-Backups
  - Monatlich: Offsite-Backups (Cloud/NAS)
- ✅ **Soft-Delete:** Daten nicht direkt löschen (bereits implementiert!)
- ✅ **Backup Retention:** Mindestens 30 Tage
- ✅ **Restore Tests:** Quartalsweise Backups wiederherstellen (Test!)
- ✅ **Access Controls:** AUTH_ENABLED=true in Production
- ✅ **Audit Logging:** Wer hat was gelöscht?

---

## 5. Security Breach

**Severity:** P0 - Critical

### Symptoms
- ⚠️ Unbekannte Logins im System
- ⚠️ Verdächtige Datei-Uploads
- ⚠️ Unerwartete Cron-Jobs oder Prozesse
- ⚠️ Externe Scan-Alerts (Firewall, IDS)

### Immediate Actions (< 10 Min)

```bash
# 1. ISOLATE SYSTEM IMMEDIATELY
sudo ufw deny 8080  # Block backend access
sudo ufw deny 80    # Block frontend access (if needed)

# 2. BACKUP CURRENT STATE (for forensics)
docker-compose logs > /tmp/incident-$(date +%Y%m%d_%H%M%S).log
tar czf /tmp/uploads-forensics.tar.gz backend/uploads/

# 3. CHANGE ALL PASSWORDS
# - MySQL root
# - MySQL anmeldung user
# - Admin login (ADMIN_PASSWORD_HASH)
# - Server SSH keys

# 4. CHECK FOR UNAUTHORIZED ACCESS
docker-compose logs backend | grep -i "login"
docker-compose logs backend | grep -i "upload"
```

### Recovery Steps

#### Step 1: Investigation

```bash
# 1. Check running processes
docker-compose exec backend ps aux

# 2. Check for backdoors/webshells
find backend/ -name "*.php" -mtime -7  # Modified in last 7 days
grep -r "eval(" backend/
grep -r "base64_decode" backend/
grep -r "system(" backend/

# 3. Check uploads for malicious files
docker-compose exec backend find /var/www/html/uploads -type f -name "*.php"
docker-compose exec backend find /var/www/html/uploads -type f -executable

# 4. Check database for SQL injection traces
docker-compose exec mysql mysql -u root -p -e "SHOW PROCESSLIST;"
```

#### Step 2: Clean & Harden

```bash
# 1. Remove suspicious files
# (Nach Review und Backup!)
find backend/uploads/ -name "*.php" -delete
find backend/uploads/ -type f -executable -delete

# 2. Update all dependencies
cd backend
composer update

# 3. Pull latest security updates
git fetch origin
git checkout origin/main

# 4. Rebuild containers (fresh images)
docker-compose build --no-cache
docker-compose up -d --force-recreate

# 5. Harden .htaccess
cp backend/public/.htaccess.example backend/public/.htaccess
# Enable all security headers!
```

#### Step 3: Restore from Clean Backup

**Falls System kompromittiert:**

```bash
# 1. Vollständiger Neuaufbau
docker-compose down -v  # Remove all volumes!

# 2. Code frisch auschecken
cd /var/www
rm -rf ondisos.old
mv ondisos ondisos.compromised
git clone <repo-url> ondisos

# 3. Restore nur DB-Daten (nach Review!)
cd ondisos/backend
docker-compose up -d mysql
docker-compose exec -T mysql mysql -u root -p anmeldung < /var/backups/ondisos/mysql-clean.sql

# 4. Uploads nach Review wiederherstellen
# (Nur nach Malware-Scan!)
```

### Verification

```bash
# 1. Security Scan
# ClamAV für Malware
clamscan -r backend/

# 2. Vulnerability Scan
# OWASP ZAP oder Nikto
nikto -h http://localhost:8080

# 3. Check for remaining backdoors
grep -r "eval\|base64_decode\|system\|exec" backend/src/

# 4. Firewall rules active
sudo ufw status

# 5. HTTPS enforced
curl -I http://intranet.example.com | grep -i "location: https"
```

### Prevention

- ✅ **AUTH_ENABLED=true** in Production
- ✅ **Strong Passwords** (min 16 Zeichen)
- ✅ **HTTPS Enforcement** (FORCE_HTTPS=true)
- ✅ **Rate Limiting** aktiv
- ✅ **File Upload Validation** (bereits implementiert)
- ✅ **Regular Security Updates**
- ✅ **Firewall** (ufw, iptables)
- ✅ **Fail2Ban** für SSH
- ✅ **Security Headers** (CSP, HSTS, etc.)
- ✅ **Audit Logging**

---

## 6. Disk Full

**Severity:** P1 - High

### Symptoms
- ⚠️ "No space left on device"
- ⚠️ Backend kann nicht schreiben (Uploads, Cache, Logs)
- ⚠️ MySQL kann nicht schreiben

### Immediate Actions

```bash
# 1. Check disk usage
df -h

# 2. Find largest directories
du -sh /* | sort -hr | head -10
du -sh /var/lib/docker/* | sort -hr | head -10

# 3. Quick cleanup
docker system prune -f  # Remove unused images/containers
```

### Recovery Steps

#### Step 1: Emergency Cleanup

```bash
# 1. Clear Docker cache
docker system prune -af --volumes
# CAUTION: This removes ALL unused volumes!

# 2. Clear old logs
find /var/log -name "*.log" -mtime +30 -delete
truncate -s 0 /var/log/apache2/*.log

# 3. Clear application logs
docker-compose exec backend rm -rf /var/www/html/logs/*.log
docker-compose exec backend rm -rf /var/www/html/cache/*

# 4. Clear old backups (keep last 7 days)
find /var/backups/ondisos -name "*.sql" -mtime +7 -delete
find /var/backups/ondisos -name "*.tar.gz" -mtime +7 -delete
```

#### Step 2: Persistent Fix

```bash
# 1. Configure log rotation
sudo nano /etc/docker/daemon.json
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3",
    "compress": "true"
  }
}

sudo systemctl restart docker

# 2. Configure backup retention
nano /usr/local/bin/ondisos-backup.sh
# Set RETENTION_DAYS=7  # statt 30

# 3. Add disk monitoring
# → Uptime Robot Disk Space Alert
```

### Verification

```bash
# 1. Sufficient space available
df -h | grep "/$"
# Should show < 80% usage

# 2. Docker working
docker ps

# 3. Backend can write
docker-compose exec backend touch /var/www/html/uploads/test.txt
docker-compose exec backend rm /var/www/html/uploads/test.txt

# 4. MySQL can write
docker-compose exec mysql mysql -u root -p -e "CREATE TABLE test (id INT); DROP TABLE test;" anmeldung
```

### Prevention

- ✅ **Disk Monitoring** (Alert bei 80% voll)
- ✅ **Log Rotation** (Docker, Apache, MySQL)
- ✅ **Backup Retention** (7-30 Tage, nicht unbegrenzt)
- ✅ **Regelmäßiges Cleanup** (Cron: docker system prune)
- ✅ **Separate Partition** für /var/lib/docker

---

## 7. Performance Issues

**Severity:** P2 - Medium

### Symptoms
- ⚠️ Backend langsam (> 5s Ladezeit)
- ⚠️ Timeouts bei Formular-Submissions
- ⚠️ Hohe Server-Last

### Immediate Actions

```bash
# 1. Check system load
top
htop  # if available

# 2. Check Docker stats
docker stats

# 3. Check slow queries
docker-compose exec mysql mysql -u root -p -e "SHOW PROCESSLIST;"
```

### Recovery Steps

#### Step 1: Identify Bottleneck

**CPU-bound:**
```bash
# Check processes
docker stats

# If backend using 100% CPU:
# → Check for infinite loops in code
# → Check for slow queries
# → Check for large file processing
```

**Memory-bound:**
```bash
# Check memory usage
free -h
docker stats

# If backend using too much RAM:
# → Restart backend: docker-compose restart backend
# → Increase limits in docker-compose.yml
```

**Disk I/O-bound:**
```bash
# Check disk I/O
iostat -x 1

# If high I/O wait:
# → Check for large file uploads
# → Check MySQL slow queries
# → Check log file writes
```

**Database-bound:**
```bash
# Enable slow query log
docker-compose exec mysql mysql -u root -p << 'EOF'
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;
SET GLOBAL slow_query_log_file = '/var/log/mysql/slow.log';
EOF

# Check slow queries after some time
docker-compose exec mysql tail -f /var/log/mysql/slow.log
```

#### Step 2: Quick Fixes

```bash
# 1. Clear cache
docker-compose exec backend rm -rf /var/www/html/cache/*

# 2. Restart services
docker-compose restart

# 3. Optimize database (if many deleted entries)
docker-compose exec mysql mysqlcheck -u root -p --optimize anmeldung

# 4. Increase PHP memory limit (if needed)
# backend/php.ini or docker-compose.yml environment:
# - PHP_MEMORY_LIMIT=256M
```

### Verification

```bash
# 1. Response time < 2s
time curl http://localhost:8080/index.php

# 2. Low server load
uptime  # Load average < number of CPUs

# 3. Database queries fast
docker-compose exec mysql mysqladmin -u root -p processlist

# 4. No slow queries
docker-compose exec mysql mysql -u root -p -e "SELECT * FROM mysql.slow_log LIMIT 10;"
```

### Prevention

- ✅ **Resource Limits** in docker-compose.yml
- ✅ **Database Indexes** auf häufig abgefragten Feldern
- ✅ **Query Optimization** (N+1 Problem vermeiden)
- ✅ **Caching** (OPcache für PHP)
- ✅ **Monitoring** (Prometheus + Grafana)
- ✅ **Regular Optimization** (mysqlcheck --optimize)

---

## 8. Bad Deployment

**Severity:** P1 - High

### Symptoms
- ⚠️ System funktioniert nicht nach Update
- ⚠️ Neue Fehler in Logs
- ⚠️ Features kaputt

### Immediate Actions

```bash
# 1. Check what changed
git log --oneline -5

# 2. Check logs for new errors
docker-compose logs --tail=200 backend | grep -i error

# 3. Decide: Fix forward or rollback?
```

### Recovery Steps

#### Option A: Rollback (Recommended for P0/P1)

```bash
# 1. Find previous working version
git log --oneline -10

# 2. Rollback code
git checkout <previous-commit-hash>

# 3. Rebuild and restart
docker-compose build --no-cache backend
docker-compose up -d --force-recreate backend

# 4. Verify
curl -I http://localhost:8080/index.php

# 5. If database changes: restore DB backup
docker-compose exec -T mysql mysql -u root -p anmeldung < /var/backups/ondisos/mysql-before-deployment.sql
```

#### Option B: Fix Forward (für P2/P3)

```bash
# 1. Identify issue
docker-compose logs --tail=500 backend

# 2. Fix code
nano backend/src/...

# 3. Test locally
docker-compose restart backend

# 4. Commit fix
git add .
git commit -m "Hotfix: ..."
git push
```

#### GitLab CI/CD Rollback

```bash
# In GitLab UI:
# Pipelines → Find previous successful deployment → Retry "deploy:production"

# Oder manuell:
gitlab-runner exec docker rollback:production
```

### Verification

```bash
# 1. System works
curl http://localhost:8080/index.php | grep -i "anmeldungen"

# 2. No errors in logs
docker-compose logs --tail=100 backend | grep -i error

# 3. Test key features
# → Login
# → Formular ansehen
# → Testanmeldung
# → Excel Export

# 4. Database intact
docker-compose exec mysql mysql -u root -p -e "SELECT COUNT(*) FROM anmeldung.anmeldungen;"
```

### Prevention

- ✅ **Staging Environment** (Test vor Production!)
- ✅ **Automated Tests** (PHPUnit in CI/CD)
- ✅ **Manual Approval** für Production-Deployments
- ✅ **Backup vor Deployment** (automatisch in CI/CD)
- ✅ **Health Checks** nach Deployment
- ✅ **Gradual Rollout** (Blue-Green, Canary)
- ✅ **Deployment Windows** (nicht Freitag Abend!)

---

## 📞 Eskalationspfad

### Level 1: Automated Response

- Monitoring-Alerts → Auto-Restart (Docker restart policy)
- Log-based Alerts → Slack/Email Notification

### Level 2: On-Call Admin

- P0/P1 Incidents → Immediate Response
- Execute Playbook Steps
- Document actions in incident log

### Level 3: Developer

- Complex Issues benötigen Code-Änderungen
- Security Incidents
- Data Corruption Investigation

### Level 4: External Support

- Hosting Provider (bei Infrastruktur-Problemen)
- GitLab Support (bei Pipeline-Issues)
- External Security Team (bei Breach)

---

## 📝 Incident Log Template

```markdown
# Incident Report: [Titel]

**Date:** 2026-02-05 14:30 UTC
**Severity:** P0
**Status:** Resolved

## Timeline

- 14:30: Incident detected (monitoring alert)
- 14:32: On-call admin notified
- 14:35: Investigation started
- 14:45: Root cause identified (database corruption)
- 14:50: Recovery initiated (restore from backup)
- 15:00: System restored
- 15:10: Verification completed
- 15:15: Incident closed

## Root Cause

MySQL container crashed due to out-of-memory condition.

## Impact

- 45 minutes downtime
- 0 data loss (backup restore successful)
- 3 users affected (could not submit forms)

## Actions Taken

1. Restored database from 02:00 backup
2. Increased MySQL memory limit to 1GB
3. Enabled OOM alerts

## Prevention

- [ ] Add memory monitoring alerts
- [ ] Increase backup frequency to hourly
- [ ] Review MySQL configuration
- [ ] Add resource limits in docker-compose.yml

## Lessons Learned

- Current backup strategy worked well
- Need faster alert response time
- Should have had memory limits configured
```

---

## 🧪 Regular Drills

**Empfehlung:** Quartalsweise Disaster Recovery Drills durchführen!

### Drill 1: Backup Restore

```bash
# Simuliere Data Loss und restore
docker-compose exec mysql mysql -u root -p -e "DROP DATABASE anmeldung;"
# → Restore from backup
# → Verify data intact
```

### Drill 2: Complete Rebuild

```bash
# Simuliere Server-Crash
docker-compose down -v
# → Rebuild from scratch
# → Restore all data
# → Verify system works
```

### Drill 3: Security Incident

```bash
# Simuliere Breach
# → Isolate system
# → Investigation
# → Password changes
# → Rebuild
```

---

## 📚 Weitere Ressourcen

- [DOCKER.md](DOCKER.md) - Docker Deployment Guide
- [CI_CD.md](CI_CD.md) - Automated Deployment
- [CLAUDE.md](CLAUDE.md) - Full Documentation

**Checklisten:**
- [ ] Backup-Prozedur getestet (monatlich)
- [ ] Rollback-Prozedur getestet (quartalsweise)
- [ ] Notfall-Kontakte aktuell
- [ ] Monitoring & Alerts aktiv
- [ ] .env Secrets sicher
- [ ] Offsite-Backups konfiguriert

---

**Version:** 1.0
**Last Updated:** Februar 2026
**Review Date:** Mai 2026

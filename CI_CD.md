# CI/CD Pipeline - Automated Deployment Guide

## 📋 Übersicht

Dieses Dokument beschreibt die automatisierte Deployment-Pipeline für das Schulanmeldungs-System.

**Was ist CI/CD?**
- **Continuous Integration (CI):** Automatisches Testen bei jedem Code-Push
- **Continuous Deployment (CD):** Automatisches Deployment bei erfolgreichen Tests

**Vorteile:**
- ✅ Automatische Tests verhindern Bugs in Production
- ✅ Schnellere Deployments (Minuten statt Stunden)
- ✅ Konsistente Deployments (keine manuellen Fehler)
- ✅ Rollback mit einem Klick
- ✅ Deployment-History nachvollziehbar

---

## 🏗️ Pipeline-Architektur

```
Git Push → GitLab
    ↓
┌─────────────────────────────────────────────────────────┐
│ CI/CD Pipeline                                          │
├─────────────────────────────────────────────────────────┤
│ Stage 1: Build                                          │
│  - Composer install                                     │
│  - Docker image build                                   │
├─────────────────────────────────────────────────────────┤
│ Stage 2: Test                                           │
│  - PHPUnit tests                                        │
│  - Code coverage                                        │
│  - Security scans                                       │
├─────────────────────────────────────────────────────────┤
│ Stage 3: Deploy (Staging)                               │
│  - Deploy to staging server                             │
│  - Smoke tests                                          │
│  - Manual approval gate                                 │
├─────────────────────────────────────────────────────────┤
│ Stage 4: Deploy (Production)                            │
│  - Deploy to production                                 │
│  - Health checks                                        │
│  - Rollback on failure                                  │
└─────────────────────────────────────────────────────────┘
    ↓
Production Server
```

---

## 🚀 GitLab CI/CD Pipeline

### .gitlab-ci.yml (Vollständig)

Erstelle diese Datei im Repository-Root:

```yaml
# ============================================================================
# GitLab CI/CD Pipeline for Ondisos School Registration System
# ============================================================================
# Stages: build → test → deploy-staging → deploy-production
# ============================================================================

stages:
  - build
  - test
  - deploy-staging
  - deploy-production

# ============================================================================
# Global Variables
# ============================================================================
variables:
  DOCKER_DRIVER: overlay2
  DOCKER_TLS_CERTDIR: "/certs"
  MYSQL_ROOT_PASSWORD: "testpass123"
  MYSQL_DATABASE: "anmeldung_test"
  MYSQL_USER: "anmeldung_test"
  MYSQL_PASSWORD: "testpass123"

# ============================================================================
# Build Stage
# ============================================================================
build:backend:
  stage: build
  image: composer:2
  cache:
    key: composer-$CI_COMMIT_REF_SLUG
    paths:
      - backend/vendor/
  script:
    - cd backend
    - composer install --no-dev --optimize-autoloader
    - composer dump-autoload --optimize
  artifacts:
    paths:
      - backend/vendor/
    expire_in: 1 hour
  only:
    - main
    - develop
    - /^release-.*$/

build:docker:
  stage: build
  image: docker:24
  services:
    - docker:24-dind
  before_script:
    - docker login -u $CI_REGISTRY_USER -p $CI_REGISTRY_PASSWORD $CI_REGISTRY
  script:
    - cd backend
    - docker build -t $CI_REGISTRY_IMAGE/backend:$CI_COMMIT_SHORT_SHA .
    - docker build -t $CI_REGISTRY_IMAGE/backend:latest .
    - docker push $CI_REGISTRY_IMAGE/backend:$CI_COMMIT_SHORT_SHA
    - docker push $CI_REGISTRY_IMAGE/backend:latest
  only:
    - main
    - develop

# ============================================================================
# Test Stage
# ============================================================================
test:unit:
  stage: test
  image: php:8.2-cli
  services:
    - mysql:8.0
  variables:
    MYSQL_HOST: mysql
    DB_HOST: mysql
  before_script:
    - apt-get update && apt-get install -y git unzip libzip-dev zip
    - docker-php-ext-install pdo pdo_mysql zip
    - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  script:
    - cd backend
    - composer install
    - cp .env.example .env
    - php -r "echo 'Running PHPUnit tests...' . PHP_EOL;"
    - ./vendor/bin/phpunit --testdox --colors=never
  artifacts:
    when: always
    reports:
      junit: backend/tests/results/junit.xml
  only:
    - main
    - develop
    - merge_requests

test:coverage:
  stage: test
  image: php:8.2-cli
  services:
    - mysql:8.0
  before_script:
    - apt-get update && apt-get install -y git unzip libzip-dev zip
    - pecl install xdebug && docker-php-ext-enable xdebug
    - docker-php-ext-install pdo pdo_mysql zip
    - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  script:
    - cd backend
    - composer install
    - cp .env.example .env
    - XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text --colors=never
  coverage: '/^\s*Lines:\s*\d+\.\d+\%/'
  artifacts:
    paths:
      - backend/coverage/
    expire_in: 30 days
  only:
    - main
    - develop

security:sast:
  stage: test
  image: returntocorp/semgrep
  script:
    - semgrep --config auto backend/src/
  allow_failure: true
  only:
    - main
    - develop
    - merge_requests

security:secrets:
  stage: test
  image: trufflesecurity/trufflehog:latest
  script:
    - trufflehog filesystem . --json --no-update
  allow_failure: true
  only:
    - main
    - develop
    - merge_requests

# ============================================================================
# Deploy Staging
# ============================================================================
deploy:staging:
  stage: deploy-staging
  image: alpine:latest
  before_script:
    - apk add --no-cache openssh-client rsync
    - eval $(ssh-agent -s)
    - echo "$SSH_PRIVATE_KEY" | tr -d '\r' | ssh-add -
    - mkdir -p ~/.ssh
    - chmod 700 ~/.ssh
    - ssh-keyscan -H $STAGING_SERVER >> ~/.ssh/known_hosts
  script:
    - echo "Deploying to staging server..."

    # Rsync backend code
    - rsync -avz --delete --exclude='.git' --exclude='vendor' --exclude='uploads' --exclude='cache'
        backend/ $STAGING_USER@$STAGING_SERVER:$STAGING_PATH/backend/

    # SSH into staging and update
    - ssh $STAGING_USER@$STAGING_SERVER << 'EOF'
        cd $STAGING_PATH/backend

        # Pull latest images
        docker-compose -f docker-compose.yml -f docker-compose.prod.yml pull

        # Restart containers
        docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d --no-deps backend

        # Health check
        sleep 10
        curl -f http://localhost:8080/index.php || exit 1

        echo "Staging deployment successful!"
      EOF
  environment:
    name: staging
    url: https://staging.intranet.example.com
  only:
    - develop
  when: manual

# ============================================================================
# Deploy Production
# ============================================================================
deploy:production:
  stage: deploy-production
  image: alpine:latest
  before_script:
    - apk add --no-cache openssh-client rsync
    - eval $(ssh-agent -s)
    - echo "$SSH_PRIVATE_KEY_PROD" | tr -d '\r' | ssh-add -
    - mkdir -p ~/.ssh
    - chmod 700 ~/.ssh
    - ssh-keyscan -H $PRODUCTION_SERVER >> ~/.ssh/known_hosts
  script:
    - echo "Deploying to production server..."

    # Backup first
    - ssh $PRODUCTION_USER@$PRODUCTION_SERVER << 'EOF'
        /usr/local/bin/ondisos-backup.sh
      EOF

    # Rsync backend code
    - rsync -avz --delete --exclude='.git' --exclude='vendor' --exclude='uploads' --exclude='cache'
        backend/ $PRODUCTION_USER@$PRODUCTION_SERVER:$PRODUCTION_PATH/backend/

    # SSH into production and update
    - ssh $PRODUCTION_USER@$PRODUCTION_SERVER << 'EOF'
        cd $PRODUCTION_PATH/backend

        # Pull latest images
        docker-compose -f docker-compose.yml -f docker-compose.prod.yml pull

        # Rolling update (zero-downtime)
        docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d --no-deps backend

        # Health check
        sleep 15
        curl -f http://localhost:8080/index.php || exit 1

        # Cleanup old images
        docker image prune -f

        echo "Production deployment successful!"
      EOF
  environment:
    name: production
    url: https://intranet.example.com
  only:
    - main
  when: manual

# ============================================================================
# Rollback Production
# ============================================================================
rollback:production:
  stage: deploy-production
  image: alpine:latest
  before_script:
    - apk add --no-cache openssh-client
    - eval $(ssh-agent -s)
    - echo "$SSH_PRIVATE_KEY_PROD" | tr -d '\r' | ssh-add -
    - mkdir -p ~/.ssh
    - chmod 700 ~/.ssh
    - ssh-keyscan -H $PRODUCTION_SERVER >> ~/.ssh/known_hosts
  script:
    - echo "Rolling back production to previous version..."
    - ssh $PRODUCTION_USER@$PRODUCTION_SERVER << 'EOF'
        cd $PRODUCTION_PATH/backend

        # Rollback to previous git commit
        git log --oneline -5
        read -p "Enter commit hash to rollback to: " COMMIT_HASH
        git checkout $COMMIT_HASH

        # Rebuild and restart
        docker-compose -f docker-compose.yml -f docker-compose.prod.yml build backend
        docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d --no-deps backend

        # Health check
        sleep 10
        curl -f http://localhost:8080/index.php || exit 1

        echo "Rollback successful!"
      EOF
  environment:
    name: production
  only:
    - main
  when: manual
```

---

## 🔐 GitLab CI/CD Variables einrichten

### 1. GitLab UI öffnen

```
Project → Settings → CI/CD → Variables
```

### 2. Variablen anlegen

**Staging:**
| Key | Value | Protected | Masked |
|-----|-------|-----------|--------|
| `STAGING_SERVER` | `staging.example.com` | ✅ | ❌ |
| `STAGING_USER` | `deploy` | ✅ | ❌ |
| `STAGING_PATH` | `/var/www/ondisos` | ✅ | ❌ |
| `SSH_PRIVATE_KEY` | `<staging-ssh-key>` | ✅ | ✅ |

**Production:**
| Key | Value | Protected | Masked |
|-----|-------|-----------|--------|
| `PRODUCTION_SERVER` | `intranet.example.com` | ✅ | ❌ |
| `PRODUCTION_USER` | `deploy` | ✅ | ❌ |
| `PRODUCTION_PATH` | `/var/www/ondisos` | ✅ | ❌ |
| `SSH_PRIVATE_KEY_PROD` | `<production-ssh-key>` | ✅ | ✅ |

**Docker Registry:**
| Key | Value | Protected | Masked |
|-----|-------|-----------|--------|
| `CI_REGISTRY` | `registry.gitlab.com` | ❌ | ❌ |
| `CI_REGISTRY_USER` | `gitlab-ci-token` | ❌ | ❌ |
| `CI_REGISTRY_PASSWORD` | `<auto-generated>` | ✅ | ✅ |

---

## 🔑 SSH-Keys für Deployment generieren

### 1. SSH-Key auf CI-Server generieren

```bash
# Auf lokalem Rechner
ssh-keygen -t ed25519 -C "gitlab-ci-deploy" -f ~/.ssh/gitlab-ci-deploy

# Private Key (für GitLab Variables)
cat ~/.ssh/gitlab-ci-deploy

# Public Key (für Server authorized_keys)
cat ~/.ssh/gitlab-ci-deploy.pub
```

### 2. Public Key auf Servern hinterlegen

**Staging:**
```bash
ssh user@staging.example.com
mkdir -p ~/.ssh
chmod 700 ~/.ssh
nano ~/.ssh/authorized_keys
# Paste public key
chmod 600 ~/.ssh/authorized_keys
```

**Production:**
```bash
ssh user@intranet.example.com
mkdir -p ~/.ssh
chmod 700 ~/.ssh
nano ~/.ssh/authorized_keys
# Paste public key
chmod 600 ~/.ssh/authorized_keys
```

### 3. Test SSH-Verbindung

```bash
ssh -i ~/.ssh/gitlab-ci-deploy user@staging.example.com
ssh -i ~/.ssh/gitlab-ci-deploy user@intranet.example.com
```

---

## 🎯 Deployment-Workflow

### Entwicklung → Staging

```bash
# 1. Feature entwickeln
git checkout -b feature/my-feature
# Code ändern...
git add .
git commit -m "Add my feature"
git push origin feature/my-feature

# 2. Merge Request erstellen
# → GitLab UI: Create Merge Request

# 3. Nach Review: Merge in develop
# → Automatically runs: build + test

# 4. Deploy to Staging (Manual)
# → GitLab UI: Pipelines → Run manual job "deploy:staging"

# 5. Staging testen
curl https://staging.intranet.example.com
```

### Staging → Production

```bash
# 1. Merge develop → main
git checkout main
git merge develop
git push origin main

# 2. Pipeline läuft automatisch
# → build + test + coverage

# 3. Deploy to Production (Manual Approval!)
# → GitLab UI: Pipelines → Run manual job "deploy:production"

# 4. Health Check
curl https://intranet.example.com
docker-compose logs -f backend
```

### Rollback

```bash
# GitLab UI: Pipelines → Run manual job "rollback:production"

# Oder manuell:
ssh user@intranet.example.com
cd /var/www/ondisos/backend
git log --oneline -10
git checkout <previous-commit>
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build backend
```

---

## 📊 Monitoring & Alerts

### GitLab Pipeline Notifications

**.gitlab-ci.yml** (am Ende hinzufügen):

```yaml
# Notify on pipeline failure
notify:slack:
  stage: .post
  image: curlimages/curl:latest
  script:
    - |
      curl -X POST -H 'Content-type: application/json' \
      --data "{\"text\":\"Pipeline failed: $CI_PROJECT_NAME ($CI_COMMIT_REF_NAME)\"}" \
      $SLACK_WEBHOOK_URL
  only:
    - main
  when: on_failure
```

### Uptime Monitoring

**UptimeRobot / Pingdom:**
- Monitor: `https://intranet.example.com/index.php`
- Interval: 5 minutes
- Alert: Email + Slack

---

## 🧪 Testing Pipeline

### Lokales Testen (GitLab Runner)

```bash
# Install GitLab Runner
curl -L https://packages.gitlab.com/install/repositories/runner/gitlab-runner/script.deb.sh | sudo bash
sudo apt-get install gitlab-runner

# Test pipeline lokal
gitlab-runner exec docker test:unit
gitlab-runner exec docker test:coverage
```

### Pipeline debuggen

```yaml
# In .gitlab-ci.yml temporär hinzufügen
debug:
  stage: test
  script:
    - echo "Debug info:"
    - pwd
    - ls -la
    - env | sort
  when: manual
```

---

## 🔧 Troubleshooting

### Pipeline schlägt fehl: "Permission denied"

**Problem:** SSH-Key nicht korrekt hinterlegt

**Lösung:**
```bash
# GitLab Variables prüfen
# → SSH_PRIVATE_KEY muss kompletten Key enthalten (inkl. BEGIN/END)

# authorized_keys auf Server prüfen
ssh user@server
cat ~/.ssh/authorized_keys
```

### Pipeline schlägt fehl: "Docker image not found"

**Problem:** Docker Registry Login fehlgeschlagen

**Lösung:**
```bash
# CI/CD Variables prüfen:
# CI_REGISTRY_USER
# CI_REGISTRY_PASSWORD

# Oder manuell testen:
docker login registry.gitlab.com -u $CI_REGISTRY_USER -p $CI_REGISTRY_PASSWORD
```

### Deployment langsam

**Problem:** rsync überträgt zu viele Dateien

**Lösung:**
```yaml
# .gitlab-ci.yml: exclude mehr Verzeichnisse
- rsync -avz --delete
    --exclude='.git'
    --exclude='vendor'
    --exclude='node_modules'
    --exclude='uploads'
    --exclude='cache'
    --exclude='logs'
    backend/ user@server:/path/
```

### Health Check schlägt fehl nach Deployment

**Problem:** Container startet zu langsam

**Lösung:**
```yaml
# Mehr Zeit geben
- sleep 30  # statt sleep 10
- curl -f http://localhost:8080/index.php || exit 1
```

---

## 📚 Best Practices

### 1. Branch Strategy

```
main (production)
  ↑
develop (staging)
  ↑
feature/* (feature branches)
```

### 2. Semantic Versioning

```bash
# Git Tags für Releases
git tag -a v2.5.0 -m "Release 2.5.0"
git push origin v2.5.0

# In .gitlab-ci.yml:
# only:
#   - tags
```

### 3. Environment-specific Configs

```yaml
# .gitlab-ci.yml
deploy:staging:
  variables:
    APP_ENV: staging
    APP_DEBUG: true

deploy:production:
  variables:
    APP_ENV: production
    APP_DEBUG: false
```

### 4. Deployment Windows

```yaml
# Nur zu bestimmten Zeiten deployen
deploy:production:
  only:
    - schedules  # Via GitLab Schedules
  # Oder manuell nur Mo-Fr 9-17 Uhr
```

### 5. Blue-Green Deployment (Advanced)

```bash
# Zwei identische Environments
# Aktiv: backend-blue (Port 8080)
# Standby: backend-green (Port 8081)

# Deploy to green
docker-compose up -d backend-green

# Test green
curl http://localhost:8081/index.php

# Switch traffic (Nginx/HAProxy)
# backend → localhost:8081

# Keep blue as rollback
```

---

## 🆘 Support

**Bei Problemen:**
1. GitLab Pipeline Logs prüfen
2. Server Logs prüfen: `docker-compose logs -f backend`
3. Health Checks manuell ausführen
4. Rollback erwägen

**Weitere Ressourcen:**
- [GitLab CI/CD Docs](https://docs.gitlab.com/ee/ci/)
- [Docker Compose Docs](https://docs.docker.com/compose/)
- DISASTER_RECOVERY.md

---

**Version:** 1.0
**Last Updated:** Februar 2026

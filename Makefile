.PHONY: help build up down restart logs shell test test-unit test-coverage clean

# Default target
help: ## Show this help message
	@echo "📋 Available commands:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

# Docker commands
build: ## Build all containers
	docker-compose build

up: ## Start all containers
	docker-compose up -d
	@echo "✅ Services started!"
	@echo "🌐 Backend:     http://localhost:8080"
	@echo "🌐 Frontend:    http://localhost:8081"
	@echo "📊 PHPMyAdmin:  http://localhost:8082 (with --profile dev)"

down: ## Stop all containers
	docker-compose down

restart: ## Restart all containers
	docker-compose restart

logs: ## Show logs (press Ctrl+C to exit)
	docker-compose logs -f

logs-backend: ## Show backend logs only
	docker-compose logs -f backend

logs-frontend: ## Show frontend logs only
	docker-compose logs -f frontend

logs-mysql: ## Show MySQL logs only
	docker-compose logs -f mysql

# Shell access
shell: ## Open bash in backend container
	docker-compose exec backend bash

shell-frontend: ## Open bash in frontend container
	docker-compose exec frontend bash

mysql: ## Open MySQL CLI
	docker-compose exec mysql mysql -u anmeldung -psecret123 anmeldung

# Testing
test: ## Run all tests
	docker-compose exec backend composer test

test-unit: ## Run unit tests only
	docker-compose exec backend composer test -- --testsuite=Unit

test-integration: ## Run integration tests only
	docker-compose exec backend composer test -- --testsuite=Integration

test-coverage: ## Run tests with code coverage
	docker-compose exec backend composer test:coverage
	@echo "📊 Coverage report: backend/coverage/index.html"

test-watch: ## Run tests in watch mode (requires watchexec)
	watchexec -e php -- make test

# Composer
composer-install: ## Install composer dependencies
	docker-compose exec backend composer install

composer-update: ## Update composer dependencies
	docker-compose exec backend composer update

composer-dump: ## Dump autoloader
	docker-compose exec backend composer dump-autoload

# Database
db-dump: ## Create database dump
	docker-compose exec mysql mysqldump -u anmeldung -psecret123 anmeldung > backup_$(shell date +%Y%m%d_%H%M%S).sql
	@echo "✅ Database backup created"

db-restore: ## Restore database from backup.sql
	docker-compose exec -T mysql mysql -u anmeldung -psecret123 anmeldung < backup.sql
	@echo "✅ Database restored"

db-reset: ## Reset database (⚠️  deletes all data!)
	@echo "⚠️  This will delete all data! Press Ctrl+C to cancel, or Enter to continue..."
	@read
	docker-compose down -v
	docker-compose up -d
	@echo "✅ Database reset"

# Maintenance
clean: ## Clean up cache and logs
	docker-compose exec backend rm -rf cache/* logs/*.log
	@echo "✅ Cache and logs cleaned"

clean-all: ## Remove all containers, volumes, and images (⚠️  DESTRUCTIVE!)
	@echo "⚠️  This will delete ALL data! Press Ctrl+C to cancel, or Enter to continue..."
	@read
	docker-compose down -v
	docker system prune -a --volumes -f
	@echo "✅ Everything cleaned"

rebuild: down build up ## Rebuild and restart all containers

# Development
dev: ## Start with PHPMyAdmin (dev profile)
	docker-compose --profile dev up -d
	@echo "✅ Services started (dev mode)!"
	@echo "🌐 Backend:     http://localhost:8080"
	@echo "🌐 Frontend:    http://localhost:8081"
	@echo "📊 PHPMyAdmin:  http://localhost:8082"

prod: ## Start in production mode
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# Status
ps: ## Show container status
	docker-compose ps

stats: ## Show container resource usage
	docker stats --format "table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}"

# Quick actions
quick-test: up test ## Start containers and run tests
	@echo "✅ Quick test complete!"

fix-permissions: ## Fix file permissions
	docker-compose exec backend chown -R www-data:www-data uploads cache logs
	docker-compose exec backend chmod -R 755 uploads cache logs
	@echo "✅ Permissions fixed"

# Install (first time setup)
install: build up composer-install ## Complete installation
	@echo "✅ Installation complete!"
	@echo ""
	@echo "📝 Next steps:"
	@echo "  1. Visit http://localhost:8080 (Backend Admin)"
	@echo "  2. Visit http://localhost:8081/?form=bs (Frontend Form)"
	@echo "  3. Run 'make test' to verify setup"

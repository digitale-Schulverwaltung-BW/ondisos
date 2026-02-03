# AGENTS.md - Development Guidelines for Ondisos School Registration System

This document provides guidelines for agentic coding agents working on the Ondisos school registration system.

## Project Structure

This is a dual-server application:
- **Frontend**: Public-facing SurveyJS forms at `frontend/public/`
- **Backend**: Admin interface at `backend/public/` (intranet only)

## Build/Lint/Test Commands

### Backend (PHP)

**Dependencies:**
```bash
cd backend
composer install
```

**Testing:**
```bash
# Run all tests
composer test

# Run with code coverage
composer test:coverage

# Run specific test class
composer test:filter RateLimiterTest
composer test:filter PdfTokenServiceTest

# Run specific test method
composer test:filter "RateLimiterTest::testRequestTracking"

# Run only Unit tests
composer test -- --testsuite=Unit

# Run only Integration tests
composer test -- --testsuite=Integration
```

**PHPUnit Configuration:**
- Bootstrap: `tests/bootstrap.php`
- Test suites: Unit (in `tests/Unit/`), Integration (in `tests/Integration/`)
- Coverage excludes: `src/Config/`, `src/Utils/NullableHelpers.php`

**Environment Setup:**
```bash
# Copy example env
cp .env.example .env

# Generate PDF token secret
openssl rand -hex 32
# Add to .env: PDF_TOKEN_SECRET=<generated-key>

# Create directories
mkdir -p cache uploads logs
chmod 755 cache uploads logs
```

### Frontend (PHP)

**Dependencies:**
```bash
cd frontend
composer install
```

**Testing:**
- No automated tests currently
- Manual testing via browser

## Code Style Guidelines

### PHP Standards

**File Headers:**
```php
<?php
// src/Path/To/File.php

declare(strict_types=1);

namespace App\Path\To;
```

**Type Safety:**
- Always use `declare(strict_types=1)`
- Use type hints for all parameters
- Use return type declarations
- Use nullable types (`?string`, `?int`)
- Use readonly classes for immutable data models

**Naming Conventions:**
- **Classes**: PascalCase (`AnmeldungService`, `PdfTokenService`)
- **Methods**: camelCase (`getPaginatedAnmeldungen`, `processSubmission`)
- **Properties**: camelCase (`$repository`, `$testStorageDir`)
- **Constants**: UPPER_SNAKE_CASE (`ALLOWED_PER_PAGE`, `DEFAULT_PER_PAGE`)
- **Files**: PascalCase for classes (`AnmeldungService.php`), kebab-case for views (`index.php`)

**Namespace Structure:**
- Backend: `App\` (e.g., `App\Services\AnmeldungService`)
- Frontend: `Frontend\` (e.g., `Frontend\Services\AnmeldungService`)

**Import Statements:**
```php
use App\Repositories\AnmeldungRepository;
use App\Models\Anmeldung;
use DateTimeImmutable;
use mysqli;

// Group by: 1) Internal, 2) External, 3) PHP built-ins
```

**Error Handling:**
```php
try {
    // Code that might throw
} catch (SpecificException $e) {
    // Handle specific exception
    throw new CustomException('User-friendly message', 0, $e);
} catch (Throwable $e) {
    // Last resort catch-all
    error_log('Unexpected error: ' . $e->getMessage());
    throw new CustomException('Unexpected error occurred');
}
```

**Validation:**
- Use validation methods in services (`validatePerPage`, `validateEmail`)
- Return structured validation errors
- Use constants for allowed values

**Database:**
- Use prepared statements with parameter binding
- Never concatenate SQL strings
- Use repository pattern for data access
- Handle null values properly with `?? null`

**Security:**
- Use `htmlspecialchars()` for HTML output
- Use `filter_var()` for email validation
- Implement CSRF protection
- Use HMAC for token generation
- Never expose sensitive data in errors

### JavaScript Standards (Frontend)

**File Structure:**
```javascript
// Class-based JavaScript
class SurveyHandler {
    constructor() {
        this.config = {};
        this.messages = {};
    }

    async init() {
        await this.loadConfig();
        await this.loadMessages();
        this.setupEventListeners();
    }
}
```

**Naming:**
- **Classes**: PascalCase (`SurveyHandler`, `BackendApiClient`)
- **Methods**: camelCase (`loadConfig`, `setupEventListeners`)
- **Variables**: camelCase (`config`, `messages`)
- **Constants**: UPPER_SNAKE_CASE (`MAX_FILE_SIZE`, `ALLOWED_TYPES`)

**Async/Await:**
- Use async/await instead of .then() chains
- Handle errors with try/catch
- Use Promise.all() for parallel operations

**Error Handling:**
```javascript
try {
    const response = await fetch(url, options);
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    return await response.json();
} catch (error) {
    console.error('API call failed:', error);
    throw error;
}
```

**API Integration:**
- Use JSON for all API communication
- Include CSRF tokens in headers
- Handle HTTP status codes appropriately
- Use consistent error response format

## Testing Guidelines

### PHPUnit Tests

**Test Structure:**
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\RateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private string $testStorageDir;
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();
        // Setup test environment
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up test environment
    }

    public function testMethodDoesSomething(): void
    {
        $this->assertTrue(true);
    }
}
```

**Test Naming:**
- `testMethodDoesWhatWhenCondition`
- `testConstructorThrowsExceptionIfSecretMissing`
- `testRequestTrackingAndLimitEnforcement`

**Test Data:**
- Use realistic test data
- Test edge cases (empty strings, null values, large inputs)
- Test error conditions

**Environment Variables:**
- Use `putenv()` for test-specific environment variables
- Restore original values in `tearDown()`
- Use test database credentials

## Configuration Management

### Environment Files

**Backend:**
- `.env.example` - Template with all configuration options
- `.env` - Actual environment file (gitignored)
- Test environment: `.env.test` (for PHPUnit)

**Frontend:**
- `.env.example` - Template
- `.env` - Actual environment file (gitignored)

**Form Configuration:**
- `config/forms-config.php` - Main form configuration
- `config/forms-config.example.php` - Template for local overrides

### Local Overrides

Use `.local.php` files for local customizations:
- `messages.local.php` for custom UI text
- `forms-config.local.php` for form customizations
- These files are gitignored to prevent conflicts

## Security Guidelines

### Input Validation
- Always validate user input
- Use prepared statements for database queries
- Validate file uploads (type, size, extension)
- Sanitize output for HTML contexts

### Authentication
- Use session-based authentication for admin interface
- Implement CSRF protection
- Use secure password hashing
- Implement rate limiting for login attempts

### Data Protection
- Use HTTPS in production
- Implement proper access controls
- Use HMAC for secure tokens
- Never log sensitive data

## Performance Guidelines

### Database
- Use indexes on frequently queried columns
- Implement pagination for large result sets
- Use connection pooling
- Optimize queries with EXPLAIN

### Caching
- Use file-based caching for expensive operations
- Implement cache invalidation strategies
- Use appropriate cache lifetimes

### Frontend
- Optimize JavaScript bundle size
- Use lazy loading for large resources
- Implement proper caching headers

## Documentation Guidelines

### PHPDoc
```php
/**
 * Short description
 * 
 * @param string $param Description
 * @param int $optionalParam Default value
 * @return array{success: bool, data?: array} Description
 * @throws SpecificException When condition occurs
 */
```

### JavaScript
```javascript
/**
 * Short description
 * @param {string} param - Description
 * @param {number} [optionalParam=0] - Default value
 * @returns {Promise<object>} Description
 * @throws {Error} When condition occurs
 */
```

## Git Workflow

### Commit Messages
- Use present tense: "add feature", "fix bug", "update documentation"
- Keep first line under 50 characters
- Add detailed description if needed
- Reference issues if applicable

### Branch Strategy
- `main` - Production
- `develop` - Development
- Feature branches: `feature/description`
- Bug fixes: `fix/description`

## Common Patterns

### Service Pattern
```php
class Service
{
    public function __construct(
        private Repository $repository,
        private Dependency $dependency
    ) {}

    public function method(): array
    {
        try {
            $result = $this->repository->find();
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
```

### Repository Pattern
```php
class Repository
{
    public function findPaginated(
        ?string $filter = null,
        int $limit = 25,
        int $offset = 0
    ): array {
        $sql = "SELECT * FROM table WHERE deleted = 0";
        $params = [];
        $types = '';

        if ($filter !== null) {
            $sql .= " AND column = ?";
            $params[] = $filter;
            $types .= 's';
        }

        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        // Execute prepared statement
        return $this->executeQuery($sql, $types, $params);
    }
}
```

## Testing Single Tests

To run a single test method:
```bash
composer test:filter "RateLimiterTest::testRequestTracking"
composer test:filter "PdfTokenServiceTest::testTokenGenerationFormat"
```

To run all tests in a specific test file:
```bash
composer test:filter RateLimiterTest
```

To run tests with specific tags or groups (if configured):
```bash
composer test -- --group=unit
composer test -- --group=integration
```

## Docker Development Environment

**Alternative to local PHP installation:**

This project includes a comprehensive Docker setup that provides a complete development environment with all required PHP extensions and services.

### Quick Start
```bash
cd /path/to/ondisos

docker-compose up -d
```

### Running Tests in Docker
```bash
# In Backend container
docker-compose exec backend composer test

# With code coverage
docker-compose exec backend composer test:coverage

# Only unit tests
docker-compose exec backend composer test -- --testsuite=Unit

# Specific test class
docker-compose exec backend composer test:filter AnmeldungValidatorTest
```

### Available Services
- **Backend**: http://localhost:8080 (Admin interface)
- **Frontend**: http://localhost:8081 (Public forms)
- **MySQL**: localhost:3306
- **PHPMyAdmin**: http://localhost:8082 (dev only)

### Benefits
- No local PHP installation required
- Consistent environment across teams
- All required extensions pre-installed
- Hot-reload for code changes
- Complete MySQL development database

### Debugging Tests
```bash
# Verbose output
docker-compose exec backend composer test -- --testdox

# Debug output
docker-compose exec backend composer test -- --debug

# Single test execution
docker-compose exec backend ./vendor/bin/phpunit tests/Unit/Validators/AnmeldungValidatorTest.php::testValidateFormularNameRejectsSqlInjection
```

### Logs and Troubleshooting
```bash
# All service logs
docker-compose logs -f

# Backend specific logs
docker-compose logs -f backend

# PHP error logs
docker-compose exec backend tail -f /var/www/html/logs/php_errors.log

# Apache error logs
docker-compose exec backend tail -f /var/log/apache2/error.log
```

**Note:** The Docker environment is fully configured with all PHP extensions needed for testing, including GD, PDO, MySQL, and Xdebug for code coverage.

### Testing Without Local PHP

If you don't have PHP installed locally, you can use the Docker environment to run all tests:

```bash
# Run all tests
docker-compose exec backend composer test

# Run specific test
docker-compose exec backend composer test:filter RateLimiterTest

# Run with coverage
docker-compose exec backend composer test:coverage
```

The Docker container includes all necessary PHP extensions and Composer dependencies, making it a complete development environment without requiring local PHP installation.

## Database Schema

Always use the following pattern for database operations:
- Use `deleted` flag for soft deletes
- Use `created_at` and `updated_at` timestamps
- Use proper indexing for performance
- Use UTF-8 encoding with `utf8mb4_unicode_ci` collation

## File Upload Guidelines

- Validate file types against whitelist
- Check file size limits
- Store files outside web root when possible
- Use secure file names (avoid user input)
- Implement virus scanning if available

## API Response Format

```json
{
    "success": true,
    "data": {},
    "message": "Operation completed successfully"
}
```

```json
{
    "success": false,
    "error": "Error description",
    "code": "ERROR_CODE"
}
```
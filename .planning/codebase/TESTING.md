# Testing Patterns

**Analysis Date:** 2026-03-13

## Test Framework

**Runner:**
- PHPUnit 10.5
- Config: `backend/phpunit.xml`

**Assertion Library:**
- PHPUnit built-in assertions (no external assertion library)

**Run Commands:**
```bash
cd backend

# Run all tests
composer test
# or: ./vendor/bin/phpunit

# Watch mode
# (Not configured; re-run composer test as needed)

# Coverage report
composer test:coverage
# Generates: backend/coverage/index.html

# Filter tests by name
composer test:filter MessageServiceTest
# or: ./vendor/bin/phpunit --filter MessageServiceTest
```

## Test File Organization

**Location:**
- Co-located: Tests in `backend/tests/` parallel structure to `backend/src/`

**Naming:**
- Pattern: `ClassName` + `Test.php` suffix
- Examples: `MessageServiceTest.php`, `RateLimiterTest.php`, `AnmeldungServiceTest.php`

**Structure:**
```
backend/tests/
├── bootstrap.php                           # Test environment setup
├── Unit/                                   # Unit tests (no external dependencies)
│   ├── Controllers/
│   │   └── DetailControllerTest.php
│   ├── Models/
│   │   └── AnmeldungTest.php
│   ├── Services/
│   │   ├── AnmeldungServiceTest.php
│   │   ├── AuditLoggerTest.php
│   │   ├── ExportServiceTest.php
│   │   ├── ExpungeServiceTest.php
│   │   ├── MessageServiceTest.php
│   │   ├── PdfTokenServiceTest.php
│   │   ├── RateLimiterTest.php
│   │   ├── RequestExpungeServiceTest.php
│   │   ├── SchoolLookupServiceTest.php
│   │   ├── StatusServiceTest.php
│   │   └── VirusScanServiceTest.php
│   ├── Upload/
│   │   ├── MimeTypeValidationTest.php
│   │   └── UploadSecurityTest.php
│   ├── Utils/
│   │   └── DataFormatterTest.php
│   └── Validators/
│       ├── AnmeldungFormValidatorTest.php
│       └── AnmeldungValidatorTest.php
└── Integration/                            # Integration tests (with real DB)
    └── (currently empty)
```

## Test Structure

**Suite Organization:**
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MessageService;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for MessageService
 *
 * Tests central message management including:
 * - Dot notation access
 * - Placeholder replacement
 * - Local overrides
 * - Contact info integration
 * - Fallback handling
 */
class MessageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup before each test
        MessageService::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Cleanup after each test
        MessageService::reset();
    }

    // =========================================================================
    // Organized by functionality (section comments)
    // =========================================================================

    public function testGetReturnsSimpleMessage(): void
    {
        $message = MessageService::get('validation.required_formular');
        $this->assertNotEmpty($message);
    }

    public function testGetReturnsDefaultWhenKeyNotFound(): void
    {
        $default = 'Fallback Text';
        $message = MessageService::get('nonexistent.key', $default);
        $this->assertEquals($default, $message);
    }
}
```

**Patterns:**
- Setup: Initialize dependencies, create mocks, set test data
- Teardown: Clean up temporary files, reset static state, restore environment variables
- Assertion-first: Arrange → Act → Assert (AAA pattern)
- Section comments: `// =========================================================================` to group related tests
- Descriptive names: `testMethodDoesWhatWhenCondition()`

## Mocking

**Framework:** PHPUnit's built-in `createMock()` and mocking features

**Patterns:**
```php
// Mock a class dependency
private AnmeldungRepository $mockRepo;

protected function setUp(): void
{
    parent::setUp();
    $this->mockRepo = $this->createMock(AnmeldungRepository::class);
    $this->service = new AnmeldungService($this->mockRepo);
}

// Configure mock to return specific values
private function repoReturns(int $total = 0, array $items = []): void
{
    $this->mockRepo->method('findPaginated')->willReturn([
        'total' => $total,
        'items' => $items,
    ]);
}

// Assert mock was called with specific arguments
$this->mockRepo->expects($this->once())
    ->method('findPaginated')
    ->with(
        formularFilter: null,
        statusFilter: null,
        limit: 25,
        offset: 0
    )
    ->willReturn(['total' => 0, 'items' => []]);
```

**Advanced Mocking - Anonymous Subclass Pattern:**

For testing internal logic of classes with hard-to-mock dependencies (like network sockets), use anonymous subclasses with Reflection:

```php
// In VirusScanServiceTest.php
private function makeScannerWith(string $clamdResponse): VirusScanService
{
    return new class($clamdResponse) extends VirusScanService {
        public function __construct(private string $fakeResponse)
        {
            parent::__construct('localhost', 3310, 1);
        }

        public function scanFile(string $filePath): array
        {
            // Bypass real socket; call parsing logic via reflection
            $ref = new \ReflectionClass(VirusScanService::class);
            $method = $ref->getMethod('parseResponse');
            $method->setAccessible(true);
            return $method->invoke($this, $this->fakeResponse);
        }
    };
}

public function testParsesOkResponse(): void
{
    $result = $this->makeScannerWith('stream: OK')->scanFile('/fake');
    $this->assertTrue($result['clean']);
}
```

**What to Mock:**
- External services: database (use dependency injection)
- Repository calls: mock when testing service layer
- API calls: mock HTTP clients
- Configuration: use test environment variables

**What NOT to Mock:**
- Models: test with real instances
- Value objects: test constructor validation
- Utility functions: test with real data
- MessageService: test with real message files
- Static utility classes: test directly

## Fixtures and Factories

**Test Data:**
- Inline data: Create test models directly in test methods
- Arrays: Pass test data as arrays to be converted to objects

**Example:**
```php
public function testValidateRejectsInvalidEmail(): void
{
    $validator = new AnmeldungValidator();
    $data = [
        'formular' => 'bs',
        'name' => 'John Doe',
        'email' => 'invalid-email',
    ];

    $isValid = $validator->validate($data);

    $this->assertFalse($isValid);
    $this->assertArrayHasKey('email', $validator->getErrors());
}
```

**Fixture Files:**
- Temporary directories created in setUp: `sys_get_temp_dir() . '/test_' . uniqid()`
- Cleaned up in tearDown
- Example: RateLimiter tests create `$this->testStorageDir` for file-based rate limit data

**Location:**
- No separate fixtures directory; test data created inline or in setUp()
- Environment variables set via phpunit.xml `<env>` element

## Coverage

**Requirements:**
- Target: >80% code coverage for new code
- Currently tracked: 100% for RateLimiter, PdfTokenService, MessageService, VirusScanService
- Not excluded from coverage: Service layer, Validators, Repositories

**Exclude from Coverage (in phpunit.xml):**
```xml
<exclude>
    <directory>src/Config</directory>        <!-- Config/setup -->
    <file>src/Utils/NullableHelpers.php</file>  <!-- Utility helpers -->
</exclude>
```

**View Coverage:**
```bash
composer test:coverage
# Opens: backend/coverage/index.html (HTML report)

# View in terminal
cat backend/coverage/coverage.txt
```

**Coverage Report Format:**
- HTML report in `backend/coverage/`
- Line coverage, branch coverage, method coverage displayed
- Color-coded (green = covered, red = uncovered)
- Artefacts retained for 30 days in CI/CD pipeline

## Test Types

**Unit Tests:**
- Scope: Individual class/method in isolation
- Approach: Mock all external dependencies, test business logic
- Location: `backend/tests/Unit/`
- Examples: `MessageServiceTest.php` (tests message loading, formatting), `RateLimiterTest.php` (tests rate limiting algorithm)

**Integration Tests:**
- Scope: Multiple components working together (e.g., Service + Repository + Database)
- Approach: Real database connection, test data flow end-to-end
- Location: `backend/tests/Integration/` (currently empty)
- Not yet implemented; planned for future phases

**E2E Tests:**
- Not used; reliance on manual testing for full request/response flows
- Could be added via tools like Selenium or Playwright

## Common Patterns

**Async Testing:**
Not applicable (PHP is synchronous). However, if testing async operations:
```php
// N/A for backend PHP
// Frontend JavaScript async patterns tested manually
```

**Error Testing:**
```php
public function testConstructorThrowsExceptionIfSecretMissing(): void
{
    putenv('PDF_TOKEN_SECRET');  // Unset env var

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('PDF_TOKEN_SECRET not configured');

    new PdfTokenService();
}

public function testValidateThrowsExceptionForInvalidFormular(): void
{
    $this->expectException(\InvalidArgumentException::class);

    AnmeldungValidator::validateFormularName('invalid<>form');
}
```

**Testing with Environment Variables:**
```php
protected function setUp(): void
{
    parent::setUp();
    // Save original
    $this->originalSecret = getenv('PDF_TOKEN_SECRET') ?: '';
}

protected function tearDown(): void
{
    parent::tearDown();
    // Restore original
    if (!empty($this->originalSecret)) {
        putenv('PDF_TOKEN_SECRET=' . $this->originalSecret);
    } else {
        putenv('PDF_TOKEN_SECRET');
    }
}
```

**Testing File Operations:**
```php
protected function setUp(): void
{
    parent::setUp();
    // Create temporary directory
    $this->testStorageDir = sys_get_temp_dir() . '/ratelimit_test_' . uniqid();
    mkdir($this->testStorageDir, 0755, true);
}

protected function tearDown(): void
{
    parent::tearDown();
    // Clean up
    $files = glob($this->testStorageDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
    }
    rmdir($this->testStorageDir);
}
```

**Testing Static Methods:**
```php
public function testGetReturnsMessageFromCache(): void
{
    // Call static method directly
    $message = MessageService::get('validation.required_formular');

    // Reset cache between tests
    MessageService::reset();
}
```

**Testing Array Returns with Type Checking:**
```php
public function testScanFileReturnsCleanArray(): void
{
    $result = $scanner->scanFile('/path/to/file.pdf');

    $this->assertIsArray($result);
    $this->assertArrayHasKey('clean', $result);
    $this->assertArrayHasKey('virus', $result);
    $this->assertArrayHasKey('error', $result);
    $this->assertTrue($result['clean']);
    $this->assertNull($result['virus']);
}
```

## PHPUnit Configuration Details

**phpunit.xml:**
```xml
<phpunit bootstrap="tests/bootstrap.php"
         beStrictAboutOutputDuringTests="true"
         failOnRisky="true"
         failOnWarning="true">

    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>

    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="PDF_TOKEN_SECRET" value="test-secret-key-for-unit-tests-min-32-chars"/>
    </php>
</phpunit>
```

**Key Settings:**
- `failOnRisky="true"`: Fail if tests have no assertions
- `failOnWarning="true"`: Fail on deprecation warnings
- `beStrictAboutOutputDuringTests="true"`: Fail if methods echo output

**Test Bootstrap (tests/bootstrap.php):**
```php
<?php
declare(strict_types=1);

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load test environment variables
App\Config\EnvLoader::load(__DIR__ . '/../.env.test');

// Set timezone
date_default_timezone_set('Europe/Berlin');

// Define testing constants
define('TESTING', true);
define('SKIP_AUTO_EXPUNGE', true);
define('SKIP_AUTH_CHECK', true);
```

## Current Test Coverage Summary

**Total Tests:** 18 test files, 400+ test assertions

**Fully Tested (100%):**
- `RateLimiterTest.php`: 11 tests — request limiting, window expiration, retry calculation
- `PdfTokenServiceTest.php`: 20 tests — token generation, validation, security
- `MessageServiceTest.php`: 30+ tests — message loading, placeholders, local overrides
- `VirusScanServiceTest.php`: 10 tests — virus detection, response parsing
- `AnmeldungValidatorTest.php`: Tests for validation rules
- `DataFormatterTest.php`: Tests for date/currency formatting
- `StatusServiceTest.php`: Tests for status transitions
- `ExpungeServiceTest.php`: Tests for auto-expunge logic

**Partially Tested:**
- `AnmeldungServiceTest.php`: Pagination, filtering, sorting (with mocked repository)

**Not Yet Tested:**
- `AnmeldungRepository` (marked for Integration Tests)
- `PdfGeneratorService` (complex, marked for future)
- `ExportService` (complex, marked for future)
- `DetailController` (HTTP-level, marked for future)

## Running Tests in CI/CD

**GitLab CI Pipeline (.gitlab-ci.yml):**
```bash
# Unit tests with JUnit report
test_unit:
  script:
    - cd backend
    - composer install
    - composer test -- --testdox

# Coverage report (main/master/develop only)
coverage:
  script:
    - composer test:coverage
  artifacts:
    paths:
      - backend/coverage/
    expire_in: 30 days
```

---

*Testing analysis: 2026-03-13*

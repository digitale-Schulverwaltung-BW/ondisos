# Coding Conventions

**Analysis Date:** 2026-03-13

## Naming Patterns

**Files:**
- Classes: PascalCase with `.php` extension (`AnmeldungService.php`, `MessageService.php`, `RateLimiter.php`)
- Views/Templates: kebab-case with `.php` extension (`data-table.php`, `custom-section.php`)
- Test files: TestClass name + `Test.php` suffix (`MessageServiceTest.php`, `RateLimiterTest.php`)

**Functions/Methods:**
- camelCase for all public and private methods (`getPaginatedAnmeldungen()`, `isAllowed()`, `validateRequired()`)
- Getter methods: `get` prefix or direct property access (`getFilePath()`, `getErrors()`, `getFirstError()`)
- Boolean methods: `is` or `has` prefix (`isAllowed()`, `isComplete()`, `hasErrors()`)
- Private helper methods: Use `private` visibility with descriptive camelCase names

**Variables:**
- camelCase for local variables, parameters, and properties (`$anmeldungId`, `$perPage`, `$sortColumn`, `$testStorageDir`)
- CONSTANT names: SCREAMING_SNAKE_CASE for class constants (`ALLOWED_PER_PAGE`, `ALLOWED_SORT_COLUMNS`, `ALLOWED_MIME_TYPES`)
- Prefixes for special cases: `$_` for superglobals (`$_GET`, `$_POST`, `$_ENV`)

**Types/Classes:**
- PascalCase: `Anmeldung`, `MessageService`, `AnmeldungValidator`, `RateLimiter`
- Model suffix for data objects: `CompleteAnmeldung` (extends base model with guarantees)
- Service suffix for business logic: `AnmeldungService`, `ExportService`, `StatusService`
- Repository suffix for data access: `AnmeldungRepository`
- Validator suffix for validation logic: `AnmeldungValidator`
- Controller suffix for request handlers: `AnmeldungController`, `DetailController`
- Exception classes: Use SPL exceptions (`InvalidArgumentException`, `RuntimeException`)

## Code Style

**Formatting:**
- No automated formatter configured (no .prettierrc or eslint config)
- PSR-12 compliance observed throughout
- 4 spaces for indentation (not tabs)
- No line length limit enforced, but lines typically under 120 characters
- Closing braces on new line for classes and functions

**Linting:**
- No configured linter (no .eslintrc or phpstan.json)
- Type safety enforced through PHP 8.2+ strict types
- `declare(strict_types=1);` required in every PHP file

**Strict Types Declaration:**
```php
<?php
declare(strict_types=1);

namespace App\Services;
```
This must appear at the start of every PHP file before any other code.

## Import Organization

**Order:**
1. `declare(strict_types=1);` statement
2. Blank line
3. `namespace App\...;` declaration
4. Blank line
5. `use` statements (PHP built-in classes, then App classes, alphabetically)
6. Blank line
7. Class/interface definition

**Example:**
```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Models\Anmeldung;
use App\Repositories\AnmeldungRepository;
use DateTimeImmutable;
use mysqli;
```

**Path Aliases:**
- Base namespace: `App\` for backend source code
- Test namespace: `Tests\` for test files
- Subdirectories map directly: `App\Services\`, `App\Models\`, `App\Repositories\`, `App\Controllers\`, `App\Validators\`, `App\Utils\`

**Shorthand Imports:**
- `use App\Services\MessageService as M;` for frequently-used classes in validation/formatting context

## Error Handling

**Patterns:**
- Exceptions thrown for validation failures: `InvalidArgumentException` for bad input
- Exceptions thrown for configuration issues: `RuntimeException` for missing config/database connection
- Try-catch blocks used in service layers to provide meaningful error messages
- Never catch exceptions silently; always log or rethrow with context
- Service methods return `['clean' => bool, 'virus' => ?string, 'error' => ?string]` arrays for optional error handling (e.g., `VirusScanService`)

**Example:**
```php
// In validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new \InvalidArgumentException("Invalid email: $email");
}

// In model construction
if (!$this->isComplete()) {
    throw new \InvalidArgumentException(
        "Cannot convert incomplete Anmeldung #{$this->id} to CompleteAnmeldung"
    );
}

// In service methods returning error arrays
public function scanFile(string $filePath): array
{
    return [
        'clean' => $isClean,
        'virus' => $virusName,
        'error' => $errorMessage,
    ];
}
```

**Error Messages:**
- Use centralized `MessageService` for user-facing messages: `M::get('validation.required_email')`
- Fallback pattern: `M::get('key', 'Default fallback text')`
- Include contact info in system errors: `M::withContact('errors.generic_error')`

## Logging

**Framework:** PHP `error_log()` function or no explicit logging configured

**Patterns:**
- Errors logged through exception handling in entry points
- Audit events logged via static `AuditLogger` class to JSON-Lines format
- Log file: `backend/logs/audit.log` (JSON-Lines, one event per line)
- No standard application logging middleware; reliance on PHP error handling

**Audit Logger Usage:**
```php
use App\Services\AuditLogger;

AuditLogger::log('login_success', [
    'user_ip' => $_SERVER['REMOTE_ADDR'],
    'timestamp' => time(),
]);
```

## Comments

**When to Comment:**
- Only for complex logic or non-obvious decisions
- File-level docblock not required (class-level PHPDoc is sufficient)
- Inline comments for business logic: `// Defense-in-depth: validate formular filter at repository level`
- No over-commenting; code should be self-explanatory through clear naming

**PHPDoc/TSDoc:**
- Required for public methods with type hints in docblock comments
- Parameter types and return types documented in docblock
- Special array shapes documented: `@return array{items: Anmeldung[], total: int}`
- Private methods: docblock optional if method name is descriptive

**Example:**
```php
/**
 * Get paginated anmeldungen with validation
 *
 * @return array{
 *   items: \App\Models\Anmeldung[],
 *   pagination: array{
 *     page: int,
 *     perPage: int,
 *     totalPages: int,
 *     totalItems: int
 *   }
 * }
 */
public function getPaginatedAnmeldungen(
    ?string $formularFilter = null,
    // ...
): array
```

## Function Design

**Size:**
- Methods typically 20-40 lines; service methods up to 80 lines
- Single responsibility principle observed
- Helper methods extracted for validation/sanitization

**Parameters:**
- Named arguments preferred: `$this->service->getPaginatedAnmeldungen(formularFilter: $form, statusFilter: $status)`
- Type hints required for all parameters (including `?string`, `?int`, etc.)
- Nullable types used explicitly: `?string`, `?array`
- Default values provided for optional parameters

**Return Values:**
- Type hints required for all return values
- Array returns documented with shape in PHPDoc
- Void return type used explicitly when method has no return value

**Example:**
```php
public function findPaginated(
    ?string $formularFilter = null,
    ?string $statusFilter = null,
    int $limit = 25,
    int $offset = 0,
    string $sortColumn = 'id',
    string $sortDirection = 'DESC'
): array {
    // Implementation
}
```

## Module Design

**Exports:**
- Classes export public methods with clear responsibilities
- Static methods used for utility functions (`MessageService::get()`, `AuditLogger::log()`)
- Constructor injection preferred for dependencies
- Private properties with public accessors only when needed

**Barrel Files:**
- Not used; each class imported directly by full namespace path
- No re-export patterns observed

**Example Architecture:**
```
src/
├── Models/Anmeldung.php       # Data object (readonly class)
├── Repositories/AnmeldungRepository.php  # Data access (depends on Database)
├── Services/AnmeldungService.php         # Business logic (depends on Repository)
└── Controllers/AnmeldungController.php   # Request handler (depends on Service)
```

## Type System

**PHP 8.2+ Type Features Used:**
- Constructor property promotion: `public function __construct(private AnmeldungService $service) {}`
- Readonly classes: `readonly class Anmeldung { ... }`
- Named arguments: method calls with explicit parameter names
- Union types not observed (explicit nullable `?Type` instead)
- Match expressions used for status transitions

**Type Safety Pattern:**
```php
// Use readonly for immutable data objects
readonly class Anmeldung {
    public function __construct(
        public int $id,
        public string $formular,
        public ?string $name,  // Explicitly nullable
        // ...
    ) {}
}

// Use strict types for business logic
declare(strict_types=1);
public function isAllowed(string $identifier): bool
```

## Patterns & Best Practices

**Null-Safe Operations:**
- Explicit null checks with null coalescing: `$value ?? 'default'`
- Nullable type hints: `?string`, `?array` instead of optional parameters
- Conversion methods: `Anmeldung::toComplete()` throws if incomplete (type-safe conversion)

**Security-First:**
- Prepared statements ALWAYS: `$stmt->bind_param($types, ...$params)`
- SQL injection prevention via whitelisting: `ALLOWED_SORT_COLUMNS`, `ALLOWED_MIME_TYPES`
- XSS prevention via `htmlspecialchars()` in templates
- Input validation at repository + service levels (defense-in-depth)

**Object-Oriented:**
- Models as value objects with validated constructors
- Services encapsulate business logic
- Repositories encapsulate data access
- Controllers handle HTTP requests only
- Dependency injection through constructor

---

*Convention analysis: 2026-03-13*

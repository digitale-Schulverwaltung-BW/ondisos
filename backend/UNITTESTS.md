# Unit Test Dokumentation

## Übersicht

Das Backend verfügt über eine PHPUnit 10.5 Test-Suite. Tests laufen via Docker ohne lokale PHP-Installation.

**Stand: Februar 2026**

| Metrik | Wert |
|---|---|
| Gesamt-Tests | 289 |
| Assertions | 737 |
| Line Coverage | **45.53%** (540 / 1186) |

---

## Coverage nach Bereich

| Bereich | Lines abgedeckt | % |
|---|---|---|
| Utils | 63 / 63 | **100%** ✅ |
| Validators | 83 / 86 | **97%** ✅ |
| Models | 57 / 75 | **76%** 🟡 |
| Services | 284 / 621 | **46%** 🟡 |
| Controllers | 53 / 191 | **28%** 🔴 |
| Repositories | 0 / 150 | **0%** 🔴 |

---

## Testdateien

```
tests/
├── bootstrap.php
└── Unit/
    ├── Models/
    │   └── AnmeldungTest.php          # Anmeldung, CompleteAnmeldung
    ├── Services/
    │   ├── ExportServiceTest.php      # ExportService (mit Mock-Repository)
    │   ├── MessageServiceTest.php     # MessageService (dot-notation, placeholders)
    │   ├── PdfTokenServiceTest.php    # PdfTokenService (HMAC, Tokens)
    │   └── RateLimiterTest.php        # RateLimiter (file-based, sliding window)
    ├── Upload/
    │   ├── MimeTypeValidationTest.php
    │   └── UploadSecurityTest.php
    ├── Utils/
    │   └── DataFormatterTest.php      # DataFormatter (format, filter, sort)
    ├── Validators/
    │   ├── AnmeldungValidatorTest.php # Datei-Upload-Validierung, validateFormularName
    │   └── AnmeldungFormValidatorTest.php  # validate() Instance-Methode
    └── Controllers/
        └── DetailControllerTest.php
```

### Abgedeckte Klassen

| Klasse | Datei | Coverage (ca.) |
|---|---|---|
| `DataFormatter` | `Utils/DataFormatterTest.php` | ~100% |
| `AnmeldungValidator` | `Validators/AnmeldungValidatorTest.php` + `AnmeldungFormValidatorTest.php` | ~97% |
| `Anmeldung` | `Models/AnmeldungTest.php` | ~80% |
| `CompleteAnmeldung` | `Models/AnmeldungTest.php` | ~70% |
| `ExportService` | `Services/ExportServiceTest.php` | ~80% |
| `MessageService` | `Services/MessageServiceTest.php` | ~100% |
| `PdfTokenService` | `Services/PdfTokenServiceTest.php` | ~100% |
| `RateLimiter` | `Services/RateLimiterTest.php` | ~100% |

### Nicht (oder kaum) abgedeckt

| Klasse | Grund |
|---|---|
| `AnmeldungRepository` | DB-abhängig → Integration Test nötig |
| `AnmeldungService` | DB-abhängig |
| `StatusService` | Mockbar → **TODO** |
| `ExpungeService` | Mockbar (Config via Reflection) → **TODO** |
| `SpreadsheetBuilder` | PhpSpreadsheet-Abhängigkeit |
| `PdfGeneratorService` | mPDF-Abhängigkeit |
| `PdfTemplateRenderer` | mPDF-Abhängigkeit |
| `AnmeldungController` | HTTP-Context |
| `BulkActionsController` | HTTP-Context |
| `DetailController` | HTTP-Context |

---

## Tests ausführen

### Voraussetzung: Docker

```bash
cd backend

# Einmalig: Test-Image bauen
make build-test

# Alle Tests ausführen
make test

# Tests + Coverage-Report (öffnet Browser)
make coverage-open

# Shell im Container (für Debugging)
make shell
```

### Einzelne Tests

```bash
# Spezifische Klasse
docker compose -f docker-compose.test.yml run --rm test \
  composer test:filter DataFormatterTest

# Spezifische Methode
docker compose -f docker-compose.test.yml run --rm test \
  composer test:filter "DataFormatterTest::testFormatValueConvertsIsoDateToGerman"
```

### Coverage-Report lesen

Nach `make coverage-open` öffnet sich `coverage/html/index.html` im Browser.
Dort sind alle Klassen mit Zeilen-genauer Abdeckung sichtbar.

---

## Neue Tests schreiben

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class MeinServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Mock erstellen:
        $this->mockRepo = $this->createMock(AnmeldungRepository::class);
        $this->service = new MeinService($this->mockRepo);
    }

    public function testMachWasWennBedingung(): void
    {
        $this->mockRepo->method('findById')->willReturn(null);
        $result = $this->service->machWas(99);
        $this->assertFalse($result);
    }
}
```

**Konventionen:**
- Namespace: `Tests\Unit\*`
- `declare(strict_types=1)` in jeder Datei
- Testmethoden: `testMethodeWasTutWasBeiWelcherBedingung`
- Ein Aspekt pro Testmethode
- `setUp()` für Initialisierung, `tearDown()` für Cleanup (Dateien, Singleton-Reset)
- Mocks für alle externen Abhängigkeiten (DB, Config-Singletons via Reflection)

---

## Ziele

| Ziel | Status |
|---|---|
| RateLimiter 100% | ✅ |
| PdfTokenService 100% | ✅ |
| MessageService 100% | ✅ |
| DataFormatter 100% | ✅ |
| Validators >90% | ✅ |
| Models >60% | ✅ |
| StatusService >80% | ✅ (100%) |
| ExpungeService >80% | ✅ (~85%) |
| Gesamt >50% | TODO |
| AnmeldungRepository (Integration) | Langfristig |
| Gesamt >80% | Langfristig |

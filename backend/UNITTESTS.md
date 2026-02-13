# Unit Test Dokumentation

## Übersicht

Das Backend verfügt über eine PHPUnit 10.5 Test-Suite. Tests laufen via Docker ohne lokale PHP-Installation.

**Stand: Februar 2026**

| Metrik | Wert |
|---|---|
| Gesamt-Tests | 366 |
| Assertions | 877 |
| Line Coverage | **55.82%** (662 / 1186) |

---

## Coverage nach Bereich

| Bereich | Lines abgedeckt | % |
|---|---|---|
| Utils | 63 / 63 | **100%** ✅ |
| Validators | 83 / 86 | **97%** ✅ |
| Models | 57 / 75 | **76%** 🟡 |
| Services | ~372 / 621 | **~60%** 🟡 |
| Controllers | 94 / 191 | **49%** 🟡 |
| Repositories | 0 / 150 | **0%** 🔴 |

Klassen mit 100%: `AnmeldungService`, `ExpungeService`, `StatusService`, `MessageService`, `PdfTokenService`, `DataFormatter`, `Anmeldung`
`DetailController`: **99%** (94/95 Lines)

---

## Testdateien

```
tests/
├── bootstrap.php
└── Unit/
    ├── Models/
    │   └── AnmeldungTest.php          # Anmeldung, CompleteAnmeldung
    ├── Services/
    │   ├── AnmeldungServiceTest.php      # AnmeldungService (Pagination, Filter, Validierung)
    │   ├── ExportServiceTest.php         # ExportService (autoMarkAsRead, extractColumns, formatCellValue)
    │   ├── ExpungeServiceTest.php        # ExpungeService (autoExpunge, previewExpunge, manualExpunge)
    │   ├── MessageServiceTest.php        # MessageService (dot-notation, placeholders)
    │   ├── PdfTokenServiceTest.php       # PdfTokenService (HMAC, Tokens)
    │   ├── RateLimiterTest.php           # RateLimiter (file-based, sliding window)
    │   ├── RequestExpungeServiceTest.php # RequestExpungeService (throttling, cache, forceRun)
    │   └── StatusServiceTest.php         # StatusService (markAsExported, archive, delete, updateStatus)
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
| `AnmeldungService` | `Services/AnmeldungServiceTest.php` | **100%** |
| `ExportService` | `Services/ExportServiceTest.php` | **96%** |
| `ExpungeService` | `Services/ExpungeServiceTest.php` | **100%** |
| `RequestExpungeService` | `Services/RequestExpungeServiceTest.php` | **96%** |
| `MessageService` | `Services/MessageServiceTest.php` | ~100% |
| `PdfTokenService` | `Services/PdfTokenServiceTest.php` | ~100% |
| `RateLimiter` | `Services/RateLimiterTest.php` | ~100% |
| `StatusService` | `Services/StatusServiceTest.php` | ~100% |
| `DetailController` | `Controllers/DetailControllerTest.php` | **99%** |

### Nicht (oder kaum) abgedeckt

| Klasse | Grund |
|---|---|
| `AnmeldungRepository` | DB-abhängig → Integration Test nötig |
| `SpreadsheetBuilder` | PhpSpreadsheet-Abhängigkeit |
| `PdfGeneratorService` | mPDF-Abhängigkeit |
| `PdfTemplateRenderer` | mPDF-Abhängigkeit |
| `AnmeldungController` | `$_GET` Kopplung |
| `BulkActionsController` | `$_SERVER`/`$_POST` Kopplung |
| `DownloadController` | `exit` + `readfile()` nicht testbar |

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

Nach `make coverage-open` öffnet sich `coverage/index.html` im Browser.
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
| AnmeldungService 100% | ✅ |
| ExportService >90% | ✅ (96%) |
| RequestExpungeService >90% | ✅ (96%) |
| Gesamt >50% | ✅ (**52.36%**) |
| AnmeldungRepository (Integration) | Langfristig |
| Gesamt >80% | Langfristig |

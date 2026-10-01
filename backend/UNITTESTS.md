# Unit Test Dokumentation

## Übersicht

Das Backend verfügt über eine PHPUnit 10.5 Test-Suite. Tests laufen via Docker ohne lokale PHP-Installation.

| Metrik | Wert |
|---|---|
| Tests | 513 (Unit) |
| Assertions | 1144 |
| Line Coverage | **55,7 %** (1059 / 1902 Zeilen, Unit-Suite; gemessen mit `composer test:coverage -- --testsuite=Unit --coverage-text`) |

Die Tests brauchen die PHP-Extension `mysqli` (Klassen wie `AnmeldungRepository` erben von bzw. nutzen `mysqli`); das
Test-Image (`docker/test/Dockerfile`) bringt sie mit.

---

## Testdateien

```
tests/
├── bootstrap.php
├── Integration/
│   └── Repositories/AnmeldungRepositoryIsolationTest.php   # braucht eine Test-Datenbank
└── Unit/
    ├── Auth/           LoginServiceTest
    ├── Config/         FormConfigDbTest · TenantContextTest · TenantContextAllTenantsTest
    ├── Controllers/    DetailControllerTest
    ├── Models/         AnmeldungTest
    ├── Repositories/   AnmeldungRepositoryAdjacentIdsTest · AnmeldungRepositoryTenantLookupTest
    │                   TenantRepositorySlugTest · TenantRepositoryWriteTest · TenantAdminRepositoryTest
    ├── Services/       AnmeldungServiceTest · ExportServiceTest · ExpungeServiceTest (+TenantScoping)
    │                   RequestExpungeServiceTest · StatusServiceTest · MessageServiceTest
    │                   PdfTokenServiceTest · RateLimiterTest · VirusScanServiceTest · SchoolLookupServiceTest
    │                   AuditLoggerTest (+TenantId)
    │                   HmacValidationTest · SecretPolicyTest · UploadCleanupServiceTest
    │                   BackendApiClientTest · BackendApiClientSigningTest · FormConfigLoaderTest
    ├── Upload/         UploadSecurityTest (FilenameSanitizer) · MimeTypeValidationTest · UploadPathIsolationTest
    ├── Utils/          DataFormatterTest
    └── Validators/     AnmeldungValidatorTest · AnmeldungFormValidatorTest
```

### Schwerpunkte (3.0)

| Bereich | Was abgesichert ist |
|---|---|
| Tenant-Isolierung | `TenantContext`, tenant-gefilterte Repository-Abfragen (`findAdjacentIds`, `findTenantIdById`), Upload-Pfade, Expunge pro Tenant |
| API-Sicherheit | `HmacValidator` (Body-/Upload-Signatur), `SecretPolicy` (Platzhalter, Dev-Default in Production), Round-Trip Client-Signatur ↔ Validator |
| Frontend-Konfiguration | `FormConfigLoader` (laden, mergen, einmalig abfragen), `BackendApiClient::fetchFormConfig` |
| Uploads | `FilenameSanitizer` (Bereinigung, Traversal, Sonderzeichen), Typ-Erkennung per Inhalt |

**Mock-Strategie für `mysqli`:** Anonymous Subclasses von `mysqli`/`mysqli_stmt`/`mysqli_result`, die SQL, Typen-String und gebundene
Werte mitschreiben (siehe `AnmeldungRepositoryAdjacentIdsTest`) — so lässt sich prüfen, dass die `tenant_id` korrekt in der Abfrage steckt,
ohne Datenbank.

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
| Endpoint-Skripte (`public/api/*.php`, `pdf/download.php`) | Scripts mit `exit`/Superglobals; bisher nur live geprüft |
| JavaScript (`survey-handler-*.js`), WordPress-Plugin | kein JS-/WP-Test-Setup |

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
| Tenant-Isolierung, HMAC, SecretPolicy, FormConfigLoader | ✅ |
| RateLimiter, PdfTokenService, MessageService, DataFormatter | ✅ |
| Validators, StatusService, ExpungeService, AnmeldungService, ExportService | ✅ |
| Endpoint-Skripte testbar machen (Logik aus den Scripts in Services ziehen) | offen |
| AnmeldungRepository (Integration, Test-Datenbank) | Langfristig |
| Coverage neu messen und Ziel >80 % | Langfristig |

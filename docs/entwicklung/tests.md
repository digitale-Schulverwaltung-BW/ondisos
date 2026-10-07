# Tests

PHPUnit-Suite, Pipeline und manuelle Tests. Container-Hinweise: [docker-entwicklung.md](docker-entwicklung.md); Pipeline: [CI_CD.md](CI_CD.md).


## Automated Tests (PHPUnit)

Das Projekt verfügt über eine umfassende PHPUnit Test-Suite mit Unit- und Integration-Tests.

### Test-Struktur

```
backend/tests/
├── bootstrap.php              # Test-Setup (Autoloader, Env-Variablen)
├── Unit/                      # Unit Tests (ohne DB; mysqli wird per Anonymous-Subclass gemockt)
│   ├── Auth/                  # LoginService
│   ├── Config/                # FormConfig (DB), TenantContext
│   ├── Controllers/           # DetailController
│   ├── Models/                # Anmeldung
│   ├── Repositories/          # Anmeldung (Adjacent/Tenant-Lookup), Tenant*, TenantAdmin
│   ├── Services/              # u. a. HmacValidation, SecretPolicy, BackendApiClient(+Signing),
│   │                          # FormConfigLoader, PdfToken, RateLimiter, VirusScan, AuditLogger, …
│   ├── Forms/                 # 3.1: SurveyValidator, HtmlPolicy, FormConfigValidator, SurveyLinter, Schema-Drift
│   ├── Upload/                # MIME, Sicherheit, Pfad-Isolierung
│   ├── Utils/                 # DataFormatter
│   └── Validators/
└── Integration/               # Tests mit DB (Repositories/AnmeldungRepositoryIsolationTest, Forms/ = Formular-Editor 3.1)
```

Stand 2026-10-07: 958 Unit-Tests (Zählung von PHPUnit; bei 3.1.0 waren es 924) (`composer test -- --testsuite=Unit`; die Coverage misst der Pipeline-Job `coverage`) und 182 Integration-Tests bei 3.1.0, seitdem nicht neu gezählt (`--testsuite=Integration`, brauchen MySQL mit `database/schema.sql`). Der Test-Container braucht die PHP-Extension `mysqli`.

### Tests lokal ausführen

**1. Dependencies installieren:**
```bash
cd backend
composer install
```

**2. Alle Tests ausführen:**
```bash
composer test
# oder direkt:
./vendor/bin/phpunit
```

**3. Nur Unit Tests:**
```bash
composer test -- --testsuite=Unit
```

**4. Nur Integration Tests:**
```bash
composer test -- --testsuite=Integration
```

**5. Spezifische Test-Klasse:**
```bash
composer test:filter RateLimiterTest
# oder:
./vendor/bin/phpunit --filter RateLimiterTest
```

**6. Mit Code Coverage:**
```bash
composer test:coverage
# Generiert: backend/coverage/index.html
```

**7. Mit ausführlicher Ausgabe (testdox):**
```bash
composer test -- --testdox
```

### Test-Konfiguration

**phpunit.xml:**
- Bootstrap: `tests/bootstrap.php`
- Test-Suites: Unit, Integration
- Test-Environment-Variablen
- Coverage-Excludes: Config, NullableHelpers

**tests/bootstrap.php:**
- Lädt Composer Autoloader
- Setzt Test-Environment-Variablen
- Definiert Test-Konstanten: `TESTING`, `SKIP_AUTO_EXPUNGE`, `SKIP_AUTH_CHECK`

### Schwerpunkte der Test-Suite

| Bereich | Was abgesichert ist |
|---|---|
| **Tenant-Isolierung** | `TenantContext`, tenant-gefilterte Repository-Abfragen (`findAdjacentIds`, `findTenantIdById`), Upload-Pfade, Expunge pro Tenant |
| **API-Sicherheit** | `HmacValidator` (Body/Upload-Signatur), `SecretPolicy` (Platzhalter, Dev-Default in Production), Round-Trip Client-Signatur ↔ Validator (`BackendApiClientSigningTest`) |
| **Frontend-Config** | `FormConfigLoader` (laden, mergen, einmalig abfragen), `BackendApiClient::fetchFormConfig` |
| **PDF** | `PdfTokenService` (Token-Format, Ablauf, Manipulation) |
| **Sonstiges** | `RateLimiter`, `MessageService`, `VirusScanService`, `AuditLogger`, Export, Status, Upload-Validierung |

**Nicht durch Unit-Tests abgedeckt:** die Endpoint-Skripte selbst (`public/api/*.php`, `pdf/download.php`) und der Browser-Teil (SurveyJS/JavaScript). Dafür gibt es die Manual Tests unten.

### Neue Tests schreiben

**1. Test-Klasse erstellen:**
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class MyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup vor jedem Test
    }

    public function testSomething(): void
    {
        $this->assertTrue(true);
    }
}
```

**2. Best Practices:**
- Namespace: `Tests\Unit\*` oder `Tests\Integration\*`
- Strict types: `declare(strict_types=1)`
- setUp/tearDown für Initialisierung/Cleanup
- Descriptive test names: `testMethodDoesWhatWhenCondition`
- Use type hints für alle Parameter
- Test eine Sache pro Test-Methode

**3. Test ausführen:**
```bash
composer test:filter MyServiceTest
```

## Pipeline

Jobs, Stufen, Release und lokales Nachstellen: [CI_CD.md](CI_CD.md).

## Manual Tests

**Frontend Submission:**
```bash
# 1. Formular öffnen (Standalone) bzw. WordPress-Seite mit [ondisos form="bs"]
http://anmeldung.example.com/index.php?form=bs

# 2. Ausfüllen und absenden
# 3. Check Backend: sollte als "neu" erscheinen
```

**Backend Admin:**
```bash
# 1. Übersicht
http://intranet.example.com/backend/

# 2. Einträge in der Liste auswählen und Excel-Export testen (Status sollte → "exportiert")
# 3. Detail ansehen
# 4. Bulk-Action: Archivieren und Status setzen (📝 ☑️ 👎) für mehrere ausgewählte Einträge
# 5. Papierkorb prüfen
```

**Auto-Expunge:**
```bash
# Dashboard öffnen
http://intranet.example.com/backend/dashboard.php

# Check "Auto-Expunge Status"
# Sollte zeigen: Letzter Lauf, Nächster Lauf, Anzahl bereit
```

## Testlücken und Ziele

Welche Bereiche noch keine Tests haben und was als Nächstes ansteht, führt [TODO.md](TODO.md#testlücken).

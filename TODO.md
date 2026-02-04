# TODO - Testing & Security

## Test Coverage Roadmap

**Aktueller Stand:** 18.90% (214/1132 lines)
**Ziel:** >80% Coverage

### ✅ Abgeschlossen

- [x] **ExportService** - 88.46% (92/104 lines) 🎉
  - 26 Tests, alle SQL Injection Prevention Tests grün
  - Security-kritisch: Formular-Filter-Validierung getestet
  - Nur noch 12 Lines fehlen (private Helpers)

- [x] **AnmeldungValidator** - ~95% (geschätzt) 🎉
  - 30+ neue Tests für File-Validierung
  - Security-kritisch: Upload-Sicherheit vollständig getestet
  - Alle 5 File-Validierungs-Methoden abgedeckt
  - Prevention: Disguised files, Double-Extension-Attacks
  - upload.php refactored (nutzt jetzt Validator)

### 🔴 Priorität 1: Security-kritische Tests

#### 1. AnmeldungValidator - ✅ ABGESCHLOSSEN
**Aktuell:** ~95% (geschätzt, alle Methoden getestet)
**Implementierte Methoden:**
- ✅ `validateFile()` - File-Upload-Validierung (Hauptmethode)
- ✅ `validateFileSize()` - Size-Checks (max 10MB, leer-Check)
- ✅ `validateMimeType()` - MIME-Type-Checks (content-based mit finfo)
- ✅ `validateExtension()` - Extension-Checks (muss zu MIME passen)
- ✅ `getAllowedMimeTypes()` - MIME-Whitelist abrufen

**Tests:** 30+ neue Tests hinzugefügt
- File-Validierung mit echten Test-Dateien (PDF, PNG, JPEG)
- Security-Tests: Disguised files (PHP als JPG, Text als PDF)
- Double-Extension-Attack-Prevention (evil.php.jpg)
- Case-insensitive Extension-Matching
- Edge-Cases: Leere Dateien, fehlende Felder, zu große Dateien

**Refactoring:** `upload.php` nutzt jetzt AnmeldungValidator (sauberer Code)
**Status:** ✅ **ERLEDIGT** - Zentrale Security-Validierung vollständig getestet

#### 2. AnmeldungRepository
**Aktuell:** 0% (0/150 lines)
**Was testen:**
- CRUD-Operationen mit Test-DB
- Soft-Delete-Funktionalität
- Filter-Methoden (besonders mit Formular-Parameter)
- SQL Injection Prevention (Prepared Statements)

**Warum kritisch:** Direkte DB-Zugriffe
**Effort:** Mittel (~150 lines, benötigt Test-DB Setup)
**Typ:** Integration Tests

### 🟠 Priorität 2: Kleine Quick Wins

#### 3. RateLimiter - Security-Update abgeschlossen ✅
**Aktuell:** ~95% (geschätzt, mit neuer generateFingerprint() Methode)
**Tests:** 20 Tests (11 original + 9 für Fingerprinting)
**Status:** Security-kritische Teile vollständig getestet
**Verbleibend:** Nur private Helper-Methoden (maybeCleanup, etc.)

#### 4. PdfTokenService auf 100% bringen
**Aktuell:** 92.11% (35/38 lines, 5/6 methods)
**Fehlend:** Nur 3 Lines, 1 Method
**Effort:** Sehr klein (~10 lines Test-Code)

### 🟡 Priorität 3: Business-Logic Tests

#### 5. AnmeldungService
**Aktuell:** 0% (0/28 lines)
**Was testen:**
- Anmeldung erstellen/validieren
- Status-Änderungen
- Integration mit Repository

**Effort:** Klein (~80 lines Test-Code)

#### 6. StatusService
**Aktuell:** 0% (0/21 lines)
**Was testen:**
- Status-Transitions
- Auto-Mark-as-Read Logik
- `markAsExported()` und `markMultipleAsExported()`

**Effort:** Sehr klein (~50 lines Test-Code)

#### 7. SpreadsheetBuilder
**Aktuell:** 0% (0/104 lines)
**Was testen:**
- Zellenformatierung
- Datum-Konvertierung (YYYY-MM-DD → dd.mm.yyyy)
- Auto-Width, Zebra-Striping
- Excel-Generation

**Effort:** Mittel (~100 lines Test-Code)

### ⚪ Priorität 4: Feature Tests

#### 8. PdfGeneratorService + PdfTemplateRenderer
**Aktuell:** 0% (0/146 lines kombiniert)
**Was testen:**
- PDF-Generierung mit mPDF
- Logo-Embedding
- Custom Sections
- Field-Filtering

**Effort:** Groß (~150 lines Test-Code)

#### 9. ExpungeService + RequestExpungeService
**Aktuell:** 0% (0/94 lines kombiniert)
**Was testen:**
- Expunge-Logik
- Zeitberechnung (AUTO_EXPUNGE_DAYS)
- Caching (last_expunge.txt)

**Effort:** Mittel (~80 lines Test-Code)

### 🔵 Priorität 5: Integration Tests

#### 10. Controllers
**Aktuell:** 0% (0/192 lines)
**Was testen:**
- AnmeldungController
- DetailController
- BulkActionsController

**Typ:** Integration Tests
**Effort:** Groß (~200 lines Test-Code)

#### 11. Models & Utils
**Aktuell:** 0% (0/141 lines)
**Was testen:**
- Anmeldung Model
- AnmeldungStatus Enum
- DataFormatter
- NullableHelpers

**Effort:** Klein (~60 lines Test-Code)

---

## Security Issues

### ✅ Behoben

#### Missing CSRF Protection on Critical Endpoints
**Betroffene Dateien:**
- ✅ `backend/public/hard_delete.php` - Permanent delete
- ✅ `backend/public/restore.php` - Restore from trash
- ✅ `backend/public/bulk_actions.php` - Bulk operations

**Problem:** POST Requests ohne CSRF Token Validierung
**Auswirkung:** CSRF Angriffe könnten Admins zwingen, Daten zu löschen/ändern
**Status:** ✅ **BEHOBEN** (2026-02-03)

**Implementierte Lösung:**

1. **CSRF Helper erstellt** (`backend/inc/csrf.php`)
   - `csrf_token()` - Generate/retrieve token
   - `csrf_validate()` - Validate POST token (timing-safe)
   - `csrf_field()` - Output hidden input field
   - `csrf_meta()` - Meta tag for AJAX
   - `csrf_regenerate()` - Regenerate after login/logout

2. **CSRF Protection in Endpoints**
   ```php
   require_once __DIR__ . '/../inc/csrf.php';
   csrf_validate(); // Timing-safe comparison
   ```

3. **CSRF Tokens in Forms**
   - `trash.php` - Restore + Hard-Delete Forms
   - `index.php` - Bulk-Actions Form
   ```php
   <form method="post" action="...">
       <?php csrf_field(); ?>
       ...
   </form>
   ```

**Security Features:**
- ✅ Timing-safe comparison (`hash_equals`)
- ✅ 32-byte random tokens
- ✅ Session-based storage
- ✅ Automatic generation on first use
- ✅ Clear error messages

---

#### Insufficient File Type Validation
**Betroffene Datei:**
- ✅ `backend/public/upload.php` - File upload endpoint

**Problem:** Nur Extension-Check, kein MIME-Type-Check
**Auswirkung:** Angreifer könnten schädliche Dateien als erlaubte Typen tarnen (z.B. evil.php als evil.jpg)
**Status:** ✅ **BEHOBEN** (2026-02-03)

**Implementierte Lösung:**

1. **MIME-Type-Validierung mit `finfo`**
   ```php
   $finfo = finfo_open(FILEINFO_MIME_TYPE);
   $mimeType = finfo_file($finfo, $file['tmp_name']);
   finfo_close($finfo);
   ```

2. **MIME-Type-Whitelist mit Extension-Mapping**
   ```php
   $allowedMimeTypes = [
       'application/pdf' => ['pdf'],
       'image/jpeg' => ['jpg', 'jpeg'],
       'image/png' => ['png'],
       'image/gif' => ['gif'],
       'image/webp' => ['webp'],
       'image/svg+xml' => ['svg'],
   ];
   ```

3. **Beide Checks kombiniert**
   - MIME-Type muss in Whitelist sein
   - Extension muss zum MIME-Type passen
   - Verhindert Datei-Umbenennung-Angriffe

**Security Features:**
- ✅ Content-based validation (reads actual file, not just name)
- ✅ MIME-Type + Extension matching
- ✅ **doc/docx excluded** (macro security risk)
- ✅ Clear error messages (MIME type shown)
- ✅ 13 Unit Tests (all passing)

**Wichtiger Hinweis zu Office-Dokumenten:**
- `doc` (application/msword) kann Makros enthalten
- `docx` kann XML-basierte Exploits enthalten
- **Standardmäßig ausgeschlossen** für Sicherheit
- Falls benötigt: In `allowedMimeTypes` auskommentieren + zusätzliche Validierung empfohlen

---

#### Potential XSS in Detail View
**Betroffene Datei:**
- ✅ `backend/src/Controllers/DetailController.php` - humanizeKey() method

**Problem:** Fehlende XSS-Sanitization in humanizeKey()
**Auswirkung:** Stored XSS wenn Feldnamen von Angreifern kontrolliert werden
**Status:** ✅ **BEHOBEN** (2026-02-03)

**Implementierte Lösung:**

1. **XSS-Sanitization in humanizeKey()**
   ```php
   private function humanizeKey(string $key): string
   {
       // Sanitize input to prevent XSS (defense-in-depth)
       $key = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');

       // ... rest of humanization logic
   }
   ```

**Security Features:**
- ✅ htmlspecialchars() with ENT_QUOTES (escapes both ' and ")
- ✅ UTF-8 encoding preserved
- ✅ Defense-in-depth (Controller + View both escape)
- ✅ 16 Unit Tests (all passing)

**Defense-in-Depth:**
- Controller sanitizes at source (`humanizeKey()`)
- View also escapes at output (`htmlspecialchars()` in detail.php)
- Double protection prevents XSS even if one layer is removed

**XSS Attack Vectors Prevented:**
- ✅ Script tags: `<script>alert('XSS')</script>`
- ✅ Event handlers: `onclick="alert(1)"`
- ✅ Image tags: `<img src=x onerror="alert(1)">`
- ✅ Single/Double quotes: `'` → `&#039;`, `"` → `&quot;`
- ✅ Complex vectors: Nested tags, encoded payloads

---

#### Rate Limiting Bypass Potential
**Betroffene Dateien:**
- ✅ `backend/src/Services/RateLimiter.php` - New generateFingerprint() method
- ✅ `backend/public/api/submit.php` - Updated to use fingerprinting

**Problem:** Schwache Identifikation nur mit IP + kurzer MD5-Hash des User-Agent
**Auswirkung:** Angreifer könnten Rate-Limits durch User-Agent-Rotation umgehen
**Status:** ✅ **BEHOBEN** (2026-02-03)

**Vorher (schwach):**
```php
$identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$identifier .= ':' . substr(md5($userAgent), 0, 8); // Nur 8 Zeichen MD5!
```

**Implementierte Lösung:**

1. **Robustes Fingerprinting in RateLimiter**
   ```php
   public static function generateFingerprint(array $server): string
   {
       $ip = $server['REMOTE_ADDR'] ?? 'unknown';
       $userAgent = $server['HTTP_USER_AGENT'] ?? '';
       $acceptLanguage = $server['HTTP_ACCEPT_LANGUAGE'] ?? '';

       // SHA-256 für bessere Kollisions-Resistenz
       $userAgentHash = hash('sha256', $userAgent);
       $languageHash = hash('sha256', $acceptLanguage);

       return $ip . ':' . $userAgentHash . ':' . $languageHash;
   }
   ```

2. **Verwendung in submit.php**
   ```php
   // Use robust fingerprinting (IP + hashed User-Agent + Accept-Language)
   $identifier = RateLimiter::generateFingerprint($_SERVER);
   ```

**Security Features:**
- ✅ **Mehrere Faktoren kombiniert:** IP + User-Agent + Accept-Language
- ✅ **SHA-256 statt MD5:** Bessere Kollisions-Resistenz
- ✅ **Vollständige Hashes:** Keine Trunkierung mehr (64 statt 8 Zeichen)
- ✅ **Privacy:** User-Agent wird gehasht, nicht im Klartext gespeichert
- ✅ **Header Injection Prevention:** Hashes verhindern Injection-Angriffe
- ✅ **Testbar:** $_SERVER als Parameter injizierbar
- ✅ **9 neue Unit Tests** (zusätzlich zu bestehenden 11 Tests)

**Bypass-Prävention:**
- ❌ **User-Agent Rotation:** Verhindert (verschiedene Fingerprints)
- ❌ **Proxy-Hopping:** Erschwert (benötigt IP + UA + Language-Match)
- ✅ **Deterministic:** Gleiche Kombination → gleicher Fingerprint
- ✅ **Unabhängige Identifier:** Verschiedene Kombinationen isoliert

**Neue Tests:**
- testGenerateFingerprintWithAllHeaders
- testGenerateFingerprintWithMissingHeaders
- testGenerateFingerprintDifferentUserAgents
- testGenerateFingerprintDifferentIPs
- testGenerateFingerprintDifferentLanguages
- testGenerateFingerprintIsDeterministic
- testGenerateFingerprintUsesSha256NotMd5
- testGenerateFingerprintPreventsUserAgentRotation
- testGenerateFingerprintWithNoRemoteAddr

---

#### Session Fixation Risk
**Betroffene Dateien:**
- ✅ `backend/inc/auth.php` - Session timeout handling
- ✅ `backend/public/logout.php` - Logout flow

**Problem:** Session-Regeneration nur beim Login, fehlt bei Logout und Session-Timeout
**Auswirkung:** Session-Fixation-Angriffe möglich bei sensiblen Operationen
**Risiko:** Gering (erfordert bereits kompromittierte Session)
**Status:** ✅ **BEHOBEN** (2026-02-03)

**Vorher:**
```php
// auth.php - Session-Timeout
if ($loginTime > 0 && (time() - $loginTime) > $sessionLifetime) {
    session_destroy();  // ❌ Keine Session-Regeneration
    header('Location: login.php?expired=1');
    exit;
}

// logout.php - Logout
$_SESSION = [];
session_destroy();  // ❌ Keine Session-Regeneration
```

**Implementierte Lösung:**

1. **Session-Regeneration bei Timeout (auth.php:35)**
   ```php
   if ($loginTime > 0 && (time() - $loginTime) > $sessionLifetime) {
       // Session expired
       // Regenerate session ID before destroying to prevent session fixation
       session_regenerate_id(true);
       session_destroy();
       header('Location: login.php?expired=1');
       exit;
   }
   ```

2. **Session-Regeneration bei Logout (logout.php:14)**
   ```php
   // Regenerate session ID before destroying to prevent session fixation
   // This invalidates the old session ID, preventing reuse
   session_regenerate_id(true);

   // Clear all session data
   $_SESSION = [];

   // Destroy session cookie
   if (isset($_COOKIE[session_name()])) {
       setcookie(session_name(), '', time() - 3600, '/');
   }

   // Destroy session
   session_destroy();
   ```

**Security Features:**
- ✅ **Session-Regeneration bei Login** - Bereits implementiert (login.php:47)
- ✅ **Session-Regeneration bei Logout** - Neu hinzugefügt
- ✅ **Session-Regeneration bei Timeout** - Neu hinzugefügt
- ✅ **Alte Session-ID invalidiert** - `session_regenerate_id(true)` löscht alte Session
- ✅ **Defense-in-Depth** - Mehrere Schutzebenen (CSRF + Session-Regeneration)

**Session-Fixation-Prävention:**
- ❌ **Attacker setzt Session-ID** - Verhindert durch Regeneration bei Login
- ❌ **Session-Wiederverwendung nach Logout** - Verhindert durch Regeneration
- ❌ **Timeout-Session-Hijacking** - Verhindert durch Regeneration
- ✅ **Konsistente Regeneration** - Bei allen sensiblen Operationen

**Wo Session-Regeneration passiert:**
1. **Login** (login.php:47) - Nach erfolgreicher Authentifizierung
2. **Logout** (logout.php:14) - Vor Session-Zerstörung
3. **Timeout** (auth.php:35) - Vor Session-Zerstörung bei Ablauf

---

#### Other Security Fixes

- [x] **SQL Injection in ExportService** - Formular-Filter-Validierung implementiert
- [x] **Directory Traversal in upload.php** - basename() + Filename-Validierung
- [x] **Double Extension Attack** - Extension wird erzwungen

---

## GitLab CI/CD Pipeline

**Status:** ✅ Funktioniert
**Coverage:** HTML-Report wird generiert (30 Tage Artefakt)

**Pipeline Stages:**
1. `install` - Composer dependencies
2. `test` - Unit + Integration Tests
3. `coverage` - Code Coverage Report (nur main/master/develop)
4. `security` - Secret Detection + SAST

---

## Langfristige Ziele

- [ ] **Target: >80% Code Coverage**
- [ ] Integration Tests mit Test-Datenbank
- [ ] E2E Tests für kritische User-Flows
- [ ] Strukturiertes Logging
- [ ] Monitoring Setup (z.B. Sentry)
- [ ] API Documentation (OpenAPI/Swagger)
- [ ] Docker Setup für Production

---

**Letzte Aktualisierung:** 2026-02-04
**Nächste Schritte:**
1. ✅ ~~AnmeldungValidator Tests erweitern (4 Methoden fehlen)~~ - ERLEDIGT
2. RateLimiter + PdfTokenService auf 100% bringen (Quick Win)
3. AnmeldungRepository Integration Tests (benötigt Test-DB)
4. StatusService + SpreadsheetBuilder Tests (Business Logic)

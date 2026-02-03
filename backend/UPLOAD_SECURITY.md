# Upload Security Documentation

## Overview

This document describes the security measures implemented in the file upload system to prevent common attack vectors.

## Security Measures

### 1. Directory Traversal Prevention

**Attack:** Uploading files with path components like `../../etc/passwd`

**Mitigation:**
- Use `basename()` to strip all path components
- Validate filename contains only safe characters (no slashes, dots)
- Force target directory (uploads cannot escape)

**Example:**
```php
// UNSAFE: Direct use of user input
$filename = $_FILES['file']['name']; // Could be "../../etc/passwd"

// SAFE: Strip path components
$filename = basename($_FILES['file']['name']); // "passwd"
```

### 2. Double Extension Attack Prevention

**Attack:** Uploading files with double extensions like `evil.php.jpg`

**Problem:**
- Extension check might only look at `.jpg` (allowed)
- Misconfigured servers might execute `.php` part
- User could execute PHP code

**Mitigation:**
```php
// Extract filename without extension
$nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME); // "evil.php"

// Validate: No dots allowed (prevents double extension)
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $nameWithoutExt)) {
    throw new RuntimeException('Invalid filename');
}

// Force validated extension
$safeFilename = $anmeldungId . '_' . $nameWithoutExt . '.' . $extension;
// Result: 123_evil.jpg (NOT evil.php.jpg)
```

**Attack Flow (Prevented):**
```
User uploads: evil.php.jpg
↓
Extension check: pathinfo() → "jpg" ✓ allowed
↓
Filename validation: "evil.php" contains dot → ✗ REJECTED
```

### 3. Extension Forcing

**Attack:** Upload `script.php` but claim it's a `.jpg`

**Mitigation:**
- Validate extension independently
- Force validated extension in filename
- Don't trust user-supplied extension

```php
// Get and validate extension
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, $allowedTypes, true)) {
    throw new RuntimeException('File type not allowed');
}

// Force extension (ignore what user claimed)
$safeFilename = $anmeldungId . '_' . $nameWithoutExt . '.' . $extension;
```

### 4. Special Character Filtering

**Attack:** Filenames with special characters: `test;rm -rf.jpg`, `test|cat /etc/passwd.jpg`

**Mitigation:**
- Whitelist approach: Only allow `[a-zA-Z0-9_-]`
- No dots (except for extension separator)
- No spaces, semicolons, pipes, etc.

**Blocked Characters:**
```
/ \ | ; : ? * " < > , . $ % & @ ! # ( ) [ ] { } ' ` ~ = +
```

### 5. Null Byte Injection Prevention

**Attack:** `test.php\0.jpg` (null byte terminates string in some contexts)

**Mitigation:**
- Regex validation excludes null bytes
- PHP `basename()` handles null bytes safely
- Strict character whitelist

### 6. Unicode/Multibyte Prevention

**Attack:** Unicode characters that might bypass filters or confuse filesystems

**Mitigation:**
- Only allow ASCII alphanumeric characters
- Reject any character outside `[a-zA-Z0-9_-]`

### 7. Empty Filename Prevention

**Attack:** Upload file with name `.jpg` or empty string

**Mitigation:**
```php
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $nameWithoutExt)) {
    throw new RuntimeException('Invalid filename');
}
```

An empty `$nameWithoutExt` fails regex validation.

### 8. Hidden File Prevention

**Attack:** Upload `.htaccess`, `.env`, etc.

**Mitigation:**
- Files starting with dot are rejected
- `pathinfo('.htaccess', PATHINFO_FILENAME)` returns empty string
- Empty filename fails validation

## Complete Upload Flow

```
1. User uploads file (evil.php.jpg)
   ↓
2. Validate HTTP method (POST only)
   ↓
3. Validate anmeldung_id (integer > 0)
   ↓
4. Check upload success (UPLOAD_ERR_OK)
   ↓
5. Validate file size (max 10MB)
   ↓
6. Validate extension (whitelist: pdf, jpg, png, etc.)
   ↓
7. Strip path components with basename()
   → "evil.php.jpg"
   ↓
8. Extract filename without extension
   → "evil.php"
   ↓
9. Validate filename (only [a-zA-Z0-9_-]+)
   → REJECTED (contains dot)
   ↓
10. Error response: "Invalid filename"
```

## Testing

Run the comprehensive test suite:

```bash
cd backend
composer test:filter UploadSecurityTest
```

**Test Coverage:**
- ✅ Valid filenames accepted
- ✅ Directory traversal blocked (`../../etc/passwd`)
- ✅ Double extensions blocked (`evil.php.jpg`)
- ✅ Extension forcing (`test.jpg` → `123_test.png` if validated as PNG)
- ✅ Special characters blocked (`;`, `|`, `&`, etc.)
- ✅ Null bytes blocked
- ✅ Unicode blocked
- ✅ Empty filenames blocked
- ✅ Hidden files blocked (`.htaccess`)
- ✅ Path components stripped by `basename()`

## Configuration

**Environment Variables:**

```bash
# .env
UPLOAD_MAX_SIZE=10485760  # 10MB in bytes
UPLOAD_ALLOWED_TYPES=pdf,jpg,jpeg,png,gif,doc,docx
```

## Examples

### ✅ Allowed Filenames

```
test.jpg         → 123_test.jpg
test-file.pdf    → 123_test-file.pdf
test_file.png    → 123_test_file.png
Test123.doc      → 123_Test123.doc
a.jpg            → 123_a.jpg
```

### ❌ Blocked Filenames

```
../../etc/passwd     → REJECTED (dots in filename)
evil.php.jpg         → REJECTED (dot in filename "evil.php")
test;rm -rf.jpg      → REJECTED (semicolon)
test|cat.jpg         → REJECTED (pipe)
test.backup.jpg      → REJECTED (dot in filename)
.htaccess            → REJECTED (empty filename)
testö.jpg            → REJECTED (unicode)
test file.jpg        → REJECTED (space)
test.php\0.jpg       → REJECTED (null byte)
```

## Best Practices

1. **Always use `basename()`** - Strip path components first
2. **Whitelist approach** - Only allow safe characters
3. **Force extension** - Don't trust user input
4. **Validate separately** - Check extension independently from filename
5. **No dots in filename** - Prevents double extension attacks
6. **Store outside webroot** - Uploads directory should not be executable (use `.htaccess`)
7. **Set permissions** - `chmod 0644` for uploaded files
8. **Log uploads** - Track who uploaded what, when

## Additional Security Layers

### Server-Level Protection

**Apache `.htaccess` in uploads directory:**

```apache
# Disable script execution in uploads directory
<FilesMatch "\.(php|phtml|php3|php4|php5|pl|py|jsp|asp|sh|cgi)$">
    Order Deny,Allow
    Deny from all
</FilesMatch>

# Force download for common file types
<FilesMatch "\.(pdf|jpg|jpeg|png|gif|doc|docx)$">
    Header set Content-Disposition attachment
</FilesMatch>
```

### Database-Level Protection

- Store only filename (not full path) in database
- Associate uploads with anmeldung_id (ownership)
- Validate ownership before serving downloads

## References

- OWASP: File Upload Cheat Sheet
- CWE-22: Path Traversal
- CWE-434: Unrestricted Upload of File with Dangerous Type

## Version

**Version:** 2.5
**Last Updated:** February 2026
**Author:** Security Audit Team

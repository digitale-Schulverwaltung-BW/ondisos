# Upload-Verwaltung

## Verzeichnisstruktur

```
uploads/
├── .gitkeep          # Damit Ordner im Git bleibt
├── 123_dokument.pdf  # Format: {anmeldung_id}_{filename}
└── 456_foto.jpg
```

## Dateinamen-Konvention

**Format:** `{anmeldung_id}_{original_filename}`

Beispiele:
- `42_lebenslauf.pdf`
- `42_zeugnis_2024.pdf`
- `127_bewerbungsfoto.jpg`

## Erlaubte Dateitypen

- **Dokumente:** PDF, DOC, DOCX, XLS, XLSX, TXT
- **Bilder:** JPG, JPEG, PNG, GIF

Siehe `DownloadController::ALLOWED_EXTENSIONS` für die aktuelle Liste.

## Sicherheit

### Directory Traversal Protection
```php
// ❌ NICHT: $_GET['file'] direkt verwenden
// ✅ JA: Validierung durch DownloadController
```

### Validierungen
1. **Filename:** Keine `..`, `/`, `\` erlaubt
2. **Extension:** Nur whitelist-basiert
3. **Path:** Muss innerhalb von `uploads/` sein
4. **Auth:** Download nur für authentifizierte User

## File Upload API (TODO)

Wenn das Frontend Files hochlädt:

```http
POST /api/upload.php
Content-Type: multipart/form-data

anmeldung_id: 123
file: {binary}
```

### Response
```json
{
  "success": true,
  "filename": "123_dokument.pdf",
  "size": 12345,
  "url": "/download.php?file=123_dokument.pdf"
}
```

## Permissions

```bash
# Upload-Verzeichnis muss schreibbar sein
chmod 755 uploads/
chown www-data:www-data uploads/

# Files sollten 644 sein
chmod 644 uploads/*
```

## Aufräumen alter Files

```bash
# Finde verwaiste Files (Anmeldung gelöscht)
# TODO: Cleanup-Script erstellen
```

## Maximale Upload-Größe

In `.env`:
```
UPLOAD_MAX_SIZE=10485760  # 10MB in Bytes
```

In `php.ini`:
```ini
upload_max_filesize = 10M
post_max_size = 10M
```
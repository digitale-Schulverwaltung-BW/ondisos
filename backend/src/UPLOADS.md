# Upload-Verwaltung

## Verzeichnisstruktur

Uploads liegen **pro Tenant** in einem eigenen Unterverzeichnis (ab 3.0):

```
uploads/
├── .gitkeep
├── tenant-1/
│   ├── 123_dokument.pdf     # Format: {anmeldung_id}_{bereinigter_name}.{erlaubte_endung}
│   └── 456_foto.jpg
└── tenant-2/
    └── 17_zeugnis.pdf
```

Bestehende Dateien aus 2.x (flach in `uploads/`) verschiebt `migrate.php` (Step 4d) nach `uploads/tenant-1/`.
Ein Tenant kann nie auf das Verzeichnis eines anderen zugreifen: `DownloadController::getAllowedUploadDir()` liefert
ausschließlich `uploads/tenant-<id>` des aktiven Tenants.

## Dateinamen-Konvention

**Format:** `{anmeldung_id}_{bereinigter_name}.{erlaubte_endung}`

- Der Name wird bereinigt (`FilenameSanitizer::sanitizeStem()`), Pfadbestandteile und Sonderzeichen entfallen,
  Umlaute werden transliteriert (`Zeugnis Übersicht 2026.pdf` → `5_Zeugnis_Uebersicht_2026.pdf`).
- Die Endung wird aus dem per Inhalt erkannten Dateityp abgeleitet (nicht aus dem Namen des Uploads).

## Erlaubte Dateitypen

- **Upload** (`AnmeldungValidator::ALLOWED_MIME_TYPES`, Typ wird am Inhalt erkannt): PDF, JPG/JPEG, PNG, GIF, WebP, SVG.
  Office-Formate (DOC/DOCX/…) sind bewusst ausgeschlossen (Makro-Risiko).
- **Download** über das Backend (`DownloadController::ALLOWED_EXTENSIONS`): PDF, JPG, JPEG, PNG, GIF, DOC, DOCX, XLS, XLSX, TXT.

> Die beiden Listen weichen ab: WebP- und SVG-Uploads werden gespeichert, lassen sich aber im Backend nicht herunterladen.

## Sicherheit

Ausführlich: [../UPLOAD_SECURITY.md](../UPLOAD_SECURITY.md).

- **Directory Traversal:** Namen werden bereinigt, der Zielpfad ist fest (`uploads/tenant-<id>/`); beim Download
  wird `realpath()` gegen das Tenant-Verzeichnis geprüft.
- **Authentifizierung:** Upload nur mit gültiger Tenant-Signatur (`X-Signature`); Download nur für angemeldete Admins
  des Tenants.
- **Zuordnung:** Ein Upload wird nur angenommen, wenn die Anmeldung existiert **und** zum Tenant gehört.
- **Virenscan:** ClamAV (optional, `VIRUS_SCAN_ENABLED=true`), siehe [../../DOCKER.md](../../DOCKER.md).

## File Upload API

Wird vom Frontend (`BackendApiClient`) nach dem Submit aufgerufen, je Datei ein Request:

```http
POST /api/upload.php?tenant=<slug>
X-Signature: <HMAC-SHA256 über "{anmeldung_id}:{fieldname}:{dateiname}" mit dem Tenant-Secret>
Content-Type: multipart/form-data

anmeldung_id: 123
fieldname: Zeugnis_0
file: {binary}
```

### Antworten

```json
{ "success": true, "filename": "123_dokument.pdf", "size": 12345 }
```

| Status | Bedeutung |
|---|---|
| 200 | gespeichert |
| 400 | ungültige Datei (Typ/Größe/Inhalt), fehlende Felder, Virus gefunden |
| 401 | Signatur fehlt/falsch, Tenant unbekannt oder inaktiv, Tenant-Secret ist ein bekannter Platzhalter |
| 404 | Anmeldung existiert nicht oder gehört einem anderen Tenant (Audit-Log: `idor_attempt`) |
| 503 | Virenscan nicht verfügbar (nur bei `VIRUS_SCAN_STRICT=true`) |

Signatur-Details und Beispiele: [../../MIGRATION-3.0.md § Eigene API-Clients](../../MIGRATION-3.0.md#7-eigene-api-clients).

## Permissions

```bash
# Upload-Verzeichnis muss für den Webserver beschreibbar sein (Docker: macht entrypoint.sh)
chmod 755 uploads/
chown www-data:www-data uploads/

# Hochgeladene Dateien werden mit 644 angelegt
```

## Aufräumen alter Files

Beim endgültigen Löschen einer Anmeldung (`hard_delete.php`, Auto-Expunge, manuelles Expunge) entfernt
`UploadCleanupService` nach dem DB-Delete alle Dateien `uploads/tenant-<id>/{anmeldung_id}_*` des aktiven Tenants
(exakter Präfix, nur direkt im Tenant-Verzeichnis). Fehler werden per `error_log` und Audit-Log
(`upload_cleanup_failed`) protokolliert und brechen das Löschen nicht ab; Erfolge erscheinen als `uploads_deleted`.
Das Soft-Delete (Papierkorb, Bulk-„Löschen") lässt die Dateien bewusst bestehen, damit Wiederherstellen möglich bleibt.
Ein Cleanup für bereits verwaiste Dateien aus der Zeit vor diesem Fix gibt es nicht.

## Maximale Upload-Größe

In `.env`:
```
UPLOAD_MAX_SIZE=10485760  # 10MB in Bytes
```

In `php.ini`:
```ini
upload_max_filesize = 10M
post_max_size = 12M
```

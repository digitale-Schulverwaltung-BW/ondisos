# WordPress-Plugin aus dem Git-Repository installieren

Diese Anleitung ist für **Entwickler** und für Server, auf denen das Repository ohnehin ausgecheckt ist (Updates per `git pull`, mehrere WordPress-Websites auf einem Server, Entwicklungsumgebung mit Docker).
Schulen installieren das Plugin in aller Regel mit der **fertigen ZIP-Datei**, ohne Kommandozeile: siehe [INSTALL.md](INSTALL.md). Dort stehen auch die Einstellungen im WordPress-Backend, der Shortcode,
der Test und die Fehlersuche, die für beide Wege gelten.

## Inhalt

1. [Voraussetzungen](#voraussetzungen)
2. [Layouts](#layouts)
3. [Variante A: Git-Clone mit Symlink](#variante-a-git-clone-mit-symlink)
4. [Variante B: Docker oder getrennte Verzeichnisse](#variante-b-docker-oder-getrennte-verzeichnisse)
5. [Rechte und Cache](#rechte-und-cache)
6. [Konfiguration per `.env`](#konfiguration-per-env)
7. [Aktualisieren](#aktualisieren)
8. [Fehlersuche](#fehlersuche)
9. [Deinstallation](#deinstallation)

---

## Voraussetzungen

- WordPress 5.8+, PHP 8.1+ (Erweiterung `curl`), Git
- ein laufendes Ondisos-Backend (3.0+), das **vom WordPress-Server aus** erreichbar ist (die Anfragen kommen serverseitig, nicht aus dem Browser)
- im Backend ein Tenant mit **API-Secret** und eingespielten Formularen (für eine Schule genügt Tenant 1, Slug `default`), siehe [installation.md](../docs/betreiber/installation.md)
- bei Variante A: ein Webserver, der **Symlinks folgt**

## Layouts

Hier erwartet das Plugin den Frontend-Code **neben** sich. Zwei Anordnungen werden unterstützt (die ZIP enthält den Frontend-Code im Plugin selbst, das ist „Layout C“):

| Layout | Verzeichnisse | Typischer Einsatz |
|---|---|---|
| **A: Git-Clone auf dem Server** | `…/ondisos/wordpress-plugin/` und `…/ondisos/frontend/` (ein Repository) | Server mit Git-Checkout; Updates per `git pull` |
| **B: Docker / getrennte Verzeichnisse** | `wp-content/plugins/ondisos/` **und** `wp-content/plugins/ondisos-frontend/` | Docker-Volumes oder Symlinks |
| **C: ZIP** | `wp-content/plugins/ondisos/` mit Unterordner `frontend/` | Release-ZIP, siehe [INSTALL.md](INSTALL.md) |

Der Code wählt automatisch (Konstante `ONDISOS_LAYOUT`, sichtbar unter *Einstellungen → Ondisos → Systeminfo*): Gibt es `ondisos/frontend/src/`, gilt Layout C; sonst, wenn `plugins/ondisos-frontend/` existiert,
Layout B; sonst das Verzeichnis `frontend/` neben dem Plugin (Layout A).

Wer von Layout A oder B auf die ZIP wechselt: Plugin-Ordner (bzw. Symlink) `ondisos` und `ondisos-frontend` entfernen, dann die ZIP installieren. Die Einstellungen bleiben erhalten (sie liegen in der WordPress-Datenbank).

## Variante A: Git-Clone mit Symlink

```bash
# Repository klonen (irgendwo außerhalb des WordPress-Verzeichnisses)
git clone https://github.com/digitale-Schulverwaltung-BW/ondisos.git /opt/ondisos
cd /opt/ondisos && git checkout v3.1.1      # Release-Tag (Liste: git tag)

# Symlink ins WordPress-Plugin-Verzeichnis (absoluter Pfad!)
cd /var/www/html/wp-content/plugins
ln -s /opt/ondisos/wordpress-plugin ondisos
ls -la ondisos                               # → ondisos -> /opt/ondisos/wordpress-plugin
```

Die Frontend-Assets (SurveyJS, Fonts) liefert der mitgelieferte Symlink `wordpress-plugin/frontend-assets → ../frontend/public`. Der Webserver muss deshalb Symlinks folgen:

```apache
<Directory /var/www/html>
    Options +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

Nginx folgt Symlinks standardmäßig (kein `disable_symlinks`). Hilfsskript: `SYMLINK-SETUP.sh` (Pfade oben im Skript anpassen, dann `sudo ./SYMLINK-SETUP.sh`).

Danach in WordPress unter *Plugins* **„ondisos - Onboarding Digital Souverän + Open Source“** aktivieren und unter *Einstellungen → Ondisos* konfigurieren ([INSTALL.md](INSTALL.md#einstellungen)).

## Variante B: Docker oder getrennte Verzeichnisse

Beide Verzeichnisse als Plugins bereitstellen (Volumes oder Symlinks):

```yaml
# docker-compose.yml des WordPress-Containers (Ausschnitt)
volumes:
  - /opt/ondisos/wordpress-plugin:/var/www/html/wp-content/plugins/ondisos
  - /opt/ondisos/frontend:/var/www/html/wp-content/plugins/ondisos-frontend
```

Ohne Docker entsprechend zwei Symlinks (`ondisos` → `…/wordpress-plugin`, `ondisos-frontend` → `…/frontend`). Das Verzeichnis `ondisos-frontend` ist **kein** eigenes Plugin und wird nicht aktiviert.
Läuft WordPress selbst in Docker, beachten Sie die [Hinweise zur Backend-Adresse](INSTALL.md#einstellungen) (`localhost` ist dort der Container).

## Rechte und Cache

Das WordPress-Benutzerkonto (z. B. `www-data`) muss beide Verzeichnisse lesen können (Verzeichnisse 755, Dateien 644).

WordPress legt den Formular-Cache unter `wp-content/uploads/ondisos-cache/` an (ab 3.1; beschreibbar wie der Rest von `uploads`). Er enthält die zuletzt vom Backend gelieferte Fassung eines Formulars und hält das Formular
verfügbar, wenn das Backend kurz nicht erreichbar ist. Ohne Schreibrecht arbeitet das Plugin ohne Cache.

## Konfiguration per `.env`

Statt der WordPress-Einstellungen können dieselben Werte in einer `.env` im Frontend-Verzeichnis stehen (`BACKEND_API_URL`, `TENANT_SLUG`, `TENANT_API_SECRET`, `FROM_EMAIL`).
**Die WordPress-Einstellungen haben Vorrang.**

> **Wichtig:** Liegt das Frontend-Verzeichnis unterhalb von `wp-content/plugins/` (Layout B), ist eine dort abgelegte `.env` **über den Webserver abrufbar**, sofern nicht gesperrt.
> Bevorzugen Sie die WordPress-Einstellungen (das Secret liegt dann in der Datenbank) oder sperren Sie Dotfiles:
>
> ```apache
> <FilesMatch "^\.env">
>     Require all denied
> </FilesMatch>
> ```
> ```nginx
> location ~ /\.env { deny all; }
> ```

## Aktualisieren

```bash
cd /opt/ondisos        # Layout A: Git-Repository
git pull               # bzw. git fetch --tags && git checkout <neues-Tag>
```

Die Änderungen sind sofort in WordPress wirksam (kein Neustart). Bei aktiven Cache-Plugins den Cache leeren; die Plugin-Version ist an die Asset-URLs gekoppelt, sodass Browser geänderte JavaScript-Dateien neu laden.
Beim Wechsel von 2.x auf 3.0 zusätzlich [MIGRATION-3.0.md](../docs/betreiber/MIGRATION-3.0.md) beachten (Tenant-Slug und Secret eintragen).

## Fehlersuche

Allgemeine Symptome (Formular nicht verfügbar, „Unknown form“, „Unauthorized“, PDF-Link, alte Fassung): [INSTALL.md → Fehlersuche](INSTALL.md#fehlersuche). Zusätzlich bei Git-Installationen:

| Symptom | Ursache und Lösung |
|---|---|
| Plugin erscheint nicht in der Liste | Symlink prüfen: `ls -la wp-content/plugins/ondisos`, `readlink -f …`; Plugin-Header in `ondisos.php` vorhanden? |
| 403 Forbidden auf Plugin-Dateien | Variante A: `Options +FollowSymLinks`; Dateirechte und Besitzer prüfen |
| Assets (SurveyJS, Fonts) 404 | Variante A: Existiert `wordpress-plugin/frontend-assets` (Symlink → `../frontend/public`)? Variante B: `plugins/ondisos-frontend/public/assets/` vorhanden? |
| Permission denied | `sudo chown -R www-data:www-data <ondisos>/wordpress-plugin <ondisos>/frontend` |
| Formular lädt, PDF-Link schlägt fehl | Plugin-Proxy: `admin-ajax.php?action=ondisos_pdf_download`; Backend-URL und Erreichbarkeit vom WordPress-Server aus prüfen |

## Deinstallation

1. Plugin in WordPress deaktivieren und löschen: `uninstall.php` entfernt die Optionen (`ondisos_backend_url`, `ondisos_from_email`, `ondisos_tenant_slug`, `ondisos_tenant_api_secret`).
2. Symlinks bzw. Volumes entfernen (`rm wp-content/plugins/ondisos`, bei Symlinks **ohne** abschließenden `/`).
3. Das Quell-Repository nur löschen, wenn es nicht noch vom Backend oder anderen Installationen genutzt wird.

Anmeldedaten liegen im Backend und bleiben unberührt.

## Mehrere WordPress-Installationen

Mehrere WordPress-Sites können denselben Code verwenden (Symlink bzw. Volume auf dasselbe Repository). Jede Site hat **eigene** Einstellungen, bei verschiedenen Schulen jeweils mit dem passenden Tenant-Slug und -Secret.

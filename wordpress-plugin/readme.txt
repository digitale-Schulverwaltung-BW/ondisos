=== ondisos ===
Contributors: J. Seyfried
Tags: forms, survey, surveyjs, registration, anmeldung, Schule
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 3.1.0
License: MIT
License URI: https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos/-/blob/main/LICENSE

SurveyJS-based Schulanmeldungs-System with secure backend submission.

== Description ==

Ondisos ist ein leistungsstarkes Formular-Plugin basierend auf SurveyJS für WordPress. 
Es ermöglicht die Integration von komplexen Anmeldungs-Formularen mit Backend-Anbindung. 
Eine direkte ASV-Export-Funktion erleichtert die Übernahme direkt in die Schulverwaltungen 
von Bayern und BW.

**Features:**

* SurveyJS-Integration mit lokalen Fonts (DSGVO-konform)
* Multiple Formulare über Shortcode
* CSRF-Protection via WordPress Nonces
* File-Upload Support
* Prefill-Funktionalität für wiederholte Anmeldungen, etwa durch Ausbildungbetriebe
* Backend API Integration
* Clean MVC-Architektur
* Type-Safe PHP 8.1+

**Verwendung:**

Fügen Sie den Shortcode in eine Seite oder einen Beitrag ein:

`[ondisos form="bs"]`

Der Parameter `form` ist verpflichtend und muss einem Formular entsprechen, das im Backend für den konfigurierten Tenant angelegt ist.

**Verfügbare Formulare:**

Die Formular-Konfiguration liegt im Ondisos-Backend (pro Tenant) und wird vom Plugin von dort abgerufen.

**Einstellungen:**

Unter Einstellungen → Ondisos können Sie:

* Backend API URL konfigurieren
* Tenant-Slug und Tenant-API-Secret (schreibgeschützt) eintragen
* Absender-E-Mail-Adresse festlegen

== Installation ==

**Wichtig:** Das Plugin benötigt ein Ondisos-Backend ab Version 3.0.

**Ohne Shell (empfohlen):** Die Release-ZIP `ondisos-<version>.zip` enthält Plugin und Frontend-Code. Unter Plugins → Installieren → Plugin hochladen auswählen, installieren, aktivieren, danach die Zugangsdaten unter Einstellungen → Ondisos eintragen.

**Mit Shell (Git-Clone):** Das Plugin braucht dann den Frontend-Code des Ondisos-Repositories neben sich.

1. Repository klonen: `git clone https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos.git /opt/ondisos`
2. Symlink im WordPress Plugins-Verzeichnis anlegen:
   `cd /var/www/wordpress/wp-content/plugins/`
   `ln -s /opt/ondisos/wordpress-plugin ondisos`
   (Docker/getrennte Verzeichnisse: zusätzlich `ondisos-frontend` → `/opt/ondisos/frontend`, siehe INSTALL.md)
3. Plugin aktivieren unter Plugins → Installierte Plugins
4. Einstellungen → Ondisos: Backend API URL, Tenant-Slug und Tenant-API-Secret eintragen

**Voraussetzungen:**

* PHP 8.1 oder höher, WordPress 5.8 oder höher
* Webserver muss Symlinks unterstützen (Apache: `Options +FollowSymLinks`)
* Ondisos-Backend 3.0+, vom WordPress-Server aus erreichbar

== Frequently Asked Questions ==

= Welche PHP-Version wird benötigt? =

Das Plugin erfordert mindestens PHP 8.1.

= Wie funktioniert die Symlink-Integration? =

Das Plugin ist als Symlink konzipiert, damit Updates via `git pull` automatisch in WordPress verfügbar sind, ohne Dateien kopieren zu müssen.

= Wo werden die Formulardaten gespeichert? =

Die Formulardaten werden nicht in der WordPress-Datenbank gespeichert, sondern signiert (HMAC mit dem Tenant-Secret) über eine Backend API an ein separates System übertragen.
Dieses Backend-System sollte nicht vom Internet aus erreichbar sein.

= Kann ich mehrere Formulare auf einer Seite verwenden? =

Ja, Sie können mehrere `[ondisos]` Shortcodes mit unterschiedlichen `form` Parametern auf einer Seite verwenden.

= Wie funktioniert die Prefill-Funktionalität? =

Nach erfolgreicher Submission wird ein Link generiert, der vorausgefüllte Formulardaten enthält. Dies ermöglicht schnelle Mehrfach-Anmeldungen mit ähnlichen Daten.
Welche Felder im Link enthalten sind, steht in der Formular-Konfiguration im Backend (`prefill_fields`; Vorlage: frontend/config/forms-config-dist.php).
Zusätzlich lässt sich jedes Feld per einfachem URL-Parameter vorbelegen, z. B. `?Klasse=5a`.

== Changelog ==

= 3.1.0 =
* Die Versionsnummer folgt ab jetzt dem Ondisos-Release (Backend, Frontend und Plugin sind 3.1.0); frühere Plugin-Versionen waren 2.1.x
* Surveys und Themes kommen mit der Formular-Konfiguration vom Backend (ein Aufruf, ETag); Datei im Frontend bleibt Fallback
* Das Plugin merkt sich die letzte Fassung eines Formulars (wp-content/uploads/ondisos-cache) und zeigt sie weiter an, wenn das Backend kurz nicht erreichbar ist
* Verbindungsstatus: prüft per signierter Anfrage, ob das Tenant-API-Secret zum Tenant passt, und zeigt die Zahl der Formulare (bei 0 ein Hinweis)
* Survey und Theme werden sicher in die Seite eingebettet (kein Ausbrechen aus dem script-Element)

= 2.1.1 =
* Fehlermeldungen unterscheiden „Backend nicht erreichbar", „Tenant abgelehnt" und „Formular unbekannt" (Administratoren sehen die Ursache, Besucher eine neutrale Meldung)
* Einstellungsseite: Verbindungsstatus (Backend, Tenant, Secret) und Warnung beim Speichern einer nicht erreichbaren Backend-URL; Hinweis auf „localhost" im Docker-Container

= 2.1.0 =
* Formular-Konfiguration wird vom Backend geladen (pro Tenant)
* Anfragen ans Backend werden mit dem Tenant-Secret signiert
* Neue Einstellungen: Tenant-Slug, Tenant-API-Secret
* Prefill über einfache URL-Parameter, dynamische Platzhalter (`placeholderExpression`)

== Upgrade Notice ==

= 3.1.0 =
Läuft mit Backend 3.0 und 3.1; die neuen Funktionen (Surveys aus dem Backend, Statuszeilen) brauchen Backend 3.1. Der Cache liegt in wp-content/uploads und muss beschreibbar sein (ohne Schreibrecht arbeitet das Plugin ohne Cache). Siehe docs/betreiber/MIGRATION-3.1.md im Ondisos-Repository.

= 2.1.1 =
Nur Diagnose-Verbesserungen, keine Konfigurationsänderung nötig.

= 2.1.0 =
Benötigt ein Ondisos-Backend ab 3.0. Nach dem Update Tenant-Slug und Tenant-API-Secret unter Einstellungen → Ondisos eintragen (siehe docs/betreiber/MIGRATION-3.0.md im Ondisos-Repository).

== Developer Notes ==

**Architecture:**

Das Plugin verwendet eine Clean MVC-Architektur mit Service Layer:

* `Ondisos\*` - WordPress Plugin Namespace
* `Frontend\*` - Shared Frontend Services (wiederverwendet)

**Hooks:**

* `ondisos_enqueue_assets` - Wird beim Rendern des Shortcodes aufgerufen

**AJAX Endpoints:**

* `wp_ajax_ondisos_submit` / `wp_ajax_nopriv_ondisos_submit` - Form submission
* `wp_ajax_ondisos_pdf_download` / `wp_ajax_nopriv_ondisos_pdf_download` - PDF-Proxy
* `wp_ajax_ondisos_ical` / `wp_ajax_nopriv_ondisos_ical` - iCal-Download

**File Structure:**

```
wordpress-plugin/
├── ondisos.php             # Main plugin file
├── includes/
│   ├── class-plugin.php
│   ├── class-autoloader.php
│   ├── class-shortcode.php
│   ├── class-form-config-loader.php
│   ├── class-ajax-handler.php
│   ├── class-pdf-proxy.php
│   ├── class-assets.php
│   └── class-settings.php
└── assets/
    ├── js/
    │   └── survey-handler-wp.js
    └── css/
        └── ondisos.css
```

**Git Updates:**

```bash
cd /opt/ondisos/
git pull
# Changes sind sofort in WordPress verfügbar
```

== Support ==

Für Support und Bug-Reports besuchen Sie:
https://github.com/digitale-Schulverwaltung-BW/ondisos oder
https://codeberg.org/digitale-Schulverwaltung-BW/ondisos


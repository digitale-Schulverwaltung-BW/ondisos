=== ondisos ===
Contributors: J. Seyfried
Tags: forms, survey, surveyjs, registration, anmeldung, Schule
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 2.0.0
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

`[anmeldung form="bs"]`

Der Parameter `form` ist verpflichtend und muss einem konfigurierten Formular entsprechen.

**Verfügbare Formulare:**

Die verfügbaren Formulare werden in der `frontend/config/forms-config.php` konfiguriert.

**Einstellungen:**

Unter Einstellungen → Anmeldung Forms können Sie:

* Backend API URL konfigurieren
* Absender-E-Mail-Adresse festlegen
* Liste aller verfügbaren Formulare mit Shortcodes ansehen

== Installation ==

**Wichtig:** Dieses Plugin ist für die Verwendung mit Symlinks konzipiert.

1. Klonen Sie das Repository:
   `git clone https://github.com/yourusername/anmeldung-forms.git /path/to/repo/ondisos/`

2. Erstellen Sie einen Symlink im WordPress Plugins-Verzeichnis:
   `cd /var/www/wordpress/wp-content/plugins/`
   `ln -s /path/to/repo/ondisos/wordpress-plugin anmeldung-forms`

3. Aktivieren Sie das Plugin in WordPress unter Plugins → Installierte Plugins

4. Konfigurieren Sie die Einstellungen unter Einstellungen → Anmeldung Forms

**Voraussetzungen:**

* PHP 8.1 oder höher
* WordPress 5.8 oder höher
* Webserver muss Symlinks unterstützen (Apache: `Options +FollowSymLinks`)

== Frequently Asked Questions ==

= Welche PHP-Version wird benötigt? =

Das Plugin erfordert mindestens PHP 8.1.

= Wie funktioniert die Symlink-Integration? =

Das Plugin ist als Symlink konzipiert, damit Updates via `git pull` automatisch in WordPress verfügbar sind, ohne Dateien kopieren zu müssen.

= Wo werden die Formulardaten gespeichert? =

Die Formulardaten werden nicht in der WordPress-Datenbank gespeichert, sondern über eine Backend API an ein separates System übertragen.
Dieses Backend-System sollte nicht vom Internet aus erreichbar sein.

= Kann ich mehrere Formulare auf einer Seite verwenden? =

Ja, Sie können mehrere `[anmeldung]` Shortcodes mit unterschiedlichen `form` Parametern auf einer Seite verwenden.

= Wie funktioniert die Prefill-Funktionalität? =

Nach erfolgreicher Submission wird ein Link generiert, der vorausgefüllte Formulardaten enthält. Dies ermöglicht schnelle Mehrfach-Anmeldungen mit ähnlichen Daten.
Diese Funktionalität wird in der forms-config.php im Verzeichnis frontend/config aktiviert. Das Beispiel-Formular
"bs" kann hier herangezogen werden; die Felder, die als Prefill bereitgestellt werden, können im Array
"prefill_fields" definiert werden.

== Changelog ==

= 2.0.0 =
* Initial WordPress plugin release
* SurveyJS integration
* Symlink-based architecture
* Backend API client
* CSRF protection via WordPress nonces
* File upload support
* Prefill functionality
* Settings page
* Multiple forms support

== Upgrade Notice ==

= 2.0.0 =
Initial release. Keine Upgrade-Schritte erforderlich.

== Developer Notes ==

**Architecture:**

Das Plugin verwendet eine Clean MVC-Architektur mit Service Layer:

* `Anmeldung_Forms\*` - WordPress Plugin Namespace
* `Frontend\*` - Shared Frontend Services (wiederverwendet)

**Hooks:**

* `anmeldung_enqueue_assets` - Wird beim Rendern des Shortcodes aufgerufen

**AJAX Endpoints:**

* `wp_ajax_anmeldung_submit` - Form submission (logged in)
* `wp_ajax_nopriv_anmeldung_submit` - Form submission (public)

**File Structure:**

```
wordpress-plugin/
├── anmeldung-forms.php     # Main plugin file
├── includes/
│   ├── class-plugin.php
│   ├── class-autoloader.php
│   ├── class-shortcode.php
│   ├── class-ajax-handler.php
│   ├── class-assets.php
│   └── class-settings.php
└── assets/
    ├── js/
    │   └── survey-handler-wp.js
    └── css/
        └── anmeldung.css
```

**Git Updates:**

```bash
cd /path/to/repo/ondisos/
git pull origin main
# Changes sind sofort in WordPress verfügbar
```

== Support ==

Für Support und Bug-Reports besuchen Sie:
https://github.com/digitale-Schulverwaltung-BW/ondisos oder
https://codeberg.org/digitale-Schulverwaltung-BW/ondisos


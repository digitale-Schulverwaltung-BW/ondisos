# ondisos

Onboarding - Digital Souverän und Open Source

## Was ist ondisos?

Eine Open Source Onboarding-Lösung für berufliche Schulen. Basierend auf SurveyJS wird ein modernes Web-Formular zur Schulanmeldung angeboten, das
leicht anpassbar ist. Die Anmeldedaten können übermittelt werden per
- E-Mail
- geschützter Datenbank, Abruf der Daten per Web-UI und (Massen-) Download der Anmeldungen per Excel
- Excel-Anhang an E-Mail
...und beliebigen Kombinationen davon. Mehrere unterschiedliche Anmeldeformulare für verschiedene Schularten sind konfigurierbar, die jeweils
eigene Anmeldedaten-Übermittlungen beinhalten, um die schulischen Prozesse optimal abzubilden.

## Systemvoraussetzungen

- Webserver mit PHP
- (optional) SQL-Datenbank in gesichertem Netz mit DB-Benutzer, der nur Schreibrechte hat
- funktionierende ```mail()```-Funktion auf Webserver
- [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet), ```composer require phpoffice/phpspreadsheet```

## Installation

Nach einem ```git clone``` in einem Verzeichnis, das intern auf einem Webserver erreichbar ist, muss die Datei ```config-sample.php``` in eine funktionierende Kopie als ```config.php``` abgelegt werden. Als Voraussetzung muss noch ein ```composer require phpoffice/phpspreadsheet``` ausgeführt werden, damit Excel-Dateien vom Skript erzeugt werden können. Wenn die Datenbank angelegt wurde, ist das Programm bereits einsatzbereit.


## Beitragen/Contributing
Ergänzungen, Bug reports und Kommentare können sehr gerne über die Projektseiten auf GitHub oder Codeberg übermittelt werden.

## Authors and acknowledgment
Show your appreciation to those who have contributed to the project.

## License
For open source projects, say how it is licensed.

## Projekt Status
Aktuell in Entwicklung

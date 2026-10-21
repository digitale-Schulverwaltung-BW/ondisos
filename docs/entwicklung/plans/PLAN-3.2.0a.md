# PLAN 3.2.0a – Unterschrift per Smartphone (QR-Code)

**Status:** Umgesetzt (Stand 2026-10-21): AP1 bis AP6. Offen ist die Abnahme auf echten Geräten, hinter dem Reverse-Proxy und mit WAF ([ABNAHME.md](../releases/ABNAHME.md#v320a-unterschrift-per-smartphone)). Unabhängig vom grafischen Survey-Editor ([PLAN-3.2.0.md](PLAN-3.2.0.md)) und in beliebiger Reihenfolge dazu umsetzbar.
Die Aufwände sind Schätzungen für eine Person und nicht an einem Prototyp gemessen.

## Ausgangslage

- Seit 3.1.2 unterstützt Ondisos den SurveyJS-Fragetyp `signaturepad` ([SURVEYJS.md](../../redaktion/SURVEYJS.md#unterschriftsfelder)). Die Unterschrift kommt als PNG-Data-URI mit der Anmeldung; `submit.php` nimmt Inline-Daten nur als kleines PNG/JPEG an (`DataFormatter::isImageDataUri()`, höchstens 500 000 Zeichen). Detailansicht und PDF zeigen das Bild, Excel und Mail den Platzhalter „[Unterschrift]“.
- Am Rechner ohne Touchscreen ist das Unterschreiben mit der Maus mühsam. Einige Schulen und Träger bestehen trotzdem auf einer Unterschrift.
- **Bedarf bestätigt:** Eine Schule und das SMZ haben das Feature angefragt.
- Vorbild ist DocuSeal (https://www.docuseal.com/): Auf Wunsch zeigt das Formular einen QR-Code, der Nutzer unterschreibt auf Smartphone oder Tablet, die Unterschrift erscheint im Formular am Rechner.
- **Rechtlicher Wert:** Eine gemalte Unterschrift ist eine einfache elektronische Signatur, egal auf welchem Gerät gezeichnet. Das Feature verbessert die Bedienung, nicht die Beweiskraft. Ob eine Unterschrift genügt, klärt jede Schule mit Träger oder Datenschutzbeauftragten (Hinweis steht schon in SURVEYJS.md).

## Ziel

Bei einem Unterschriftsfeld kann der Nutzer am Rechner **„Auf dem Smartphone unterschreiben“** wählen: Ein QR-Code öffnet auf dem Smartphone eine Seite mit einem Unterschriftsfeld, die Unterschrift erscheint danach als Vorschau am Rechner und wird nach **Übernehmen** zum Wert des `signaturepad`. Ab da läuft alles wie heute.

**Nicht Ziel:** qualifizierte oder fortgeschrittene Signatur, Unterschrift auf einem fertigen PDF, Versand von Links per Mail oder SMS, Anbindung von DocuSeal selbst.

## Entscheidungen

1. **Der Wert bleibt ein normaler `signaturepad`-Wert.** Das Smartphone liefert nur das PNG; gesetzt wird es im Browser am Rechner (`question.value`). Anmeldung, `submit.php`, Detailansicht, PDF, Export und Mail bleiben unverändert.
2. **Relay über das Backend (Variante B).** Das Smartphone erreicht nur das öffentliche Frontend, die Sitzung liegt aber im Backend (Tabelle mit Ablaufzeit); das Frontend reicht über die signierte API durch.
   Verworfen: Zustand im Frontend (Temp-Dateien bzw. WP-Transients), weil das zwei Implementierungen wären und die Unterschrift auf dem öffentlichen Server läge.
3. **Zwei Geheimnisse:** Ein **Schreib-Token** steht im QR-Code und erlaubt nur `info` und das Abliefern einer Unterschrift; ein **Lese-Schlüssel** bleibt im Browser am Rechner und erlaubt nur das Abholen. Wer den QR-Code abfotografiert, kann nichts mitlesen.
4. **Bestätigung am Rechner.** Nichts landet ungesehen im Formular: Der Nutzer sieht die Unterschrift als Vorschau und wählt *Übernehmen* oder *Verwerfen*. Das ist der Schutz gegen fremde Kritzeleien über einen abfotografierten Code. Zusätzlich zeigt der Rechner „Smartphone verbunden“, sobald der Code gescannt ist (Status `scanned`, siehe 9): Scannt ein Fremder zuerst, fällt das vor der Unterschrift auf.
5. **Schalter in der Formular-Konfiguration, nicht im Survey-JSON.** Neue Option `signature_handoff` (bool, Vorgabe aus) im Reiter *Allgemein*. Eine eigene Survey-Eigenschaft würde der SurveyJS Creator beim Bearbeiten vermutlich verwerfen (nicht geprüft); die Konfiguration übersteht jede Runde.
6. **Clean-Room-Nachbau.** DocuSeal steht unter AGPL-3.0 (geprüft 2026-10-21). Übernommen wird nur die Idee, kein Code; damit spielt die Lizenz für Ondisos (MIT) keine Rolle.
7. **Stack bleibt:** Vanilla JavaScript, Bootstrap 5, keine neue Bibliothek. Den QR-Code erzeugt das Backend mit dem vorhandenen `App\Utils\QrCodeSvg` (bacon-qr-code, seit 3.1.2 für TOTP); die Handy-Seite nutzt SurveyJS selbst (ein Formular mit nur einem `signaturepad`), damit das PNG genau so entsteht wie im Formular.
8. **Die URL der Handy-Seite liefert das Frontend.** Das Backend kennt die öffentliche Adresse des Frontends nicht (beim Plugin schon gar nicht). `create` enthält deshalb `sign_url` (Standalone: `…/sign.php`, Plugin: `admin-ajax.php?action=ondisos_sign`); der Request ist signiert, das Backend prüft nur `https://` bzw. `http://` und die Länge, hängt `#s=<id>&t=<Schreib-Token>` an und rendert daraus das QR-SVG. Derselbe Link kommt als `sign_link` mit (Ausweg für den Rechner, wenn Scannen nicht klappt; der Nutzer hält dann beide Geheimnisse, was unbedenklich ist).
9. **Aktion `info` für die Handy-Seite.** Mit dem Schreib-Token liefert sie Gültigkeit, Restlaufzeit, Schulname, Formulartitel, Feldtitel und die Maße der Unterschriftsfläche, und sie setzt den Status `scanned`. So meldet die Handy-Seite einen abgelaufenen Code, bevor jemand unterschreibt, und der Nutzer sieht, wofür er unterschreibt.
10. **Kurzes Polling statt Long Poll.** Long Poll und Server-Sent Events belegen pro wartendem Rechner je einen PHP-Worker auf Frontend und Backend, laufen in Timeouts von Proxys, WAFs und `max_execution_time` und halten die PHP-Session-Sperre. Stattdessen fragt der Rechner alle **3 s**, pausiert bei verborgenem Tab (`visibilitychange`) und hört bei Ablauf oder geschlossenem Dialog auf. Höchstens 200 Anfragen pro Sitzung, üblich 10 bis 20, jede ein indizierter SELECT. Der Relay im Frontend gibt die Session direkt nach der CSRF-Prüfung frei (`session_write_close()`).
11. **Querformat per Gestaltung, nicht per Zwang.** `screen.orientation.lock()` funktioniert nur im Vollbild und nicht in Safari auf iOS. Die Handy-Seite zeigt deshalb eine fest begrenzte Fläche im Seitenverhältnis des Feldes am Rechner (`signatureWidth`/`signatureHeight`). Im Hochformat wird sie ein schmaler Streifen (Dreh-Reflex), dazu erscheint per `@media (orientation: portrait)` der Hinweis „Für mehr Platz das Gerät drehen“. Wo es geht (Android), versucht die Seite beim Tippen auf *Unterschreiben* Vollbild und `lock('landscape')` und ignoriert Fehler.
12. **Nur für PNG-Felder.** Der Button erscheint nur bei `dataFormat` png (Vorgabe von SurveyJS); bei jpeg oder svg nicht.
12a. **Farben wie am Rechner.** `create` übergibt Strich- und Hintergrundfarbe, mit denen das Feld am Rechner tatsächlich zeichnet (Feldeinstellung, sonst Theme, sonst SurveyJS-Vorgabe); die Handy-Seite übernimmt sie. Erlaubt sind nur Farbangaben (#hex, rgb/rgba, hsl/hsla, Farbwort).
13. **Restlaufzeit statt Zeitstempel.** `create` und `info` liefern `expires_in` (Sekunden), nicht `expires_at`: Die Uhr des Smartphones kann falsch gehen. Rechner und Handy zeigen einen Countdown; das Handy hebt die letzte Minute hervor und sperrt *Senden* bei 0. Die Frist verlängert sich beim Scannen nicht (Regel bleibt „10 Minuten ab Erzeugen“); entscheidend ist immer die Prüfung im Backend.
14. **Ablaufzeit 10 Minuten** (Konstante im `SignatureHandoffService`).
15. **Je Unterschriftsfeld eine Sitzung.** Mehrere Felder (z. B. Schüler und Erziehungsberechtigte) laufen nacheinander; die Sitzung trägt den Feldnamen und -titel.
16. **Handy-Seite im Plugin über `admin-ajax.php`** (`action=ondisos_sign`). Keine Rewrite-Regel, kein Neuspeichern der Permalinks. `admin-ajax.php` muss ohnehin öffentlich sein: Absenden und PDF-Download des Plugins laufen schon darüber.
17. **Nur Signaturformat 2.** Der neue Endpunkt nimmt Format 1 auch im Modus `compat` nicht an: Ein Frontend, das das Feature kennt, kennt auch Format 2.

## Ablauf

```
Rechner (Formular)            Frontend (öffentlich)               Backend (Intranet)
──────────────────            ─────────────────────               ──────────────────
„Auf dem Smartphone            POST …/signature?action=create  ─►  legt Sitzung an:
 unterschreiben“  ──CSRF──►    (signiert, Tenant, form_key,        id, Hash(Schreib-Token), Hash(Lese-Schlüssel),
                               Feld, sign_url)                     Feld, Maße, expires_at = jetzt + 10 min
QR-Code + Countdown  ◄──────── id, Lese-Schlüssel, QR-SVG,    ◄──  QR-Inhalt: <sign_url>#s=<id>&t=<Schreib-Token>
                               expires_in
Smartphone scannt ───────────► Handy-Seite (sign.php bzw. admin-ajax.php?action=ondisos_sign),
                               liest Token aus dem Fragment
Seite lädt ──────────────────► POST …/signature?action=info   ──►  prüft Token; Status „scanned“; liefert Schule,
                                                                   Formular, Feldtitel, Maße, expires_in
Unterschrift, „Senden“ ──────► POST …/signature?action=submit ──►  prüft Token (Hash, Ablauf, einmalig),
                                                                   prüft PNG, speichert, Status „signed“
Rechner fragt alle 3 s ──────► POST …/signature?action=poll   ──►  prüft Lese-Schlüssel; liefert Status
 („verbunden“ ab scanned)                                          (open/scanned/signed/expired), das PNG einmal,
                                                                   danach wird die Sitzung gelöscht
Vorschau → „Übernehmen“ setzt question.value (Data-URI); Absenden wie bisher
```

## Sicherheit

- **Tokens:** je 256 Bit aus `random_bytes()` (hex), in der Datenbank nur als SHA-256-Hash, Vergleich mit `hash_equals()`. Sitzungs-ID ebenfalls zufällig (128 Bit, hex), nicht fortlaufend. Schreib-Token einmalig (`submit` nur im Status open/scanned); nach dem Abholen ist die Sitzung gelöscht. Gültigkeit 10 Minuten.
- **Token im URL-Fragment** (`#s=…&t=…`), nicht im Query-String: Es erscheint weder in Server-Logs noch im `Referer`. Die Handy-Seite liest es per JavaScript und sendet es im Body.
- **Bindung:** Die Sitzung gehört zu Tenant und `form_key`; die Endpunkte filtern nach `tenant_id` wie jede Abfrage. Ein Schreib-Token eines anderen Tenants ist ungültig.
- **PNG-Prüfung:** nur `data:image/png;base64,…` über `isImageDataUri()`, dazu eine eigene, kleinere Obergrenze (200 000 Zeichen); Signatur-Bytes `\x89PNG` prüfen; wenn GD verfügbar ist, dekodieren und neu kodieren (entfernt Metadaten und Anhängsel, prüft das Bild wirklich). GD ist in allen Docker-Images enthalten; ohne GD (Betrieb ohne Docker) entfällt nur das Neu-Kodieren.
- **Missbrauch als Speicher:** Rate-Limit beim Anlegen von Sitzungen (vorhandener `RateLimiter`, Schlüssel `ClientIdentity::fingerprint()`: das Frontend schickt schon heute `X-Ondisos-Client`, Standalone wie Plugin) mit eigenem Topf je Aktion (`create` streng, `poll`/`info` großzügig), und eine Obergrenze offener Sitzungen pro Tenant (200). Abgelaufene Sitzungen werden beim nächsten `create` gelöscht (Muster: `RequestExpungeService`), zusätzlich bei `migrate.php`.
- **CSRF:** `create` und `poll` kommen von der Formularseite und tragen das vorhandene CSRF-Token (`csrf_token.php` bzw. WP-Nonce). `info` und `submit` brauchen keins, das Schreib-Token ist die Berechtigung.
- **Handy-Seite:** gleiche CSP wie das Formular (`ContentSecurityPolicy`; `connect-src 'self'`, `img-src` mit `data:` genügt), keine personenbezogenen Daten. Gezeigt werden Schulname, Formulartitel und Feldtitel, damit der Nutzer erkennt, wofür er unterschreibt.
- **Datenschutz:** Die Unterschrift liegt höchstens bis zum Abholen oder bis zum Ablauf im Backend; kein Dritter ist beteiligt. Audit-Log nur für Fehlversuche (falsches Token, Tenant-Fremdzugriff), nicht für jede Sitzung.

## Randbedingungen aus dem bestehenden Code

- **Zwei Frontends:** Standalone (`frontend/public/`) und WordPress-Plugin. Gemeinsam sind `survey-handler-base.js` (dort kommt die Button-Logik hin) und `Frontend\Services\BackendApiClient` (das Plugin lädt `Frontend\*` über seinen Autoloader). Das Plugin braucht eigene AJAX-Aktionen und eine öffentliche Handy-Seite. Vorbild für den Proxy: `frontend/public/pdf/download.php` und `wordpress-plugin/includes/class-pdf-proxy.php`.
- **Vorschau im Backend** hat eine eigene Kopie von `survey-handler-base.js` (`backend/public/assets/preview/`): dort **kein** Button (die Vorschau sendet nichts). `sync-preview-assets.sh` und die Kopie im Blick behalten.
- **Erreichbarkeit:** Das Frontend ist ohnehin öffentlich (Anmeldungen kommen von außen), das Smartphone erreicht es also auch über Mobilfunk.
- **WAF und Härtung im Plugin:** Wer `admin-ajax.php` auf interne Zugriffe beschränkt, hat schon heute ein kaputtes Formular. Neu ist, dass `admin-ajax.php` eine HTML-Seite ausliefert und Base64-Bilder im Body empfängt; Wordfence und ähnliche WAFs dürfen das und das Polling (`action=ondisos_sig`) nicht blockieren. Gehört in die Doku für die Schul-IT.
- **UI-Texte** über den `MessageService` (Frontend: `messages.json.php`), keine Texte im Code.
- **Signierte API:** neuer Endpunkt mit Signaturformat 2 wie `submit.php` (Zweck `signature`, Payload = SHA-256 des Bodys); der Tenant kommt wie immer aus `?tenant=<slug>`.

## Arbeitspakete

| AP | Inhalt | Aufwand (Schätzung) |
|---|---|---|
| ~~**AP0**~~ | Erledigt: Bedarf (Schule, SMZ), DocuSeal AGPL-3.0 (Clean Room), GD in allen Images, Client-Kennung `X-Ondisos-Client` vorhanden, Plugin-Route `admin-ajax.php` | – |
| ~~**AP1**~~ | Erledigt 2026-10-21. Backend: Migration `signature_handoffs` (`id`, `tenant_id`, `form_key`, `field_name`, `field_title`, `width`, `height`, `write_hash`, `read_hash`, `png` MEDIUMTEXT NULL, `status` open/scanned/signed, `created_at`, `expires_at` als Unix-Sekunden; Index auf `tenant_id`, `expires_at`); `SignatureHandoffRepository` (tenant-gefiltert), `SignatureHandoffService` (Tokens, Ablauf, Einmaligkeit, PNG-Prüfung, Aufräumen, Obergrenze); Unit-Tests | 1,5 Tage |
| ~~**AP2**~~ | Erledigt 2026-10-21 (Aktion im signierten JSON-Body, Fehler als Code mit HTTP-Status 400/403/404/409/410/422/429). Backend: Endpunkt `api/signature-handoff.php` (`create`, `info`, `submit`, `poll`), signiert (nur Format 2), Rate-Limit je Aktion, QR-SVG aus `sign_url` in der Antwort von `create`; Option `signature_handoff` in `FormConfigValidator`, `FormConfigFormMapper` und Formular-Editor (Reiter *Allgemein*); Auslieferung über `form-config.php` | 1 Tag |
| ~~**AP3**~~ | Erledigt 2026-10-21 (Logik in `Frontend\Services\SignatureRelay`, auch fürs Plugin; im Browser getestet, Hoch-/Querformat, Drehen behält die Striche; iOS-Simulator noch offen). Frontend Standalone: Relay `public/api/signature.php` (CSRF für `create`/`poll`, `session_write_close()`), Handy-Seite `public/sign.php` (SurveyJS mit einem `signaturepad`, Querformat-Fläche, Dreh-Hinweis, Countdown, Meldung „Code abgelaufen“); `BackendApiClient` um die vier Aufrufe erweitern | 1,5 Tage |
| ~~**AP4**~~ | Erledigt 2026-10-21 (Methode `setupSignatureHandoff()` der Basisklasse, nur Standalone ruft sie auf; Dialog als `<dialog>`, QR als `<img>`, Link mit Kopieren-Ausweg ohne Clipboard-API; im Browser mit Demo-Formular getestet: verbunden, Vorschau, Übernehmen, Verwerfen, Absenden). `survey-handler-base.js`: bei `signaturepad` mit `dataFormat` png, Option an und `matchMedia('(pointer: coarse)')` falsch einen Button über `onAfterRenderQuestion` einfügen; Dialog mit QR-Code, Countdown, „Smartphone verbunden“, *Neuen Code erzeugen*, Polling alle 3 s (Pause bei verborgenem Tab), Vorschau, *Übernehmen*/*Verwerfen* | 1 Tag |
| ~~**AP5**~~ | Erledigt 2026-10-21: eine AJAX-Aktion `ondisos_sig` (Aktion im JSON-Body, wie im Standalone) statt vier, Handy-Seite `admin-ajax.php?action=ondisos_sign`, Nonce `ondisos_signature` für create/poll, Texte als Data-Attribut (mit `messages.local.php`); `build-zip.sh` packt `sign-page.js` mit. Im WordPress auf :8080 getestet, auch als anonymer Besucher. | 1 Tag |
| ~~**AP6**~~ | Erledigt 2026-10-21 (Schul-IT: `schul-it/standalone-frontend.md` und `wordpress-plugin/INSTALL.md`; Abnahme in ABNAHME.md, Geräte-Tests offen). Doku und Abnahme: SURVEYJS.md (Abschnitt Unterschriftsfelder, Empfehlung „Checkbox mit getipptem Namen“ für Schulen, denen eine Bestätigung genügt), sicherheit.md, architektur.md (Endpunkt, Tabelle), konfiguration.md, Schul-IT (WAF/Wordfence, `admin-ajax.php`), Messages, manuelle Tests in ABNAHME.md | 0,5 Tage |

Summe grob **6,5 Tage**. Ein erster nutzbarer Stand ohne Plugin ist AP1 bis AP4.

## Tests

- **Unit:** Token-Erzeugung und -Prüfung (Hash, `hash_equals`, Ablauf, einmalig), Lese-Schlüssel ≠ Schreib-Token, Statusübergänge (open → scanned → signed → abgeholt), `expires_in` statt Zeitpunkt, PNG-Prüfung (PNG ja, JPEG/SVG/zu groß/kaputt nein), `sign_url` (nur http/https), Obergrenze offener Sitzungen, Aufräumen abgelaufener Sitzungen, Option `signature_handoff` im `FormConfigValidator`.
- **Integration (MySQL):** Repository tenant-gefiltert, ein Tenant sieht keine fremde Sitzung.
- **Manuell (Abnahme):** Rechner ohne Touch zeigt den Button, Tablet nicht; Feld mit `dataFormat` svg ohne Button; QR scannen, „Smartphone verbunden“ am Rechner, unterschreiben, Vorschau übernehmen, absenden, Bild in Detailansicht und PDF; Countdown auf beiden Geräten; Code abgelaufen (Meldung auf dem Handy vor dem Unterschreiben); zweites Senden mit demselben Code scheitert; Verwerfen und neuer Code; zwei Unterschriftsfelder nacheinander; Drehen des Handys während des Unterschreibens (iOS Safari, Android Chrome: gehen Striche verloren?); WordPress-Plugin mit Wordfence; Vorschau im Backend ohne Button.

## Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Missbrauch als anonymer Bildspeicher | Größenlimit, kurze Ablaufzeit, Rate-Limit, Obergrenze pro Tenant, Bild nur einmal abrufbar |
| Fremde Unterschrift über abfotografierten Code | „Smartphone verbunden“ am Rechner, Vorschau und *Übernehmen* (Entscheidung 4) |
| Drehen löscht die Striche (Canvas-Größe ändert sich) | Fläche fester Größe, nur per CSS skaliert; auf echten Geräten testen |
| WAF (Wordfence) blockiert Polling oder Base64-Body | Doku für Schul-IT; Button bleibt optional, die Unterschrift mit der Maus funktioniert weiter |
| Aufwand im WordPress-Plugin unterschätzt | AP5 getrennt; Standalone zuerst ausliefern |
| Erwartung „rechtssichere Unterschrift“ | Klarer Hinweis in SURVEYJS.md und in der Formular-Konfiguration |

## Offene Fragen

Keine. Beantwortet am 2026-10-21: Ablaufzeit 10 Minuten (14), Rate-Limit über `X-Ondisos-Client` (Sicherheit), Plugin-Route `admin-ajax.php` (16), je Feld eine Sitzung (15), Checkbox mit getipptem Namen als Empfehlung in SURVEYJS.md (AP6).

# PLAN 3.2.0a – Unterschrift per Smartphone (QR-Code)

**Status:** Entwurf (Stand 2026-10-21), noch keine Zeile Code. Unabhängig vom grafischen Survey-Editor ([PLAN-3.2.0.md](PLAN-3.2.0.md)) und in beliebiger Reihenfolge dazu umsetzbar.
Die Aufwände sind Schätzungen für eine Person und nicht an einem Prototyp gemessen.

## Ausgangslage

- Seit 3.1.2 unterstützt Ondisos den SurveyJS-Fragetyp `signaturepad` ([SURVEYJS.md](../../redaktion/SURVEYJS.md#unterschriftsfelder)). Die Unterschrift kommt als PNG-Data-URI mit der Anmeldung; `submit.php` nimmt Inline-Daten nur als kleines PNG/JPEG an (`DataFormatter::isImageDataUri()`, höchstens 500 000 Zeichen). Detailansicht und PDF zeigen das Bild, Excel und Mail den Platzhalter „[Unterschrift]“.
- Am Rechner ohne Touchscreen ist das Unterschreiben mit der Maus mühsam. Einige Schulen und Träger bestehen trotzdem auf einer Unterschrift.
- Vorbild ist DocuSeal (https://www.docuseal.com/): Auf Wunsch zeigt das Formular einen QR-Code, der Nutzer unterschreibt auf Smartphone oder Tablet, die Unterschrift erscheint im Formular am Rechner.
- **Rechtlicher Wert:** Eine gemalte Unterschrift ist eine einfache elektronische Signatur, egal auf welchem Gerät gezeichnet. Das Feature verbessert die Bedienung, nicht die Beweiskraft. Ob eine Unterschrift genügt, klärt jede Schule mit Träger oder Datenschutzbeauftragten (Hinweis steht schon in SURVEYJS.md).

## Ziel

Bei einem Unterschriftsfeld kann der Nutzer am Rechner **„Auf dem Smartphone unterschreiben“** wählen: Ein QR-Code öffnet auf dem Smartphone eine Seite mit einem Unterschriftsfeld, die Unterschrift erscheint danach als Vorschau am Rechner und wird nach **Übernehmen** zum Wert des `signaturepad`. Ab da läuft alles wie heute.

**Nicht Ziel:** qualifizierte oder fortgeschrittene Signatur, Unterschrift auf einem fertigen PDF, Versand von Links per Mail oder SMS, Anbindung von DocuSeal selbst.

## Entscheidungen

1. **Der Wert bleibt ein normaler `signaturepad`-Wert.** Das Smartphone liefert nur das PNG; gesetzt wird es im Browser am Rechner (`question.value`). Anmeldung, `submit.php`, Detailansicht, PDF, Export und Mail bleiben unverändert.
2. **Relay über das Backend (Variante B).** Das Smartphone erreicht nur das öffentliche Frontend, die Sitzung liegt aber im Backend (Tabelle mit Ablaufzeit); das Frontend reicht über die signierte API durch.
   Verworfen: Zustand im Frontend (Temp-Dateien bzw. WP-Transients), weil das zwei Implementierungen wären und die Unterschrift auf dem öffentlichen Server läge.
3. **Zwei Geheimnisse:** Ein **Schreib-Token** steht im QR-Code und erlaubt nur das Abliefern einer Unterschrift; ein **Lese-Schlüssel** bleibt im Browser am Rechner und erlaubt nur das Abholen. Wer den QR-Code abfotografiert, kann nichts mitlesen.
4. **Bestätigung am Rechner.** Nichts landet ungesehen im Formular: Der Nutzer sieht die Unterschrift als Vorschau und wählt *Übernehmen* oder *Verwerfen*. Das ist der Schutz gegen fremde Kritzeleien über einen abfotografierten Code.
5. **Schalter in der Formular-Konfiguration, nicht im Survey-JSON.** Neue Option `signature_handoff` (bool, Vorgabe aus) im Reiter *Allgemein*. Eine eigene Survey-Eigenschaft würde der SurveyJS Creator beim Bearbeiten vermutlich verwerfen (nicht geprüft); die Konfiguration übersteht jede Runde.
6. **Clean-Room-Nachbau.** Ondisos steht unter MIT, DocuSeal nach unserem Kenntnisstand unter AGPL-3.0 (vor AP1 prüfen). Übernommen wird nur die Idee, kein Code.
7. **Stack bleibt:** Vanilla JavaScript, Bootstrap 5, keine neue Bibliothek. Den QR-Code erzeugt das Backend mit dem vorhandenen `App\Utils\QrCodeSvg` (bacon-qr-code, seit 3.1.2 für TOTP); die Handy-Seite nutzt SurveyJS selbst (ein Formular mit nur einem `signaturepad`), damit das PNG genau so entsteht wie im Formular.

## Ablauf

```
Rechner (Formular)            Frontend (öffentlich)               Backend (Intranet)
──────────────────            ─────────────────────               ──────────────────
„Auf dem Smartphone            POST …/signature?action=create  ─►  legt Sitzung an:
 unterschreiben“  ──CSRF──►    (signiert, Tenant, form_key)        id, Hash(Schreib-Token), Hash(Lese-Schlüssel),
                                                                   expires_at = jetzt + 10 min
QR-Code + Countdown  ◄──────── id, Lese-Schlüssel, QR-SVG     ◄──  QR-Inhalt: <Frontend>/sign…#s=<id>&t=<Schreib-Token>

Smartphone scannt ───────────► Handy-Seite (sign.php bzw. WP-Route), liest Token aus dem Fragment
Unterschrift, „Senden“ ──────► POST …/signature?action=submit ──►  prüft Token (Hash, Ablauf, einmalig),
                                                                   prüft PNG, speichert, Status „signed“
Rechner fragt alle 2 s ──────► POST …/signature?action=poll   ──►  prüft Lese-Schlüssel; liefert das PNG einmal aus
                                                                   und löscht die Sitzung
Vorschau → „Übernehmen“ setzt question.value (Data-URI); Absenden wie bisher
```

## Sicherheit

- **Tokens:** je mindestens 128 Bit aus `random_bytes()`, in der Datenbank nur als SHA-256-Hash, Vergleich mit `hash_equals()`. Schreib-Token einmalig; nach dem Abholen ist die Sitzung gelöscht. Gültigkeit 10 Minuten (Konstante, siehe offene Fragen).
- **Token im URL-Fragment** (`#s=…&t=…`), nicht im Query-String: Es erscheint weder in Server-Logs noch im `Referer`. Die Handy-Seite liest es per JavaScript und sendet es im Body.
- **Bindung:** Die Sitzung gehört zu Tenant und `form_key`; die Endpunkte filtern nach `tenant_id` wie jede Abfrage. Ein Schreib-Token eines anderen Tenants ist ungültig.
- **PNG-Prüfung:** nur `data:image/png;base64,…` über `isImageDataUri()`, dazu eine eigene, kleinere Obergrenze (Vorschlag 200 KB); wenn GD verfügbar ist, dekodieren und neu kodieren (entfernt Metadaten und Anhängsel, prüft das Bild wirklich). GD-Verfügbarkeit in Docker-Image und Betrieb ohne Docker in AP0 prüfen.
- **Missbrauch als Speicher:** Rate-Limit beim Anlegen von Sitzungen (vorhandener `RateLimiter`; Schlüssel: Client-IP am Frontend, siehe offene Fragen) und eine Obergrenze offener Sitzungen pro Tenant (Vorschlag 200). Abgelaufene Sitzungen werden beim nächsten `create` gelöscht (Muster: `RequestExpungeService`), zusätzlich bei `migrate.php`.
- **CSRF:** `create` und `poll` kommen von der Formularseite und tragen das vorhandene CSRF-Token (`csrf_token.php` bzw. WP-Nonce). `submit` braucht keins, das Schreib-Token ist die Berechtigung.
- **Handy-Seite:** gleiche CSP wie das Formular (`ContentSecurityPolicy`; `connect-src 'self'`, `img-src` mit `data:` genügt), keine personenbezogenen Daten. Gezeigt werden Schulname und Formulartitel, damit der Nutzer erkennt, wofür er unterschreibt.
- **Datenschutz:** Die Unterschrift liegt höchstens bis zum Abholen oder bis zum Ablauf im Backend; kein Dritter ist beteiligt. Audit-Log nur für Fehlversuche (falsches Token, Tenant-Fremdzugriff), nicht für jede Sitzung.

## Randbedingungen aus dem bestehenden Code

- **Zwei Frontends:** Standalone (`frontend/public/`) und WordPress-Plugin. Gemeinsam ist `survey-handler-base.js`; dort kommt die Button-Logik hin. Das Plugin braucht eigene AJAX-Aktionen und eine öffentliche Handy-Seite. Vorbild für den Proxy: `frontend/public/pdf/download.php` und `wordpress-plugin/includes/class-pdf-proxy.php`.
- **Vorschau im Backend** hat eine eigene Kopie von `survey-handler-base.js` (`backend/public/assets/preview/`): dort **kein** Button (die Vorschau sendet nichts). `sync-preview-assets.sh` und die Kopie im Blick behalten.
- **Erreichbarkeit:** Das Smartphone muss die URL des Frontends erreichen. Bei einem Frontend nur im Schulnetz klappt das nur im WLAN der Schule; das gehört in die Doku für die Schul-IT.
- **UI-Texte** über den `MessageService` (Frontend: `messages.json.php`), keine Texte im Code.
- **Signierte API:** neue Endpunkte mit Signaturformat 2 wie `submit.php`; der Tenant kommt wie immer aus `?tenant=<slug>`.

## Arbeitspakete

| AP | Inhalt | Aufwand (Schätzung) |
|---|---|---|
| **AP0** | Klären: Lizenz DocuSeal, GD im Image, Weitergabe der Client-IP vom Frontend fürs Rate-Limit, Rückmeldung von mindestens einer Schule, dass sie es nutzen würde | 0,5 Tage |
| **AP1** | Backend: Migration `signature_handoffs` (`id`, `tenant_id`, `form_key`, `write_hash`, `read_hash`, `png` MEDIUMTEXT NULL, `status`, `created_at`, `expires_at`; Index auf `expires_at`); `SignatureHandoffRepository` (tenant-gefiltert), `SignatureHandoffService` (Tokens, Ablauf, Einmaligkeit, PNG-Prüfung, Aufräumen); Unit-Tests | 1,5 Tage |
| **AP2** | Backend: Endpunkt `api/signature-handoff.php` (`create`, `submit`, `poll`), signiert, Rate-Limit, QR-SVG in der Antwort von `create`; Option `signature_handoff` in `FormConfigValidator`, `FormConfigFormMapper` und Formular-Editor (Reiter *Allgemein*); Auslieferung über `form-config.php` | 1 Tag |
| **AP3** | Frontend Standalone: Relay `public/api/signature.php` (CSRF für `create`/`poll`), Handy-Seite `public/sign.php` (SurveyJS mit einem `signaturepad`, PNG); `BackendApiClient` um die drei Aufrufe erweitern | 1 Tag |
| **AP4** | `survey-handler-base.js`: bei `signaturepad`, Option an und `matchMedia('(pointer: coarse)')` falsch einen Button über `onAfterRenderQuestion` einfügen; Dialog mit QR-Code, Countdown, *Neuen Code erzeugen*, Polling alle 2 s, Vorschau, *Übernehmen*/*Verwerfen* | 1 Tag |
| **AP5** | WordPress-Plugin: AJAX-Aktionen `ondisos_sig_create`/`_submit`/`_poll` (`nopriv`), Handy-Seite als eigene Route (`admin-ajax.php?action=ondisos_sign` oder Rewrite-Regel, in AP0 entscheiden), Nonce | 1 Tag |
| **AP6** | Doku und Abnahme: SURVEYJS.md (Abschnitt Unterschriftsfelder), sicherheit.md, architektur.md (Endpunkte, Tabelle), konfiguration.md, Schul-IT (Erreichbarkeit), Messages, manuelle Tests in ABNAHME.md | 0,5 Tage |

Summe grob **6,5 Tage**. Ein erster nutzbarer Stand ohne Plugin ist AP0 bis AP4.

## Tests

- **Unit:** Token-Erzeugung und -Prüfung (Hash, `hash_equals`, Ablauf, einmalig), Lese-Schlüssel ≠ Schreib-Token, PNG-Prüfung (PNG ja, JPEG/SVG/zu groß nein), Obergrenze offener Sitzungen, Aufräumen abgelaufener Sitzungen, Option `signature_handoff` im `FormConfigValidator`.
- **Integration (MySQL):** Repository tenant-gefiltert, ein Tenant sieht keine fremde Sitzung.
- **Manuell (Abnahme):** Rechner ohne Touch zeigt den Button, Tablet nicht; QR scannen, unterschreiben, Vorschau übernehmen, absenden, Bild in Detailansicht und PDF; Code abgelaufen; zweites Senden mit demselben Code scheitert; Verwerfen und neuer Code; WordPress-Plugin; Vorschau im Backend ohne Button.

## Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Frontend vom Smartphone nicht erreichbar (nur Schulnetz) | Doku für Schul-IT; Button bleibt optional, die Unterschrift mit der Maus funktioniert weiter |
| Missbrauch als anonymer Bildspeicher | Größenlimit, kurze Ablaufzeit, Rate-Limit, Obergrenze pro Tenant, Bild nur einmal abrufbar |
| Fremde Unterschrift über abfotografierten Code | Vorschau und *Übernehmen* am Rechner (Entscheidung 4) |
| Aufwand im WordPress-Plugin unterschätzt | AP5 getrennt; Standalone zuerst ausliefern |
| Erwartung „rechtssichere Unterschrift“ | Klarer Hinweis in SURVEYJS.md und in der Formular-Konfiguration |

## Offene Fragen

1. **Ablaufzeit:** 10 Minuten oder kürzer (5)? Länger erhöht die Angriffsfläche, kürzer ärgert Nutzer, die erst das Handy suchen.
2. **Rate-Limit-Schlüssel:** Das Backend sieht als Client das Frontend. Reicht ein Limit pro Tenant, oder gibt das Frontend die Client-IP signiert weiter?
3. **Handy-Seite im Plugin:** `admin-ajax.php` (einfach, URL unschön) oder Rewrite-Regel (schöne URL, Permalinks neu speichern)?
4. **Mehrere Unterschriftsfelder** in einem Formular (z. B. Schüler und Erziehungsberechtigte): je Feld eine eigene Sitzung (Vorschlag) oder eine Sitzung für alle?
5. **Alternative ohne Aufwand** für Schulen, denen „irgendeine Bestätigung“ genügt: Checkbox mit getipptem Namen. Hat denselben Beweiswert; in SURVEYJS.md als Empfehlung aufnehmen?

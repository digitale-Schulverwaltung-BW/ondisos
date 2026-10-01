# Survey-Editor-Bundle (CodeMirror 6)

Baut `backend/public/assets/codemirror/survey-editor-cm.js`: den Code-Editor (JSON, Zeilennummern, Fehlermarkierung) der Seite
`form_survey.php`. Das fertige Bundle ist eingecheckt; Node.js wird zum **Betrieb nicht** gebraucht, nur zum Neubauen.

```bash
cd backend/tools/survey-editor-bundle
npm ci                 # installiert die in package-lock.json festgelegten Versionen
npm run build          # schreibt dist/survey-editor-cm.js
cp dist/survey-editor-cm.js ../../public/assets/codemirror/
cp node_modules/@codemirror/state/LICENSE ../../public/assets/codemirror/LICENSE-codemirror   # Lizenztext (MIT)
```

- Versionen sind in `package.json` fest eingetragen (kein `^`); Updates bewusst durchführen und das Bundle neu bauen.
- Das Bundle bindet die Pakete `@codemirror/*` ein (MIT, © Marijn Haverbeke und andere). Der Lizenztext liegt neben dem Bundle.
- Fehlt das Bundle, arbeitet `form_survey.php` mit einer normalen Textarea.
- `node_modules/` und `dist/` gehören nicht ins Repository (siehe `.gitignore`).

// Theme editor (theme_edit.php, 3.2.0b): the fields above the JSON (AP4, theme-editor/ui.js) and the "Live-Vorschau"
// button (AP3) with the preview window (theme_preview_live.php). The window checks every text with the server before its
// sandboxed frame applies it; this side only sends what is in the text area (the code editor keeps it current).
// Without JavaScript the fields and the button stay hidden; the JSON alone still works.

import { createLiveLink } from './survey-builder/live-link.js';
import { initThemeFields } from './theme-editor/ui.js';

const area = document.getElementById('survey-text');

const fieldsRoot = document.getElementById('tf-root');
if (fieldsRoot && area) {
    let config = {};
    try { config = JSON.parse(document.getElementById('tf-config')?.textContent || '{}'); } catch (e) { config = {}; }
    window.OndisosThemeFields = initThemeFields({
        root: fieldsRoot,
        area,
        getEditor: () => window.OndisosSurveyEditor?.last,
        texts: config.texts ?? {},
    });
}

const button = document.getElementById('te-live-preview');
if (button && area) {
    // A theme has no elements to pick: clicks in the preview fill in the form.
    createLiveLink({ button, area, onPick: () => {} });
}

// Theme editor (theme_edit.php, 3.2.0b AP3): connects the "Live-Vorschau" button to the preview window
// (theme_preview_live.php). The window checks every text with the server before its sandboxed frame applies it; this
// side only sends what is in the text area (the code editor keeps it current). Without JavaScript the button stays hidden.

import { createLiveLink } from './survey-builder/live-link.js';

const button = document.getElementById('te-live-preview');
const area = document.getElementById('survey-text');
if (button && area) {
    // A theme has no elements to pick: clicks in the preview fill in the form.
    createLiveLink({ button, area, onPick: () => {} });
}

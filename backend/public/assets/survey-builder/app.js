// Entry point of the graphical survey builder on form_survey.php. Everything it needs is on the page: the text area with
// the survey, the configuration (rules of the server, texts, findings) as JSON, and the two tabs.
//
// A page without this script (or with a script that fails) still works: the "JSON" tab and the text area are untouched.

import { createBuilder } from './ui.js';
import { createTextBridge } from './text-bridge.js';

const root = document.getElementById('sb-root');
const area = document.getElementById('survey-text');
const configElement = document.getElementById('builder-config');

if (root && area && configElement) {
    try {
        const config = JSON.parse(configElement.textContent || '{}');
        const bridge = createTextBridge(area, () => window.OndisosSurveyEditor && window.OndisosSurveyEditor.last);
        const builder = createBuilder({ root, bridge, config, page: document.getElementById('se-page') });
        const graphic = document.getElementById('se-tab-graphic');
        const json = document.getElementById('se-tab-json');

        graphic?.addEventListener('shown.bs.tab', () => builder.activate());
        json?.addEventListener('shown.bs.tab', () => builder.deactivate());
        if (graphic?.classList.contains('active')) { builder.activate(); }

        window.OndisosBuilder = builder; // for debugging in the browser console
    } catch (error) {
        root.textContent = String(error && error.message ? error.message : error);
        console.error(error);
    }
}

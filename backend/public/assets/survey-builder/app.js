// Entry point of the graphical survey builder on form_survey.php. Everything it needs is on the page: the text area with
// the survey, the configuration (rules of the server, texts, findings) as JSON, and the two tabs.
//
// A page without this script (or with a script that fails) still works: the "JSON" tab and the text area are untouched.

import { createBuilder } from './ui.js';
import { createTextBridge } from './text-bridge.js';
import { createLiveLink } from './live-link.js';
import { targetOf, pathOf } from './live-targets.js';

const root = document.getElementById('sb-root');
const area = document.getElementById('survey-text');
const configElement = document.getElementById('builder-config');

if (root && area && configElement) {
    try {
        const config = JSON.parse(configElement.textContent || '{}');
        const bridge = createTextBridge(area, () => window.OndisosSurveyEditor && window.OndisosSurveyEditor.last);
        let live = null;
        const builder = createBuilder({
            root, bridge, config, page: document.getElementById('se-page'),
            onSelect: (path, doc) => live?.show(targetOf(doc, path)),
        });
        const graphic = document.getElementById('se-tab-graphic');
        const json = document.getElementById('se-tab-json');

        graphic?.addEventListener('shown.bs.tab', () => builder.activate());
        json?.addEventListener('shown.bs.tab', () => builder.deactivate());
        if (graphic?.classList.contains('active')) { builder.activate(); }

        // Live preview (AP4a): a click in the preview selects the element in the tree (in the "Grafisch" tab).
        const liveButton = document.getElementById('se-live-preview');
        if (liveButton) {
            live = createLiveLink({
                button: liveButton,
                area,
                onPick(target) {
                    const reveal = () => {
                        const { doc } = builder.getState();
                        const path = pathOf(doc, target);
                        if (path) { builder.reveal(path); }
                    };
                    if (graphic && !graphic.classList.contains('active')) {
                        graphic.addEventListener('shown.bs.tab', reveal, { once: true });
                        graphic.click();
                    } else {
                        reveal();
                    }
                },
                onState(state) { liveButton.classList.toggle('active', state === 'connected'); },
            });
        }

        window.OndisosBuilder = builder; // for debugging in the browser console
    } catch (error) {
        root.textContent = String(error && error.message ? error.message : error);
        console.error(error);
    }
}

// Live preview (AP4a), the editor's side: opens the preview window, sends it the text after every change and the
// element selected in the tree, and hands back what was clicked in the preview.
//
// The window (form_preview_live.php) checks every text with the server before its sandboxed frame renders it; the editor
// sends whatever is in the text area. Change detection: the text area always holds the current text (the builder writes
// it, the code editor keeps it up to date without an event), so while a window is connected the link compares it a few
// times per second and sends it once it has been quiet for SEND_DELAY ms. Undo, the JSON tab and the builder all arrive
// the same way.
//
// Who may talk to whom: messages are only accepted from the editor's own origin, from a window this page opened (its
// `opener` is this window, which also holds after the editor was reloaded) and for this form.

import { message, isMessage } from './live-targets.js';

const SEND_DELAY = 300;
const POLL = 150;
const SHOW_DELAY = 120;

/**
 * @param {object} o
 * @param {HTMLElement} o.button     the "Live-Vorschau" button (data-url, data-window, data-form)
 * @param {HTMLTextAreaElement} o.area the survey text
 * @param {(target: object) => void} o.onPick  an element was clicked in the preview
 * @param {(state: string) => void} [o.onState] 'connected' | 'closed' (for the button's look)
 */
export function createLiveLink({ button, area, onPick, onState = () => {} }) {
    const url = button.getAttribute('data-url');
    const name = button.getAttribute('data-window');
    const form = button.getAttribute('data-form');
    let preview = null;   // the window, once it said hello
    let session = null;   // id of the window's current page load: a new one gets the whole state again
    let lastSent = null;
    let lastSeen = area.value;
    let changedAt = 0;
    let showTimer = null;
    let lastTarget = null;

    const connected = () => preview !== null && !preview.closed;
    const post = (type, payload) => { if (connected()) { preview.postMessage(message(type, payload), location.origin); } };

    function open() {
        if (connected()) { preview.focus(); return; }
        // An existing window of this name comes back as it is (no reload); a new one starts blank and is sent to the page.
        const w = window.open('', name, `popup=yes,width=${Math.min(720, screen.availWidth)},height=${screen.availHeight}`);
        if (!w) { return; } // blocked: the button stays, a second click is a user gesture again
        let blank = true;
        try { blank = w.location.href === 'about:blank'; } catch (e) { blank = true; }
        if (blank) { w.location.href = url; }
        w.focus();
    }

    function isOurWindow(source) {
        try { return Boolean(source) && source.opener === window; } catch (e) { return false; }
    }

    window.addEventListener('message', (event) => {
        if (event.origin !== location.origin || !isMessage(event.data)) { return; }
        const data = event.data;
        if (data.type === 'hello') {
            if (data.form !== form || !isOurWindow(event.source)) { return; }
            preview = event.source;
            post('ack', {});
            if (data.session !== session) {
                session = data.session;
                lastSent = null; // a new page load in the window: send everything again
                if (lastTarget) { post('show', { target: lastTarget }); }
            }
            onState('connected');
            return;
        }
        if (event.source !== preview) { return; }
        if (data.type === 'pick' && data.target && typeof data.target === 'object') { onPick(data.target); }
    });

    setInterval(() => {
        if (!connected()) {
            if (preview !== null) { preview = null; session = null; onState('closed'); }
            return;
        }
        const now = Date.now();
        const text = area.value;
        if (text !== lastSeen) { lastSeen = text; changedAt = now; }
        if (text !== lastSent && now - changedAt >= SEND_DELAY) {
            lastSent = text;
            post('text', { text });
        }
    }, POLL);

    button.addEventListener('click', open);
    button.hidden = false;

    return {
        open,
        /** The element selected in the tree; the preview goes there (shortly after, so arrow keys do not flood it). */
        show(target) {
            lastTarget = target;
            clearTimeout(showTimer);
            if (!target) { return; }
            showTimer = setTimeout(() => {
                // the text first, so the preview knows a just-added element
                if (area.value !== lastSent) { lastSent = area.value; post('text', { text: lastSent }); }
                post('show', { target });
            }, SHOW_DELAY);
        },
        isConnected: connected,
    };
}

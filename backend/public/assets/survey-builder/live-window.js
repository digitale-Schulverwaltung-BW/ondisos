// Live preview window (AP4a, form_preview_live.php): between the editor (window.opener) and the sandboxed frame.
//
//   editor ──text──▶ window ──check──▶ server (form_preview_check.php)
//                    window ──render─▶ frame          only texts the server accepted, exactly as checked
//   editor ──show──▶ window ──show───▶ frame          after the text before it is rendered
//   frame  ──pick──▶ window ──pick───▶ editor
//
// The window says hello to the editor every HELLO ms (with an id of this page load), so an editor that was reloaded finds
// it again, and it can tell when the editor is gone. Messages are accepted from the opener only if it is of the same
// origin, from the frame only if they come from the frame's window; both carry the protocol name and version.
// Unreadable text is not sent to the server (typing in the JSON tab); the last good state stays on screen with a notice.

import { message, isMessage } from './live-targets.js';

const HELLO = 1500;
const LOST = 5000;
const CACHE = 12;

const config = JSON.parse(document.getElementById('live-config')?.textContent || '{}');
const texts = config.texts ?? {};
const frame = document.getElementById('lp-frame');
const status = document.getElementById('lp-status');
const notice = document.getElementById('lp-notice');
const pickButton = document.getElementById('lp-pick');
const invisible = document.getElementById('lp-invisible');
const session = Math.random().toString(36).slice(2) + Date.now().toString(36);

const state = {
    heardAt: 0,            // last message from the editor
    frameReady: false,
    wanted: null,          // latest text from the editor
    shown: null,           // text the frame shows (accepted by the server)
    checking: false,
    blockedUntil: 0,       // rate limited
    showAfter: null,       // a "show" that waits for the text before it
    seq: 0,
};
const verdicts = new Map(); // text → verdict, the last CACHE ones (undo/redo come back to known texts)

const fill = (template, vars = {}) => String(template ?? '').replace(/\{\{(\w+)\}\}/g, (m, k) => (k in vars ? String(vars[k]) : m));

function setStatus(key, kind = 'secondary') {
    status.textContent = texts[key] ?? key;
    status.className = `badge text-bg-${kind}`;
}

/** kind: 'text' (about the current text) or 'show' (about the element to show; cleared by the next one). */
function setNotice(text, list = [], kind = 'text') {
    if (!text) { notice.hidden = true; notice.replaceChildren(); delete notice.dataset.kind; return; }
    notice.dataset.kind = kind;
    notice.replaceChildren(document.createTextNode(text));
    if (list.length) {
        const ul = document.createElement('ul');
        for (const item of list) {
            const li = document.createElement('li');
            li.textContent = item;
            ul.append(li);
        }
        notice.append(ul);
    }
    notice.hidden = false;
}

const toFrame = (type, payload = {}) => {
    // The frame is sandboxed (opaque origin): it cannot be addressed by origin. Only this window holds a reference to it.
    if (frame.contentWindow) { frame.contentWindow.postMessage(message(type, payload), '*'); }
};
const toEditor = (type, payload = {}) => {
    const editor = window.opener;
    if (editor && !editor.closed) { editor.postMessage(message(type, payload), location.origin); }
};

function sendOptions() {
    toFrame('options', { pick: pickButton.getAttribute('aria-pressed') === 'true', invisible: invisible.checked });
}

// ---- checking and rendering ------------------------------------------------------------------------------------------

async function verdictFor(text) {
    if (verdicts.has(text)) { return verdicts.get(text); }
    const body = new URLSearchParams({ csrf_token: config.csrf ?? '', form: config.form ?? '', survey_text: text });
    let response;
    try {
        response = await fetch(config.checkUrl, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    } catch (e) {
        return { status: 'failed' };
    }
    let data = null;
    try { data = await response.json(); } catch (e) { data = null; }
    if (response.status === 429) { return { status: 'rate_limited', retry: Number(data?.retry_after) || 10 }; }
    if (!data || typeof data !== 'object') { return { status: 'session' }; } // e.g. the login page after a redirect
    if (response.status === 403) { return { status: 'session' }; }
    if (!response.ok || data.ok !== true) { return { status: 'failed' }; }
    const verdict = { status: data.status === 'ok' ? 'ok' : 'invalid', errors: Array.isArray(data.errors) ? data.errors : [] };
    verdicts.set(text, verdict);
    if (verdicts.size > CACHE) { verdicts.delete(verdicts.keys().next().value); }
    return verdict;
}

async function pump() {
    if (state.checking || !state.frameReady) { return; }
    const text = state.wanted;
    if (text === null) { return; }
    if (text === state.shown) { // back to the state on screen (undo, a fixed typo): nothing to check or render
        if (notice.dataset.kind === 'text') { setNotice(''); }
        flushShow();
        return;
    }
    const wait = state.blockedUntil - Date.now();
    if (wait > 0) { setTimeout(pump, wait); return; }

    try { JSON.parse(text); } catch (e) {
        setNotice(texts.unreadable);
        return; // nothing goes to the server until the text is readable again
    }

    state.checking = true;
    const verdict = await verdictFor(text);
    state.checking = false;

    if (text !== state.wanted) { pump(); return; } // a newer text came in meanwhile: check that one
    if (verdict.status === 'ok') {
        state.seq += 1;
        toFrame('render', { text, seq: state.seq });
        state.shown = text; // the frame answers "rendered"; a failure there brings the notice
        setNotice('');
        flushShow();
        return;
    }
    if (verdict.status === 'invalid') {
        setNotice(texts.invalid, verdict.errors.map((e) => (e.path ? `${e.path}: ` : '') + e.message));
    } else if (verdict.status === 'rate_limited') {
        state.blockedUntil = Date.now() + verdict.retry * 1000;
        setNotice(fill(texts.rate_limited, { seconds: verdict.retry }));
        setTimeout(pump, verdict.retry * 1000);
    } else {
        setNotice(texts[verdict.status] ?? texts.failed);
    }
}

function flushShow() {
    if (state.showAfter && state.shown !== null && state.wanted === state.shown) {
        toFrame('show', { target: state.showAfter });
        state.showAfter = null;
    }
}

// ---- messages ------------------------------------------------------------------------------------------------------

window.addEventListener('message', (event) => {
    if (!isMessage(event.data)) { return; }
    const data = event.data;

    if (event.source === frame.contentWindow) {
        if (data.type === 'ready') {
            state.frameReady = true;
            state.shown = null; // a new frame document shows nothing yet
            sendOptions();
            pump();
        } else if (data.type === 'rendered' && data.ok === false) {
            setNotice(texts.render_error, [String(data.error ?? '')]);
        } else if (data.type === 'pick' && data.target && typeof data.target === 'object') {
            toEditor('pick', { target: data.target });
        } else if (data.type === 'pick-cancel') {
            setPicking(false);
        } else if (data.type === 'shown') {
            if (data.ok === false) { setNotice(texts[data.reason] ?? texts.missing, [], 'show'); }
            else if (notice.dataset.kind === 'show') { setNotice(''); }
        }
        return;
    }

    if (event.source !== window.opener || event.origin !== location.origin) { return; }
    state.heardAt = Date.now();
    setStatus('connected', 'success');
    if (data.type === 'text' && typeof data.text === 'string') {
        state.wanted = data.text;
        pump();
    } else if (data.type === 'show' && data.target && typeof data.target === 'object') {
        state.showAfter = data.target;
        flushShow();
    }
});

function hello() {
    if (!window.opener || window.opener.closed) {
        setStatus('disconnected', 'warning');
        return;
    }
    toEditor('hello', { form: config.form, session });
    if (state.heardAt > 0 && Date.now() - state.heardAt > LOST) { setStatus('disconnected', 'warning'); }
}
setInterval(hello, HELLO);
hello();

// ---- toolbar -------------------------------------------------------------------------------------------------------

function setPicking(on) {
    pickButton.setAttribute('aria-pressed', on ? 'true' : 'false');
    sendOptions();
}
pickButton.addEventListener('click', () => setPicking(pickButton.getAttribute('aria-pressed') !== 'true'));
invisible.addEventListener('change', sendOptions);

const widths = document.getElementById('lp-width');
widths?.querySelectorAll('button').forEach((button) => {
    button.addEventListener('click', () => {
        widths.querySelectorAll('button').forEach((b) => b.classList.remove('active'));
        button.classList.add('active');
        const w = button.getAttribute('data-width');
        frame.style.width = w.includes('%') ? w : `${w}px`;
    });
});

window.OndisosLivePreview = { state, verdicts }; // for debugging in the browser console

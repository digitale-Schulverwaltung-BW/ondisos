// AP0 spike: outline + title editing on the survey text. Dev-only (see index.html).
import { loadModel, outline, setTitle, formatPath } from '../../public/assets/survey-builder/model.js';

const SURVEYS = ['bs', 'gs', 'vabo', 'zq', 'ausbildernachmittag', 'pdf', 'prefill'];
const $ = (id) => document.getElementById(id);
let original = '';

function status(msg, isError = false) {
    $('status').textContent = msg;
    $('status').className = isError ? 'error' : '';
}

/** Lines that differ from the loaded text (same number of lines), otherwise only the delta. */
function diffSummary(now) {
    const a = original.split('\n');
    const b = now.split('\n');
    if (a.length !== b.length) { return `${b.length - a.length >= 0 ? '+' : ''}${b.length - a.length} Zeilen`; }
    const n = a.reduce((c, line, i) => c + (line === b[i] ? 0 : 1), 0);
    return n === 0 ? 'unverändert' : `${n} Zeile${n === 1 ? '' : 'n'} geändert`;
}

function render() {
    const text = $('text').value;
    const m = loadModel(text);
    $('tree').replaceChildren();
    $('warn').textContent = '';
    $('size').textContent = `${(new Blob([text]).size / 1024).toFixed(1)} KB (Limit des Servers: 512 KB)`;
    if (!m.ok) {
        status(`Kein lesbares JSON: ${m.error.message} (Zeile ${m.error.line}, Spalte ${m.error.column}). Die grafische Ansicht ist deaktiviert.`, true);
        return;
    }
    $('warn').textContent = m.warnings.join(' ');
    status(`Gelesen: ${diffSummary(text)} gegenüber dem geladenen Text.`);
    let doc = m.doc;
    const tree = $('tree');
    for (const page of outline(doc)) {
        const head = document.createElement('div');
        head.className = 'page';
        head.textContent = page.implicit ? 'Seite (implizit)' : `Seite ${page.name || formatPath(page.path)}`;
        tree.append(head);
        tree.append(list(page.elements));
    }

    function list(items) {
        const ul = document.createElement('ul');
        for (const e of items) {
            const li = document.createElement('li');
            const row = document.createElement('div');
            row.className = 'el';
            const type = document.createElement('span');
            type.className = 'type';
            type.textContent = e.type;
            const input = document.createElement('input');
            input.value = e.title;
            input.placeholder = e.name || '(ohne Namen)';
            input.setAttribute('aria-label', `Titel von ${e.name || e.type}`);
            input.addEventListener('change', () => {
                // One change = one splice into the text; everything else stays byte for byte.
                doc = setTitle(doc, e.path, input.value);
                $('text').value = doc.text;
                render();
            });
            row.append(type, input);
            li.append(row);
            if (e.children.length) { li.append(list(e.children)); }
            ul.append(li);
        }
        return ul;
    }
}

$('pick').append(...SURVEYS.map((s) => new Option(s, s)));
$('pick').addEventListener('change', load);
$('text').addEventListener('input', render);

async function load() {
    const res = await fetch(`../../../frontend/surveys/${$('pick').value}.json`);
    if (!res.ok) { status(`Konnte ${$('pick').value}.json nicht laden (HTTP ${res.status}). Server im Repository-Stamm gestartet?`, true); return; }
    original = await res.text();
    $('text').value = original;
    render();
}
load();

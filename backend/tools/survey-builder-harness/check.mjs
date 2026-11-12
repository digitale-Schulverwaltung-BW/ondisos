// Browser check of the graphical survey builder (development tool, not part of the product or the pipeline).
//
//   node backend/tools/survey-builder-harness/check.mjs [screenshot-dir]
//
// Needs: PHP with `composer install` in backend/, Node 22+, the `playwright` package and a Chromium
// (PLAYWRIGHT_BROWSERS_PATH, or CHROMIUM_PATH for the executable). It renders the editor tabs of form_survey.php with
// render.php for several surveys (the real ones from frontend/surveys plus small odd ones), serves them and drives the
// builder like a person would: edit, rename, add, move, delete, keyboard, tab switch, broken JSON, findings, questions in
// answers.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { readFileSync, writeFileSync, mkdtempSync, mkdirSync, existsSync, statSync, createReadStream } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, extname, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../../..');
const require = createRequire(process.env.PLAYWRIGHT_MODULES ?? '/opt/node22/lib/node_modules/');
const { chromium } = require('playwright');
const shots = process.argv[2];
if (shots) { mkdirSync(shots, { recursive: true }); }

// ---- fixtures ------------------------------------------------------------------------------------------------------
const root = mkdtempSync(join(tmpdir(), 'sb-harness-'));
const write = (name, content) => { const f = join(root, name); writeFileSync(f, content); return f; };
const render = (name, survey, report, env = {}) => execFileSync('php', [join(here, 'render.php'), survey, join(root, name), ...(report ? [report] : [])], { stdio: 'pipe', env: { ...process.env, ...env } });
const surveys = (n) => join(repo, 'frontend/surveys', `${n}.json`);

const odd = write('odd.json', JSON.stringify({
    title: 'Odd',
    elements: [
        { type: 'text', name: 'Name', title: { de: 'DE-Name', default: 'Name', fr: 'Nom' }, isRequired: true, 'x-custom': [1, 2] },
        { type: 'matrix', name: 'm', rows: ['a', 'b'], columns: ['1', '2'] },
        { type: 'dropdown', name: 'land', choices: [{ value: 'a', text: 'A', visibleIf: '{Name} notempty' }, 'b'] },
        { type: 'html', name: 'h', html: '<p>Hallo</p>' },
        { type: 'comment', name: 'k', rows: 3, placeholder: '' },
    ],
}));
const nested = write('nested.json', JSON.stringify({
    pages: [{ name: 'p1', elements: [
        { type: 'radiogroup', name: 'form', title: 'Unterrichtsform', choices: [
            { value: 'vz', text: 'Vollzeit', elements: [{ type: 'text', name: 'start_vz', title: 'Beginn Vollzeit' }] },
            'tz',
            'abend',
        ] },
        { type: 'checkbox', name: 'teile', title: 'Teile', choices: ['I', 'II'] },
        { type: 'dropdown', name: 'land', title: 'Land', choices: ['a', 'b'] },
        { type: 'text', name: 'other', title: 'Sonstiges' },
    ] }],
}, null, 2));
const liveSurvey = write('live.json', JSON.stringify({
    pages: [
        { name: 'p1', title: 'Eins', elements: [
            { type: 'radiogroup', name: 'form', title: 'Unterrichtsform', choices: [
                { value: 'vz', text: 'Vollzeit', elements: [{ type: 'text', name: 'start_vz', title: 'Beginn Vollzeit' }] },
                'tz',
            ] },
            { type: 'panel', name: 'grp', title: 'Gruppe', elements: [{ type: 'text', name: 'inner', title: 'Innen' }] },
            { type: 'text', name: 'cond', title: 'Bedingt', visibleIf: "{form} = 'tz'" },
        ] },
        { name: 'p2', title: 'Zwei', elements: [{ type: 'text', name: 'later', title: 'Später' }] },
    ],
}, null, 2));
const empty = write('empty.json', '');
const broken = write('broken.json', '{"pages": [,]}');
const reportBroken = write('report-broken.json', JSON.stringify({ valid: false, errors: [{ path: '', message: 'Allgemeiner Fehler' }], warnings: [] }));
const reportWarn = write('report-warn.json', JSON.stringify({
    valid: true, errors: [],
    warnings: [{ path: 'pages[0].elements[1].visibleIf', message: 'Unbekannte Funktion foo() im Ausdruck' }, { path: '', message: 'Hinweis ohne Pfad' }],
}));
render('gs', surveys('gs'));
render('ap4', surveys('gs'));
render('bs', surveys('bs'));
render('vabo', surveys('vabo'));
render('odd', odd);
render('nested', nested);
render('empty', empty);
render('broken', broken, reportBroken);
render('warn', surveys('ausbildernachmittag'), reportWarn);
render('nocm', surveys('ausbildernachmittag'), null, { SB_NO_CODEMIRROR: '1' });
render('live', liveSurvey, null, { SB_LIVE: '1' });

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.woff2': 'font/woff2' };
// the backend's policy (ContentSecurityPolicy::policy) and the sandbox policy of form_preview_frame.php, nonce "harness"
const ADMIN_CSP = "default-src 'self'; script-src 'self' 'nonce-abc'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";
const FRAME_CSP = "sandbox allow-scripts; default-src 'none'; script-src 'nonce-harness' 'unsafe-eval'; style-src * 'unsafe-inline' data:; font-src data:; img-src * data: blob:; connect-src *; frame-ancestors 'self'";
let liveChecks = 0;
const server = createServer((req, res) => {
    const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    if (req.method === 'POST' && path.endsWith('/check')) { // form_preview_check.php: the server's validator decides
        let body = '';
        req.on('data', (c) => { body += c; });
        req.on('end', () => {
            const form = new URLSearchParams(body);
            res.setHeader('content-type', 'application/json');
            if (form.get('csrf_token') !== 'harness-token') { res.writeHead(403); res.end('{"ok":false,"error":"csrf"}'); return; }
            liveChecks += 1;
            const v = serverSays(form.get('survey_text') ?? '');
            res.writeHead(200);
            res.end(JSON.stringify({ ok: true, status: v.valid ? 'ok' : 'invalid', errors: v.errors.slice(0, 5).map((e) => ({ path: e.path, message: e.message })) }));
        });
        return;
    }
    const file = resolve(root, `.${path}`);
    if (!file.startsWith(root) || !existsSync(file) || !statSync(file).isFile()) { res.writeHead(404); res.end(); return; }
    const headers = { 'content-type': MIME[extname(file)] ?? 'application/octet-stream' };
    if (path.startsWith('/live/') && path.endsWith('frame.html')) { headers['content-security-policy'] = FRAME_CSP; }
    else if (path.startsWith('/live/') && path.endsWith('.html')) { headers['content-security-policy'] = ADMIN_CSP; }
    res.writeHead(200, headers);
    createReadStream(file).pipe(res);
});
await new Promise((ok) => server.listen(0, '127.0.0.1', ok));
const base = `http://127.0.0.1:${server.address().port}`;

// ---- helpers ---------------------------------------------------------------------------------------------------
const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
const results = [];
const check = (name, ok, extra = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? `  -> ${extra}` : ''}`); };
let errors = [];
let dialogs = [];

async function open(name, viewport = { width: 1400, height: 1000 }) {
    const page = await browser.newPage({ viewport });
    errors = [];
    dialogs = [];
    page.on('console', (m) => { if (m.type() === 'error' && !/404/.test(m.text())) { errors.push(m.text().slice(0, 200)); } });
    page.on('pageerror', (e) => errors.push(`pageerror: ${e.message.slice(0, 300)}`));
    page.on('dialog', (d) => { dialogs.push(d.message().slice(0, 160)); d.accept(); });
    await page.goto(`${base}/${name}/index.html`);
    await page.waitForTimeout(900);
    return page;
}
const text = (p) => p.inputValue('#survey-text');
/** What the server's SurveyValidator says about a text. */
const serverSays = (t) => JSON.parse(execFileSync('php', [join(here, 'validate.php')], { input: t, encoding: 'utf8' }));
const survey = async (p) => JSON.parse(await text(p));
const lines = (a, c) => { const x = a.split('\n'); const y = c.split('\n'); return x.length !== y.length ? `lines ${x.length}->${y.length}` : String(x.filter((l, i) => l !== y[i]).length); };
const allElements = (s) => (s.pages ?? [{ elements: s.elements }]).flatMap((pg) => pg.elements).flatMap(function f(e) { return [e, ...(e.elements ?? []).flatMap(f)]; });
const submitted = (p) => p.evaluate(() => location.hash === '#submitted');
const selectedIndex = (p) => p.evaluate(() => [...document.querySelectorAll('.sb-item')].findIndex((e) => e.classList.contains('sb-selected')));

// ---- gs: editing, Enter, rename, add, move, delete, keyboard, tabs, broken JSON, undo --------------------------------
{
    const original = readFileSync(surveys('gs'), 'utf8');
    const p = await open('gs');
    check('gs: the text area holds the file', (await text(p)).trim() === original.trim());
    check('gs: the builder is the active tab, in full width', await p.$eval('#se-tab-graphic', (e) => e.classList.contains('active')) && (await p.$eval('#se-page', (e) => e.className)).includes('container-fluid'));

    await p.locator('.sb-item', { hasText: 'Familienname' }).first().click();
    check('gs: selecting shows the properties', (await p.textContent('.sb-props h2')) === 'Textfeld');

    const before = await text(p);
    const title = p.locator('[data-prop="title"] input');
    await title.fill('Nachname (geändert)');
    await title.press('Enter');
    await p.waitForTimeout(300);
    const after = await text(p);
    check('gs: Enter commits the title and does not submit the form', after.includes('Nachname (geändert)') && !(await submitted(p)));
    check('gs: one edit changes exactly one line', lines(before, after) === '1', lines(before, after));
    check('gs: the tree label follows, the focus stays in the field', await p.locator('.sb-item', { hasText: 'Nachname (geändert)' }).count() === 1
        && await p.evaluate(() => document.activeElement?.closest('[data-prop]')?.getAttribute('data-prop') === 'title'));

    const nameOf = async () => p.locator('[data-prop="name"] input').inputValue();
    const target = await nameOf();
    const req = p.locator('[data-prop="isRequired"] input');
    const had = allElements(await survey(p)).find((e) => e.name === target).isRequired === true;
    await req.click();
    await p.waitForTimeout(200);
    const now = allElements(await survey(p)).find((e) => e.name === target).isRequired === true;
    check('gs: the checkbox changes isRequired in the text', now === !had, `${had} -> ${now}`);

    await p.selectOption('[data-prop="inputType"] select', 'email');
    await p.waitForTimeout(200);
    check('gs: a select writes its value', (await text(p)).includes('"inputType": "email"'));

    const name = p.locator('[data-prop="name"] input');
    const stored = await name.inputValue();
    await name.fill('Vorname');
    await name.press('Enter');
    await p.waitForTimeout(200);
    check('gs: a duplicate name is refused inline and the field goes back', /schon verwendet/.test(await p.textContent('[data-prop="name"] .sb-error')) && (await name.inputValue()) === stored && dialogs.length === 0);
    await name.fill('_fieldTypes');
    await name.press('Enter');
    await p.waitForTimeout(150);
    check('gs: a reserved name is refused', /reserviert/.test(await p.textContent('[data-prop="name"] .sb-error')));

    const refName = (original.match(/\{([A-Za-z0-9_]+)\}/) || [])[1];
    const rows = await p.locator('.sb-item').count();
    let found = false;
    for (let i = 0; i < rows && !found; i++) {
        await p.locator('.sb-item').nth(i).click();
        found = (await p.locator('[data-prop="name"] input').inputValue().catch(() => '')) === refName;
    }
    dialogs = [];
    const refs = (original.match(new RegExp(`\\{${refName}\\}`, 'g')) || []).length;
    const nameInput = p.locator('[data-prop="name"] input');
    await nameInput.fill('Neuer_Name_X');
    await nameInput.press('Enter');
    await p.waitForTimeout(300);
    const renamed = await text(p);
    check('gs: renaming a field asks first (a new field, not just a new name)', found && dialogs.length === 1 && /neues Feld/.test(dialogs[0]), dialogs[0]?.slice(0, 60));
    check('gs: and the references follow', renamed.includes('"name": "Neuer_Name_X"') && !renamed.includes(`{${refName}}`) && renamed.includes('{Neuer_Name_X}'), `${refs} references`);

    const rows0 = await p.locator('.sb-item').count();
    await p.click('.sb-toolbar .dropdown-toggle');
    await p.waitForSelector('.sb-palette.show');
    await p.click('.sb-palette [data-type="dropdown"]');
    await p.waitForTimeout(300);
    check('gs: adding from the palette: one more row, selected, with three answers, form not submitted',
        (await p.locator('.sb-item').count()) === rows0 + 1 && (await p.textContent('.sb-props h2')) === 'Dropdown'
        && (await p.inputValue('[data-prop="choices"] textarea')).split('\n').length === 3 && !(await submitted(p)));
    const added = p.locator('[data-prop="choices"] textarea');
    await added.fill('Antwort 1\nZweite Antwort\nAntwort 3\nAntwort 4');
    await added.blur();
    await p.waitForTimeout(250);
    check('gs: the list of answers is written', allElements(await survey(p)).some((e) => e.type === 'dropdown' && JSON.stringify(e.choices) === '["Antwort 1","Zweite Antwort","Antwort 3","Antwort 4"]'));

    await p.locator('.sb-item.sb-selected').focus();
    const i0 = await selectedIndex(p);
    await p.keyboard.press('Alt+ArrowUp');
    await p.waitForTimeout(250);
    check('gs: Alt+ArrowUp moves the element, keeps the focus, announces it', (await selectedIndex(p)) === i0 - 1
        && await p.evaluate(() => document.activeElement?.classList.contains('sb-selected')) && /Verschoben/.test(await p.textContent('[aria-live]')));
    await p.keyboard.press('ArrowDown');
    await p.waitForTimeout(150);
    check('gs: ArrowDown moves the selection and the properties follow', (await selectedIndex(p)) === i0 && (await p.textContent('.sb-props h2')).length > 0);
    await p.keyboard.press('ArrowUp');
    dialogs = [];
    const c0 = await p.locator('.sb-item').count();
    await p.keyboard.press('Delete');
    await p.waitForTimeout(300);
    check('gs: Delete asks first, then removes', dialogs.length === 1 && (await p.locator('.sb-item').count()) === c0 - 1, dialogs[0]);
    await p.keyboard.press('Enter');
    check('gs: Enter goes to the properties', await p.evaluate(() => !!document.activeElement?.closest('.sb-props')));
    await p.keyboard.press('Escape');
    check('gs: Escape comes back to the list', await p.evaluate(() => !!document.activeElement?.classList.contains('sb-item')));

    const verdict = serverSays(await text(p));
    check('gs: after all of that the server accepts the survey', verdict.valid, JSON.stringify(verdict.errors).slice(0, 200));

    // undo: the code editor takes back what the builder did
    await p.click('#se-tab-json');
    await p.waitForTimeout(500);
    const withChange = await text(p);
    check('gs: the code editor holds the builder text and the page is narrow again', (await p.evaluate(() => window.OndisosSurveyEditor.last.view.state.doc.toString())) === withChange && !(await p.$eval('#se-page', (e) => e.className)).includes('container-fluid'));
    await p.evaluate(() => window.OndisosSurveyEditor.last.view.focus());
    await p.keyboard.press('ControlOrMeta+z');
    await p.waitForTimeout(200);
    check('gs: Ctrl+Z in the JSON tab takes back the last builder change', (await text(p)) !== withChange, `${lines(withChange, await text(p))}`);
    await p.keyboard.press('Control+y');
    await p.waitForTimeout(200);

    // broken JSON and back
    await p.evaluate(() => window.OndisosSurveyEditor.last.view.dispatch({ changes: { from: 0, to: 1, insert: '[' } }));
    await p.click('#se-tab-graphic');
    await p.waitForTimeout(500);
    check('gs: broken JSON: the builder explains and offers the JSON tab', /nicht lesbar/.test(await p.textContent('.sb-alerts')) && await p.locator('.sb-alerts button').count() >= 1);
    await p.click('.sb-alerts button');
    await p.waitForTimeout(400);
    check('gs: the button opens the JSON tab', await p.$eval('#se-tab-json', (e) => e.classList.contains('active')));
    await p.evaluate(() => window.OndisosSurveyEditor.last.view.dispatch({ changes: { from: 0, to: 1, insert: '{' } }));
    await p.click('#se-tab-graphic');
    await p.waitForTimeout(500);
    check('gs: fixed JSON: the builder is back', await p.locator('.sb-item').count() > 10);
    if (shots) { await p.screenshot({ path: join(shots, 'builder-gs.png') }); }
    check('gs: no console errors', errors.length === 0, errors.join(' | '));
    await p.close();
}

// ---- bs: hand-formatted, compact lists ----------------------------------------------------------------------------
{
    const original = readFileSync(surveys('bs'), 'utf8');
    const p = await open('bs');
    check('bs: all pages are there', (await p.locator('.sb-page').count()) === 5);
    await p.locator('.sb-item', { hasText: 'Hinweistext' }).first().click();
    check('bs: the html control names the allowed tags', /Erlaubt sind nur diese Elemente: <a>/.test(await p.textContent('[data-prop="html"]')));
    const dds = p.locator('.sb-item', { hasText: 'Dropdown' });
    let best = 0;
    let bestN = 0;
    for (let i = 0; i < await dds.count(); i++) {
        await dds.nth(i).click();
        const k = (await p.inputValue('[data-prop="choices"] textarea')).split(String.fromCharCode(10)).length;
        if (k > bestN) { best = i; bestN = k; }
    }
    await dds.nth(best).click();
    const area = p.locator('[data-prop="choices"] textarea');
    const entries = (await area.inputValue()).split('\n');
    entries[2] += ' (geändert)';
    const before = await text(p);
    await area.fill(entries.join('\n'));
    await area.blur();
    await p.waitForTimeout(300);
    check(`bs: editing 1 of ${entries.length} answers changes exactly one line`, lines(before, await text(p)) === '1', lines(before, await text(p)));
    entries.push('NEU | Neues Land');
    const b2 = await text(p);
    await area.fill(entries.join('\n'));
    await area.blur();
    await p.waitForTimeout(300);
    const t2 = await text(p);
    check('bs: a new answer is one new compact line, in the style of the list', lines(b2, t2) === `lines ${b2.split('\n').length}->${b2.split('\n').length + 1}` && t2.includes('{"value": "NEU", "text": "Neues Land"}'));
    check('bs: nothing else changed', t2.length - original.length < 120, `+${t2.length - original.length} bytes`);
    await p.close();
}

// ---- odd: no pages, unknown type, languages, advanced answers -------------------------------------------------------
{
    const p = await open('odd');
    check('odd: a survey without pages shows one implicit page that explains itself', (await p.locator('.sb-page').count()) === 1 && (await p.locator('.sb-item.sb-page').click(), /direkt im Dokument/.test(await p.textContent('.sb-props'))));
    await p.locator('.sb-item', { hasText: 'Name' }).first().click();
    check('odd: other languages are named, not edited', /Weitere Sprachen bleiben unverändert: de, fr/.test(await p.textContent('[data-prop="title"]')));
    const tin = p.locator('[data-prop="title"] input');
    await tin.fill('Vorname');
    await tin.press('Enter');
    await p.waitForTimeout(250);
    const s1 = await survey(p);
    check('odd: only "default" of the localized title changes; unknown properties survive and are listed', JSON.stringify(s1.elements[0].title) === JSON.stringify({ de: 'DE-Name', default: 'Vorname', fr: 'Nom' })
        && JSON.stringify(s1.elements[0]['x-custom']) === '[1,2]' && /x-custom/.test(await p.textContent('.sb-props')));
    await p.locator('.sb-item', { hasText: 'matrix' }).first().click();
    check('odd: an unknown type is listed by its raw name, without controls, with an explanation',
        (await p.textContent('.sb-props h2')) === 'matrix' && (await p.locator('.sb-props input, .sb-props textarea, .sb-props select').count()) === 0 && /nicht bearbeitet/.test(await p.textContent('.sb-props')));
    const m0 = (await survey(p)).elements[1];
    await p.locator('.sb-item.sb-selected').focus();
    await p.keyboard.press('Alt+ArrowDown');
    await p.waitForTimeout(250);
    const moved = (await survey(p)).elements[2];
    check('odd: the unknown element can be moved and its content is the same', moved.type === 'matrix' && JSON.stringify(moved) === JSON.stringify(m0));
    await p.locator('.sb-item', { hasText: 'Dropdown' }).first().click();
    const answers = p.locator('[data-prop="choices"] textarea');
    check('odd: answers with conditions are editable, with an explanation', await answers.isEditable() && /weitere Einstellungen/.test(await p.textContent('[data-prop="choices"]')));
    await answers.fill('a | A (neu)\nb');
    await answers.blur();
    await p.waitForTimeout(250);
    check('odd: a changed line keeps the condition of its answer', JSON.stringify(allElements(await survey(p)).find((e) => e.name === 'land').choices[0]) === '{"value":"a","text":"A (neu)","visibleIf":"{Name} notempty"}');
    await p.locator('.sb-item', { hasText: 'Mehrzeiliger Text' }).first().click();
    check('odd: an empty placeholder stays empty (it is not the same as none)', allElements(await survey(p)).find((e) => e.name === 'k').placeholder === '');
    const rows = p.locator('[data-prop="rows"] input');
    await rows.fill('0');
    await rows.press('Enter');
    await p.waitForTimeout(150);
    check('odd: an out-of-range number is refused with the right limits, the field goes back', /zwischen 1 und 50/.test(await p.textContent('[data-prop="rows"] .sb-error')) && (await rows.inputValue()) === '3', await p.textContent('[data-prop="rows"] .sb-error'));
    await rows.fill('7');
    await rows.press('Enter');
    await p.waitForTimeout(150);
    check('odd: a valid number is written', allElements(await survey(p)).find((e) => e.name === 'k').rows === 7);
    await rows.fill('');
    await rows.press('Enter');
    await p.waitForTimeout(150);
    check('odd: an empty number removes the property', !('rows' in allElements(await survey(p)).find((e) => e.name === 'k')));
    await p.locator('.sb-item', { hasText: 'Hallo' }).first().click();
    await p.locator('[data-prop="html"] textarea').fill('<p>x</p><script>alert(1)</script>');
    check('odd: a disallowed HTML tag is flagged while typing', /Nicht erlaubt: <script>/.test(await p.textContent('[data-prop="html"]')));
    await p.click('.sb-toolbar >> text=Seite hinzufügen');
    await p.waitForTimeout(200);
    check('odd: a page cannot be added where there are none, and it says why', /keine Seiten/.test(await p.textContent('.sb-alerts')));
    check('odd: no console errors', errors.length === 0, errors.join(' | '));
    await p.close();
}

// ---- empty text, report, pages, narrow ---------------------------------------------------------------------------
{
    const p = await open('empty');
    check('empty: offers an empty survey', /noch keine Survey/.test(await p.textContent('.sb-alerts')));
    await p.click('.sb-alerts >> text=Leere Survey anlegen');
    await p.waitForTimeout(300);
    const created = await survey(p);
    check('empty: one page with an empty list', created.pages.length === 1 && created.pages[0].elements.length === 0);
    await p.click('.sb-toolbar .dropdown-toggle');
    await p.click('.sb-palette [data-type="text"]');
    await p.waitForTimeout(250);
    check('empty: the first name keeps away from a published one (question1 is taken in the harness)', (await survey(p)).pages[0].elements[0].name === 'question2');
    await p.close();
}
{
    const p = await open('broken');
    check('report: errors start the page in the JSON tab', await p.$eval('#se-tab-json', (e) => e.classList.contains('active')));
    await p.close();
    const q = await open('warn');
    check('report: a valid report starts in the builder', await q.$eval('#se-tab-graphic', (e) => e.classList.contains('active')));
    check('report: the finding marks its element in the tree', await q.locator('.sb-item.sb-flagged').count() === 1 && /foo\(\)/.test(await q.locator('.sb-item.sb-flagged').getAttribute('title')));
    check('report: a finding without a path is shown above', /Hinweis ohne Pfad/.test(await q.textContent('.sb-alerts')));
    await q.locator('.sb-item.sb-flagged').click();
    check('report: the element lists its findings; they are not yet stale', /foo\(\)/.test(await q.textContent('.sb-findings')) && await q.locator('.sb-findings [data-sb-stale]').isHidden());
    const t = q.locator('[data-prop="title"] input');
    await t.fill('Anders');
    await t.press('Enter');
    await q.waitForTimeout(200);
    check('report: after an edit the findings are marked as out of date', await q.locator('.sb-findings [data-sb-stale]').isVisible() && /Stand der letzten Prüfung/.test(await q.textContent('.sb-alerts')));
    await q.close();
}
{
    const p = await open('vabo');
    const pages0 = await p.locator('.sb-page').count();
    await p.locator('.sb-page').nth(1).click();
    await p.click('.sb-toolbar >> text=Seite hinzufügen');
    await p.waitForTimeout(250);
    const pj = (await survey(p)).pages[2];
    check('pages: add inserts after the selected page, selects it, names it', (await p.locator('.sb-page').count()) === pages0 + 1 && await p.$eval('.sb-page.sb-selected', (e) => e.getAttribute('data-path')) === '["pages",2]' && /^page\d+$/.test(pj.name) && pj.elements.length === 0);
    await p.locator('.sb-page.sb-selected').focus();
    await p.keyboard.press('Alt+ArrowUp');
    await p.waitForTimeout(250);
    check('pages: Alt+ArrowUp moves the page and the selection follows', await p.$eval('.sb-page.sb-selected', (e) => e.getAttribute('data-path')) === '["pages",1]');
    dialogs = [];
    await p.keyboard.press('Delete');
    await p.waitForTimeout(250);
    check('pages: delete asks first and removes the page', dialogs.length === 1 && /Seite/.test(dialogs[0]) && (await p.locator('.sb-page').count()) === pages0, dialogs[0]);
    await p.locator('.sb-page').first().focus();
    await p.keyboard.press('ArrowLeft');
    await p.waitForTimeout(150);
    const collapsed = await p.locator('.sb-item').count();
    await p.keyboard.press('ArrowRight');
    await p.waitForTimeout(150);
    check('keyboard: ArrowLeft collapses a page, ArrowRight expands it', collapsed < (await p.locator('.sb-item').count()));
    await p.close();
}
{
    const p = await open('vabo', { width: 420, height: 900 });
    check('narrow: tree and properties stack, no horizontal scrolling', await p.$eval('.sb-main', (e) => getComputedStyle(e).gridTemplateColumns.split(' ').length) === 1
        && await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    if (shots) { await p.screenshot({ path: join(shots, 'builder-narrow.png') }); }
    await p.close();
}

// ---- ausbildernachmittag: a complete session, the server has the last word -------------------------------------------
{
    const p = await open('warn');
    const baseline = serverSays(await text(p));
    const types = ['text', 'comment', 'dropdown', 'tagbox', 'radiogroup', 'checkbox', 'boolean', 'file', 'signaturepad', 'expression', 'html', 'panel'];
    // every element: new title, flip "required" where there is one
    const n = await p.locator('.sb-item').count();
    for (let i = 0; i < n; i++) {
        await p.locator('.sb-item').nth(i).click();
        const title = p.locator('[data-prop="title"] input');
        if (await title.count()) { await title.fill(`Geändert ${i}`); await title.press('Enter'); }
        const req = p.locator('[data-prop="isRequired"] input');
        if (await req.count()) { await req.click(); }
    }
    // every type once, each after the previous one
    for (const type of types) {
        await p.click('.sb-toolbar .dropdown-toggle');
        await p.click(`.sb-palette [data-type="${type}"]`);
        await p.waitForTimeout(120);
    }
    // a page, an element on it, move things around, delete a few
    await p.click('.sb-toolbar >> text=Seite hinzufügen');
    await p.click('.sb-toolbar .dropdown-toggle');
    await p.click('.sb-palette [data-type="text"]');
    await p.waitForTimeout(150);
    await p.locator('.sb-item.sb-selected').focus();
    await p.keyboard.press('Alt+ArrowUp');
    await p.keyboard.press('Delete');
    await p.waitForTimeout(150);
    const finalText = await text(p);
    const final = JSON.parse(finalText);
    const verdictFinal = serverSays(finalText);
    const names = allElements(final).filter((e) => e.type !== 'html' && e.type !== 'panel').map((e) => e.name);
    check('session: the survey after a complete session is valid for the server', verdictFinal.valid, JSON.stringify(verdictFinal.errors).slice(0, 240));
    check('session: it started valid and grew by the 12 added types (the one deleted element was a new one)', baseline.valid && allElements(final).length === 5 + types.length, `${allElements(final).length} elements`);
    const originalNames = allElements(JSON.parse(readFileSync(surveys('ausbildernachmittag'), 'utf8'))).map((e) => e.name);
    check('session: field names are unique and every original field is still there', new Set(names).size === names.length && originalNames.every((x) => allElements(final).some((e) => e.name === x)), originalNames.join(','));
    // the last added element (a text field on the new page) was moved and deleted again; every other type is present
    check('session: every added type is in the survey', types.filter((t) => t !== 'text').every((t) => allElements(final).some((e) => e.type === t)), types.filter((t) => !allElements(final).some((e) => e.type === t)).join(','));
    check('session: no console errors', errors.length === 0, errors.join(' | '));
    await p.close();
}

// ---- AP4: moving between containers by keyboard and mouse, duplicating, undo/redo ------------------------------------
{
    const original = readFileSync(surveys('gs'), 'utf8');
    const p = await open('ap4', { width: 1400, height: 3600 }); // the whole tree without scrolling: a drag starts where the mouse went down
    /** Path of the element (or page) with this name in the current text, as the tree writes it in data-path. */
    const pathOf = async (name) => {
        const s = await survey(p);
        const find = (list, base) => {
            for (const [i, e] of (list ?? []).entries()) {
                if (e.name === name) { return [...base, i]; }
                const inner = find(e.elements, [...base, i, 'elements']);
                if (inner) { return inner; }
            }
            return null;
        };
        for (const [i, pg] of s.pages.entries()) {
            if (pg.name === name) { return ['pages', i]; }
            const found = find(pg.elements, ['pages', i, 'elements']);
            if (found) { return found; }
        }
        return null;
    };
    const row = (path) => `.sb-item[data-path='${JSON.stringify(path)}']`;
    const selected = () => p.evaluate(() => document.querySelector('.sb-item.sb-selected')?.getAttribute('data-path'));
    const key = async (k) => { await p.keyboard.press(k); await p.waitForTimeout(150); };
    const steps = [];
    const step = async () => { steps.push(await text(p)); };

    await p.click(row(await pathOf('Schulart')));
    await key('Alt+ArrowUp');
    check('ap4: Alt+Up at the top of a page moves to the end of the previous page; the selection follows',
        JSON.stringify(await pathOf('Schulart')) === '["pages",2,"elements",3]' && await selected() === '["pages",2,"elements",3]', String(await pathOf('Schulart')));
    await step();
    await key('Alt+ArrowRight');
    check('ap4: Alt+Right moves into the panel above, at its end', JSON.stringify(await pathOf('Schulart')) === '["pages",2,"elements",2,"elements",10]');
    await step();
    await key('Alt+ArrowLeft');
    check('ap4: Alt+Left moves out of the panel, right behind it', JSON.stringify(await pathOf('Schulart')) === '["pages",2,"elements",3]');
    await step();
    await key('Alt+ArrowDown');
    check('ap4: Alt+Down at the end of a page moves to the start of the next; the text is the original again', (await text(p)) === original);
    await step();

    await key('ControlOrMeta+d');
    const afterDup = await survey(p);
    check('ap4: Ctrl/Cmd+D puts a copy with a new name right behind it', afterDup.pages[3].elements[1]?.name === 'Schulart_2'
        && JSON.stringify({ ...afterDup.pages[3].elements[1], name: 'x' }) === JSON.stringify({ ...afterDup.pages[3].elements[0], name: 'x' })
        && await selected() === '["pages",3,"elements",1]');
    await step();

    const box = async (sel) => p.locator(sel).boundingBox();
    const drag = async (from, to, where) => {
        const b = await box(to);
        const y = where === 'top' ? 3 : where === 'bottom' ? b.height - 3 : b.height / 2;
        await p.dragAndDrop(from, to, { targetPosition: { x: Math.min(60, b.width / 2), y } });
        await p.waitForTimeout(250);
    };
    await drag(row(await pathOf('Kindergarten')), row(['pages', 0]), 'middle');
    check('ap4: an element dragged onto a page lands at its start', JSON.stringify(await pathOf('Kindergarten')) === '["pages",0,"elements",0]', String(await pathOf('Kindergarten')));
    await step();
    await drag(row(['pages', 5]), row(['pages', 0]), 'top');
    check('ap4: a page dragged onto the upper half of a page goes before it', (await survey(p)).pages[0].name === 'page2');
    await step();
    const panel = await pathOf('panel_wohnort');
    await drag(row(await pathOf('Anmerkungen')), row(panel), 'middle');
    check('ap4: an element dragged onto an open panel goes in as its first element', JSON.stringify(await pathOf('Anmerkungen')) === JSON.stringify([...panel, 'elements', 0]));
    await step();
    await drag(row(await pathOf('Geburtsort')), row(await pathOf('Austausch_Kindergarten')), 'bottom');
    const austausch = await pathOf('Austausch_Kindergarten');
    check('ap4: dragged onto the lower half of a question it goes behind it, also from inside a panel',
        JSON.stringify(await pathOf('Geburtsort')) === JSON.stringify([...austausch.slice(0, -1), austausch.at(-1) + 1]));
    await step();
    const beforeSelf = await text(p);
    await drag(row(await pathOf('panel_kind')), row(await pathOf('Vorname')), 'middle');
    await drag(row(['pages', 1]), row(await pathOf('Schulart')), 'middle');
    check('ap4: a panel cannot go into itself, a page not into an element: nothing changes', (await text(p)) === beforeSelf);
    check('ap4: the result is valid for the server', serverSays(await text(p)).errors.length === 0, JSON.stringify(serverSays(await text(p)).errors).slice(0, 200));

    // undo/redo: every step back to the exact text, then forward again
    const final = await text(p);
    const undo = p.locator('.sb-toolbar button', { hasText: 'Rückgängig' });
    const redo = p.locator('.sb-toolbar button', { hasText: 'Wiederholen' });
    let exact = true;
    for (let i = steps.length - 2; i >= -1; i--) {
        await undo.click();
        await p.waitForTimeout(120);
        if ((await text(p)) !== (i >= 0 ? steps[i] : original)) { exact = false; }
    }
    check('ap4: undo goes back step by step to the exact texts, down to the original', exact && (await text(p)) === original && await undo.isDisabled(), `${steps.length} steps`);
    check('ap4: after undo the moved element is selected where it was', await selected() === JSON.stringify(['pages', 3, 'elements', 0]));
    for (let i = 0; i < steps.length; i++) { await redo.click(); await p.waitForTimeout(120); }
    check('ap4: redo goes forward to the last state', (await text(p)) === final && await redo.isDisabled());

    await p.click(row(['pages', 0]));
    await key('ControlOrMeta+z');
    check('ap4: Ctrl/Cmd+Z in the tree undoes the last step', (await text(p)) === steps[steps.length - 2]);
    await key('ControlOrMeta+Shift+z');
    check('ap4: Ctrl/Cmd+Shift+Z redoes it', (await text(p)) === final);
    await p.click(row(await pathOf('Kindergarten')));
    const title = p.locator('[data-prop="title"] input');
    await title.fill('Kita');
    await title.press('ControlOrMeta+z');
    await p.waitForTimeout(150);
    check('ap4: Ctrl/Cmd+Z in a text field is the field\'s own undo, not the builder\'s', (await text(p)) === final);
    await title.press('Escape');

    await p.click('#se-tab-json');
    await p.waitForTimeout(300);
    await p.click('#se-tab-graphic');
    await p.waitForTimeout(300);
    check('ap4: switching tabs without a change adds no step', !(await redo.isEnabled()) && (await text(p)) === final);
    check('ap4: no console errors', errors.length === 0, errors.join(' | '));
    await p.close();
}

// ---- questions in answers (choices[].elements): tree, answer list, adding to a plain answer, moving -----------------
{
    const original = readFileSync(nested, 'utf8');
    const p = await open('nested', { width: 1400, height: 1600 });
    const Q = ['pages', 0, 'elements', 0];
    const VZ = [...Q, 'choices', 0];
    const TZ = [...Q, 'choices', 1];
    const row = (path) => `.sb-item[data-path='${JSON.stringify(path)}']`;
    const selected = () => p.evaluate(() => document.querySelector('.sb-item.sb-selected')?.getAttribute('data-path'));
    const choicesOf = async () => (await survey(p)).pages[0].elements[0].choices;
    const undo = p.locator('.sb-toolbar button', { hasText: 'Rückgängig' });

    check('nested: an answer with questions is a row under its question, its question below it',
        await p.locator(row(VZ)).count() === 1 && /Antwort/.test(await p.textContent(row(VZ))) && /Vollzeit/.test(await p.textContent(row(VZ)))
        && await p.locator(row([...VZ, 'elements', 0])).count() === 1 && await p.locator(row(TZ)).count() === 0);
    await p.click(row(VZ));
    check('nested: selecting the answer explains it', (await p.textContent('.sb-props h2')) === 'Antwort mit Fragen' && /Beginn|1 Elemente/.test(await p.textContent('.sb-props')));
    await p.click('.sb-props button:has-text("Zur Frage")');
    await p.waitForTimeout(150);
    check('nested: "Zur Frage" selects the question', await selected() === JSON.stringify(Q) && (await p.textContent('.sb-props h2')) === 'Einfachauswahl');

    const list = p.locator('[data-prop="choices"] textarea');
    check('nested: the answer list is editable', await list.isEditable() && (await list.inputValue()) === 'vz | Vollzeit\ntz\nabend');
    const before = await text(p);
    await list.fill('vz | Vollzeit (Mo–Fr)\ntz\nabend');
    await list.blur();
    await p.waitForTimeout(250);
    const c1 = await choicesOf();
    check('nested: a changed line changes the text of its answer, the question in it stays', c1[0].text === 'Vollzeit (Mo–Fr)' && c1[0].elements?.[0]?.name === 'start_vz'
        && lines(before, await text(p)) === '1', lines(before, await text(p)));

    dialogs = [];
    const beforeDelete = await text(p);
    await list.fill('tz\nabend');
    await list.blur();
    await p.waitForTimeout(250);
    check('nested: removing an answer with questions asks first, then the question goes with it',
        dialogs.length === 1 && /mitgelöscht/.test(dialogs[0]) && !(await text(p)).includes('start_vz'), dialogs[0]);
    await undo.click();
    await p.waitForTimeout(200);
    check('nested: undo brings answer and question back', (await text(p)) === beforeDelete);

    await p.click(row(Q));
    const opener = p.locator('[data-sb-open-choice]');
    check('nested: single choice offers questions for its plain answers', await opener.count() === 1
        && JSON.stringify(await opener.locator('option').allTextContents()) === '["tz","abend"]');
    await opener.locator('select').selectOption({ label: 'tz' });
    await opener.locator('button').click();
    await p.waitForTimeout(250);
    check('nested: the answer becomes one with questions, shows up in the tree and is selected',
        JSON.stringify((await choicesOf())[1]) === '{"value":"tz","elements":[]}' && await selected() === JSON.stringify(TZ)
        && /Keine Fragen in dieser Antwort/.test(await p.textContent('.sb-props')));
    await p.click('.sb-toolbar .dropdown-toggle');
    await p.waitForSelector('.sb-palette.show');
    await p.click('.sb-palette [data-type="text"]');
    await p.waitForTimeout(250);
    const added = (await choicesOf())[1].elements?.[0]?.name;
    check('nested: a new element from the palette goes into the selected answer', typeof added === 'string' && await selected() === JSON.stringify([...TZ, 'elements', 0]));
    await p.locator('.sb-item.sb-selected').focus();
    await p.keyboard.press('Alt+ArrowLeft');
    await p.waitForTimeout(250);
    const s1 = await survey(p);
    check('nested: Alt+Left moves it out of the answer, right behind its question',
        s1.pages[0].elements[1]?.name === added && s1.pages[0].elements[0].choices[1].elements.length === 0 && await selected() === '["pages",0,"elements",1]');
    await p.click(row(TZ));
    await p.click('.sb-props button:has-text("Keine Fragen in dieser Antwort")');
    await p.waitForTimeout(250);
    check('nested: an empty answer becomes a plain one again', (await choicesOf())[1] === 'tz' && await p.locator(row(TZ)).count() === 0 && await selected() === JSON.stringify(Q));

    const otherAt = async () => { const s = await survey(p); return s.pages[0].elements.findIndex((e) => e.name === 'other'); };
    const b = await p.locator(row(VZ)).boundingBox();
    await p.dragAndDrop(row(['pages', 0, 'elements', await otherAt()]), row(VZ), { targetPosition: { x: Math.min(60, b.width / 2), y: b.height / 2 } });
    await p.waitForTimeout(250);
    check('nested: an element dragged onto an answer goes into it', (await choicesOf())[0].elements.some((e) => e.name === 'other') && (await otherAt()) === -1);

    const at = async (name) => (await survey(p)).pages[0].elements.findIndex((e) => e.name === name);
    await p.click(row(['pages', 0, 'elements', await at('teile')]));
    const checkboxHas = await p.locator('[data-sb-open-choice]').count();
    await p.click(row(['pages', 0, 'elements', await at('land')]));
    check('nested: multiple choice offers it too, a dropdown does not (SurveyJS shows no questions there)', checkboxHas === 1 && await p.locator('[data-sb-open-choice]').count() === 0);

    const verdict = serverSays(await text(p));
    check('nested: the server accepts the result', verdict.valid && (await text(p)) !== original, JSON.stringify(verdict.errors).slice(0, 200));
    check('nested: no console errors', errors.length === 0, errors.join(' | '));
    await p.close();
}

// ---- without the code editor bundle: the plain text area ----------------------------------------------------------
{
    const p = await open('nocm');
    check('nocm: no code editor, the text area is the editor', await p.locator('.se-cm').count() === 0 && await p.evaluate(() => !window.OndisosSurveyEditor));
    await p.locator('.sb-item', { hasText: 'Geänderter' }).count();
    await p.locator('.sb-item').nth(1).click();
    const t = p.locator('[data-prop="title"] input');
    await t.fill('Ohne Code-Editor');
    await t.press('Enter');
    await p.waitForTimeout(200);
    check('nocm: a change goes straight into the text area', (await text(p)).includes('Ohne Code-Editor') && !(await submitted(p)));
    await p.click('#se-tab-json');
    await p.waitForTimeout(300);
    check('nocm: the JSON tab shows the plain text area with the same text', await p.locator('#survey-text').isVisible() && (await p.inputValue('#survey-text')).includes('Ohne Code-Editor'));
    await p.fill('#survey-text', (await text(p)).replace('Ohne Code-Editor', 'Von Hand geändert'));
    await p.click('#se-tab-graphic');
    await p.waitForTimeout(400);
    check('nocm: a change typed into the text area shows up in the builder', await p.locator('.sb-item', { hasText: 'Von Hand geändert' }).count() === 1);
    check('nocm: no console errors', errors.length === 0, errors.join(' | '));
    await p.close();
}

// ---- the page's own Content-Security-Policy -------------------------------------------------------------------------
{
    const csp = "default-src 'self'; script-src 'self' 'nonce-abc'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";
    const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
    const p = await context.newPage();
    await p.route('**/*', async (route) => { const r = await route.fetch(); await route.fulfill({ response: r, headers: { ...r.headers(), 'content-security-policy': csp } }); });
    const violations = [];
    p.on('console', (m) => { if (/content security policy|refused to/i.test(m.text())) { violations.push(m.text().slice(0, 160)); } });
    p.on('pageerror', (e) => violations.push(`pageerror: ${e.message}`));
    p.on('dialog', (d) => d.accept());
    await p.goto(`${base}/gs/index.html`);
    await p.waitForTimeout(900);
    await p.locator('.sb-item', { hasText: 'Familienname' }).first().click();
    const t = p.locator('[data-prop="title"] input');
    await t.fill('Unter CSP');
    await t.press('Enter');
    await p.click('.sb-toolbar .dropdown-toggle');
    await p.click('.sb-palette [data-type="text"]');
    await p.waitForTimeout(300);
    check('csp: the builder works under the backend policy without a violation', (await p.locator('.sb-item').count()) > 10 && (await p.inputValue('#survey-text')).includes('Unter CSP') && violations.length === 0, violations.join(' | '));
    await context.close();
}

// ---- AP4a: live preview in its own window --------------------------------------------------------------------------
{
    const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
    const problems = [];
    const watch = (pg, who) => {
        pg.on('console', (m) => { if (m.type() === 'error' || /content security policy|refused to/i.test(m.text())) { problems.push(`${who}: ${m.text().slice(0, 200)}`); } });
        pg.on('pageerror', (e) => problems.push(`${who} pageerror: ${e.message.slice(0, 200)}`));
        pg.on('dialog', (d) => d.accept());
    };
    const p = await context.newPage();
    watch(p, 'editor');
    await p.goto(`${base}/live/index.html`);
    await p.waitForTimeout(900);
    check('live: the editor shows the "Live-Vorschau" button', await p.locator('#se-live-preview').isVisible());
    const [win] = await Promise.all([p.waitForEvent('popup'), p.click('#se-live-preview')]);
    watch(win, 'window');
    await win.waitForLoadState();
    await win.waitForTimeout(1800);
    const F = win.frameLocator('#lp-frame');
    const frame = () => win.frames().find((f) => f.url().endsWith('frame.html'));
    const pageName = () => frame().evaluate(() => window.__preview.survey.currentPage?.name);
    const row = (path) => p.locator(`.sb-item[data-path='${JSON.stringify(path)}']`);
    const selected = () => p.locator('.sb-selected').getAttribute('data-path');
    const notice = async () => ((await win.locator('#lp-notice').isHidden()) ? '' : await win.textContent('#lp-notice'));
    const setText = (t) => p.evaluate((x) => { const v = window.OndisosSurveyEditor.last.view; v.dispatch({ changes: { from: 0, to: v.state.doc.length, insert: x } }); }, t);
    const original = await text(p);

    check('live: the window connects and renders the unsaved text in the sandboxed frame',
        (await win.textContent('#lp-status')).includes('Verbunden') && await F.locator('[data-name="inner"]').count() === 1 && await F.locator('[data-name="cond"]').count() === 0);
    check('live: the frame has no origin of its own (sandbox)', await frame().evaluate(() => window.origin) === 'null');

    // answers and page stay when the text changes
    await F.locator('[data-name="inner"] input').fill('Eingabe');
    await F.locator('[data-name="inner"] input').press('Tab');
    await F.locator('#preview-next').click();
    await win.waitForTimeout(300);
    await row(['pages', 1, 'elements', 0]).click();
    const title = p.locator('[data-prop="title"] input');
    await title.fill('Später (live)');
    await title.press('Enter');
    await win.waitForTimeout(1200);
    check('live: a title change shows without saving; page and answers stay',
        (await F.locator('[data-name="later"] .sd-question__title').textContent()).includes('(live)') && (await pageName()) === 'p2'
        && await frame().evaluate(() => window.__preview.survey.data.inner) === 'Eingabe');

    // tree → preview
    await row(['pages', 0, 'elements', 1, 'elements', 0]).click();
    await win.waitForTimeout(800);
    check('live: selecting in the tree goes to the page and outlines the element (inside a panel)', (await pageName()) === 'p1' && await F.locator('[data-name="inner"].lp-flash').count() === 1);
    await row(['pages', 0, 'elements', 0, 'choices', 0]).click();
    await win.waitForTimeout(800);
    check('live: an answer with questions is outlined as the option', await F.locator('[data-name="form"] .sd-item.lp-flash').count() === 1);

    // add, move, delete, undo
    const count = () => F.locator('.sd-question').count();
    const before = await count();
    await row(['pages', 0, 'elements', 2]).click();
    await p.click('.sb-toolbar .dropdown-toggle');
    await p.click('.sb-palette [data-type="comment"]');
    await win.waitForTimeout(1000);
    const added = await count();
    await p.keyboard.press('Alt+ArrowUp');
    await win.waitForTimeout(1000);
    const order = await frame().evaluate(() => window.__preview.survey.currentPage.elements.map((e) => e.name));
    await p.click('.sb-toolbar button:has-text("Rückgängig")');
    await p.click('.sb-toolbar button:has-text("Rückgängig")');
    await win.waitForTimeout(1200);
    check('live: adding, moving and undoing arrive in the preview', added === before + 1 && order.length === 4 && order[3] === 'cond' && await count() === before && (await text(p)).trim() === original.trim().replace('"Später"', '"Später (live)"'),
        `${before}/${added}/${await count()} ${order.join(',')}`);

    // preview → tree: picking mode and Alt + click
    await win.click('#lp-pick');
    await F.locator('[data-name="inner"] .sd-question__title').click();
    await p.waitForTimeout(400);
    const pickedInner = await selected();
    await F.locator('.sd-panel__title', { hasText: 'Gruppe' }).click();
    await p.waitForTimeout(400);
    const pickedPanel = await selected();
    await F.locator('[data-name="form"] .sd-item__control-label', { hasText: 'Vollzeit' }).click();
    await p.waitForTimeout(400);
    const pickedChoice = await selected();
    await F.locator('.sd-page__title').click();
    await p.waitForTimeout(400);
    const pickedPage = await selected();
    check('live: picking selects question, panel, answer with questions and page in the tree',
        pickedInner === JSON.stringify(['pages', 0, 'elements', 1, 'elements', 0]) && pickedPanel === JSON.stringify(['pages', 0, 'elements', 1])
        && pickedChoice === JSON.stringify(['pages', 0, 'elements', 0, 'choices', 0]) && pickedPage === JSON.stringify(['pages', 0]),
        [pickedInner, pickedPanel, pickedChoice, pickedPage].join(' '));
    check('live: picking does not answer the form', await frame().evaluate(() => window.__preview.survey.data.form) === undefined);
    await win.click('#lp-pick');
    await F.locator('[data-name="form"] .sd-item__control-label', { hasText: 'tz' }).click({ modifiers: ['Alt'] });
    await p.waitForTimeout(400);
    check('live: Alt + click on a plain answer selects its question, without answering', (await selected()) === JSON.stringify(['pages', 0, 'elements', 0]) && await frame().evaluate(() => window.__preview.survey.data.form) === undefined);

    // hidden elements
    await row(['pages', 0, 'elements', 2]).click();
    await win.waitForTimeout(800);
    const hiddenNote = await notice();
    await win.click('label[for=lp-invisible]');
    await row(['pages', 0, 'elements', 0]).click();
    await win.waitForTimeout(300);
    await row(['pages', 0, 'elements', 2]).click();
    await win.waitForTimeout(800);
    check('live: a hidden element is named as hidden; "Ausgeblendete zeigen" shows it', hiddenNote.includes('ausgeblendet') && await F.locator('[data-name="cond"].lp-flash').count() === 1 && (await notice()) === '');

    // questions in an answer that is not chosen: "Ausgeblendete zeigen" opens every answer, faded
    const nestedShown = () => F.locator('[data-name="start_vz"]').count();
    const opacity = () => F.locator('[data-name="start_vz"]').evaluate((el) => getComputedStyle(el.closest('.sd-item + .sd-panel')).opacity);
    check('live: with "Ausgeblendete zeigen" the questions of an answer that is not chosen show, faded', await nestedShown() === 1 && await opacity() === '0.35', await nestedShown() ? await opacity() : 'not rendered');
    await row(['pages', 0, 'elements', 0, 'choices', 0, 'elements', 0]).click();
    await win.waitForTimeout(800);
    check('live: such a question can be shown from the tree', await F.locator('[data-name="start_vz"].lp-flash').count() === 1 && (await notice()) === '');
    await F.locator('[data-name="form"] .sd-item__control-label', { hasText: 'tz' }).click();
    await win.waitForTimeout(300);
    const afterChoosingOther = await nestedShown();
    await F.locator('[data-name="form"] .sd-item__control-label', { hasText: 'Vollzeit' }).click();
    await win.waitForTimeout(1000);
    const chosenOpacity = await opacity();
    await win.click('label[for=lp-invisible]');
    await win.waitForTimeout(400);
    const offChosen = await nestedShown();
    await F.locator('[data-name="form"] .sd-item__control-label', { hasText: 'tz' }).click();
    await win.waitForTimeout(400);
    check('live: answering keeps them open; the chosen answer\'s questions are not faded; switched off, SurveyJS\'s own rule applies again',
        afterChoosingOther === 1 && chosenOpacity === '1' && offChosen === 1 && await nestedShown() === 0, `${afterChoosingOther} ${chosenOpacity} ${offChosen} ${await nestedShown()}`);
    await frame().evaluate(() => { window.__preview.survey.clearValue('form'); });
    await win.waitForTimeout(300);

    // what the server refuses is not rendered
    const good = await text(p);
    await p.click('#se-tab-json');
    await p.waitForTimeout(300);
    const evil = JSON.parse(good);
    evil.pages[0].elements.push({ type: 'html', name: 'evil', html: '<img src=x onerror="document.title=1">' });
    const checksBefore = liveChecks;
    await setText(JSON.stringify(evil, null, 2));
    await win.waitForTimeout(1200);
    check('live: a text the server refuses is not rendered; the last good state stays with a notice',
        (await notice()).includes('ablehnt') && await F.locator('[data-name="evil"]').count() === 0 && await F.locator('[data-name="inner"]').count() === 1 && liveChecks === checksBefore + 1);
    await setText('{"pages": [');
    await win.waitForTimeout(1000);
    check('live: unreadable JSON is not even sent to the server', (await notice()).includes('kein lesbares JSON') && liveChecks === checksBefore + 1);
    await setText(good);
    await win.waitForTimeout(1000);
    check('live: back to the last good text: the notice goes, no new check', (await notice()) === '' && liveChecks === checksBefore + 1);

    // a reloaded editor finds its window again; the button brings it to the front instead of opening another
    await p.click('#se-tab-graphic');
    await p.reload();
    await p.waitForTimeout(2500);
    await row(['pages', 1, 'elements', 0]).click();
    await win.waitForTimeout(900);
    check('live: after reloading the editor the window is connected again and follows the selection', (await win.textContent('#lp-status')).includes('Verbunden') && (await pageName()) === 'p2' && await F.locator('[data-name="later"].lp-flash').count() === 1);
    const windows = context.pages().length;
    await p.click('#se-live-preview');
    await p.waitForTimeout(500);
    check('live: a second click on the button opens no second window', context.pages().length === windows);
    check('live: few checks on the server (unchanged and known texts are not checked again)', liveChecks > 0 && liveChecks <= 12, String(liveChecks));
    check('live: no console errors and no CSP violation in editor, window and frame', problems.length === 0, problems.join(' | '));
    if (shots) { await win.screenshot({ path: join(shots, 'live-window.png') }); }
    await context.close();
}

console.log(`\n${results.filter(Boolean).length}/${results.length} checks passed`);
await browser.close();
server.close();
process.exit(results.every(Boolean) ? 0 : 1);

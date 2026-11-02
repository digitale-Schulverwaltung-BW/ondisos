// Browser check of the graphical survey builder (development tool, not part of the product or the pipeline).
//
//   node backend/tools/survey-builder-harness/check.mjs [screenshot-dir]
//
// Needs: PHP with `composer install` in backend/, Node 22+, the `playwright` package and a Chromium
// (PLAYWRIGHT_BROWSERS_PATH, or CHROMIUM_PATH for the executable). It renders the editor tabs of form_survey.php with
// render.php for several surveys (the real ones from frontend/surveys plus small odd ones), serves them and drives the
// builder like a person would: edit, rename, add, move, delete, keyboard, tab switch, broken JSON, findings.
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
const empty = write('empty.json', '');
const broken = write('broken.json', '{"pages": [,]}');
const reportBroken = write('report-broken.json', JSON.stringify({ valid: false, errors: [{ path: '', message: 'Allgemeiner Fehler' }], warnings: [] }));
const reportWarn = write('report-warn.json', JSON.stringify({
    valid: true, errors: [],
    warnings: [{ path: 'pages[0].elements[1].visibleIf', message: 'Unbekannte Funktion foo() im Ausdruck' }, { path: '', message: 'Hinweis ohne Pfad' }],
}));
render('gs', surveys('gs'));
render('bs', surveys('bs'));
render('vabo', surveys('vabo'));
render('odd', odd);
render('empty', empty);
render('broken', broken, reportBroken);
render('warn', surveys('ausbildernachmittag'), reportWarn);
render('nocm', surveys('ausbildernachmittag'), null, { SB_NO_CODEMIRROR: '1' });

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.woff2': 'font/woff2' };
const server = createServer((req, res) => {
    const file = resolve(root, `.${decodeURIComponent(new URL(req.url, 'http://x').pathname)}`);
    if (!file.startsWith(root) || !existsSync(file) || !statSync(file).isFile()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'content-type': MIME[extname(file)] ?? 'application/octet-stream' });
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
    await p.keyboard.press('Control+z');
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
    check('odd: answers with conditions are read-only with an explanation', await p.locator('[data-prop="choices"] textarea[readonly]').count() === 1 && /weitere Einstellungen/.test(await p.textContent('[data-prop="choices"]')));
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

console.log(`\n${results.filter(Boolean).length}/${results.length} checks passed`);
await browser.close();
server.close();
process.exit(results.every(Boolean) ? 0 : 1);

// Durchgang durch die echte Seite des Survey-Editors im laufenden Docker-Stack (Abnahme vor einem Release; nicht in der Pipeline).
// Ergänzt check.mjs, das dieselben Skripte gegen gerendertes Markup ohne Datenbank prüft.
//
// Legt ein Formular abnahme-<Zeit> an, nimmt die Vorlage „Schüleranmeldung“, prüft Live-Vorschau, Baustein, Bedingung, Rückgängig,
// Prüfen, Entwurf, Vorschau und Veröffentlichen, liest die veröffentlichte Fassung über form-config.php und löscht das Formular wieder.
// Login als Schul-Admin ohne zweiten Faktor (ein Platform-Admin müsste erst einen Tenant wählen):
//
//   BASE=http://localhost:9080 SLUG=<tenant-slug> USER_NAME=<schul-admin> USER_PASS=<passwort> \
//   PLAYWRIGHT_MODULES=<…/node_modules/> CHROMIUM_PATH=<Chrome> node backend/tools/survey-builder-harness/realpage.mjs
//
// Zugangsdaten nur über Umgebungsvariablen übergeben.
import { createRequire } from 'node:module';
const require = createRequire(process.env.PLAYWRIGHT_MODULES ?? '/opt/node22/lib/node_modules/');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://localhost:9080', SLUG = process.env.SLUG;
const FORM = `abnahme-${Date.now().toString(36)}`;
const results = [];
const check = (name, ok, info = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && info ? ' — ' + info : ''}`); };
const problems = [];
const watch = (pg, label) => {
    pg.on('console', (m) => { if (m.type() === 'error' && !/favicon/.test(m.text())) problems.push(`${label}: ${m.text()}`); });
    pg.on('pageerror', (e) => problems.push(`${label}: ${e.message}`));
};

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true });
const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, locale: 'de-DE' });
const p = await ctx.newPage();
watch(p, 'editor');
p.on('dialog', (d) => d.accept());
await p.addInitScript(() => document.addEventListener('securitypolicyviolation', (e) => console.error('CSP ' + e.violatedDirective + ' ' + e.blockedURI)));

// login
await p.goto(`${BASE}/login.php`);
await p.fill('[name=username]', process.env.USER_NAME);
await p.fill('[name=password]', process.env.USER_PASS);
await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
check('login as school admin without second factor', !p.url().includes('login.php'), p.url());

// new form
await p.goto(`${BASE}/forms.php`);
await p.fill('#form_key', FORM);
await Promise.all([p.waitForNavigation(), p.click('form:has(input[name=action][value=create]) button[type=submit]')]);
check(`new form "${FORM}" created`, p.url().includes(`form_edit.php?form=${FORM}`), p.url());

// editor, empty: templates
await p.goto(`${BASE}/form_survey.php?form=${FORM}`);
const offered = await p.locator('.sb-templates [data-template]').count();
check('empty editor offers templates and examples, notice says there is no survey yet', offered >= 10 && !(await p.textContent('#se-page')).includes('nutzt die Datei im Frontend'), String(offered));
await p.click('.sb-templates [data-template="schueleranmeldung"]');
await p.waitForTimeout(400);
const text = () => p.inputValue('#survey-text');
const doc = async () => JSON.parse(await text());
check('template lands in the editor (tree and text)', (await p.locator('.sb-item').count()) > 20 && (await doc()).pages.length >= 3);

// live preview window
const [win] = await Promise.all([p.waitForEvent('popup'), p.click('#se-live-preview')]);
watch(win, 'window');
await win.waitForTimeout(2500);
const F = win.frameLocator('#lp-frame');
check('live preview window connects and renders', (await win.textContent('#lp-status')).includes('Verbunden') && (await F.locator('.sd-question').count()) > 0);

// title change in the graphic editor arrives in the preview without saving
await p.click('.sb-item.sb-survey');
await p.fill('[data-prop="title"] input', 'Anmeldung Abnahme 3.2.0');
await p.press('[data-prop="title"] input', 'Enter');
await win.waitForTimeout(1500);
check('title change shows in the live preview without saving', ((await F.locator('body').textContent()) || '').includes('Anmeldung Abnahme 3.2.0'));

// selection: tree -> preview
await p.locator('.sb-item', { hasText: 'Vorname' }).first().click();
await win.waitForTimeout(1200);
check('selecting "Vorname" in the tree highlights it in the preview', (await F.locator('[data-name="Vorname"].lp-flash').count()) === 1);

// block: Ausbildungsbetrieb
await p.locator('.sb-item.sb-page').last().click();
await p.click('button:has-text("Baustein einfügen")');
await p.locator('.sb-blocks [data-block]', { hasText: 'Ausbildungsbetrieb' }).click();
await p.waitForTimeout(400);
const names = JSON.stringify(await doc());
check('block "Ausbildungsbetrieb" inserted with ASV names', names.includes('"Betrieb') || names.includes('Ausbildungsbetrieb'));
await p.click('button:has-text("Baustein einfügen")');
await p.locator('.sb-blocks [data-block]', { hasText: 'Ausbildungsbetrieb' }).click();
await p.waitForTimeout(400);
check('the same block a second time is refused, text unchanged', JSON.stringify(await doc()) === names);

// condition with suggestions on "Geburtsort"
await p.locator('.sb-item', { hasText: 'Geburtsort' }).first().click();
const field = p.locator('[data-prop="visibleIf"] input');
const group = p.locator('details.sb-group', { has: field });
if (!(await group.evaluate((d) => d.open))) { await group.locator('summary').click(); }
await field.click();
await field.press('{');
await p.waitForTimeout(200);
const opts = await p.locator('[data-prop="visibleIf"] .sb-suggest [role="option"]').allTextContents();
check('"{" offers the fields with titles', opts.length > 5 && opts.some((o) => o.includes('Geburtsdatum')), opts.slice(0, 5).join(' | '));
await field.press('Escape');
await field.fill("{Geburtsland} = 'Deutschland'");
await field.press('Enter');
await p.waitForTimeout(300);
const reads = await p.locator('[data-prop="visibleIf"] .sb-cond-reads, [data-prop="visibleIf"] .sb-cond-warn').allTextContents();
check('a condition gets plain language or a warning', reads.join(' ').length > 0, reads.join(' | '));

// undo back to the template title? only check undo works one step
const beforeUndo = await text();
await p.click('button:has-text("Rückgängig")');
await p.waitForTimeout(200);
check('undo changes the text', (await text()) !== beforeUndo);
await p.click('button:has-text("Wiederholen")');
await p.waitForTimeout(200);
check('redo restores it exactly', (await text()) === beforeUndo);

// check (server)
await Promise.all([p.waitForNavigation(), p.click('button[name=action][value=check]')]);
const body = await p.textContent('body');
check('Prüfen: no errors, placeholder link reported as hint', !/Fehler \(/.test(body) && /example\.com/.test(body));
check('after Prüfen the edited title is still in the editor', (await doc()).title === 'Anmeldung Abnahme 3.2.0' || JSON.stringify((await doc()).title).includes('Abnahme 3.2.0'));

// draft
await Promise.all([p.waitForNavigation(), p.click('button[name=action][value=save_draft]')]);
check('draft saved', (await p.locator('.badge.text-bg-warning').count()) === 1);

// preview page
const pv = await ctx.newPage(); watch(pv, 'preview');
await pv.goto(`${BASE}/form_preview.php?form=${FORM}`);
await pv.waitForTimeout(2500);
const pvText = await pv.frameLocator('iframe').first().locator('body').textContent().catch(() => '');
check('Vorschau shows the draft', (pvText || '').includes('Abnahme 3.2.0'), (pvText || '').slice(0, 80));
await pv.close();

// publish
await Promise.all([p.waitForNavigation(), p.click('button[name=action][value=publish]')]);
check('published, no draft left', (await p.locator('.badge.text-bg-success').count()) === 1 && (await p.locator('.badge.text-bg-warning').count()) === 0);
const api = await (await fetch(`${BASE}/api/form-config.php?tenant=${SLUG}&form=${FORM}&with=survey`)).json().catch(() => ({}));
const live = typeof api.survey_json === 'string' ? JSON.parse(api.survey_json) : (api.survey_json || {});
const liveText = JSON.stringify(live);
check('form-config.php (what the frontend fetches) delivers title, condition and block',
    live.title === 'Anmeldung Abnahme 3.2.0' && liveText.includes("{Geburtsland} = 'Deutschland'") && liveText.includes('panel_ausbildungsbetrieb'));

// editor reloaded with the published survey: graphic tab, history row
await p.goto(`${BASE}/form_survey.php?form=${FORM}`);
check('editor reopens in the graphic tab with the published survey', await p.locator('#se-tab-graphic.active').count() === 1 && (await p.locator('.sb-item').count()) > 20);
check('history lists the publication', /Verlauf/.test(await p.textContent('body')));

await win.close().catch(() => {});

// clean up: delete the form (possible as long as there are no submissions)
await p.goto(`${BASE}/form_edit.php?form=${FORM}`);
await p.click('#tab-btn-info');
await Promise.all([p.waitForNavigation(), p.click('form:has(input[name=action][value=delete]) button[type=submit]')]);
const gone = await fetch(`${BASE}/api/form-config.php?tenant=${SLUG}&form=${FORM}`);
check('form deleted again', gone.status === 404, String(gone.status));
check('no console errors, no CSP violations (editor, window, preview)', problems.length === 0, problems.join('\n'));
await browser.close();
console.log(`\n${results.filter(Boolean).length}/${results.length} checks passed`);
process.exit(results.every(Boolean) ? 0 : 1);

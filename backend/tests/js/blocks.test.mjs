// Tests for inserting building blocks (AP6): fixed names, nothing renamed, nothing changed on a clash.
// The blocks come from the server (App\Forms\SurveyBlocks); fixtures/survey-blocks.json is its committed copy.
// Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { loadModel, insertBlock, addElement } from '../../public/assets/survey-builder/model.js';
import { toValue, valueAt } from '../../public/assets/survey-builder/json-doc.js';
import { elementsOf } from '../../public/assets/survey-builder/names.js';

const require = createRequire(import.meta.url);
const Survey = require('../../../frontend/public/assets/survey.core.min.js');
const RULES = JSON.parse(readFileSync(new URL('./fixtures/survey-rules.json', import.meta.url), 'utf8'));
const BLOCKS = JSON.parse(readFileSync(new URL('./fixtures/survey-blocks.json', import.meta.url), 'utf8'));
const block = (key) => { const b = BLOCKS.find((x) => x.key === key); assert.ok(b, key); return b.element; };
const read = (f) => readFileSync(new URL(`../fixtures/surveys/${f}`, import.meta.url), 'utf8');

const EMPTY = '{\n  "pages": [\n    {\n      "name": "page1",\n      "elements": []\n    }\n  ]\n}';
const load = (text) => { const m = loadModel(text); assert.ok(m.ok); return m.doc; };
const good = (r) => { assert.equal(r.ok, true, JSON.stringify({ ...r, doc: undefined })); return r; };
const namesIn = (doc) => elementsOf(doc).map((e) => e.value.name);
const PAGE = ['pages', 0];

test('a block goes in as it is: same value, its names unchanged, SurveyJS builds it', () => {
    for (const b of BLOCKS) {
        const r = good(insertBlock(load(EMPTY), PAGE, 0, b.element, { rules: RULES }));
        assert.deepEqual(valueAt(r.doc, r.path), b.element, b.key);
        assert.deepEqual(r.path, ['pages', 0, 'elements', 0]);
        const model = new Survey.Model(toValue(r.doc.root));
        for (const n of r.names.filter((n) => !n.startsWith('panel_') && n !== 'datenschutz_hinweis')) {
            assert.ok(model.getQuestionByName(n), `${b.key}: SurveyJS knows ${n}`);
        }
    }
});

test('a school registration takes every block except "name and e-mail", one after the other', () => {
    let doc = load(EMPTY);
    for (const b of BLOCKS.filter((x) => x.block !== 'name_email')) {
        doc = good(insertBlock(doc, PAGE, valueAt(doc, [...PAGE, 'elements']).length, b.element, { rules: RULES })).doc;
    }
    const names = namesIn(doc);
    assert.equal(new Set(names).size, names.length);
    assert.ok(names.includes('Erz1Name') && names.includes('Erz2Name') && names.includes('Telefon 1'));
    assert.doesNotThrow(() => new Survey.Model(toValue(doc.root)));
});

test('a clash changes nothing and names the fields that exist already', () => {
    const doc = good(insertBlock(load(EMPTY), PAGE, 0, block('erziehungsberechtigte_1'), { rules: RULES })).doc;
    const again = insertBlock(doc, PAGE, 1, block('erziehungsberechtigte_1'), { rules: RULES });
    assert.equal(again.ok, false);
    assert.equal(again.code, 'blockNamesTaken');
    assert.ok(again.names.includes('Erz1Name') && again.names.includes('panel_erz1'));
    assert.equal(again.doc, undefined);
    // the other guardian fits
    good(insertBlock(doc, PAGE, 1, block('erziehungsberechtigte_2'), { rules: RULES }));
});

test('"Person" does not fit next to "Name und E-Mail": both use "Name", the one name the server looks for', () => {
    const doc = good(insertBlock(load(EMPTY), PAGE, 0, block('name_email'), { rules: RULES })).doc;
    const r = insertBlock(doc, PAGE, 1, block('person'), { rules: RULES });
    assert.deepEqual([r.code, r.names], ['blockNamesTaken', ['Name']]);
});

test('the real Berufsschule survey already has the person fields: the block is refused, the text unchanged', () => {
    const doc = load(read('bs.json'));
    const r = insertBlock(doc, PAGE, 0, block('person'), { rules: RULES });
    assert.equal(r.code, 'blockNamesTaken');
    assert.ok(r.names.includes('Vorname') && r.names.includes('Geburtsland'));
});

test('names of pages, calculated values and reserved names count as taken', () => {
    const text = JSON.stringify({ calculatedValues: [{ name: 'Anmerkungen', expression: '1' }], pages: [{ name: 'panel_person', elements: [] }] }, null, 2);
    assert.deepEqual(insertBlock(load(text), PAGE, 0, block('anmerkungen'), { rules: RULES }).names, ['Anmerkungen']);
    assert.deepEqual(insertBlock(load(text), PAGE, 0, block('person'), { rules: RULES }).names, ['panel_person']);
    const reserved = { type: 'text', name: RULES.reservedNames[0] };
    assert.equal(insertBlock(load(EMPTY), PAGE, 0, reserved, { rules: RULES }).code, 'blockNamesTaken');
});

test('a name of the published version is no clash (it is the same field)', () => {
    // taken names only keep generated names from coming back; insertBlock has no such option on purpose
    const r = good(insertBlock(load(EMPTY), PAGE, 0, block('anmerkungen'), { rules: RULES }));
    assert.deepEqual(r.names, ['Anmerkungen']);
});

test('only into pages, panels and answers with questions; position and depth are checked like for a new element', () => {
    const withQuestion = good(addElement(load(EMPTY), PAGE, 0, 'text', { rules: RULES })).doc;
    assert.equal(insertBlock(withQuestion, ['pages', 0, 'elements', 0], 0, block('anmerkungen'), { rules: RULES }).code, 'notContainer');
    assert.equal(insertBlock(withQuestion, PAGE, 5, block('anmerkungen'), { rules: RULES }).code, 'index');
    // into a panel: the block's own panel counts as one level more
    let doc = load(EMPTY);
    let parent = PAGE;
    for (let i = 0; i < RULES.maxDepth; i++) {
        const r = good(addElement(doc, parent, 0, 'panel', { rules: RULES }));
        doc = r.doc;
        parent = r.path;
    }
    assert.equal(insertBlock(doc, parent, 0, block('person'), { rules: RULES }).code, 'tooDeep');
    good(insertBlock(doc, parent, 0, block('anmerkungen'), { rules: RULES }));
});

test('the text around the block stays byte for byte (compact lists in bs.json)', () => {
    const text = read('bs.json');
    const r = good(insertBlock(load(text), PAGE, 0, block('unterschrift'), { rules: RULES }));
    const lines = text.split('\n');
    const after = r.doc.text.split('\n');
    const added = after.length - lines.length;
    assert.ok(added > 10);
    // everything before and after the inserted lines is unchanged
    const at = after.findIndex((l, i) => l !== lines[i]);
    assert.deepEqual(after.slice(0, at), lines.slice(0, at));
    assert.deepEqual(after.slice(at + added).slice(1), lines.slice(at).slice(1));
});

test('a broken block value is refused, not written', () => {
    assert.equal(insertBlock(load(EMPTY), PAGE, 0, null).code, 'path');
    assert.equal(insertBlock(load(EMPTY), PAGE, 0, { type: 'paneldynamic', name: 'x' }).code, 'unknownType');
});

test('every building block and every template or example has an icon, and there are no icons for ones that do not exist', async () => {
    const { BLOCK_ICONS, TEMPLATE_ICONS } = await import('../../public/assets/survey-builder/icons.js');
    const manifest = JSON.parse(readFileSync(new URL('../../resources/survey-templates/manifest.json', import.meta.url), 'utf8'));
    assert.deepEqual(Object.keys(BLOCK_ICONS).sort(), [...new Set(BLOCKS.map((b) => b.block))].sort());
    assert.deepEqual(Object.keys(TEMPLATE_ICONS).sort(), [...manifest.templates, ...manifest.examples].map((e) => e.key).sort());
});

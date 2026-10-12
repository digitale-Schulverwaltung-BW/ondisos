// Tests for the change operations of the model (setProp, rename, add/remove/move). Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { loadModel, outline, flatten, setProp, setLocalized, renameElement, addElement, removeElement, moveElement, addPage, removePage, movePage, countElements, formatPath }
    from '../../public/assets/survey-builder/model.js';
import { toValue, valueAt, parseDoc } from '../../public/assets/survey-builder/json-doc.js';
import { elementsOf } from '../../public/assets/survey-builder/names.js';
import { TYPES, template, typeDef } from '../../public/assets/survey-builder/registry.js';

const require = createRequire(import.meta.url);
const Survey = require('../../../frontend/public/assets/survey.core.min.js');
const RULES = JSON.parse(readFileSync(new URL('./fixtures/survey-rules.json', import.meta.url), 'utf8'));
const SURVEYS = new URL('../../../frontend/surveys/', import.meta.url);
const surveyFiles = readdirSync(SURVEYS).filter((f) => f.endsWith('.json') && f !== 'survey_theme.json').sort();
const read = (f) => readFileSync(new URL(f, SURVEYS), 'utf8');
const load = (t) => { const m = loadModel(typeof t === 'string' ? t : JSON.stringify(t, null, 2)); assert.ok(m.ok); return m.doc; };
const good = (r) => { assert.equal(r.ok, true, JSON.stringify(r)); return r; };

/** The server's name rule (SurveyValidator): every field (not html/image/panel) has a unique, non-reserved name. */
function assertServerNameRule(doc, label) {
    const seen = new Set();
    for (const e of elementsOf(doc)) {
        const t = e.value.type;
        if (t === 'panel' || RULES.displayTypes.includes(t)) { continue; }
        assert.ok(typeof e.value.name === 'string' && e.value.name !== '', `${label}: ${formatPath(e.path)} has a name`);
        assert.ok(!RULES.reservedNames.includes(e.value.name), `${label}: reserved ${e.value.name}`);
        assert.ok(!seen.has(e.value.name), `${label}: duplicate ${e.value.name}`);
        seen.add(e.value.name);
    }
}

// ---- setProp -----------------------------------------------------------------------------------------------------

test('setProp: booleans are written only when they differ from the default; the default removes the property', () => {
    const d = load({ pages: [{ elements: [{ type: 'text', name: 'a', isRequired: true, showNumber: true }] }] });
    const at = ['pages', 0, 'elements', 0];
    assert.equal(valueAt(good(setProp(d, at, 'isRequired', false)).doc, [...at, 'isRequired']), undefined, 'false is the default');
    assert.equal(valueAt(good(setProp(d, at, 'showNumber', true)).doc, [...at, 'showNumber']), undefined, 'true is the default of showNumber');
    assert.equal(valueAt(good(setProp(d, at, 'showNumber', false)).doc, [...at, 'showNumber']), false);
    const same = good(setProp(d, at, 'startWithNewLine', true));
    assert.equal(same.doc.text, d.text, 'absent + default = no change at all');
    assert.equal(valueAt(good(setProp(d, at, 'visible', false)).doc, [...at, 'visible']), false);
});

test('setProp: numbers, enums and the choices are checked against the registry', () => {
    const d = load({ pages: [{ elements: [{ type: 'radiogroup', name: 'r', choices: ['a'] }, { type: 'comment', name: 'c' }, { type: 'text', name: 't' }] }] });
    const r = ['pages', 0, 'elements', 0];
    assert.equal(valueAt(good(setProp(d, r, 'colCount', 3)).doc, [...r, 'colCount']), 3);
    assert.equal(setProp(d, r, 'colCount', 9).code, 'value');
    assert.equal(setProp(d, r, 'colCount', 1.5).code, 'value');
    assert.equal(setProp(d, r, 'colCount', '2').code, 'value');
    assert.equal(setProp(d, r, 'colCount', NaN).code, 'value');
    assert.equal(good(setProp(d, r, 'colCount', 1)).doc.text, d.text, 'default 1');
    const t = ['pages', 0, 'elements', 2];
    assert.equal(valueAt(good(setProp(d, t, 'inputType', 'email')).doc, [...t, 'inputType']), 'email');
    assert.equal(setProp(d, t, 'inputType', 'password').code, 'value', 'not offered by the registry');
    assert.equal(setProp(d, t, 'titleLocation', 'sideways').code, 'value');
    assert.deepEqual(valueAt(good(setProp(d, r, 'choices', ['x', { value: 1, text: 'Eins' }])).doc, [...r, 'choices']), ['x', { value: 1, text: 'Eins' }]);
    assert.equal(setProp(d, r, 'choices', [{ text: 'no value' }]).code, 'value');
    assert.equal(setProp(d, r, 'choices', 'a,b').code, 'value');
    assert.equal(setProp(d, ['pages', 0, 'elements', 1], 'rows', 0).code, 'value', 'min 1');
});

test('setProp: expressions and default answers; empty removes them', () => {
    const d = load({ pages: [{ elements: [{ type: 'text', name: 'a', visibleIf: '{b} = 1', defaultValue: 'x' }] }] });
    const at = ['pages', 0, 'elements', 0];
    assert.equal(valueAt(good(setProp(d, at, 'visibleIf', '{b} = 2')).doc, [...at, 'visibleIf']), '{b} = 2');
    assert.equal(valueAt(good(setProp(d, at, 'visibleIf', '')).doc, [...at, 'visibleIf']), undefined);
    assert.equal(valueAt(good(setProp(d, at, 'defaultValue', '')).doc, [...at, 'defaultValue']), undefined);
    assert.equal(setProp(d, at, 'defaultValue', true).code, 'value', 'text takes a text');
    const b = load({ pages: [{ elements: [{ type: 'boolean', name: 'b' }] }] });
    assert.equal(valueAt(good(setProp(b, ['pages', 0, 'elements', 0], 'defaultValue', true)).doc, ['pages', 0, 'elements', 0, 'defaultValue']), true);
    assert.equal(setProp(b, ['pages', 0, 'elements', 0], 'defaultValue', 'yes').code, 'value');
});

test('setProp: localized texts keep other languages; where empty means absent the property goes, otherwise "" is kept', () => {
    const d = load({ pages: [{ elements: [{ type: 'dropdown', name: 'd', title: { de: 'DE', default: 'STD' }, placeholder: 'Bitte wählen' }] }] });
    const at = ['pages', 0, 'elements', 0];
    assert.deepEqual(valueAt(good(setProp(d, at, 'title', 'Neu')).doc, [...at, 'title']), { de: 'DE', default: 'Neu' });
    // title: "" = no title (the library falls back to the name); with languages only "default" goes
    assert.deepEqual(valueAt(good(setProp(d, at, 'title', '')).doc, [...at, 'title']), { de: 'DE' });
    // clearing again removes the remaining language (with no "default" left, "de" is the one being edited)
    assert.equal(valueAt(good(setProp(good(setProp(d, at, 'title', '')).doc, at, 'title', '')).doc, [...at, 'title']), undefined);
    // placeholder: "" means "show nothing", which differs from absent ("Select..."), so it is kept
    assert.equal(valueAt(good(setProp(d, at, 'placeholder', '')).doc, [...at, 'placeholder']), '');
    assert.equal(valueAt(good(setProp(d, at, 'placeholder', null)).doc, [...at, 'placeholder']), undefined, 'null removes');
    const only = load({ pages: [{ elements: [{ type: 'text', name: 'a', title: { default: 'x' } }] }] });
    assert.equal(valueAt(good(setProp(only, ['pages', 0, 'elements', 0], 'title', '')).doc, ['pages', 0, 'elements', 0, 'title']), undefined, 'an empty localized object goes with its last text');
});

test('setProp: unknown types and properties are refused, never guessed', () => {
    const d = load({ pages: [{ elements: [{ type: 'matrix', name: 'm', rows: [] }, { type: 'text', name: 't', weird: 1 }] }] });
    assert.equal(setProp(d, ['pages', 0, 'elements', 0], 'title', 'x').code, 'unknownType');
    assert.equal(setProp(d, ['pages', 0, 'elements', 1], 'weird', 2).code, 'unknownProperty');
    assert.equal(setProp(d, ['pages', 0, 'elements', 1], 'html', 'x').code, 'unknownProperty', 'a property of another type');
    assert.equal(setProp(d, ['pages', 9], 'title', 'x').code, 'path');
    assert.equal(setProp(d, ['pages', 0, 'elements', 1, 'type'], 'title', 'x').code, 'path', 'not an object');
});

test('unknown elements and unknown properties survive every operation untouched (byte for byte)', () => {
    const text = '{\n  "pages": [\n    {\n      "name": "page1",\n      "elements": [\n        {"type": "matrix", "name": "m", "rows": ["a", "b"], "x-custom": [1, 2]},\n        {\n          "type": "text",\n          "name": "t",\n          "weird": {"k": [true]},\n          "isRequired": true\n        }\n      ]\n    }\n  ],\n  "futureSetting": 42\n}';
    const d = load(text);
    const t = ['pages', 0, 'elements', 1];
    const next = good(setProp(d, t, 'isRequired', false)).doc;
    assert.ok(next.text.includes('"weird": {"k": [true]}'));
    assert.ok(next.text.includes('{"type": "matrix", "name": "m", "rows": ["a", "b"], "x-custom": [1, 2]},'));
    assert.ok(next.text.includes('"futureSetting": 42'));
    const added = good(addElement(next, ['pages', 0], 1, 'text', { rules: RULES })).doc;
    assert.ok(added.text.includes('{"type": "matrix", "name": "m", "rows": ["a", "b"], "x-custom": [1, 2]},'));
    assert.deepEqual(valueAt(added, ['pages', 0, 'elements', 0]).rows, ['a', 'b']);
    assert.equal(valueAt(good(removeElement(added, ['pages', 0, 'elements', 1])).doc, ['pages', 0, 'elements', 0, 'x-custom']).length, 2);
});

test('flipping any property of any element and back restores the same structure and touches only that element', () => {
    for (const f of surveyFiles) {
        const text = read(f);
        const d = load(text);
        for (const e of flatten(outline(d))) {
            const v = valueAt(d, e.path);
            const def = typeDef(v.type);
            for (const p of def.props.filter((x) => x.kind === 'boolean' && typeof v[x.name] === 'boolean')) {
                const flipped = good(setProp(d, e.path, p.name, !v[p.name])).doc;
                const back = good(setProp(flipped, e.path, p.name, v[p.name])).doc;
                // a value equal to the default is not written, so an explicit default ("allowClear": true) may be gone afterwards:
                // the same survey, written shorter
                const norm = (doc) => {
                    const root = toValue(doc.root);
                    const el = e.path.reduce((o, k) => o[k], root);
                    if (el[p.name] === p.default) { delete el[p.name]; }
                    return root;
                };
                assert.deepEqual(norm(back), norm(d), `${f} ${formatPath(e.path)}.${p.name}`);
                // the text outside this element is the same as before
                const node = d.root; void node;
                const from = parseDoc(text);
                const span = (doc) => { let n = doc.root; for (const s of e.path) { n = n.type === 'object' ? n.members.find((m) => m.key === s).node : n.items[s]; } return n; };
                const a = span(from);
                const b = span(flipped);
                assert.equal(flipped.text.slice(0, b.start), text.slice(0, a.start), `${f} ${formatPath(e.path)} text before`);
                assert.equal(flipped.text.slice(b.end), text.slice(a.end), `${f} ${formatPath(e.path)} text after`);
            }
        }
    }
});

// ---- rename ------------------------------------------------------------------------------------------------------

test('renameElement: validation, data key warning, references', () => {
    const d = load({ pages: [{ name: 'page1', elements: [
        { type: 'text', name: 'vorname' },
        { type: 'text', name: 'x', visibleIf: '{vorname} notempty', title: 'Hallo {vorname}' },
        { type: 'html', name: 'h' },
    ] }] });
    const at = ['pages', 0, 'elements', 0];
    assert.equal(renameElement(d, at, 'x', { rules: RULES }).code, 'duplicate');
    assert.equal(renameElement(d, at, '_fieldTypes', { rules: RULES }).code, 'reserved');
    assert.equal(renameElement(d, at, 'a{b}', { rules: RULES }).code, 'characters');
    assert.equal(renameElement(d, at, '', { rules: RULES }).code, 'empty');
    const same = good(renameElement(d, at, 'vorname', { rules: RULES }));
    assert.equal(same.doc.text, d.text);
    const kept = good(renameElement(d, at, 'first', { rules: RULES }));
    assert.equal(valueAt(kept.doc, [...at, 'name']), 'first');
    assert.equal(kept.changesDataKey, true, 'a data field: its answers would be stored under a new key');
    assert.deepEqual(kept.references.map((r) => formatPath(r.path)), ['pages[0].elements[1].visibleIf', 'pages[0].elements[1].title']);
    assert.equal(kept.updated, 0);
    assert.equal(valueAt(kept.doc, ['pages', 0, 'elements', 1, 'visibleIf']), '{vorname} notempty', 'references are left alone by default');
    assert.equal(good(renameElement(d, ['pages', 0, 'elements', 2], 'info', { rules: RULES })).changesDataKey, false, 'html is not a data field');
});

test('renameElement with updateReferences points references at the new name, and only those', () => {
    const d = load({ pages: [{ elements: [
        { type: 'text', name: 'vorname' },
        { type: 'text', name: 'vorname2' },
        { type: 'text', name: 'x', visibleIf: '{vorname} notempty and {vorname2} notempty', title: 'Hallo {vorname}, "{vorname}"' },
    ] }], calculatedValues: [{ name: 'c', expression: '{vorname} + 1' }] });
    const r = good(renameElement(d, ['pages', 0, 'elements', 0], 'first', { rules: RULES, updateReferences: true }));
    assert.equal(r.updated, 3);
    const v = toValue(r.doc.root);
    assert.equal(v.pages[0].elements[2].visibleIf, '{first} notempty and {vorname2} notempty');
    assert.equal(v.pages[0].elements[2].title, 'Hallo {first}, "{first}"');
    assert.equal(v.calculatedValues[0].expression, '{first} + 1');
    assert.equal(v.pages[0].elements[1].name, 'vorname2');
});

test('renameElement: pages, unnamed elements, unknown types', () => {
    const d = load({ pages: [{ name: 'page1', elements: [{ type: 'html' }, { type: 'matrix', name: 'm' }] }, { name: 'page2', elements: [] }] });
    assert.equal(valueAt(good(renameElement(d, ['pages', 0], 'intro', { rules: RULES })).doc, ['pages', 0, 'name']), 'intro');
    assert.equal(renameElement(d, ['pages', 0], 'page2', { rules: RULES }).code, 'duplicate');
    assert.equal(good(renameElement(d, ['pages', 0], 'intro', { rules: RULES })).changesDataKey, false);
    assert.equal(valueAt(good(renameElement(d, ['pages', 0, 'elements', 0], 'hinweis', { rules: RULES })).doc, ['pages', 0, 'elements', 0, 'name']), 'hinweis', 'a name is added');
    assert.equal(renameElement(d, ['pages', 0, 'elements', 1], 'm2', { rules: RULES }).code, 'unknownType');
});

// ---- add / remove / move -----------------------------------------------------------------------------------------

test('addElement: every type, into every survey, builds in SurveyJS with unique names and a valid text', () => {
    for (const f of surveyFiles) {
        const d = load(read(f));
        const before = countElements(d);
        for (const t of Object.keys(TYPES)) {
            const r = good(addElement(d, ['pages', 0], 0, t, { rules: RULES, texts: { title: 'Neu', choices: ['a'] } }));
            JSON.parse(r.doc.text);
            assert.equal(countElements(r.doc), before + 1);
            assertServerNameRule(r.doc, `${f}+${t}`);
            const survey = new Survey.Model(JSON.parse(r.doc.text));
            const made = TYPES[t].container ? survey.getPanelByName(r.name) : survey.getQuestionByName(r.name);
            assert.equal(made.getType(), t, `${f} ${t}`);
            assert.deepEqual(r.path, ['pages', 0, 'elements', 0]);
            // everything else is the original text: removing the new element restores it exactly
            assert.equal(good(removeElement(r.doc, r.path)).doc.text, d.text, `${f} ${t} add + remove`);
        }
    }
});

test('addElement: position, parents, limits', () => {
    const d = load({ pages: [{ name: 'p', elements: [{ type: 'text', name: 'question1' }, { type: 'panel', name: 'panel1', elements: [] }] }, { name: 'q' }] });
    const mid = good(addElement(d, ['pages', 0], 1, 'comment', { rules: RULES }));
    assert.deepEqual(toValue(mid.doc.root).pages[0].elements.map((e) => e.name), ['question1', 'question2', 'panel1']);
    const inPanel = good(addElement(d, ['pages', 0, 'elements', 1], 0, 'text', { rules: RULES }));
    assert.equal(toValue(inPanel.doc.root).pages[0].elements[1].elements[0].name, 'question2');
    const emptyPage = good(addElement(d, ['pages', 1], 0, 'text', { rules: RULES }));
    assert.deepEqual(toValue(emptyPage.doc.root).pages[1].elements.map((e) => e.name), ['question2'], 'a page without elements gets the array');
    assert.equal(addElement(d, ['pages', 0, 'elements', 0], 0, 'text', { rules: RULES }).code, 'notContainer');
    assert.equal(addElement(d, ['pages', 0], 5, 'text', { rules: RULES }).code, 'index');
    assert.equal(addElement(d, ['pages', 0], -1, 'text', { rules: RULES }).code, 'index');
    assert.equal(addElement(d, ['pages', 7], 0, 'text', { rules: RULES }).code, 'path');
    assert.equal(addElement(d, ['pages', 0], 0, 'matrix', { rules: RULES }).code, 'unknownType');
    // the survey has 2 elements (a question and a panel)
    assert.equal(addElement(d, ['pages', 0], 0, 'text', { rules: { ...RULES, maxElements: 2 } }).code, 'tooManyElements');
    assert.equal(good(addElement(d, ['pages', 0], 0, 'text', { rules: { ...RULES, maxElements: 3 } })).ok, true);
    // depth: elements inside n nested panels
    const deep = load({ pages: [{ elements: [{ type: 'panel', name: 'a', elements: [{ type: 'panel', name: 'b', elements: [] }] }] }] });
    assert.equal(addElement(deep, ['pages', 0, 'elements', 0, 'elements', 0], 0, 'text', { rules: { ...RULES, maxDepth: 1 } }).code, 'tooDeep');
    assert.equal(good(addElement(deep, ['pages', 0, 'elements', 0, 'elements', 0], 0, 'text', { rules: { ...RULES, maxDepth: 2 } })).ok, true);
});

test('addElement into a survey with elements at the top level; a survey with neither', () => {
    const top = load({ elements: [{ type: 'text', name: 'question1' }] });
    const r = good(addElement(top, [], 1, 'text', { rules: RULES }));
    assert.deepEqual(r.path, ['elements', 1]);
    assert.deepEqual(toValue(r.doc.root).elements.map((e) => e.name), ['question1', 'question2']);
    const paged = load({ pages: [{ elements: [] }] });
    assert.equal(addElement(paged, [], 0, 'text', { rules: RULES }).code, 'notContainer', 'the root is a container only without pages');
});

test('addElement never reuses a name below the highest, and gives panels their own', () => {
    let d = load({ pages: [{ name: 'page1', elements: [] }] });
    const names = [];
    for (const t of ['text', 'panel', 'text', 'html', 'panel']) {
        const r = good(addElement(d, ['pages', 0], 0, t, { rules: RULES }));
        names.push(r.name);
        d = r.doc;
    }
    assert.deepEqual(names, ['question1', 'panel1', 'question2', 'question3', 'panel2']);
});

test('removeElement: path check; reports what still refers to the name', () => {
    const d = load({ pages: [{ elements: [{ type: 'text', name: 'a' }, { type: 'text', name: 'b', visibleIf: '{a} = 1' }] }] });
    const r = good(removeElement(d, ['pages', 0, 'elements', 0]));
    assert.deepEqual(r.references.map((x) => formatPath(x.path)), ['pages[0].elements[1].visibleIf']);
    assert.deepEqual(toValue(r.doc.root).pages[0].elements.map((e) => e.name), ['b']);
    assert.equal(removeElement(d, ['pages', 0, 'elements', 5]).code, 'path');
    assert.equal(removeElement(d, ['pages', 0]).code, 'path');
    assert.equal(removeElement(d, []).code, 'path');
});

test('moveElement: the texts change places, the layout stays; paths and bounds', () => {
    const text = '{\n  "pages": [\n    {\n      "elements": [\n        {"type": "text", "name": "a"},\n        {\n          "type": "text",\n          "name": "b"\n        },\n        {"type": "text", "name": "c"}\n      ]\n    }\n  ]\n}';
    const d = load(text);
    const down = good(moveElement(d, ['pages', 0, 'elements', 0], 2));
    assert.deepEqual(toValue(down.doc.root).pages[0].elements.map((e) => e.name), ['b', 'c', 'a']);
    assert.deepEqual(down.path, ['pages', 0, 'elements', 2]);
    assert.equal(down.doc.text.length, text.length, 'nothing lost, nothing added');
    assert.equal(good(moveElement(down.doc, down.path, 0)).doc.text, text, 'and back');
    assert.equal(moveElement(d, ['pages', 0, 'elements', 0], 3).code, 'index');
    assert.equal(moveElement(d, ['pages', 0, 'elements', 9], 0).code, 'path');
    assert.equal(good(moveElement(d, ['pages', 0, 'elements', 1], 1)).doc.text, text, 'same place: no change');
});

test('moveElement changes the order SurveyJS shows', () => {
    const d = load(read('gs.json'));
    const [page] = outline(d);
    const names = page.elements.map((e) => e.name);
    const moved = good(moveElement(d, page.elements[0].path, page.elements.length - 1)).doc;
    const order = (doc) => new Survey.Model(JSON.parse(doc.text)).pages[0].elements.map((e) => e.name);
    assert.deepEqual(order(moved), [...names.slice(1), names[0]]);
});

test('pages: add, remove, move; the last page stays; top-level elements have no pages', () => {
    const d = load({ pages: [{ name: 'page1', elements: [{ type: 'text', name: 'question1' }] }, { name: 'page2', elements: [] }] });
    const added = good(addPage(d, 1, { rules: RULES, texts: { title: 'Neue Seite' } }));
    assert.deepEqual(toValue(added.doc.root).pages.map((p) => p.name), ['page1', 'page3', 'page2']);
    assert.deepEqual(toValue(added.doc.root).pages[1], { name: 'page3', title: 'Neue Seite', elements: [] });
    assert.deepEqual(added.path, ['pages', 1]);
    assert.deepEqual(toValue(good(movePage(added.doc, 1, 0)).doc.root).pages.map((p) => p.name), ['page3', 'page1', 'page2']);
    assert.deepEqual(toValue(good(removePage(added.doc, 0)).doc.root).pages.map((p) => p.name), ['page3', 'page2']);
    assert.equal(removePage(load({ pages: [{ name: 'only' }] }), 0).code, 'lastPage');
    assert.equal(removePage(d, 5).code, 'path');
    assert.equal(movePage(d, 0, 5).code, 'index');
    assert.equal(addPage(d, 9, { rules: RULES }).code, 'index');
    assert.equal(addPage(load({ elements: [{ type: 'text', name: 'a' }] }), 0, { rules: RULES }).code, 'implicitPage');
    assert.deepEqual(toValue(good(addPage(load({ title: 'x' }), 0, { rules: RULES })).doc.root).pages, [{ name: 'page1', elements: [] }]);
    assert.equal(good(addPage(d, 2, { rules: RULES })).ok, true, 'appending');
});

// ---- differential test -------------------------------------------------------------------------------------------

/** Small seeded generator: the same run every time. */
function prng(seed) {
    let s = seed >>> 0;
    return () => { s = (Math.imul(s, 1664525) + 1013904223) >>> 0; return s / 2 ** 32; };
}

test('random sequences of operations on every survey agree with the same operations on a plain object', () => {
    const OPS = 120;
    for (const [fi, f] of surveyFiles.entries()) {
        const rand = prng(1000 + fi);
        const pick = (arr) => arr[Math.floor(rand() * arr.length)];
        let doc = load(read(f));
        const model = JSON.parse(read(f)); // the oracle
        const target = (path) => path.reduce((o, k) => o[k], model);
        for (let step = 0; step < OPS; step++) {
            const els = elementsOf(doc);
            const containers = [...(model.pages ?? []).map((_, i) => ['pages', i]), ...els.filter((e) => e.value.type === 'panel').map((e) => e.path)];
            const op = pick(['add', 'add', 'remove', 'move', 'title', 'required', 'rename']);
            let label = `${f} #${step} ${op}`;
            if (op === 'add') {
                const parent = pick(containers);
                const list = valueAt(doc, [...parent, 'elements']) ?? [];
                const index = Math.floor(rand() * (list.length + 1));
                const type = pick(Object.keys(TYPES));
                const r = good(addElement(doc, parent, index, type, { rules: RULES }));
                const holder = target(parent);
                holder.elements ??= [];
                holder.elements.splice(index, 0, template(type, r.name));
                doc = r.doc;
                label += ` ${type}`;
            } else if (op === 'remove' && els.length > 3) {
                const e = pick(els);
                doc = good(removeElement(doc, e.path)).doc;
                const arr = target(e.path.slice(0, -1));
                arr.splice(e.path[e.path.length - 1], 1);
            } else if (op === 'move') {
                const e = pick(els);
                const arrPath = e.path.slice(0, -1);
                const to = Math.floor(rand() * valueAt(doc, arrPath).length);
                doc = good(moveElement(doc, e.path, to)).doc;
                const arr = target(arrPath);
                arr.splice(to, 0, arr.splice(e.path[e.path.length - 1], 1)[0]);
            } else if (op === 'title') {
                const e = pick(els);
                const text = pick(['Neu', 'Ä "x" \\ y', '', 'Zeile\nzwei']);
                const r = setProp(doc, e.path, 'title', text);
                if (r.ok) {
                    doc = r.doc;
                    const el = target(e.path);
                    if (typeof el.title === 'object' && el.title !== null) {
                        const key = 'default' in el.title || !('de' in el.title) ? 'default' : 'de';
                        if (text === '') { delete el.title[key]; if (Object.keys(el.title).length === 0) { delete el.title; } } else { el.title[key] = text; }
                    } else if (text === '') { delete el.title; } else { el.title = text; }
                }
            } else if (op === 'required') {
                const e = pick(els.filter((x) => typeDef(x.value.type)?.props.some((p) => p.name === 'isRequired')));
                if (e) {
                    const next = !(e.value.isRequired === true);
                    doc = good(setProp(doc, e.path, 'isRequired', next)).doc;
                    const el = target(e.path);
                    if (next) { el.isRequired = true; } else { delete el.isRequired; }
                }
            } else if (op === 'rename') {
                const e = pick(els.filter((x) => typeDef(x.value.type)));
                if (e) {
                    const name = `n${step}_${Math.floor(rand() * 99)}`;
                    const r = renameElement(doc, e.path, name, { rules: RULES });
                    assert.equal(r.ok, true, label);
                    doc = r.doc;
                    target(e.path).name = name;
                }
            }
            assert.deepEqual(toValue(doc.root), model, label);
            assert.deepEqual(JSON.parse(doc.text), model, `${label}: text`);
            assertServerNameRule(doc, label);
            if (step % 15 === 0) { assert.doesNotThrow(() => new Survey.Model(JSON.parse(doc.text)), label); }
        }
    }
});

// ---- choices -------------------------------------------------------------------------------------------------------

import { setChoices, otherProperties } from '../../public/assets/survey-builder/model.js';

test('setChoices touches only the entries that changed (compact list stays compact)', () => {
    const text = '{\n  "pages": [\n    {\n      "elements": [\n        {\n          "type": "dropdown",\n          "name": "land",\n          "choices": [\n            {"value": "AFG", "text": "Afghanistan"},\n            {"value": "ALB", "text": "Albanien"},\n            "Frankreich",\n            {"value": "ZYP", "text": "Zypern"}\n          ]\n        }\n      ]\n    }\n  ]\n}';
    const d = load(text);
    const at = ['pages', 0, 'elements', 0];
    const lines = (t) => t.split('\n');
    const changed = (a, b) => lines(a).filter((l, i) => l !== lines(b)[i]).length;

    const edited = setChoices(d, at, [{ value: 'AFG', text: 'Afghanistan' }, { value: 'ALB', text: 'Albanien (Land)' }, 'Frankreich', { value: 'ZYP', text: 'Zypern' }]);
    assert.equal(lines(edited.text).length, lines(text).length);
    assert.equal(changed(text, edited.text), 1, 'one entry, one line');
    assert.ok(edited.text.includes('{"value": "ALB", "text": "Albanien (Land)"},'));

    const added = setChoices(d, at, [...valueAt(d, [...at, 'choices']), { value: 'NEU', text: 'Neu' }]);
    assert.ok(added.text.includes('{"value": "ZYP", "text": "Zypern"},\n            {"value": "NEU", "text": "Neu"}\n'), 'one more line, in the style of the list');

    const removed = setChoices(d, at, valueAt(d, [...at, 'choices']).filter((c) => c !== 'Frankreich'));
    assert.equal(lines(removed.text).length, lines(text).length - 1);
    assert.deepEqual(valueAt(removed, [...at, 'choices']).length, 3);

    assert.equal(setChoices(d, at, valueAt(d, [...at, 'choices'])).text, text, 'no change, no new text');
    const reordered = setChoices(d, at, [...valueAt(d, [...at, 'choices'])].reverse());
    assert.deepEqual(valueAt(reordered, [...at, 'choices']), [...valueAt(d, [...at, 'choices'])].reverse());
    const cleared = setChoices(d, at, []);
    assert.deepEqual(valueAt(cleared, [...at, 'choices']), []);
    JSON.parse(cleared.text);
});

test('setChoices keeps other keys of an entry; an entry that loses its text loses only that', () => {
    const d = load({ pages: [{ elements: [{ type: 'radiogroup', name: 'r', choices: [{ value: 'a', text: 'A', visibleIf: '{x} = 1' }, 'b'] }] }] });
    const at = ['pages', 0, 'elements', 0];
    const next = setChoices(d, at, [{ value: 'a', text: 'A2', visibleIf: '{x} = 1' }, 'b']);
    assert.deepEqual(valueAt(next, [...at, 'choices', 0]), { value: 'a', text: 'A2', visibleIf: '{x} = 1' });
    const noText = setChoices(d, at, [{ value: 'a', visibleIf: '{x} = 1' }, 'b']);
    assert.deepEqual(valueAt(noText, [...at, 'choices', 0]), { value: 'a', visibleIf: '{x} = 1' });
});

test('setProp choices goes through setChoices and keeps the layout; absent choices are added', () => {
    const d = load({ pages: [{ elements: [{ type: 'dropdown', name: 'd' }] }] });
    const at = ['pages', 0, 'elements', 0];
    assert.deepEqual(valueAt(good(setProp(d, at, 'choices', ['a', 'b'])).doc, [...at, 'choices']), ['a', 'b']);
});

test('otherProperties lists what the builder does not edit', () => {
    assert.deepEqual(otherProperties({ type: 'text', name: 'a', isRequired: true, weird: 1, other: { x: 1 } }), ['weird', 'other']);
    assert.deepEqual(otherProperties({ type: 'matrix', name: 'm', rows: [] }), ['name', 'rows'], 'unknown type: everything');
    assert.deepEqual(otherProperties({ type: 'panel', name: 'p', elements: [] }), []);
});

test('addElement and addPage keep away from the names the caller passes (published version, removed in this session)', () => {
    const d = load({ pages: [{ name: 'page1', elements: [{ type: 'text', name: 'question1' }] }] });
    assert.equal(good(addElement(d, ['pages', 0], 1, 'text', { rules: RULES, taken: ['question2', 'question5'] })).name, 'question6');
    assert.equal(good(addPage(d, 1, { rules: RULES, taken: ['page4'] })).name, 'page5');
});

test('otherProperties of a page: the type is passed in, a page has none of its own', () => {
    assert.deepEqual(otherProperties({ name: 'p', title: 'T', elements: [], navigationTitle: 'x' }, 'page'), ['navigationTitle']);
});

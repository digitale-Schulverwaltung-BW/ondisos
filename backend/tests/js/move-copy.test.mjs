// Tests for moving elements between pages and panels and for duplicating them (AP4). Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { loadModel, outline, flatten, moveElementTo, duplicateElement, removeElement, formatPath }
    from '../../public/assets/survey-builder/model.js';
import { toValue, valueAt, sourceAt } from '../../public/assets/survey-builder/json-doc.js';
import { elementsOf } from '../../public/assets/survey-builder/names.js';
import { isKnownType } from '../../public/assets/survey-builder/registry.js';

const require = createRequire(import.meta.url);
const Survey = require('../../../frontend/public/assets/survey.core.min.js');
const RULES = JSON.parse(readFileSync(new URL('./fixtures/survey-rules.json', import.meta.url), 'utf8'));
const SURVEYS = new URL('../../../frontend/surveys/', import.meta.url);
const surveyFiles = readdirSync(SURVEYS).filter((f) => f.endsWith('.json') && f !== 'survey_theme.json').sort();
const read = (f) => readFileSync(new URL(f, SURVEYS), 'utf8');
const load = (t) => { const m = loadModel(typeof t === 'string' ? t : JSON.stringify(t, null, 2)); assert.ok(m.ok); return m.doc; };
const good = (r) => { assert.equal(r.ok, true, JSON.stringify(r)); return r; };
const builds = (doc) => new Survey.Model(toValue(doc.root));
/** The text without indentation: what must survive a move (only the depth changes). */
const flat = (text) => text.split(/\r?\n/).map((l) => l.trim()).join('\n');

function assertServerNameRule(doc, label) {
    const seen = new Set();
    for (const e of elementsOf(doc)) {
        const t = e.value.type;
        if (t === 'panel' || RULES.displayTypes.includes(t)) { continue; }
        assert.ok(!seen.has(e.value.name), `${label}: duplicate ${e.value.name}`);
        seen.add(e.value.name);
    }
}

/** Containers of a survey as paths: pages, panels (and [] for a survey with top-level elements). */
function containers(doc) {
    const out = outline(doc).map((p) => p.path);
    for (const e of flatten(outline(doc))) { if (e.type === 'panel') { out.push(e.path); } }
    return out;
}

/** The same move on a plain object: what the text must come to. */
function plainMove(root, path, parentPath, index) {
    const at = (p) => p.reduce((o, k) => o[k], root);
    const arr = at(path.slice(0, -1));
    const parent = at(parentPath);
    const [el] = arr.splice(path[path.length - 1], 1);
    if (!parent.elements) { parent.elements = []; }
    const sameList = arr === parent.elements;
    parent.elements.splice(sameList && index > path[path.length - 1] ? index - 1 : index, 0, el);
    return root;
}

// ---- moveElementTo -------------------------------------------------------------------------------------------------

test('moveElementTo: into another page, into a panel, out of a panel; the text of the element moves as it is', () => {
    const d = load({ pages: [
        { name: 'p1', elements: [{ type: 'text', name: 'a' }, { type: 'panel', name: 'g', elements: [{ type: 'text', name: 'b' }] }] },
        { name: 'p2', elements: [{ type: 'text', name: 'c' }] },
    ] });
    const toPage = good(moveElementTo(d, ['pages', 0, 'elements', 0], ['pages', 1], 1));
    assert.deepEqual(toPage.path, ['pages', 1, 'elements', 1]);
    assert.deepEqual(valueAt(toPage.doc, ['pages', 1, 'elements']).map((e) => e.name), ['c', 'a']);
    assert.deepEqual(valueAt(toPage.doc, ['pages', 0, 'elements']).map((e) => e.name), ['g'], 'the panel moved up');

    const intoPanel = good(moveElementTo(d, ['pages', 0, 'elements', 0], ['pages', 0, 'elements', 1], 0));
    assert.deepEqual(intoPanel.path, ['pages', 0, 'elements', 0, 'elements', 0], 'the panel is now the first element');
    assert.deepEqual(valueAt(intoPanel.doc, intoPanel.path.slice(0, -2)).elements.map((e) => e.name), ['a', 'b']);

    const outOfPanel = good(moveElementTo(d, ['pages', 0, 'elements', 1, 'elements', 0], ['pages', 0], 0));
    assert.deepEqual(valueAt(outOfPanel.doc, ['pages', 0, 'elements']).map((e) => e.name), ['b', 'a', 'g']);
    assert.deepEqual(valueAt(outOfPanel.doc, ['pages', 0, 'elements', 2, 'elements']), []);
});

test('moveElementTo in its own container: the index is the insertion point, the layout stays as with moveElement', () => {
    const d = load({ pages: [{ elements: [{ type: 'text', name: 'a' }, { type: 'text', name: 'b' }, { type: 'text', name: 'c' }] }] });
    const names = (r) => valueAt(good(r).doc, ['pages', 0, 'elements']).map((e) => e.name);
    assert.deepEqual(names(moveElementTo(d, ['pages', 0, 'elements', 0], ['pages', 0], 3)), ['b', 'c', 'a'], 'behind the last');
    assert.deepEqual(names(moveElementTo(d, ['pages', 0, 'elements', 0], ['pages', 0], 2)), ['b', 'a', 'c'], 'before c');
    assert.deepEqual(names(moveElementTo(d, ['pages', 0, 'elements', 2], ['pages', 0], 0)), ['c', 'a', 'b']);
    assert.equal(good(moveElementTo(d, ['pages', 0, 'elements', 1], ['pages', 0], 1)).doc.text, d.text, 'before itself: no change');
    assert.equal(good(moveElementTo(d, ['pages', 0, 'elements', 1], ['pages', 0], 2)).doc.text, d.text, 'behind itself: no change');
});

test('moveElementTo refuses what makes no sense', () => {
    const d = load({ pages: [{ elements: [{ type: 'panel', name: 'g', elements: [{ type: 'panel', name: 'h', elements: [] }] }, { type: 'text', name: 'a' }] }] });
    const g = ['pages', 0, 'elements', 0];
    assert.equal(moveElementTo(d, g, g, 0).code, 'intoItself');
    assert.equal(moveElementTo(d, g, [...g, 'elements', 0], 0).code, 'intoItself', 'not into its own child either');
    assert.equal(moveElementTo(d, g, ['pages', 0, 'elements', 1], 0).code, 'notContainer', 'a question holds nothing');
    assert.equal(moveElementTo(d, g, ['pages', 3], 0).code, 'notContainer');
    assert.equal(moveElementTo(d, g, [], 0).code, 'notContainer', 'a survey with pages holds no elements of its own');
    assert.equal(moveElementTo(d, ['pages', 0, 'elements', 7], ['pages', 0], 0).code, 'path');
    assert.equal(moveElementTo(d, ['pages', 0], ['pages', 0], 0).code, 'path', 'pages are moved with movePage');
    assert.equal(moveElementTo(d, ['pages', 0, 'elements', 1], [...g, 'elements', 0], 1).code, 'index');
    assert.equal(moveElementTo(d, ['pages', 0, 'elements', 1], [...g, 'elements', 0], -1).code, 'index');
    const shallow = { ...RULES, maxDepth: 2 };
    assert.equal(moveElementTo(d, g, ['pages', 0], 2, { rules: shallow }).ok, true, 'same depth is fine');
    const deep = load({ pages: [{ elements: [{ type: 'panel', name: 'x', elements: [] }, { type: 'panel', name: 'g', elements: [{ type: 'panel', name: 'h', elements: [{ type: 'text', name: 'q' }] }] }] }] });
    assert.equal(moveElementTo(deep, ['pages', 0, 'elements', 1], ['pages', 0, 'elements', 0], 0, { rules: shallow }).code, 'tooDeep');
    assert.equal(moveElementTo(deep, ['pages', 0, 'elements', 1], ['pages', 0, 'elements', 0], 0, { rules: { ...RULES, maxDepth: 3 } }).ok, true);
});

test('moveElementTo into a page or panel without "elements", and in a survey with top-level elements', () => {
    const d = load({ pages: [{ name: 'p1', elements: [{ type: 'text', name: 'a' }, { type: 'panel', name: 'g' }] }, { name: 'p2' }] });
    const r = good(moveElementTo(d, ['pages', 0, 'elements', 0], ['pages', 1], 0));
    assert.deepEqual(valueAt(r.doc, ['pages', 1, 'elements']), [{ type: 'text', name: 'a' }]);
    assert.deepEqual(r.path, ['pages', 1, 'elements', 0]);
    const p = good(moveElementTo(d, ['pages', 0, 'elements', 0], ['pages', 0, 'elements', 1], 0));
    assert.deepEqual(valueAt(p.doc, ['pages', 0, 'elements', 0, 'elements']), [{ type: 'text', name: 'a' }]);
    const top = load({ elements: [{ type: 'text', name: 'a' }, { type: 'panel', name: 'g', elements: [] }] });
    const t = good(moveElementTo(top, ['elements', 0], ['elements', 1], 0));
    assert.deepEqual(valueAt(t.doc, ['elements', 0, 'elements', 0, 'name']), 'a');
    const back = good(moveElementTo(t.doc, ['elements', 0, 'elements', 0], [], 0));
    assert.equal(back.doc.text, top.text, 'and back: the text as before');
});

test('moving every element of every survey to every other container agrees with a plain object, and SurveyJS builds it', () => {
    let moves = 0;
    let pretty = 0;
    for (const f of surveyFiles) {
        const d = load(read(f));
        const creatorFormat = JSON.stringify(JSON.parse(d.text), null, 2) === d.text;
        const els = flatten(outline(d)).filter((e) => e.path.length <= 6);
        const targets = containers(d);
        for (const e of els.filter((_, i) => i % 3 === 0)) {
            for (const target of targets.filter((_, i) => i % 2 === 0)) {
                const r = moveElementTo(d, e.path, target, 0, { rules: RULES });
                if (!r.ok) {
                    assert.ok(['intoItself', 'notContainer'].includes(r.code), `${f}: ${formatPath(e.path)} → ${formatPath(target)}: ${r.code}`);
                    continue;
                }
                const expected = plainMove(toValue(d.root), e.path, target, 0);
                assert.deepEqual(toValue(r.doc.root), expected, `${f}: ${formatPath(e.path)} → ${formatPath(target)}`);
                assert.deepEqual(JSON.parse(r.doc.text), expected, 'valid JSON');
                assert.equal(flat(sourceAt(r.doc, r.path).text), flat(sourceAt(d, e.path).text), 'the element is the same text');
                if (creatorFormat) {
                    // at any depth the element is indented like the rest: the text is what the Creator would write
                    assert.equal(r.doc.text, JSON.stringify(expected, null, 2), `${f}: ${formatPath(e.path)} → ${formatPath(target)} (layout)`);
                    pretty += 1;
                }
                if (moves % 20 === 0) { builds(r.doc); }
                moves += 1;
            }
        }
    }
    assert.ok(moves > 200, `moves: ${moves}`);
    assert.ok(pretty > 100, `layout checked: ${pretty}`);
});

test('a move and the move back give the original text again (surveys written like the Creator exports them)', () => {
    let checked = 0;
    for (const f of surveyFiles) {
        const text = read(f);
        if (JSON.stringify(JSON.parse(text), null, 2) !== text) { continue; }
        const d = load(text);
        const pages = outline(d);
        if (pages.length < 2) { continue; }
        for (const e of pages[0].elements) {
            const there = good(moveElementTo(d, e.path, pages[1].path, 0));
            const index = e.path[e.path.length - 1];
            const back = good(moveElementTo(there.doc, there.path, pages[0].path, index));
            assert.equal(back.doc.text, text, `${f}: ${e.name}`);
            checked += 1;
        }
    }
    assert.ok(checked >= 5, `checked: ${checked}`);
});

test('a compact list keeps its one-line entries when its question moves between pages (bs.json)', () => {
    const d = load(read('bs.json'));
    const compact = flatten(outline(d)).find((e) => {
        const src = sourceAt(d, e.path).text;
        return Array.isArray(valueAt(d, [...e.path, 'choices'])) && /\n\s*\{"value"/.test(src);
    });
    assert.ok(compact, 'bs.json has a question with one answer per line');
    const lines = sourceAt(d, compact.path).text.split('\n').length;
    const pages = outline(d);
    const target = pages.find((p) => !p.path.every((s, i) => s === compact.path[i]));
    const r = good(moveElementTo(d, compact.path, target.path, 0));
    assert.equal(sourceAt(r.doc, r.path).text.split('\n').length, lines, 'same number of lines');
    assert.equal(r.doc.text.split('\n').length, d.text.split('\n').length, 'the whole text has as many lines as before');
});

// ---- duplicateElement ------------------------------------------------------------------------------------------------

test('duplicateElement: the copy follows the original, with new names; removing it gives the original text', () => {
    const d = load({ pages: [{ elements: [{ type: 'text', name: 'vorname', title: 'Vorname', isRequired: true }, { type: 'text', name: 'x' }] }] });
    const r = good(duplicateElement(d, ['pages', 0, 'elements', 0], { rules: RULES }));
    assert.deepEqual(r.path, ['pages', 0, 'elements', 1]);
    assert.deepEqual(r.names, { vorname: 'vorname_2' });
    assert.deepEqual(valueAt(r.doc, r.path), { type: 'text', name: 'vorname_2', title: 'Vorname', isRequired: true });
    assert.equal(good(removeElement(r.doc, r.path)).doc.text, d.text);
});

test('duplicateElement: the next free name; names that are taken, reserved or used anywhere are skipped', () => {
    const d = load({
        pages: [{ name: 'p', elements: [{ type: 'text', name: 'a' }, { type: 'text', name: 'a_2' }, { type: 'text', name: 'b_7' }] }],
        calculatedValues: [{ name: 'a_3', expression: '1' }],
    });
    assert.deepEqual(good(duplicateElement(d, ['pages', 0, 'elements', 0])).names, { a: 'a_4' }, 'a_2 is a field, a_3 a calculated value');
    assert.deepEqual(good(duplicateElement(d, ['pages', 0, 'elements', 1])).names, { a_2: 'a_4' }, 'a copy of a copy counts on');
    assert.deepEqual(good(duplicateElement(d, ['pages', 0, 'elements', 2])).names, { b_7: 'b_2' });
    assert.deepEqual(good(duplicateElement(d, ['pages', 0, 'elements', 0], { taken: ['a_4', 'a_5'] })).names, { a: 'a_6' }, 'published names');
    const r = load({ pages: [{ elements: [{ type: 'text', name: 'website_url' }] }] });
    assert.deepEqual(good(duplicateElement(r, ['pages', 0, 'elements', 0], { rules: { ...RULES, reservedNames: ['website_url', 'website_url_2'] } })).names,
        { website_url: 'website_url_3' });
});

test('duplicateElement of a panel: every name inside is new, references inside the copy follow, those to outside stay', () => {
    const d = load({ pages: [{ elements: [
        { type: 'radiogroup', name: 'schule', choices: ['A', 'B'] },
        { type: 'panel', name: 'kind', elements: [
            { type: 'text', name: 'name' },
            { type: 'text', name: 'alter', visibleIf: "{name} notempty and {schule} = 'A'", title: 'Alter von {name}' },
            { type: 'checkbox', name: 'wahl', choices: [{ value: 'x', elements: [{ type: 'text', name: 'wahl_x' }] }] },
        ] },
    ] }] });
    const r = good(duplicateElement(d, ['pages', 0, 'elements', 1], { rules: RULES }));
    assert.deepEqual(r.names, { kind: 'kind_2', name: 'name_2', alter: 'alter_2', wahl: 'wahl_2', wahl_x: 'wahl_x_2' });
    const copy = valueAt(r.doc, r.path);
    assert.equal(copy.elements[1].visibleIf, "{name_2} notempty and {schule} = 'A'");
    assert.equal(copy.elements[1].title, 'Alter von {name_2}');
    assert.equal(copy.elements[2].choices[0].elements[0].name, 'wahl_x_2', 'questions in choice options get new names too');
    assert.deepEqual(valueAt(r.doc, ['pages', 0, 'elements', 1]), toValue(d.root).pages[0].elements[1], 'the original is untouched');
    assertServerNameRule(r.doc, 'panel copy');
    assert.equal(good(removeElement(r.doc, r.path)).doc.text, d.text);
});

test('duplicateElement refuses unknown types and the element limit', () => {
    const d = load({ pages: [{ elements: [
        { type: 'paneldynamic', name: 'pd', templateElements: [{ type: 'text', name: 'x' }] },
        { type: 'panel', name: 'g', elements: [{ type: 'matrix', name: 'm' }] },
        { type: 'text', name: 't' },
    ] }] });
    assert.equal(duplicateElement(d, ['pages', 0, 'elements', 0]).code, 'unknownType', 'its inner names would clash');
    assert.equal(duplicateElement(d, ['pages', 0, 'elements', 1]).code, 'unknownType', 'nor a panel holding one');
    assert.equal(duplicateElement(d, ['pages', 0, 'elements', 2], { rules: { ...RULES, maxElements: 4 } }).code, 'tooManyElements');
    assert.equal(duplicateElement(d, ['pages', 0, 'elements', 2], { rules: { ...RULES, maxElements: 5 } }).ok, true);
    assert.equal(duplicateElement(d, ['pages', 0]).code, 'path');
});

test('duplicating every element of every survey: valid for the server rule, SurveyJS builds it, removal restores the text', () => {
    let copies = 0;
    for (const f of surveyFiles) {
        const text = read(f);
        const d = load(text);
        for (const e of flatten(outline(d))) {
            const r = duplicateElement(d, e.path, { rules: RULES });
            if (!r.ok) {
                assert.equal(r.code, 'unknownType', `${f}: ${formatPath(e.path)}`);
                assert.ok(!isKnownType(e.type) || e.children.length > 0, 'only unknown types or panels holding one');
                continue;
            }
            assertServerNameRule(r.doc, `${f}: ${formatPath(e.path)}`);
            assert.deepEqual(JSON.parse(r.doc.text), toValue(r.doc.root), 'valid JSON');
            if (JSON.stringify(JSON.parse(text), null, 2) === text) {
                assert.equal(r.doc.text, JSON.stringify(toValue(r.doc.root), null, 2), `${f}: ${formatPath(e.path)} (layout of the copy)`);
            }
            assert.equal(good(removeElement(r.doc, r.path)).doc.text, text, `${f}: ${formatPath(e.path)}`);
            if (copies % 25 === 0) { builds(r.doc); }
            copies += 1;
        }
    }
    assert.ok(copies > 200, `copies: ${copies}`);
});

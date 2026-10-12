// Tests for names: generating, checking, finding references. Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { parseDoc } from '../../public/assets/survey-builder/json-doc.js';
import { elementsOf, generateName, validateName, findReferences, replaceReferences } from '../../public/assets/survey-builder/names.js';

const RULES = JSON.parse(readFileSync(new URL('./fixtures/survey-rules.json', import.meta.url), 'utf8'));
const SURVEYS = new URL('../../../frontend/surveys/', import.meta.url);
const surveyFiles = readdirSync(SURVEYS).filter((f) => f.endsWith('.json') && f !== 'survey_theme.json').sort();
const doc = (v) => parseDoc(typeof v === 'string' ? v : JSON.stringify(v));

test('elementsOf lists elements in document order: pages, nested panels, top level', () => {
    const d = doc({ pages: [{ elements: [{ name: 'a' }, { type: 'panel', name: 'p', elements: [{ name: 'b' }, { name: 'c' }] }] }, { elements: [{ name: 'd' }] }], elements: [{ name: 'e' }] });
    assert.deepEqual(elementsOf(d).map((e) => e.value.name), ['a', 'p', 'b', 'c', 'd', 'e']);
    assert.deepEqual(elementsOf(d)[2].path, ['pages', 0, 'elements', 1, 'elements', 0]);
    assert.deepEqual(elementsOf(doc({})), []);
});

test('generateName: question<N>, one above the highest; gaps are not filled', () => {
    assert.equal(generateName(doc({ pages: [{ elements: [] }] }), 'text', RULES), 'question1');
    const d = doc({ pages: [{ elements: [{ name: 'question1' }, { name: 'question3' }, { name: 'Name' }, { name: 'question' }, { name: 'question2x' }] }] });
    assert.equal(generateName(d, 'text', RULES), 'question4');
    assert.equal(generateName(d, 'html', RULES), 'question4', 'every question type shares the counter');
});

test('generateName: panels and pages have their own counter and prefix', () => {
    const d = doc({ pages: [{ name: 'page1', elements: [{ type: 'panel', name: 'panel7', elements: [{ name: 'question2' }] }] }, { name: 'page4', elements: [] }] });
    assert.equal(generateName(d, 'panel', RULES), 'panel8');
    assert.equal(generateName(d, 'page', RULES), 'page5');
    assert.equal(generateName(d, 'text', RULES), 'question3');
});

test('generateName: names the caller knows are taken, calculated values count, reserved names never come out', () => {
    const d = doc({ pages: [{ elements: [{ name: 'question1' }] }], calculatedValues: [{ name: 'question9', expression: '1' }] });
    assert.equal(generateName(d, 'text', RULES), 'question10');
    assert.equal(generateName(d, 'text', RULES, ['question12']), 'question13', 'e.g. names of the published version');
    assert.equal(generateName(d, 'text', { reservedNames: ['question10'] }), 'question11');
});

test('generateName: the number of a deleted highest element comes back unless the caller says otherwise (documented)', () => {
    const before = doc({ pages: [{ elements: [{ name: 'question1' }, { name: 'question2' }] }] });
    const after = doc({ pages: [{ elements: [{ name: 'question1' }] }] });
    assert.equal(generateName(after, 'text', RULES), 'question2');
    assert.equal(generateName(after, 'text', RULES, ['question2']), 'question3');
    assert.equal(generateName(before, 'text', RULES), 'question3');
});

test('generateName on every survey gives a name no element, page or calculated value has', () => {
    for (const f of surveyFiles) {
        const d = doc(readFileSync(new URL(f, SURVEYS), 'utf8'));
        const n = generateName(d, 'text', RULES);
        assert.ok(!elementsOf(d).some((e) => e.value.name === n), `${f} ${n}`);
        assert.ok(!RULES.reservedNames.includes(n));
    }
});

test('validateName: the codes', () => {
    const d = doc({
        pages: [{ name: 'page1', elements: [{ type: 'text', name: 'Vorname' }, { type: 'html', name: 'hinweis' }, { type: 'text', name: 'Anmeldung am' }] }, { name: 'page2', elements: [] }],
        calculatedValues: [{ name: 'summe', expression: '1' }],
    });
    const check = (name, extra = {}) => validateName(d, name, { kind: 'text', rules: RULES, ...extra });
    assert.deepEqual(check('Nachname'), { ok: true });
    assert.deepEqual(check('Nach name'), { ok: true }, 'inner spaces are fine (two real surveys use them)');
    assert.deepEqual(check('Größe'), { ok: true });
    assert.equal(check('').code, 'empty');
    assert.equal(check(5).code, 'type');
    assert.equal(check(' x').code, 'whitespace');
    assert.equal(check('x ').code, 'whitespace');
    assert.equal(check('a{b}').code, 'characters');
    assert.equal(check('a.b').code, 'characters');
    assert.equal(check('a\nb').code, 'characters');
    assert.equal(check('_fieldTypes').code, 'reserved');
    assert.equal(check('website_url').code, 'reserved');
    assert.equal(check('Vorname').code, 'duplicate');
    assert.equal(check('hinweis').code, 'duplicate', 'also display elements: keep names unique');
    assert.equal(check('summe').code, 'calculatedValue');
});

test('validateName: an element does not collide with itself; pages have their own names', () => {
    const d = doc({ pages: [{ name: 'page1', elements: [{ type: 'text', name: 'a' }] }, { name: 'page2', elements: [] }] });
    assert.deepEqual(validateName(d, 'a', { kind: 'text', path: ['pages', 0, 'elements', 0], rules: RULES }), { ok: true });
    assert.deepEqual(validateName(d, 'page1', { kind: 'page', path: ['pages', 0], rules: RULES }), { ok: true });
    assert.equal(validateName(d, 'page2', { kind: 'page', path: ['pages', 0], rules: RULES }).code, 'duplicate');
    assert.deepEqual(validateName(d, 'a', { kind: 'page', path: ['pages', 0], rules: RULES }), { ok: true }, 'a page may share a name with a question');
    assert.equal(validateName(d, '_fieldTypes', { kind: 'page', path: ['pages', 0], rules: RULES }).ok, true, 'reserved names are field names');
});

test('findReferences: expressions and piped texts, but not name definitions or longer names', () => {
    const d = doc({
        pages: [{ elements: [
            { type: 'text', name: 'vorname' },
            { type: 'text', name: 'x', visibleIf: "{vorname} notempty", requiredIf: '{vorname} = "a" and {vorname2} = 1', title: 'Hallo {vorname}!' },
            { type: 'html', name: 'h', html: { default: '<p>{vorname}</p>', de: '<p>{Vorname}</p>' } },
            { type: 'text', name: 'vorname2' },
        ] }],
        calculatedValues: [{ name: 'c', expression: '{vorname} + {vorname.x} + {vorname[0]}' }],
    });
    const refs = findReferences(d, 'vorname').map((r) => r.path.join('.'));
    assert.deepEqual(refs, ['pages.0.elements.1.visibleIf', 'pages.0.elements.1.requiredIf', 'pages.0.elements.1.title', 'pages.0.elements.2.html.default', 'calculatedValues.0.expression']);
    assert.deepEqual(findReferences(d, 'vorname2').map((r) => r.path.join('.')), ['pages.0.elements.1.requiredIf']);
    assert.deepEqual(findReferences(d, ''), []);
    assert.deepEqual(findReferences(d, 'nobody'), []);
});

test('findReferences copes with names that contain spaces and regex characters', () => {
    const d = doc({ pages: [{ elements: [{ type: 'text', name: '2P (Ergebnis)+' }, { type: 'text', name: 'b', visibleIf: '{2P (Ergebnis)+} = 1' }] }] });
    assert.equal(findReferences(d, '2P (Ergebnis)+').length, 1);
});

test('replaceReferences points only the exact references at the new name', () => {
    assert.equal(replaceReferences('{a} = 1 and {a.b} = 2 and {a[0]} and {ab} and {A} and "a"', 'a', 'z'), '{z} = 1 and {z.b} = 2 and {z[0]} and {ab} and {A} and "a"');
    assert.equal(replaceReferences('{a} {a}', 'a', '$&'), '{$&} {$&}', 'no replacement patterns');
});

test('findReferences finds every string that mentions {name} in the existing surveys (naive scan as oracle)', () => {
    let references = 0;
    for (const f of surveyFiles) {
        const text = readFileSync(new URL(f, SURVEYS), 'utf8');
        const d = doc(text);
        const strings = [];
        JSON.stringify(JSON.parse(text), (k, v) => { if (typeof v === 'string') { strings.push(v); } return v; });
        for (const n of new Set(elementsOf(d).map((e) => e.value.name).filter((x) => typeof x === 'string'))) {
            const naive = strings.filter((x) => x.includes(`{${n}}`)).length;
            const found = findReferences(d, n).length;
            assert.ok(found >= naive, `${f} {${n}}: found ${found}, naive ${naive}`);
            references += found;
        }
    }
    assert.ok(references > 30, `the surveys really use references (${references})`);
});

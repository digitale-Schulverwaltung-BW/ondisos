// Live preview (AP4a): the builder and the preview meet on kind and name. Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { loadModel } from '../../public/assets/survey-builder/model.js';
import { elementsOf } from '../../public/assets/survey-builder/names.js';
import { targetOf, pathOf, message, isMessage, PROTOCOL, VERSION } from '../../public/assets/survey-builder/live-targets.js';

const require = createRequire(import.meta.url);
const Survey = require('../../../frontend/public/assets/survey.core.min.js');
const SURVEYS = new URL('../../../frontend/surveys/', import.meta.url);
const load = (v) => { const m = loadModel(typeof v === 'string' ? v : JSON.stringify(v, null, 2)); assert.ok(m.ok); return m.doc; };

const SURVEY = { pages: [
    { name: 'p1', elements: [
        { type: 'radiogroup', name: 'form', choices: [
            { value: 'vz', text: 'Vollzeit', elements: [{ type: 'text', name: 'start_vz' }] },
            'tz',
            { value: 3, text: 'Drei', elements: [] },
            { value: 4, text: 'Vier' },
        ] },
        { type: 'panel', name: 'g', elements: [{ type: 'text', name: 'x' }] },
    ] },
    { name: 'p2', elements: [{ type: 'text', name: 'y' }, { type: 'html', html: '<p>ohne Namen</p>' }] },
] };
const Q = ['pages', 0, 'elements', 0];

test('targetOf: pages, questions, panels, answers', () => {
    const doc = load(SURVEY);
    assert.deepEqual(targetOf(doc, ['pages', 1]), { kind: 'page', index: 1, name: 'p2' });
    assert.deepEqual(targetOf(doc, Q), { kind: 'question', name: 'form' });
    assert.deepEqual(targetOf(doc, ['pages', 0, 'elements', 1]), { kind: 'panel', name: 'g' });
    assert.deepEqual(targetOf(doc, ['pages', 0, 'elements', 1, 'elements', 0]), { kind: 'question', name: 'x' });
    assert.deepEqual(targetOf(doc, [...Q, 'choices', 0]), { kind: 'choice', name: 'form', value: 'vz' });
    assert.deepEqual(targetOf(doc, [...Q, 'choices', 0, 'elements', 0]), { kind: 'question', name: 'start_vz' });
    assert.deepEqual(targetOf(doc, [...Q, 'choices', 2]), { kind: 'choice', name: 'form', value: 3 });
});

test('targetOf: nothing to name', () => {
    const doc = load(SURVEY);
    assert.equal(targetOf(doc, null), null);
    assert.equal(targetOf(null, Q), null);
    assert.equal(targetOf(doc, ['pages', 1, 'elements', 1]), null, 'an element without a name');
    assert.deepEqual(targetOf(load({ elements: [{ type: 'text', name: 'a' }] }), []), { kind: 'page', index: 0 }, 'the implicit page');
});

test('pathOf: back to the path, answers only if they hold questions', () => {
    const doc = load(SURVEY);
    assert.deepEqual(pathOf(doc, { kind: 'question', name: 'start_vz' }), [...Q, 'choices', 0, 'elements', 0]);
    assert.deepEqual(pathOf(doc, { kind: 'panel', name: 'g' }), ['pages', 0, 'elements', 1]);
    assert.deepEqual(pathOf(doc, { kind: 'choice', name: 'form', value: 'vz' }), [...Q, 'choices', 0]);
    assert.deepEqual(pathOf(doc, { kind: 'choice', name: 'form', value: '3' }), [...Q, 'choices', 2], 'the DOM gives values as text');
    assert.deepEqual(pathOf(doc, { kind: 'choice', name: 'form', value: 'tz' }), Q, 'a plain answer selects its question');
    assert.deepEqual(pathOf(doc, { kind: 'choice', name: 'form', value: '4' }), Q, 'an answer object without questions too');
    assert.deepEqual(pathOf(doc, { kind: 'choice', name: 'form', value: 'gibtsnicht' }), Q);
    assert.deepEqual(pathOf(doc, { kind: 'page', index: 1, name: 'p2' }), ['pages', 1]);
    assert.deepEqual(pathOf(doc, { kind: 'page', index: 0, name: 'p2' }), ['pages', 1], 'the name wins over the position');
    assert.deepEqual(pathOf(doc, { kind: 'page', index: 1 }), ['pages', 1]);
});

test('pathOf: what the text does not have (any more)', () => {
    const doc = load(SURVEY);
    assert.equal(pathOf(doc, { kind: 'question', name: 'weg' }), null);
    assert.equal(pathOf(doc, { kind: 'page', index: 7 }), null);
    assert.equal(pathOf(doc, { kind: 'question', name: '' }), null);
    assert.equal(pathOf(doc, null), null);
    assert.equal(pathOf(doc, 'form'), null);
    assert.deepEqual(pathOf(load({ elements: [{ type: 'text', name: 'a' }] }), { kind: 'page', index: 0 }), []);
});

test('every named element of every survey: there and back, and SurveyJS knows it under that name', () => {
    const files = readdirSync(SURVEYS).filter((f) => f.endsWith('.json'));
    assert.ok(files.length >= 5);
    let checked = 0;
    for (const file of files) {
        const text = readFileSync(new URL(file, SURVEYS), 'utf8');
        const doc = load(text);
        const model = new Survey.Model(JSON.parse(text));
        const names = elementsOf(doc).map((e) => e.value.name).filter((n) => typeof n === 'string' && n !== '');
        for (const { path, value } of elementsOf(doc)) {
            const target = targetOf(doc, path);
            if (typeof value.name !== 'string' || value.name === '') { assert.equal(target, null); continue; }
            if (names.filter((n) => n === value.name).length > 1) { continue; } // not unique: the server refuses that text
            assert.deepEqual(pathOf(doc, target), path, `${file}: ${value.name}`);
            const found = target.kind === 'panel' ? model.getPanelByName(value.name) : model.getQuestionByName(value.name);
            if (value.type !== 'html' || found) { assert.ok(found, `${file}: SurveyJS knows ${target.kind} ${value.name}`); }
            checked += 1;
        }
        const pages = Array.isArray(JSON.parse(text).pages) ? JSON.parse(text).pages : [];
        pages.forEach((p, i) => {
            assert.deepEqual(pathOf(doc, targetOf(doc, ['pages', i])), ['pages', i], `${file}: page ${i}`);
            assert.equal(model.pages[i].name, p.name ?? model.pages[i].name, `${file}: SurveyJS counts pages like the text`);
        });
    }
    assert.ok(checked > 300, `only ${checked} elements`);
});

test('messages: protocol name and version, nothing else counts', () => {
    const m = message('text', { text: '{}' });
    assert.deepEqual(m, { ondisos: PROTOCOL, v: VERSION, type: 'text', text: '{}' });
    assert.equal(isMessage(m), true);
    assert.equal(isMessage({ ...m, v: VERSION + 1 }), false);
    assert.equal(isMessage({ ...m, ondisos: 'x' }), false);
    assert.equal(isMessage({ ondisos: PROTOCOL, v: VERSION }), false, 'no type');
    assert.equal(isMessage(null), false);
    assert.equal(isMessage('ondisos-live-preview'), false);
});

test('the frame script speaks the same protocol', () => {
    const frame = readFileSync(new URL('../../public/assets/preview/survey-preview.js', import.meta.url), 'utf8');
    assert.match(frame, new RegExp(`PROTOCOL = '${PROTOCOL}'`));
    assert.match(frame, new RegExp(`VERSION = ${VERSION};`));
});

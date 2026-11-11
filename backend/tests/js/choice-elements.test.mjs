// Questions in answer options (choices[].elements, SurveyJS 2+): the builder shows them under their question and treats an
// option that holds questions like a panel. Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { loadModel, outline, flatten, addElement, moveElementTo, duplicateElement, removeElement, countElements, isChoicePath, setProp }
    from '../../public/assets/survey-builder/model.js';
import { toValue, valueAt } from '../../public/assets/survey-builder/json-doc.js';
import { elementsOf, validateName, generateName } from '../../public/assets/survey-builder/names.js';
import { choiceToLine, linesToChoices, matchChoices } from '../../public/assets/survey-builder/ui-props.js';

const require = createRequire(import.meta.url);
const Survey = require('../../../frontend/public/assets/survey.core.min.js');
const RULES = JSON.parse(readFileSync(new URL('./fixtures/survey-rules.json', import.meta.url), 'utf8'));
const load = (v) => { const m = loadModel(JSON.stringify(v, null, 2)); assert.ok(m.ok); return m.doc; };
const good = (r) => { assert.equal(r.ok, true, JSON.stringify(r)); return r; };

/** Shape of the first draft of meister.json: an option with a question, which has an option with questions again. */
const SURVEY = { pages: [{ name: 'p1', elements: [
    { type: 'radiogroup', name: 'form', title: 'Unterrichtsform', choices: [
        { value: 'vz', text: 'Vollzeit', elements: [
            { type: 'radiogroup', name: 'focus', choices: [
                { value: 'a', text: 'Energie', elements: [{ type: 'text', name: 'start_a' }] },
                'b',
            ] },
        ] },
        { value: 'tz', elements: [{ type: 'html', name: 'note', html: 'Nur Teil I und II' }, { type: 'text', name: 'start_tz' }] },
        'other',
    ] },
    { type: 'panel', name: 'g', elements: [{ type: 'text', name: 'x' }] },
] }] };

const Q = ['pages', 0, 'elements', 0];
const VZ = [...Q, 'choices', 0];
const TZ = [...Q, 'choices', 1];

test('isChoicePath: an entry of a choices list, nothing else', () => {
    assert.equal(isChoicePath(VZ), true);
    assert.equal(isChoicePath([...VZ, 'elements', 0]), false);
    assert.equal(isChoicePath(Q), false);
    assert.equal(isChoicePath(['pages', 0]), false);
});

test('elementsOf lists questions in options after their question, as the server counts them', () => {
    const d = load(SURVEY);
    assert.deepEqual(elementsOf(d).map((e) => e.value.name), ['form', 'focus', 'start_a', 'note', 'start_tz', 'g', 'x']);
    assert.deepEqual(elementsOf(d)[2].path, [...VZ, 'elements', 0, 'choices', 0, 'elements', 0]);
    assert.equal(countElements(d), 7);
});

test('outline: options with questions as entries of their question (plain options are not), flatten reaches them', () => {
    const d = load(SURVEY);
    const [q] = outline(d)[0].elements;
    assert.deepEqual(q.choices.map((c) => [c.path, c.text, c.children.map((e) => e.name)]), [
        [VZ, 'Vollzeit', ['focus']],
        [TZ, 'tz', ['note', 'start_tz']], // no text: the value
    ]);
    assert.deepEqual(q.choices[0].children[0].choices[0].children[0].path, [...VZ, 'elements', 0, 'choices', 0, 'elements', 0]);
    assert.deepEqual(flatten(outline(d)).map((e) => e.name), ['form', 'focus', 'start_a', 'note', 'start_tz', 'g', 'x']);
});

test('names: a question in an option takes part in the duplicate check and in generating names', () => {
    const d = load({ pages: [{ name: 'p', elements: [
        { type: 'radiogroup', name: 'r', choices: [{ value: 1, elements: [{ type: 'text', name: 'question7' }] }] },
        { type: 'text', name: 'b' },
    ] }] });
    assert.deepEqual(validateName(d, 'question7', { kind: 'text', path: ['pages', 0, 'elements', 1], rules: RULES }), { ok: false, code: 'duplicate' });
    assert.equal(generateName(d, 'question', RULES), 'question8');
});

test('properties of a question in an option are edited in place', () => {
    const d = load(SURVEY);
    const at = [...TZ, 'elements', 1];
    const next = good(setProp(d, at, 'title', 'Jahr des Kursbeginns')).doc;
    assert.equal(valueAt(next, [...at, 'title']), 'Jahr des Kursbeginns');
    const renamed = good(setProp(next, at, 'name', 'start_tz', { rules: RULES })).doc; // own name: no change
    assert.equal(renamed.text, next.text);
});

test('addElement: into an option that holds questions; never into a plain option', () => {
    const d = load(SURVEY);
    const r = good(addElement(d, TZ, 2, 'text', { rules: RULES }));
    assert.deepEqual(r.path, [...TZ, 'elements', 2]);
    assert.equal(valueAt(r.doc, [...r.path, 'name']), 'question1');
    assert.equal(addElement(d, [...Q, 'choices', 2], 0, 'text', { rules: RULES }).code, 'path'); // "other" is a string
    const plainObject = load({ pages: [{ name: 'p', elements: [{ type: 'radiogroup', name: 'r', choices: [{ value: 1, text: 'eins' }] }] }] });
    assert.equal(addElement(plainObject, ['pages', 0, 'elements', 0, 'choices', 0], 0, 'text', { rules: RULES }).code, 'notContainer');
});

test('moveElementTo: out of an option, into another option, into a panel; SurveyJS builds every result', () => {
    const d = load(SURVEY);
    const out = good(moveElementTo(d, [...TZ, 'elements', 1], ['pages', 0], 1, { rules: RULES }));
    assert.deepEqual(out.path, ['pages', 0, 'elements', 1]);
    assert.deepEqual(valueAt(out.doc, [...TZ, 'elements']).map((e) => e.name), ['note']);

    const across = good(moveElementTo(d, [...TZ, 'elements', 0], VZ, 1, { rules: RULES }));
    assert.deepEqual(valueAt(across.doc, [...VZ, 'elements']).map((e) => e.name), ['focus', 'note']);

    const into = good(moveElementTo(d, ['pages', 0, 'elements', 1], TZ, 0, { rules: RULES }));
    assert.deepEqual(valueAt(into.doc, [...TZ, 'elements']).map((e) => e.name), ['g', 'note', 'start_tz']);

    for (const r of [out, across, into]) {
        const survey = new Survey.Model(toValue(r.doc.root));
        assert.equal(survey.getAllQuestions(false, false, true).length, elementsOf(r.doc).filter((e) => e.value.type !== 'panel').length);
    }
});

test('moveElementTo: a question cannot go into its own options, an option is no target when it is a plain one', () => {
    const d = load(SURVEY);
    assert.equal(moveElementTo(d, Q, TZ, 0, { rules: RULES }).code, 'intoItself');
    assert.equal(moveElementTo(d, ['pages', 0, 'elements', 1], [...Q, 'choices', 2], 0, { rules: RULES }).code, 'notContainer');
});

test('nesting depth counts questions in options, as the server does', () => {
    const d = load(SURVEY);
    const shallow = { ...RULES, maxDepth: 2 };
    // the panel g (depth 1 on the page) into the option of "focus": depth 3 + its question below
    assert.equal(moveElementTo(d, ['pages', 0, 'elements', 1], [...VZ, 'elements', 0, 'choices', 0], 0, { rules: shallow }).code, 'tooDeep');
    // and the question "form" with two levels below it into the panel
    assert.equal(moveElementTo(d, Q, ['pages', 0, 'elements', 1], 0, { rules: shallow }).code, 'tooDeep');
});

test('duplicate and remove a question together with the questions in its options', () => {
    const d = load(SURVEY);
    const dup = good(duplicateElement(d, Q, { rules: RULES }));
    assert.deepEqual(dup.names, { form: 'form_2', focus: 'focus_2', start_a: 'start_a_2', note: 'note_2', start_tz: 'start_tz_2' });
    assert.equal(countElements(dup.doc), 12);
    const rm = good(removeElement(d, Q));
    assert.deepEqual(elementsOf(rm.doc).map((e) => e.value.name), ['g', 'x']);
});

test('answer list: an edited line keeps the questions of its answer (value, text, position)', () => {
    const original = [{ value: 'vz', text: 'Vollzeit', elements: [{ type: 'text', name: 'a' }] }, { value: 2, elements: [{ type: 'text', name: 'b' }] }, 'c'];
    const lines = original.map(choiceToLine);
    assert.deepEqual(lines, ['vz | Vollzeit', '2', 'c']);

    const text = matchChoices('vz | Vollzeit (Mo–Fr)\n2 | Zwei\nc', original);
    assert.deepEqual(text.items, [
        { value: 'vz', text: 'Vollzeit (Mo–Fr)', elements: original[0].elements },
        { value: 2, elements: original[1].elements, text: 'Zwei' }, // the number value survives
        'c',
    ]);
    assert.deepEqual(text.dropped, []);

    const value = matchChoices('VZ | Vollzeit\nneu\n2\nc', original); // new value in place, a new line before an unchanged one
    assert.deepEqual(value.items[0], { value: 'VZ', text: 'Vollzeit', elements: original[0].elements });
    assert.equal(value.items[1], 'neu');
    assert.strictEqual(value.items[2], original[1]);

    const moved = matchChoices('c\n2\nvz | Vollzeit', original); // reordered: the same entries
    assert.deepEqual(moved.items.map((i) => original.indexOf(i)), [2, 1, 0]);

    const removed = matchChoices('vz | Vollzeit\nc', original);
    assert.deepEqual(removed.dropped, [1]);
});

test('answer list: other settings and translated texts stay when a line changes', () => {
    const original = [{ value: 'a', text: { default: 'A', en: 'A (en)' }, visibleIf: '{x} = 1' }];
    assert.deepEqual(linesToChoices('b', original), [{ value: 'b', text: { default: 'A', en: 'A (en)' }, visibleIf: '{x} = 1' }]);
    assert.deepEqual(linesToChoices('b | Neu', original), [{ value: 'b', text: 'Neu', visibleIf: '{x} = 1' }]);
    assert.deepEqual(linesToChoices('a | Plain', [{ value: 'a', text: 'Old' }]), [{ value: 'a', text: 'Plain' }]);
});

test('answer list: setProp writes an edited line around the questions, which stay byte for byte', () => {
    const d = load(SURVEY);
    const before = valueAt(d, [...Q, 'choices']);
    const next = good(setProp(d, Q, 'choices', linesToChoices('vz | Vollzeit (Mo–Fr)\ntz | Teilzeit\nother', before), { rules: RULES })).doc;
    assert.deepEqual(valueAt(next, [...VZ, 'text']), 'Vollzeit (Mo–Fr)');
    assert.deepEqual(valueAt(next, [...TZ, 'text']), 'Teilzeit');
    const block = (doc) => JSON.stringify(valueAt(doc, [...VZ, 'elements']));
    assert.equal(block(next), block(d));
    assert.deepEqual(elementsOf(next).map((e) => e.value.name), elementsOf(d).map((e) => e.value.name));
    const start = (doc) => doc.text.indexOf('"focus"');
    assert.equal(next.text.slice(start(next), start(next) + 400), d.text.slice(start(d), start(d) + 400));
});

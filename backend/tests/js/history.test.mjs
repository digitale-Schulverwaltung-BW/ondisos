// Tests for undo and redo of the survey builder (AP4). Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHistory } from '../../public/assets/survey-builder/history.js';
import { valueAt } from '../../public/assets/survey-builder/json-doc.js';
import { loadModel, outline, flatten, moveElementTo, duplicateElement, setProp, removeElement } from '../../public/assets/survey-builder/model.js';

const RULES = JSON.parse(readFileSync(new URL('./fixtures/survey-rules.json', import.meta.url), 'utf8'));
const read = (f) => readFileSync(new URL(`../../../frontend/surveys/${f}`, import.meta.url), 'utf8');

test('undo goes back step by step to the exact earlier texts, redo forward again', () => {
    const h = createHistory();
    h.reset('a');
    h.record('ab', { before: 1, after: 2 });
    h.record('abc', { before: 2, after: 3 });
    assert.equal(h.canRedo(), false);
    assert.deepEqual(h.undo(), { text: 'ab', selected: 2 }, 'the selection before the undone change');
    assert.deepEqual(h.undo(), { text: 'a', selected: 1 });
    assert.equal(h.undo(), null, 'nothing before the start');
    assert.equal(h.canUndo(), false);
    assert.deepEqual(h.redo(), { text: 'ab', selected: 2 }, 'the selection after the change');
    assert.deepEqual(h.redo(), { text: 'abc', selected: 3 });
    assert.equal(h.redo(), null);
    assert.equal(h.current(), 'abc');
});

test('a new change after undo drops the steps that were undone; the same text again records nothing', () => {
    const h = createHistory();
    h.reset('a');
    h.record('b');
    h.record('c');
    h.undo();
    h.record('x');
    assert.equal(h.canRedo(), false);
    assert.equal(h.undo().text, 'b');
    h.record('b');
    assert.equal(h.canRedo(), true, 'recording the current text again keeps the redo steps');
    assert.equal(h.size(), 3);
});

test('without reset the first record starts the history; nothing to undo then', () => {
    const h = createHistory();
    assert.equal(h.current(), undefined);
    assert.equal(h.undo(), null);
    assert.equal(h.redo(), null);
    h.record('a');
    assert.equal(h.current(), 'a');
    assert.equal(h.canUndo(), false);
});

test('the oldest steps go when there are too many or too much text; the current one always stays', () => {
    const h = createHistory({ limit: 3 });
    h.reset('0');
    for (const s of ['1', '2', '3', '4']) { h.record(s); }
    assert.equal(h.size(), 3);
    assert.equal(h.undo().text, '3');
    assert.equal(h.undo().text, '2');
    assert.equal(h.undo(), null);
    const big = createHistory({ maxChars: 10 });
    big.reset('aaaa');
    big.record('bbbb');
    big.record('cccc');
    assert.equal(big.size(), 2, '12 characters are too many');
    big.record('x'.repeat(50));
    assert.equal(big.size(), 1, 'a single step larger than the limit stays');
    assert.equal(big.current(), 'x'.repeat(50));
});

test('a session of model changes on a real survey: undo all of them gives the original text, redo all the final one', () => {
    const original = read('gs.json');
    const h = createHistory();
    h.reset(original);
    let doc = loadModel(original).doc;
    const texts = [original];
    const apply = (r) => { assert.ok(r.ok, JSON.stringify(r.code)); doc = r.doc; h.record(doc.text); texts.push(doc.text); };
    const pages = outline(doc);
    apply(moveElementTo(doc, pages[0].elements[0].path, pages[2].path, 1, { rules: RULES }));
    const panel = flatten(outline(doc)).find((e) => e.type === 'panel');
    apply(duplicateElement(doc, panel.path, { rules: RULES }));
    const field = flatten(outline(doc)).find((e) => e.type === 'text').path;
    apply(setProp(doc, field, 'isRequired', valueAt(doc, [...field, 'isRequired']) !== true));
    apply(removeElement(doc, flatten(outline(doc)).at(-1).path));
    for (let i = texts.length - 2; i >= 0; i--) { assert.equal(h.undo().text, texts[i]); }
    assert.equal(h.current(), original);
    for (let i = 1; i < texts.length; i++) { assert.equal(h.redo().text, texts[i]); }
});

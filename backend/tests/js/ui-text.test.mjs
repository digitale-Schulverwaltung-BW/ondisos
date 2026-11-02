// Static checks of the builder's UI code that need no browser: texts, buttons, the list-of-answers helpers.
// (The behaviour in the browser is checked by backend/tools/survey-builder-harness/check.mjs.)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { TYPES, PAGE } from '../../public/assets/survey-builder/registry.js';
import { createT } from '../../public/assets/survey-builder/dom.js';
import { choiceToLine, lineToChoice, linesToChoices, hasAdvancedChoices } from '../../public/assets/survey-builder/ui-props.js';

const DIR = new URL('../../public/assets/survey-builder/', import.meta.url);
const MESSAGES = JSON.parse(readFileSync(new URL('./fixtures/builder-messages.json', import.meta.url), 'utf8'));
const t = createT(MESSAGES);
const has = (key) => !t(key).startsWith('[');
const sources = readdirSync(DIR).filter((f) => f.endsWith('.js')).map((f) => [f, readFileSync(new URL(f, DIR), 'utf8')]);

test('every literal text the UI code asks for exists in the standard messages', () => {
    const missing = [];
    for (const [file, code] of sources.filter(([f]) => f.startsWith('ui'))) {
        for (const m of code.matchAll(/\bt\('([A-Za-z0-9_.]+)'/g)) {
            if (!has(m[1])) { missing.push(`${file}: ${m[1]}`); }
        }
    }
    assert.deepEqual(missing, []);
});

test('texts for everything that is generated: types, properties, enum values, groups, palette groups, error codes', () => {
    const missing = [];
    for (const type of [...Object.values(TYPES), PAGE]) {
        if (!has(`type.${type.type}`)) { missing.push(`type.${type.type}`); }
        for (const p of type.props) {
            if (!has(`prop.${p.name}`)) { missing.push(`prop.${p.name}`); }
            if (!has(`group.${p.group}`)) { missing.push(`group.${p.group}`); }
            for (const v of p.kind === 'enum' ? p.values : []) { if (!has(`enum.${p.name}.${v}`)) { missing.push(`enum.${p.name}.${v}`); } }
        }
        if (!has(`palette_group.${type.group}`) && type.type !== 'page') { missing.push(`palette_group.${type.group}`); }
    }
    // every failure code of the model and of the name check has a text
    const codes = new Set();
    for (const f of ['model.js', 'names.js']) {
        const code = readFileSync(new URL(f, DIR), 'utf8');
        for (const m of code.matchAll(/(?:fail|code:)\s*\(?\s*'([A-Za-z]+)'/g)) { codes.add(m[1]); }
        for (const m of code.matchAll(/code: '([A-Za-z]+)'/g)) { codes.add(m[1]); }
    }
    for (const c of [...codes, 'number', 'number_range', 'generic']) { if (!has(`err.${c}`)) { missing.push(`err.${c}`); } }
    assert.deepEqual([...new Set(missing)], []);
    assert.ok(codes.size >= 14, `found ${codes.size} error codes`);
});

test('placeholders in the texts are the ones the code fills in', () => {
    assert.equal(t('size', { kb: '1.0', max: 512, count: 2, max_elements: 2000 }), 'Größe: 1.0 KB von 512 KB · 2 von 2000 Elementen');
    assert.match(t('confirm.rename', { old: 'a', new: 'b', refs: '' }), /„a".*„b"/);
    assert.equal(t('nope.key'), '[nope.key]');
});

test('every button the builder creates is type="button" (the page is a form: a submit button would send it)', () => {
    for (const [file, code] of sources.filter(([f]) => f.startsWith('ui'))) {
        for (const m of code.matchAll(/h\('button',\s*\{([^}]*)\}/g)) {
            assert.match(m[1], /type:\s*'button'/, `${file}: ${m[0].slice(0, 60)}`);
        }
        for (const m of code.matchAll(/\bbtn\(/g)) { void m; } // btn() sets type: 'button' itself, checked below
        if (code.includes('const btn =')) { assert.match(code, /const btn = [^\n]*type: 'button'/); }
    }
});

test('the UI code never uses innerHTML or other ways to put markup into the page', () => {
    const withoutComments = (code) => code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:'"`])\/\/.*$/gm, '$1');
    for (const [file, code] of sources) {
        assert.doesNotMatch(withoutComments(code), /innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function/, file);
    }
    // the check itself works: it does see code
    assert.match(withoutComments('el.innerHTML = x; // innerHTML'), /innerHTML/);
    assert.doesNotMatch(withoutComments('/* innerHTML */ const a = 1; // eval('), /innerHTML|eval\(/);
});

test('the list of answers: one line per answer, "value | text" for pairs', () => {
    assert.equal(choiceToLine('Frankreich'), 'Frankreich');
    assert.equal(choiceToLine(3), '3');
    assert.equal(choiceToLine({ value: 'AFG', text: 'Afghanistan' }), 'AFG | Afghanistan');
    assert.equal(choiceToLine({ value: 'x', text: 'x' }), 'x');
    assert.equal(choiceToLine({ value: 'only' }), 'only');
    assert.equal(lineToChoice('Frankreich'), 'Frankreich');
    assert.deepEqual(lineToChoice('AFG | Afghanistan'), { value: 'AFG', text: 'Afghanistan' });
    assert.deepEqual(lineToChoice('AFG|  Afghanistan  '), { value: 'AFG', text: 'Afghanistan' });
    assert.equal(lineToChoice('AFG | '), 'AFG');
});

test('unchanged lines give back their original answers (kinds and number values survive); only new lines are parsed', () => {
    const original = [{ value: 1, text: 'Eins' }, 'b', { value: 'c' }, 7];
    const same = linesToChoices(original.map(choiceToLine).join('\n'), original);
    assert.deepEqual(same, original);
    assert.strictEqual(same[0], original[0], 'the very same entries');
    assert.strictEqual(same[3], original[3]);
    const edited = linesToChoices('1 | Eins\nb\nneu\n\n  \n7', original);
    assert.deepEqual(edited, [original[0], 'b', 'neu', 7], 'blank lines are dropped, "c" is gone, "neu" is new');
    assert.deepEqual(linesToChoices('a\na\na', ['a']), ['a', 'a', 'a'], 'duplicates are fine');
    assert.deepEqual(linesToChoices('', original), []);
});

test('answers with more than value and text are "advanced" and not written as lines', () => {
    assert.equal(hasAdvancedChoices(['a', { value: 'b', text: 'B' }]), false);
    assert.equal(hasAdvancedChoices(['a', { value: 'b', visibleIf: '{x} = 1' }]), true);
    assert.equal(hasAdvancedChoices([]), false);
});

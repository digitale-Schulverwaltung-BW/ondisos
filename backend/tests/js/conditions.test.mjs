// Tests for conditions: tokens, the simple form, warnings, suggestions. Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { parseDoc } from '../../public/assets/survey-builder/json-doc.js';
import { tokenize, parseSimple, fieldsOf, checkExpression, suggest, literalText, CONDITION_PROPS } from '../../public/assets/survey-builder/conditions.js';

const SURVEYS = new URL('../../../frontend/surveys/', import.meta.url);
const surveyFiles = readdirSync(SURVEYS).filter((f) => f.endsWith('.json') && f !== 'survey_theme.json').sort();
const doc = (v) => parseDoc(typeof v === 'string' ? v : JSON.stringify(v));

const SAMPLE = doc({
    pages: [{
        name: 'p1',
        elements: [
            { type: 'radiogroup', name: 'Unterrichtsform', title: 'Form des Unterrichts', choices: ['Vollzeitunterricht', { value: 'TZ', text: 'Teilzeit' }] },
            { type: 'boolean', name: 'WieKind', title: 'Anschrift wie Kind' },
            { type: 'text', name: 'Alter', inputType: 'number' },
            { type: 'html', name: 'info', html: '<p>x</p>' },
            { type: 'panel', name: 'grp', elements: [{ type: 'dropdown', name: 'Land', choices: ['DE', 'AT'], showOtherItem: true }] },
            { type: 'paneldynamic', name: 'Jobs', templateElements: [{ type: 'text', name: 'von' }, { type: 'text', name: 'bis' }] },
            { type: 'checkbox', name: 'Remote', choicesByUrl: { url: 'https://example.org' } },
        ],
    }],
    calculatedValues: [{ name: 'age', expression: 'age({Geb})' }],
});
const FIELDS = fieldsOf(SAMPLE);

/** Every condition string in a survey (any depth: elements, choices, template elements). */
function conditionsIn(value, out = []) {
    if (Array.isArray(value)) { value.forEach((v) => conditionsIn(v, out)); }
    else if (value && typeof value === 'object') {
        for (const [k, v] of Object.entries(value)) {
            if (CONDITION_PROPS.includes(k) && typeof v === 'string') { out.push(v); }
            conditionsIn(v, out);
        }
    }
    return out;
}

// ---- tokens --------------------------------------------------------------------------------------------------------

test('tokenize: references, strings, numbers, words, symbols with their positions', () => {
    const toks = tokenize("{a b} >= 18 and {c} = 'x y'");
    assert.deepEqual(toks.map((x) => [x.t, x.v]), [['var', 'a b'], ['sym', '>='], ['num', '18'], ['word', 'and'], ['var', 'c'], ['sym', '='], ['str', 'x y']]);
    assert.deepEqual([toks[0].start, toks[0].end], [0, 5]);
    assert.deepEqual([toks[6].start, toks[6].end], [22, 27]);
});

test('tokenize: an unfinished brace or string runs to the end and is marked open', () => {
    assert.deepEqual(tokenize('{Unt').map((x) => [x.t, x.v, x.open]), [['var', 'Unt', true]]);
    assert.deepEqual(tokenize("{a} = 'Vo").map((x) => [x.t, x.v, x.open ?? false]), [['var', 'a', false], ['sym', '=', false], ['str', 'Vo', true]]);
    assert.equal(tokenize('"it\\"s"')[0].v, 'it"s');
    assert.deepEqual(tokenize('{a{b}').map((x) => [x.v, x.open ?? false]), [['a', true], ['b', false]], 'a second brace ends the first');
});

// ---- the simple form ---------------------------------------------------------------------------------------------------

test('parseSimple: field – operator – value, with the operator in any spelling', () => {
    assert.deepEqual(parseSimple("{a} = 'x'"), { ok: true, join: null, terms: [{ field: 'a', op: 'equal', value: 'x' }] });
    assert.deepEqual(parseSimple('{a} EQUAL 3').terms, [{ field: 'a', op: 'equal', value: 3 }]);
    assert.deepEqual(parseSimple('{a} == false').terms, [{ field: 'a', op: 'equal', value: false }]);
    assert.deepEqual(parseSimple('{a} notempty').terms, [{ field: 'a', op: 'notempty' }]);
    assert.deepEqual(parseSimple("{a} anyof ['x', 'y']").terms, [{ field: 'a', op: 'anyof', value: ['x', 'y'] }]);
    assert.deepEqual(parseSimple('{panel.a} < 18').terms, [{ field: 'panel.a', op: 'less', value: 18 }]);
});

test('parseSimple: several terms with only "and" or only "or"', () => {
    const r = parseSimple("{a} = 'x' and {b} notempty && {c} != 2");
    assert.equal(r.ok, true);
    assert.equal(r.join, 'and');
    assert.equal(r.terms.length, 3);
    assert.equal(parseSimple('{a} empty or {b} empty').join, 'or');
});

test('parseSimple: brackets, functions, negation, mixed and/or and arithmetic are not simple', () => {
    for (const text of [
        "{a} = 'x' or ({b} = 'y' and {c} notempty)",
        "sumInArray({a}, 'b') > 0",
        "!({a} empty)",
        "{a} = 'x' and {b} = 'y' or {c} = 'z'",
        '{a} + 1 > 3',
        "'x' = {a}",
        "{a} = 'x",
        '{a} =',
    ]) {
        assert.deepEqual(parseSimple(text), { ok: false, reason: 'complex' }, text);
    }
    assert.deepEqual(parseSimple('  '), { ok: false, reason: 'empty' });
});

test('parseSimple covers nearly all conditions of the real surveys (AP5: measured, see PLAN-3.2.0)', () => {
    const all = surveyFiles.flatMap((f) => conditionsIn(JSON.parse(readFileSync(new URL(f, SURVEYS), 'utf8'))));
    const simple = all.filter((c) => parseSimple(c).ok);
    assert.ok(all.length >= 81, `found ${all.length} conditions`);
    assert.ok(simple.length / all.length >= 0.95, `${simple.length} of ${all.length}`);
    for (const c of all.filter((x) => !parseSimple(x).ok)) {
        assert.match(c, /\(|\)/, `only brackets or functions stay text: ${c}`);
    }
});

// ---- fields and warnings ---------------------------------------------------------------------------------------------

test('fieldsOf: fields with an answer (also in panels), template fields as panel.<name>, calculated values', () => {
    assert.deepEqual([...FIELDS.keys()], ['Unterrichtsform', 'WieKind', 'Alter', 'Land', 'Jobs', 'panel.von', 'panel.bis', 'Remote', 'age']);
    assert.deepEqual(FIELDS.get('Unterrichtsform').values, ['Vollzeitunterricht', 'TZ']);
    assert.equal(FIELDS.get('Unterrichtsform').labels.get('TZ'), 'Teilzeit');
    assert.deepEqual(FIELDS.get('WieKind').values, [true, false]);
    assert.deepEqual(FIELDS.get('Land').values, ['DE', 'AT', 'other']);
    assert.equal(FIELDS.get('Alter').values, null, 'a text field takes any value');
    assert.equal(FIELDS.get('Remote').values, null, 'answers loaded from elsewhere are not known');
    assert.equal(FIELDS.get('panel.von').inTemplate, true);
});

test('checkExpression: unknown field, near miss in case, unknown answer value', () => {
    assert.deepEqual(checkExpression('{Alter} < 18 and {age} > 1 and {Jobs[0].von} notempty', FIELDS), []);
    assert.deepEqual(checkExpression('{Altr} < 18', FIELDS), [{ code: 'unknownField', start: 0, end: 6, name: 'Altr' }]);
    assert.deepEqual(checkExpression('{alter} < 18', FIELDS), [{ code: 'caseField', start: 0, end: 7, name: 'alter', suggestion: 'Alter' }]);
    assert.deepEqual(checkExpression("{Unterrichtsform} = 'Vollzeit'", FIELDS),
        [{ code: 'unknownValue', start: 20, end: 30, field: 'Unterrichtsform', value: 'Vollzeit' }]);
    assert.deepEqual(checkExpression("{Unterrichtsform} = 'TZ' or {Land} anyof ['DE', 'CH', 'other']", FIELDS).map((w) => w.value), ['CH']);
    assert.deepEqual(checkExpression("{WieKind} = 'ja'", FIELDS).map((w) => w.code), ['unknownValue']);
    assert.deepEqual(checkExpression("{Alter} = 'irgendwas' and {Remote} = 'x'", FIELDS), [], 'free and unknown answers are not judged');
    assert.deepEqual(checkExpression('{row.x} = 1 and {panel.von} notempty', FIELDS), []);
    assert.deepEqual(checkExpression("{item} != 'x' and {panelIndex} > 1 and {ROW.x} = 1 and {$a} = 1", FIELDS), [], 'variables SurveyJS provides itself');
});

test('checkExpression: open brace, open quote, unbalanced brackets', () => {
    assert.deepEqual(checkExpression('{Alter', FIELDS).map((w) => w.code), ['unclosedBrace']);
    assert.deepEqual(checkExpression("{Land} = 'DE", FIELDS).map((w) => w.code), ['unclosedString']);
    assert.deepEqual(checkExpression('({Alter} > 1', FIELDS).map((w) => w.code), ['parens']);
    assert.deepEqual(checkExpression('{Alter} > 1)', FIELDS).map((w) => w.code), ['parens']);
    assert.deepEqual(checkExpression('', FIELDS), []);
});

// ---- suggestions -------------------------------------------------------------------------------------------------------

const labels = (r) => r.items.map((i) => i.label);
const apply = (text, r, i = 0) => text.slice(0, r.from) + r.items[i].insert + text.slice(r.to);

test('suggest: after "{" the fields, filtered by name or title; template fields only from inside the dynamic panel', () => {
    const r = suggest('{', 1, FIELDS);
    assert.equal(r.kind, 'field');
    assert.deepEqual(labels(r), ['Unterrichtsform', 'WieKind', 'Alter', 'Land', 'Jobs', 'Remote', 'age']);
    assert.equal(r.items[0].detail, 'Form des Unterrichts');
    assert.deepEqual(labels(suggest('{unter', 6, FIELDS)), ['Unterrichtsform']);
    assert.deepEqual(labels(suggest('{anschrift', 10, FIELDS)), ['WieKind'], 'the title matches too');
    assert.deepEqual(labels(suggest('{', 1, FIELDS, { inTemplate: true })).filter((l) => l.startsWith('panel.')), ['panel.von', 'panel.bis']);
    assert.ok(!labels(suggest('{', 1, FIELDS, { self: 'Alter' })).includes('Alter'), 'not the element itself');
    assert.equal(apply('{unt', suggest('{unt', 4, FIELDS)), '{Unterrichtsform}');
    assert.equal(apply('{unt} = 1', suggest('{unt} = 1', 4, FIELDS)), '{Unterrichtsform} = 1', 'up to the closing brace');
});

test('suggest: at the start and after and/or a field with braces', () => {
    const r = suggest('', 0, FIELDS);
    assert.equal(r.kind, 'field');
    assert.equal(r.items[0].insert, '{Unterrichtsform}');
    const text = '{Alter} > 1 and ';
    assert.equal(apply(text, suggest(text, text.length, FIELDS)), '{Alter} > 1 and {Unterrichtsform}');
});

test('suggest: after a field the operators, with a space where needed', () => {
    const r = suggest('{Alter} ', 8, FIELDS);
    assert.equal(r.kind, 'operator');
    assert.deepEqual(labels(r).slice(0, 4), ['=', '!=', 'empty', 'notempty']);
    assert.equal(apply('{Alter}', suggest('{Alter}', 7, FIELDS)), '{Alter} = ');
    assert.equal(apply('{Alter} not', suggest('{Alter} not', 11, FIELDS)), '{Alter} notempty');
    assert.deepEqual(labels(suggest('{Alter} not', 11, FIELDS)), ['notempty', 'notcontains', '!='], 'filtered by symbol or name, beginnings first');
});

test('suggest: after an operator the answers of the field, with their texts; inside a quote too', () => {
    const r = suggest('{Unterrichtsform} = ', 20, FIELDS);
    assert.equal(r.kind, 'value');
    assert.deepEqual(labels(r), ["'Vollzeitunterricht'", "'TZ'"]);
    assert.equal(r.items[1].detail, 'Teilzeit');
    const quoted = "{Unterrichtsform} = 'teil'";
    const q = suggest(quoted, quoted.length - 1, FIELDS);
    assert.deepEqual(labels(q), ["'TZ'"], 'the text of the answer matches');
    assert.equal(apply(quoted, q), "{Unterrichtsform} = 'TZ'");
    assert.equal(apply("{Land} = 'D' and", suggest("{Land} = 'D' and", 11, FIELDS)), "{Land} = 'DE' and", 'replaces up to the closing quote');
    assert.deepEqual(labels(suggest('{WieKind} = ', 12, FIELDS)), ['true', 'false']);
    assert.deepEqual(suggest('{Alter} = ', 10, FIELDS).items, [], 'a free field has no answers to offer');
});

test('suggest: after a complete comparison "and" and "or"', () => {
    for (const text of ["{Land} = 'DE' ", '{Alter} notempty ', '{Alter} > 3 ', "{Land} anyof ['DE'] "]) {
        assert.deepEqual(labels(suggest(text, text.length, FIELDS)), ['and', 'or'], text);
    }
    assert.deepEqual(labels(suggest("{Land} = 'DE' o", 15, FIELDS)), ['or']);
    assert.equal(suggest("{Land} = 'DE'", 13, FIELDS).kind, 'join');
});

test('literalText: strings in single quotes, numbers and booleans bare', () => {
    assert.equal(literalText('x'), "'x'");
    assert.equal(literalText("it's"), '"it\'s"');
    assert.equal(literalText(3), '3');
    assert.equal(literalText(false), 'false');
});

test('describeSimple: titles instead of names, answer texts instead of values, yes/no for booleans', async () => {
    const { describeSimple } = await import('../../public/assets/survey-builder/conditions.js');
    const { createT } = await import('../../public/assets/survey-builder/dom.js');
    const t = createT(JSON.parse(readFileSync(new URL('./fixtures/builder-messages.json', import.meta.url), 'utf8')));
    const say = (text) => describeSimple(parseSimple(text), FIELDS, t);
    assert.equal(say("{Unterrichtsform} = 'TZ'"), '„Form des Unterrichts" ist „Teilzeit"');
    assert.equal(say('{WieKind} = false and {Alter} >= 18'), '„Anschrift wie Kind" ist „Nein" und Alter ist mindestens 18');
    assert.equal(say("{Land} anyof ['DE', 'AT'] or {Unbekannt} notempty"), 'Land ist eines von „DE", „AT" oder Unbekannt ist ausgefüllt');
    for (const op of ['equal', 'notequal', 'less', 'lessorequal', 'greater', 'greaterorequal', 'contains', 'notcontains', 'anyof', 'allof', 'noneof', 'empty', 'notempty']) {
        assert.ok(!t(`cond.op.${op}`).startsWith('['), op);
    }
});

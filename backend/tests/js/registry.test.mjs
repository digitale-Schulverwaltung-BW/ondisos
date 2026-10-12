// The registry is a table of facts about SurveyJS. These tests check it against the shipped library
// (frontend/public/assets/survey.core.min.js) and against the surveys that exist.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { TYPES, PAGE, typeDef, propDef, defaultOf, isKnownType, palette, valueTypes, template }
    from '../../public/assets/survey-builder/registry.js';

const require = createRequire(import.meta.url);
const Survey = require('../../../frontend/public/assets/survey.core.min.js');
const SURVEYS = new URL('../../../frontend/surveys/', import.meta.url);
const surveyFiles = readdirSync(SURVEYS).filter((f) => f.endsWith('.json') && f !== 'survey_theme.json').sort();

/** Library "type" values that fit each registry kind. */
const LIB_TYPES = {
    string: ['string'],
    localized: ['string', 'text'],
    html: ['html'],
    boolean: ['boolean', 'switch'],
    number: ['number'],
    enum: ['string', 'number'],
    choices: ['choiceitem[]', 'checkboxitem[]'],
    expression: ['expression', 'condition'],
    value: ['value'],
};

function libChoices(libProp) {
    const ch = typeof libProp.choices === 'function' ? libProp.choices({}) : libProp.getChoices?.({});
    return (ch ?? []).map((c) => c?.value ?? c);
}

const allTypes = [...Object.values(TYPES), PAGE];

test('every registry property exists in the SurveyJS library for its type, with the same kind', () => {
    for (const t of allTypes) {
        for (const p of t.props) {
            if (p.custom) { continue; } // added by Ondisos, see below
            const lib = Survey.Serializer.findProperty(t.type, p.name);
            assert.ok(lib, `${t.type}.${p.name} is not a SurveyJS property`);
            assert.ok(LIB_TYPES[p.kind]?.includes(lib.type), `${t.type}.${p.name}: kind "${p.kind}" vs library type "${lib.type}"`);
            if (p.kind === 'localized' || p.kind === 'html') {
                assert.equal(lib.isLocalizable, true, `${t.type}.${p.name} is localizable in the library`);
            }
        }
    }
});

test('defaults in the registry are the library defaults', () => {
    for (const t of allTypes) {
        for (const p of t.props.filter((x) => !x.custom)) {
            const lib = Survey.Serializer.findProperty(t.type, p.name);
            const expected = lib.defaultValue === undefined && p.kind === 'boolean' ? false : lib.defaultValue;
            const emptyList = Array.isArray(expected) && expected.length === 0;
            assert.deepEqual(p.default, emptyList ? undefined : expected, `${t.type}.${p.name}`);
        }
    }
});

test('offered enum values are valid library values', () => {
    for (const t of allTypes) {
        for (const p of t.props.filter((x) => x.kind === 'enum' && !x.custom)) {
            const lib = libChoices(Survey.Serializer.findProperty(t.type, p.name));
            if (lib.length === 0) { continue; }
            for (const v of p.values) { assert.ok(lib.includes(v), `${t.type}.${p.name}: "${v}" is not offered by the library`); }
            assert.ok(p.default === undefined || p.values.includes(p.default), `${t.type}.${p.name} default is offered`);
        }
    }
});

test('"emptyIsAbsent" holds in the library: an empty title is the same as none', () => {
    const flagged = allTypes.flatMap((t) => t.props.filter((p) => p.emptyIsAbsent).map((p) => [t.type, p.name]));
    assert.deepEqual([...new Set(flagged.map(([, n]) => n))].sort(), ['description', 'title']);
    const render = (type, extra, prop) => {
        const el = { type, name: 'n1', ...extra, ...(type === 'panel' ? { elements: [{ type: 'text', name: 'x1' }] } : {}) };
        const s = new Survey.Model({ pages: [{ name: 'p', elements: [el] }] });
        const e = type === 'panel' ? s.getPanelByName('n1') : s.getQuestionByName('n1');
        return e[`loc${prop[0].toUpperCase()}${prop.slice(1)}`].renderedHtml;
    };
    for (const [type, prop] of flagged.filter(([t]) => t !== 'page')) {
        assert.equal(render(type, { [prop]: '' }, prop), render(type, {}, prop), `${type}.${prop}`);
    }
    // ...and it does not hold for a placeholder (the reason the flag is per property)
    const ph = (extra) => new Survey.Model({ elements: [{ type: 'dropdown', name: 'd', choices: ['a'], ...extra }] }).getQuestionByName('d').placeholder;
    assert.notEqual(ph({ placeholder: '' }), ph({}));
    assert.equal(propDef('dropdown', 'placeholder').emptyIsAbsent, undefined);
});

test('numeric limits match the library where it states them', () => {
    const lib = libChoices(Survey.Serializer.findProperty('radiogroup', 'colCount'));
    const col = propDef('radiogroup', 'colCount');
    assert.deepEqual([col.min, col.max], [Math.min(...lib), Math.max(...lib)]);
});

test('placeholderExpression is added to every question by survey-handler-base.js', () => {
    const base = readFileSync(new URL('../../../frontend/public/js/survey-handler-base.js', import.meta.url), 'utf8');
    assert.match(base, /addProperty\("question",\s*\{\s*name:\s*"placeholderExpression"/);
    const custom = allTypes.flatMap((t) => t.props.filter((p) => p.custom).map((p) => p.name));
    assert.deepEqual([...new Set(custom)], ['placeholderExpression']);
});

test('a new element of every type is a valid SurveyJS element of that type', () => {
    for (const t of Object.values(TYPES)) {
        const el = template(t.type, 'neu1', { title: 'Titel', choices: ['a', 'b'] });
        const survey = new Survey.Model({ pages: [{ name: 'p', elements: [el] }] });
        const made = t.container ? survey.getPanelByName('neu1') : survey.getQuestionByName('neu1');
        assert.ok(made, `${t.type} is built`);
        assert.equal(made.getType(), t.type);
        assert.deepEqual(Object.keys(el).slice(0, 2), ['type', 'name']);
    }
});

test('templates carry only what the type has', () => {
    assert.deepEqual(template('text', 'q1'), { type: 'text', name: 'q1' });
    assert.deepEqual(template('dropdown', 'q2', { choices: ['x'] }), { type: 'dropdown', name: 'q2', choices: ['x'] });
    assert.deepEqual(template('html', 'q3', { title: 'ignored', html: '<p>x</p>' }), { type: 'html', name: 'q3', html: '<p>x</p>' });
    assert.deepEqual(template('panel', 'p1', { title: 'T' }), { type: 'panel', name: 'p1', title: 'T', elements: [] });
    assert.throws(() => template('matrix', 'm'), /Unknown element type/);
});

test('the registry covers every property the existing surveys use, except the few listed', () => {
    const structural = new Set(['type', 'elements']);
    const unsupported = new Map();
    const walk = (el) => {
        if (!el || typeof el !== 'object') { return; }
        if (!isKnownType(el.type)) { unsupported.set(`(type) ${el.type}`, 1); return; }
        for (const k of Object.keys(el)) {
            if (!structural.has(k) && !propDef(el.type, k)) { unsupported.set(`${el.type}.${k}`, (unsupported.get(`${el.type}.${k}`) ?? 0) + 1); }
        }
        (el.elements ?? []).forEach(walk);
    };
    for (const f of surveyFiles) {
        const s = JSON.parse(readFileSync(new URL(f, SURVEYS), 'utf8'));
        s.pages.forEach((p) => {
            Object.keys(p).filter((k) => k !== 'elements' && !propDef('page', k)).forEach((k) => unsupported.set(`page.${k}`, 1));
            (p.elements ?? []).forEach(walk);
        });
    }
    // Documented gap: validators are not edited by the builder; they stay in the text untouched.
    assert.deepEqual([...unsupported.keys()], ['boolean.validators']);
});

test('lookups', () => {
    assert.equal(isKnownType('text'), true);
    assert.equal(isKnownType('matrix'), false);
    assert.equal(isKnownType('constructor'), false, 'no prototype keys');
    assert.equal(typeDef('__proto__'), undefined);
    assert.equal(defaultOf('text', 'isRequired'), false);
    assert.equal(defaultOf('radiogroup', 'colCount'), 1);
    assert.equal(defaultOf('text', 'title'), undefined);
    assert.equal(propDef('page', 'title').kind, 'localized');
    assert.equal(propDef('text', 'nope'), undefined);
});

test('palette and value types', () => {
    const all = palette().flatMap((g) => g.types.map((t) => t.type));
    assert.deepEqual([...all].sort(), Object.keys(TYPES).sort());
    assert.deepEqual(valueTypes().sort(), ['boolean', 'checkbox', 'comment', 'dropdown', 'expression', 'file', 'radiogroup', 'signaturepad', 'tagbox', 'text']);
    assert.equal(Object.keys(TYPES).length, 12, 'the eleven used types plus signaturepad');
});

test('the registry cannot be modified from outside', () => {
    assert.throws(() => { 'use strict'; TYPES.text = {}; }, TypeError);
});

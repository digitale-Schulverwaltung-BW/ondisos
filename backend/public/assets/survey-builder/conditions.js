// Conditions (visibleIf, requiredIf, …) and other expressions: help with the TEXT, never a second representation of it.
//
// A condition stays the text the user typed (AP5, stage B): the builder never rewrites it. This module only reads it:
//   - tokenize():    the text as tokens with positions (unfinished braces and strings are tokens too, marked `open`),
//   - parseSimple(): "field operator value", joined by only "and" or only "or" — what plain language can describe,
//   - checkExpression(): field names that do not exist, values a choice question does not have, open braces/strings,
//   - suggest():     what fits at the cursor (field names, operators, the answers of the field, and/or),
//   - describeSimple(): the simple form in plain language (field titles, answer texts).
// No DOM here, so all of it runs under `node --test` against every condition of the real surveys.
//
// The grammar follows the SurveyJS expression language (MIT library): keywords are case-insensitive, `=`/`==`/`equal`
// are the same operator, strings take ' or ", arrays are [a, b]. The server and the library stay the authority on
// whether an expression is valid; these checks only warn.

import { valueAt } from './json-doc.js';
import { elementsOf } from './names.js';
import { typeDef } from './registry.js';
import { displayText } from './model.js';

/** Properties that are conditions (true/false); the others of kind 'expression' compute a value. */
export const CONDITION_PROPS = Object.freeze(['visibleIf', 'requiredIf', 'enableIf', 'resetValueIf', 'setValueIf']);

/** Operator words and symbols → canonical name. Unary operators follow their operand ("{a} empty"). */
const OPERATOR = {
    '=': 'equal', '==': 'equal', equal: 'equal',
    '!=': 'notequal', notequal: 'notequal',
    '<': 'less', less: 'less', '<=': 'lessorequal', lessorequal: 'lessorequal',
    '>': 'greater', greater: 'greater', '>=': 'greaterorequal', greaterorequal: 'greaterorequal',
    contains: 'contains', contain: 'contains', notcontains: 'notcontains', notcontain: 'notcontains',
    anyof: 'anyof', allof: 'allof', noneof: 'noneof',
    empty: 'empty', notempty: 'notempty',
};
const UNARY = new Set(['empty', 'notempty']);
const JOIN = { and: 'and', '&&': 'and', or: 'or', '||': 'or' };
/** Comparisons whose right side should be one of the field's answers. */
const VALUE_OPS = new Set(['equal', 'notequal', 'contains', 'notcontains', 'anyof', 'allof', 'noneof']);

/** What the suggestions offer, in this order: the symbol where there is one (that is what the surveys use). */
export const OPERATOR_CHOICES = Object.freeze([
    ['=', 'equal'], ['!=', 'notequal'], ['empty', 'empty'], ['notempty', 'notempty'],
    ['<', 'less'], ['<=', 'lessorequal'], ['>', 'greater'], ['>=', 'greaterorequal'],
    ['contains', 'contains'], ['notcontains', 'notcontains'], ['anyof', 'anyof'], ['allof', 'allof'], ['noneof', 'noneof'],
]);

const SYMBOLS = ['==', '!=', '<=', '>=', '&&', '||', '=', '<', '>', '!', '+', '-', '*', '/', '%', '^'];

/**
 * @typedef {{t:'var'|'str'|'num'|'word'|'sym'|'('|')'|'['|']'|',', v:string, start:number, end:number, open?:boolean}} Token
 * var: v is the name inside the braces; str: v is the content (quotes removed); open: brace or quote not closed.
 */

/** @returns {Token[]} */
export function tokenize(text) {
    const out = [];
    let i = 0;
    const n = text.length;
    while (i < n) {
        const c = text[i];
        if (/\s/.test(c)) { i += 1; continue; }
        const start = i;
        if (c === '{') {
            // a reference ends at "}"; a "{" before it means this one was left open
            const m = /[{}]/.exec(text.slice(i + 1));
            const at = m ? i + 1 + m.index : n;
            const closed = m?.[0] === '}';
            out.push({ t: 'var', v: text.slice(i + 1, at), start, end: closed ? at + 1 : at, ...(closed ? {} : { open: true }) });
            i = closed ? at + 1 : at;
        } else if (c === '"' || c === "'") {
            let j = i + 1;
            let v = '';
            while (j < n && text[j] !== c) {
                if (text[j] === '\\' && j + 1 < n) { v += text[j + 1]; j += 2; continue; }
                v += text[j];
                j += 1;
            }
            const open = j >= n;
            out.push({ t: 'str', v, start, end: open ? n : j + 1, ...(open ? { open: true } : {}) });
            i = open ? n : j + 1;
        } else if (/[0-9]/.test(c) || (c === '.' && /[0-9]/.test(text[i + 1] ?? ''))) {
            const m = /^(0x[0-9a-fA-F]+|[0-9]*\.?[0-9]+(?:[eE][-+]?[0-9]+)?)/.exec(text.slice(i));
            out.push({ t: 'num', v: m[0], start, end: i + m[0].length });
            i += m[0].length;
        } else if (/[A-Za-z_]/.test(c)) {
            const m = /^[A-Za-z_][A-Za-z0-9_]*/.exec(text.slice(i));
            out.push({ t: 'word', v: m[0], start, end: i + m[0].length });
            i += m[0].length;
        } else if ('()[],'.includes(c)) {
            out.push({ t: c, v: c, start, end: i + 1 });
            i += 1;
        } else {
            const sym = SYMBOLS.find((s) => text.startsWith(s, i)) ?? c;
            out.push({ t: 'sym', v: sym, start, end: i + sym.length });
            i += sym.length;
        }
    }
    return out;
}

/** Canonical operator of a token, or null. */
function operatorOf(tok) {
    if (!tok || (tok.t !== 'sym' && tok.t !== 'word')) { return null; }
    return OPERATOR[tok.t === 'word' ? tok.v.toLowerCase() : tok.v] ?? null;
}

function joinOf(tok) {
    if (!tok || (tok.t !== 'sym' && tok.t !== 'word')) { return null; }
    return JOIN[tok.t === 'word' ? tok.v.toLowerCase() : tok.v] ?? null;
}

function isBool(tok) {
    return tok?.t === 'word' && /^(true|false)$/i.test(tok.v);
}

/** A literal at tokens[i]: string, number, true/false or an array of those. @returns {{value:*, end:number}|null} (end: next index) */
function literalAt(tokens, i) {
    const tok = tokens[i];
    if (!tok) { return null; }
    if (tok.t === 'str' && !tok.open) { return { value: tok.v, end: i + 1, tokens: [tok] }; }
    if (tok.t === 'num') { return { value: Number(tok.v), end: i + 1, tokens: [tok] }; }
    if (isBool(tok)) { return { value: tok.v.toLowerCase() === 'true', end: i + 1, tokens: [tok] }; }
    if (tok.t === '[') {
        const values = [];
        const parts = [];
        let j = i + 1;
        if (tokens[j]?.t === ']') { return { value: [], end: j + 1, tokens: [] }; }
        for (;;) {
            const item = literalAt(tokens, j);
            if (!item || Array.isArray(item.value)) { return null; }
            values.push(item.value);
            parts.push(...item.tokens);
            j = item.end;
            if (tokens[j]?.t === ',') { j += 1; continue; }
            if (tokens[j]?.t === ']') { return { value: values, end: j + 1, tokens: parts }; }
            return null;
        }
    }
    return null;
}

/**
 * The condition as terms, when it is simple: each term "{field} operator value" (or "{field} empty|notempty"), all joined
 * by "and" or all by "or". Anything else (brackets, functions, "!", arithmetic, mixed and/or) is not simple.
 * @returns {{ok:true, join:'and'|'or'|null, terms:{field:string, op:string, value?:*}[]} | {ok:false, reason:'empty'|'complex'}}
 */
export function parseSimple(text) {
    const tokens = tokenize(typeof text === 'string' ? text : '');
    if (tokens.length === 0) { return { ok: false, reason: 'empty' }; }
    if (tokens.some((tok) => tok.open)) { return { ok: false, reason: 'complex' }; }
    const terms = [];
    let join = null;
    let i = 0;
    for (;;) {
        const field = tokens[i];
        const op = operatorOf(tokens[i + 1]);
        if (field?.t !== 'var' || !op) { return { ok: false, reason: 'complex' }; }
        if (UNARY.has(op)) {
            terms.push({ field: field.v, op });
            i += 2;
        } else {
            const lit = literalAt(tokens, i + 2);
            if (!lit) { return { ok: false, reason: 'complex' }; }
            terms.push({ field: field.v, op, value: lit.value });
            i = lit.end;
        }
        if (i === tokens.length) { return { ok: true, join, terms }; }
        const j = joinOf(tokens[i]);
        if (!j || (join && j !== join)) { return { ok: false, reason: 'complex' }; }
        join = j;
        i += 1;
    }
}

// ---- the fields a condition can refer to ------------------------------------------------------------------------------

/**
 * @typedef {{name:string, type:string|null, title:string, values:Array<string|number|boolean>|null, labels:Map<string,string>, inTemplate:boolean}} FieldInfo
 * values: what an answer can be (null: anything, e.g. a text field or answers loaded from elsewhere); labels: value → text
 */

function choiceValues(el) {
    if (!Array.isArray(el.choices) || el.choicesByUrl || el.choicesFromQuestion) { return null; }
    const values = [];
    const labels = new Map();
    for (const c of el.choices) {
        const value = c && typeof c === 'object' && !Array.isArray(c) ? c.value : c;
        if (value === undefined || value === null || typeof value === 'object') { continue; }
        values.push(value);
        const text = c && typeof c === 'object' ? displayText(c.text) : '';
        labels.set(String(value), text !== '' ? text : String(value));
    }
    // the extra items SurveyJS adds store fixed values
    if (el.showOtherItem || el.hasOther) { values.push('other'); }
    if (el.showNoneItem || el.hasNone) { values.push('none'); }
    if (el.showRefuseItem) { values.push('refused'); }
    if (el.showDontKnowItem) { values.push('dontknow'); }
    return { values, labels };
}

function fieldInfo(el, inTemplate) {
    const type = typeof el.type === 'string' ? el.type : null;
    const info = { name: el.name, type, title: displayText(el.title), values: null, labels: new Map(), inTemplate };
    if (type === 'boolean') {
        info.values = [el.valueTrue ?? true, el.valueFalse ?? false];
        info.labels.set(String(el.valueTrue ?? true), displayText(el.labelTrue));
        info.labels.set(String(el.valueFalse ?? false), displayText(el.labelFalse));
    } else if (['dropdown', 'radiogroup', 'checkbox', 'tagbox'].includes(type)) {
        const c = choiceValues(el);
        if (c) { info.values = c.values; info.labels = c.labels; }
    }
    return info;
}

/** True for elements that hold an answer (and unknown types: they might). */
function holdsValue(el) {
    const def = typeDef(el.type);
    return def ? def.hasValue : el.type !== 'html' && el.type !== 'image';
}

/**
 * Everything a condition can name: fields with an answer, calculated values, and the fields inside dynamic panels
 * (`templateElements`, referred to as {panel.name}). Names are matched exactly; a reference that differs only in case is
 * reported as a near miss (`caseField`).
 * @returns {Map<string, FieldInfo>} by name; template fields under "panel.<name>"
 */
export function fieldsOf(doc) {
    const fields = new Map();
    const addTemplate = (list) => {
        for (const el of Array.isArray(list) ? list : []) {
            if (!el || typeof el !== 'object') { continue; }
            if (typeof el.name === 'string' && el.name !== '' && holdsValue(el) && el.type !== 'panel') {
                fields.set(`panel.${el.name}`, fieldInfo(el, true));
            }
            addTemplate(el.elements);
            addTemplate(el.templateElements);
        }
    };
    for (const { value: el } of elementsOf(doc)) {
        if (typeof el.name === 'string' && el.name !== '' && holdsValue(el) && el.type !== 'panel') {
            fields.set(el.name, fieldInfo(el, false));
        }
        addTemplate(el.templateElements);
    }
    const calculated = valueAt(doc, ['calculatedValues']);
    for (const c of Array.isArray(calculated) ? calculated : []) {
        if (typeof c?.name === 'string' && c.name !== '' && !fields.has(c.name)) {
            fields.set(c.name, { name: c.name, type: 'calculatedValue', title: '', values: null, labels: new Map(), inTemplate: false });
        }
    }
    return fields;
}

/**
 * The field a reference names: "{a}" → a, "{panel.a}" → panel.a, "{a.b}" / "{a[0].b}" → a (a part of a dynamic panel
 * or a matrix: only the question itself is checked). "{row.…}" (matrix rows) and the like are not looked up.
 * @returns {{key:string|null, exact:boolean}} key null: not checkable
 */
function fieldKey(name) {
    if (name.startsWith('panel.')) { return { key: name, exact: true }; }
    if (/^(row|item|choice|parentpanel|rootpanel)\./i.test(name)) { return { key: null, exact: false }; }
    const m = /^([^.[]+)[.[]/.exec(name);
    return m ? { key: m[1], exact: false } : { key: name, exact: true };
}

function sameValue(a, b) {
    return a === b || String(a) === String(b);
}

/**
 * Warnings about an expression (the server and SurveyJS decide what is valid; these find the usual mistakes).
 * @param {string} text
 * @param {Map<string, FieldInfo>} fields   from fieldsOf()
 * @returns {{code:'unclosedBrace'|'unclosedString'|'parens'|'unknownField'|'caseField'|'unknownValue', start:number, end:number, name?:string, value?:string, suggestion?:string, field?:string}[]}
 */
export function checkExpression(text, fields) {
    const tokens = tokenize(typeof text === 'string' ? text : '');
    const out = [];
    let depth = 0;
    let brackets = 0;
    for (const tok of tokens) {
        if (tok.t === 'var' && tok.open) { out.push({ code: 'unclosedBrace', start: tok.start, end: tok.end }); }
        if (tok.t === 'str' && tok.open) { out.push({ code: 'unclosedString', start: tok.start, end: tok.end }); }
        if (tok.t === '(') { depth += 1; }
        if (tok.t === ')') { depth -= 1; }
        if (tok.t === '[') { brackets += 1; }
        if (tok.t === ']') { brackets -= 1; }
        if (depth < 0 || brackets < 0) { break; }
    }
    if (depth !== 0 || brackets !== 0) { out.push({ code: 'parens', start: 0, end: text.length }); }

    const lower = new Map([...fields.keys()].map((k) => [k.toLowerCase(), k]));
    tokens.forEach((tok, i) => {
        if (tok.t !== 'var' || tok.open) { return; }
        const { key } = fieldKey(tok.v);
        if (key === null) { return; }
        const field = fields.get(key);
        if (!field) {
            const near = lower.get(key.toLowerCase());
            out.push(near
                ? { code: 'caseField', start: tok.start, end: tok.end, name: tok.v, suggestion: near }
                : { code: 'unknownField', start: tok.start, end: tok.end, name: tok.v });
            return;
        }
        // "{field} = 'value'": the value should be one of the field's answers
        const op = operatorOf(tokens[i + 1]);
        if (!field.values || !VALUE_OPS.has(op) || key !== tok.v) { return; }
        const lit = literalAt(tokens, i + 2);
        if (!lit) { return; }
        const list = Array.isArray(lit.value) ? lit.value : [lit.value];
        list.forEach((value, k) => {
            if (field.values.some((v) => sameValue(v, value))) { return; }
            const at = lit.tokens[k] ?? tokens[i + 2];
            out.push({ code: 'unknownValue', start: at.start, end: at.end, field: key, value: String(value) });
        });
    });
    return out;
}

// ---- suggestions at the cursor ------------------------------------------------------------------------------------------

/** How a value is written in an expression: strings in single quotes (as in the surveys), numbers and booleans bare. */
export function literalText(value) {
    if (typeof value === 'number' || typeof value === 'boolean') { return String(value); }
    const s = String(value);
    return s.includes("'") && !s.includes('"') ? `"${s}"` : `'${s.replace(/'/g, "\\'")}'`;
}

const matches = (query, ...texts) => {
    const q = query.toLowerCase();
    return q === '' || texts.some((x) => typeof x === 'string' && x.toLowerCase().includes(q));
};

/**
 * What fits at the cursor.
 * @param {string} text
 * @param {number} caret
 * @param {Map<string, FieldInfo>} fields
 * @param {{inTemplate?:boolean, self?:string}} [options]  inTemplate: the expression belongs to a field of a dynamic
 *        panel ({panel.…} is offered); self: name of the element itself (not offered)
 * @returns {{kind:'field'|'operator'|'value'|'join'|null, from:number, to:number, query:string,
 *            items:{insert:string, label:string, detail?:string, op?:string, value?:*}[], field?:string}}
 *   Replacing text.slice(from, to) by an item's `insert` applies it.
 */
export function suggest(text, caret, fields, { inTemplate = false, self = null } = {}) {
    const before = text.slice(0, caret);
    const tokens = tokenize(before);
    const last = tokens[tokens.length - 1];
    const none = { kind: null, from: caret, to: caret, query: '', items: [] };

    // inside "{…": field names
    if (last?.t === 'var' && last.open) {
        const query = last.v;
        // replace up to the closing brace (or the end of the name) after the cursor
        const rest = /^[^{}\s]*\}?/.exec(text.slice(caret))[0];
        const items = fieldItems(fields, query, { inTemplate, self }).map((f) => ({ insert: `${f.name}}`, label: f.label, detail: f.detail, value: f.name }));
        return { kind: 'field', from: last.start + 1, to: caret + rest.length, query, items };
    }
    // inside an unfinished string after "{field} =": the field's answers
    if (last?.t === 'str' && last.open) {
        const ctx = valueContext(tokens, tokens.length - 1, fields);
        if (!ctx) { return none; }
        const rest = /^[^'"]*['"]?/.exec(text.slice(caret))[0];
        return { kind: 'value', from: last.start, to: caret + rest.length, query: last.v, items: valueItems(ctx, last.v), field: ctx.name };
    }

    // a word being typed right before the cursor (an operator, and/or, true/false) is the query
    const typing = last && last.end === caret && last.t === 'word' ? last : null;
    const prevIndex = tokens.length - (typing ? 2 : 1);
    const prev = tokens[prevIndex];
    const query = typing ? typing.v : '';
    const from = typing ? typing.start : caret;
    const pad = from > 0 && !/\s/.test(text[from - 1]) ? ' ' : '';

    // nothing yet, or after and/or/(/!: a field
    if (!prev || prev.t === '(' || joinOf(prev) || (prev.t === 'sym' && prev.v === '!')) {
        const items = fieldItems(fields, query, { inTemplate, self }).map((f) => ({ insert: `${pad}{${f.name}}`, label: f.label, detail: f.detail, value: f.name }));
        return { kind: 'field', from, to: caret, query, items };
    }
    // after "{field}": operators
    if (prev.t === 'var' && !prev.open) {
        const q = query.toLowerCase();
        const fit = OPERATOR_CHOICES.filter(([sym, op]) => matches(query, sym, op));
        const items = [...fit.filter(([sym]) => sym.startsWith(q)), ...fit.filter(([sym]) => !sym.startsWith(q))]
            .map(([sym, op]) => ({ insert: `${pad}${sym}${UNARY.has(op) ? '' : ' '}`, label: sym, op }));
        return { kind: 'operator', from, to: caret, query, items, field: fieldKey(prev.v).key ?? undefined };
    }
    // after "{field} =": the field's answers
    if (operatorOf(prev) && !UNARY.has(operatorOf(prev))) {
        const ctx = valueContext(tokens, prevIndex + 1, fields);
        if (!ctx) { return { ...none, from, query }; }
        return { kind: 'value', from, to: caret, query, items: valueItems(ctx, query).map((it) => ({ ...it, insert: `${pad}${it.insert}` })), field: ctx.name };
    }
    // after a complete comparison: and / or
    const complete = (prev.t === 'str' && !prev.open) || prev.t === 'num' || isBool(prev) || prev.t === ')' || prev.t === ']'
        || UNARY.has(operatorOf(prev) ?? '');
    if (complete && !(typing && isBool(typing))) {
        const items = [['and', 'and'], ['or', 'or']].filter(([w]) => matches(query, w)).map(([w]) => ({ insert: `${pad}${w} `, label: w, op: w }));
        return { kind: 'join', from, to: caret, query, items };
    }
    return { ...none, from, query };
}

function fieldItems(fields, query, { inTemplate, self }) {
    const out = [];
    for (const [key, f] of fields) {
        if (f.inTemplate !== inTemplate && f.inTemplate) { continue; } // {panel.x} only from inside the dynamic panel
        if (key === self || (inTemplate && key === `panel.${self}`)) { continue; }
        if (!matches(query, key, f.title)) { continue; }
        out.push({ name: key, label: key, detail: f.title !== '' && f.title !== f.name ? f.title : undefined });
    }
    // names starting with the query first, then the rest in document order
    const q = query.toLowerCase();
    return [...out.filter((f) => f.name.toLowerCase().startsWith(q)), ...out.filter((f) => !f.name.toLowerCase().startsWith(q))];
}

/** "{field} op" before index `at` (the token after the operator): the field, if its answers are known. */
function valueContext(tokens, at, fields) {
    const op = operatorOf(tokens[at - 1]);
    const field = tokens[at - 2];
    if (!op || UNARY.has(op) || field?.t !== 'var' || field.open) { return null; }
    const info = fields.get(field.v);
    if (!info?.values) { return null; }
    return { name: field.v, info };
}

function valueItems({ info }, query) {
    return info.values
        .filter((v) => matches(query.replace(/^['"]/, ''), String(v), info.labels.get(String(v))))
        .map((v) => {
            const text = info.labels.get(String(v));
            return { insert: literalText(v), label: literalText(v), detail: text && text !== String(v) ? text : undefined, value: v };
        });
}

// ---- plain language ---------------------------------------------------------------------------------------------------

const shorten = (s, max = 60) => (s.length > max ? `${s.slice(0, max - 1)}…` : s);

/**
 * The simple form in words: "„Form des Unterrichts" ist „Teilzeit" und …". Fields by title (else name), answers by their
 * text (else value), yes/no for booleans without labels. Texts come from `t` (builder.cond.*, builder.value.*).
 * @param {{join:string|null, terms:{field:string, op:string, value?:*}[]}} parsed  a successful parseSimple()
 */
export function describeSimple(parsed, fields, t) {
    const parts = parsed.terms.map(({ field, op, value }) => {
        const info = fields.get(field);
        const name = info?.title ? `„${shorten(info.title)}"` : field;
        const one = (v) => {
            if (typeof v === 'boolean' && info?.type === 'boolean' && !info.labels.get(String(v))) { return `„${t(v ? 'value.yes' : 'value.no')}"`; }
            const label = info?.labels.get(String(v));
            if (label) { return `„${shorten(label)}"`; }
            return typeof v === 'string' ? `„${v}"` : String(v);
        };
        const shown = Array.isArray(value) ? value.map(one).join(', ') : one(value);
        return t(`cond.op.${op}`, { field: name, value: shown });
    });
    return parts.join(parsed.join ? ` ${t(`cond.join.${parsed.join}`)} ` : '');
}

// Names in a survey: generating them, checking them, finding where they are used.
//
// A field name is the key of the answer in the database, the Excel column and the PDF field. Renaming a field therefore
// creates a NEW field for everything that was collected before (the SurveyDiff report in the editor shows that). So:
//   - names are generated once and never derived from the title; gaps are not filled again (see generateName),
//   - a rename is validated, and everything that refers to the old name is found (findReferences) so it can be warned
//     about or updated.
//
// The server (SurveyValidator) stays the authority. What it refuses is in `rules` (SurveyBuilderRules): reserved names,
// display types. Existing names are never judged here (two real surveys use names with spaces); only a name being set is.

import { valueAt } from './json-doc.js';

const PREFIX = { page: 'page', panel: 'panel' };

/**
 * Every element of the survey with its path, in document order (pages' elements, nested panels, top-level elements).
 * @returns {{path:Array<string|number>, value:object}[]}
 */
export function elementsOf(doc) {
    const out = [];
    const walk = (list, base) => {
        if (!Array.isArray(list)) { return; }
        list.forEach((value, i) => {
            if (!value || typeof value !== 'object' || Array.isArray(value)) { return; }
            const path = [...base, i];
            out.push({ path, value });
            walk(value.elements, [...path, 'elements']);
        });
    };
    const pages = valueAt(doc, ['pages']);
    if (Array.isArray(pages)) { pages.forEach((p, i) => walk(p?.elements, ['pages', i, 'elements'])); }
    walk(valueAt(doc, ['elements']), ['elements']);
    return out;
}

/** Names of the survey's calculated values: they become data keys too, so a question must not take one. */
function calculatedNames(doc) {
    const list = valueAt(doc, ['calculatedValues']);
    return Array.isArray(list) ? list.map((c) => c?.name).filter((n) => typeof n === 'string' && n !== '') : [];
}

function pageNames(doc) {
    const pages = valueAt(doc, ['pages']);
    return Array.isArray(pages) ? pages.map((p) => p?.name).filter((n) => typeof n === 'string') : [];
}

function elementNames(doc) {
    return elementsOf(doc).map((e) => e.value.name).filter((n) => typeof n === 'string' && n !== '');
}

/**
 * A new name: "question<N>" (panels "panel<N>", pages "page<N>"), N one above the highest number in use.
 * Gaps below the highest number are not filled in. A new field should not inherit the answers collected under the name
 * of a deleted one, but this function only sees the document in front of it: if the element with the highest number was
 * deleted, its number comes back. Whoever knows more passes it as `taken`: the names of the published version and of
 * the revision history, and the names removed in this editing session.
 */
export function generateName(doc, kind, rules, taken = []) {
    const prefix = PREFIX[kind] ?? 'question';
    const pool = kind === 'page' ? pageNames(doc) : [...elementNames(doc), ...calculatedNames(doc)];
    const all = new Set([...pool, ...taken, ...(rules?.reservedNames ?? [])]);
    const re = new RegExp(`^${prefix}(\\d+)$`);
    let n = 0;
    for (const name of all) {
        const m = re.exec(name);
        if (m) { n = Math.max(n, Number(m[1])); }
    }
    let candidate;
    do { n += 1; candidate = `${prefix}${n}`; } while (all.has(candidate));
    return candidate;
}

/**
 * Checks a name that is about to be set.
 * @param {object} doc
 * @param {string} name
 * @param {{kind:string, path?:Array<string|number>, rules:{reservedNames:string[]}}} ctx
 *        kind: 'page' or an element type; path: the element being renamed (it does not collide with itself)
 * @returns {{ok:true} | {ok:false, code:'type'|'empty'|'whitespace'|'characters'|'reserved'|'duplicate'|'calculatedValue'}}
 */
export function validateName(doc, name, ctx) {
    if (typeof name !== 'string') { return { ok: false, code: 'type' }; }
    if (name === '') { return { ok: false, code: 'empty' }; }
    if (name !== name.trim()) { return { ok: false, code: 'whitespace' }; }
    // { } and . break references like {name} or {panel.name} in expressions and texts; control characters break everything
    if (/[{}.\u0000-\u001f\u007f]/.test(name)) { return { ok: false, code: 'characters' }; }
    if (ctx.kind === 'page') {
        const pages = valueAt(doc, ['pages']) ?? [];
        const clash = pages.some((p, i) => p?.name === name && !(ctx.path && samePath(['pages', i], ctx.path)));
        return clash ? { ok: false, code: 'duplicate' } : { ok: true };
    }
    if ((ctx.rules?.reservedNames ?? []).includes(name)) { return { ok: false, code: 'reserved' }; }
    const clash = elementsOf(doc).some((e) => e.value.name === name && !(ctx.path && samePath(e.path, ctx.path)));
    if (clash) { return { ok: false, code: 'duplicate' }; }
    if (calculatedNames(doc).includes(name)) { return { ok: false, code: 'calculatedValue' }; }
    return { ok: true };
}

function samePath(a, b) {
    return a.length === b.length && a.every((s, i) => s === b[i]);
}

function escapeRegExp(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** The token a reference to `name` starts with: "{name" followed by "}", "." or "[" (so "{name2}" does not match "{name}"). */
function referenceRegExp(name) {
    return new RegExp(`\\{${escapeRegExp(name)}(?=[}.\\[])`, 'g');
}

/**
 * Where is the name used? Looks at every string of the survey: expressions (visibleIf, requiredIf, expression,
 * setValueExpression, calculatedValues, …) and texts with answers piped in ("Hello {firstname}"). Definitions
 * (the "name" of an element) are not references. Matching is exact and case-sensitive.
 * @returns {{path:Array<string|number>, text:string}[]}
 */
export function findReferences(doc, name) {
    if (typeof name !== 'string' || name === '') { return []; }
    const re = referenceRegExp(name);
    const found = [];
    const walk = (node, path) => {
        if (node.type === 'string') {
            re.lastIndex = 0;
            if (re.test(node.value)) { found.push({ path, text: node.value }); }
        } else if (node.type === 'object') {
            node.members.forEach((m) => walk(m.node, [...path, m.key]));
        } else if (node.type === 'array') {
            node.items.forEach((n, i) => walk(n, [...path, i]));
        }
    };
    walk(doc.root, []);
    return found;
}

/** The text with references to `from` pointed at `to` instead. */
export function replaceReferences(text, from, to) {
    return text.replace(referenceRegExp(from), () => `{${to}`);
}

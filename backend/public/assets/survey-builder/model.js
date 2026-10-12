// Survey model for the graphical builder: a view on the SurveyJS JSON text (json-doc.js), no data format of its own.
// The JSON text stays the single source of truth; every change is a splice into that text (see json-doc.js).
//
// AP0 scope (spike): read the outline (pages, elements, panels) and edit titles. The element registry, names,
// conditions and so on follow in AP1.
//
// Clean-room: written against the documented SurveyJS JSON format only (PLAN-3.2.0.md, "Lizenz und Clean-Room").

import { parseDoc, nodeAt, toValue, setValue, insertMember, JsonSyntaxError } from './json-doc.js';

/** Containers that hold child elements. */
const CONTAINER_KEY = 'elements';

/**
 * Loads survey text.
 * @returns {{ok:true, doc:object, warnings:string[]} | {ok:false, error:{message:string, line:number, column:number}}}
 */
export function loadModel(text) {
    try {
        const doc = parseDoc(text);
        if (doc.root.type !== 'object') {
            return { ok: false, error: { message: 'A survey is a JSON object', line: 1, column: 1 } };
        }
        const warnings = doc.duplicates.map((d) => `Duplicate key "${d.key}" at ${formatPath(d.path)}: JSON keeps only the last one`);
        return { ok: true, doc, warnings };
    } catch (e) {
        if (e instanceof JsonSyntaxError) {
            return { ok: false, error: { message: e.message, line: e.line, column: e.column } };
        }
        throw e;
    }
}

export function formatPath(path) {
    return path.length === 0 ? '(root)' : path.map((p) => (typeof p === 'number' ? `[${p}]` : `.${p}`)).join('').replace(/^\./, '');
}

/** Text of a string that may be localized: {"default": …, "de": …}. SurveyJS shows "default" unless survey.locale is set. */
export function displayText(value) {
    if (typeof value === 'string') { return value; }
    if (value && typeof value === 'object') {
        if (typeof value.default === 'string') { return value.default; }
        if (typeof value.de === 'string') { return value.de; }
        const first = Object.values(value).find((v) => typeof v === 'string');
        return first ?? '';
    }
    return '';
}

function describe(doc, path, node) {
    const v = toValue(node);
    const item = {
        path,
        type: typeof v.type === 'string' ? v.type : '(unknown)',
        name: typeof v.name === 'string' ? v.name : '',
        title: displayText(v.title),
        children: [],
    };
    const kids = nodeAt(doc, [...path, CONTAINER_KEY]);
    if (kids && kids.type === 'array') {
        item.children = kids.items.map((_, i) => describe(doc, [...path, CONTAINER_KEY, i], kids.items[i]));
    }
    return item;
}

/**
 * Pages with their elements. A survey may also put `elements` at the top level (one implicit page).
 * @returns {{path:Array, name:string, title:string, implicit:boolean, elements:object[]}[]}
 */
export function outline(doc) {
    const pages = nodeAt(doc, ['pages']);
    if (pages && pages.type === 'array') {
        return pages.items.map((pageNode, i) => {
            const path = ['pages', i];
            const page = describe(doc, path, pageNode);
            return { path, name: page.name, title: page.title, implicit: false, elements: page.children };
        });
    }
    const top = nodeAt(doc, [CONTAINER_KEY]);
    if (top && top.type === 'array') {
        const elements = top.items.map((n, i) => describe(doc, [CONTAINER_KEY, i], n));
        return [{ path: [], name: '', title: '', implicit: true, elements }];
    }
    return [];
}

/** Elements in document order, panels before their children. */
export function flatten(pages) {
    const out = [];
    const walk = (items) => items.forEach((e) => { out.push(e); walk(e.children); });
    pages.forEach((p) => walk(p.elements));
    return out;
}

/**
 * Sets the visible title of an element or page. Localized titles keep their other languages: only "default" changes
 * (or "de" when there is no "default"). Returns a new document; the text changes only where the title is.
 */
export function setTitle(doc, path, text) {
    const node = nodeAt(doc, [...path, 'title']);
    if (!node) { return insertMember(doc, path, 'title', text); }
    if (node.type === 'string') { return setValue(doc, [...path, 'title'], text); }
    if (node.type === 'object') {
        const key = node.members.some((m) => m.key === 'default') || !node.members.some((m) => m.key === 'de') ? 'default' : 'de';
        return insertMember(doc, [...path, 'title'], key, text);
    }
    throw new Error(`title at ${formatPath(path)} is neither a string nor a localized object`);
}

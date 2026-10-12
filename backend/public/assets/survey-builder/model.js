// Survey model for the graphical builder: a view on the SurveyJS JSON text (json-doc.js), no data format of its own.
// The JSON text stays the single source of truth; every change is a splice into that text (see json-doc.js).
//
// Reading: loadModel, outline, flatten. Changing (all return a new document, the text changes only where the edit is):
// setProp (registry-driven), renameElement, addElement / removeElement / moveElement, addPage / removePage / movePage.
// Every change reports {ok:true, doc, …} or {ok:false, code}: nothing throws for wrong user input, and nothing is
// written that the registry does not know. Conditions come with AP5.
//
// Clean-room: written against the documented SurveyJS JSON format only (PLAN-3.2.0.md, "Lizenz und Clean-Room").

import { parseDoc, nodeAt, toValue, valueAt, setValue, insertMember, removeMember, insertItem, removeItem, moveItem, JsonSyntaxError }
    from './json-doc.js';
import { PAGE, typeDef, propDef, isKnownType, template } from './registry.js';
import { elementsOf, generateName, validateName, findReferences, replaceReferences } from './names.js';

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

const ok = (doc, extra = {}) => ({ ok: true, doc, ...extra });
const fail = (code, extra = {}) => ({ ok: false, code, ...extra });

/** 'page' for a page, otherwise the element's type (undefined when there is nothing at path). */
function typeAt(doc, path) {
    const v = valueAt(doc, path);
    if (!v || typeof v !== 'object' || Array.isArray(v)) { return undefined; }
    return path[0] === 'pages' && path.length === 2 ? 'page' : v.type;
}

/**
 * Sets a localized text property ("title", "description", "placeholder", …). Other languages are left as they are:
 * only "default" changes (or "de" when there is no "default"). null/undefined, and "" for properties where empty means
 * absent, remove the text.
 */
export function setLocalized(doc, path, prop, text, { emptyIsAbsent = false } = {}) {
    const at = [...path, prop];
    const node = nodeAt(doc, at);
    const remove = text === null || text === undefined || (emptyIsAbsent && text === '');
    if (node && node.type !== 'string' && node.type !== 'object') {
        throw new Error(`${prop} at ${formatPath(path)} is neither a string nor a localized object`);
    }
    if (node && node.type === 'object') {
        const key = node.members.some((m) => m.key === 'default') || !node.members.some((m) => m.key === 'de') ? 'default' : 'de';
        if (!remove) { return insertMember(doc, at, key, text); }
        const left = removeMember(doc, at, key);
        const rest = valueAt(left, at);
        return Object.keys(rest).length === 0 ? removeMember(left, path, prop) : left;
    }
    if (remove) { return node ? removeMember(doc, path, prop) : doc; }
    return node ? setValue(doc, at, text) : insertMember(doc, path, prop, text);
}

/** Sets the visible title of an element or page (a localized title keeps its other languages). */
export function setTitle(doc, path, text) {
    return setLocalized(doc, path, 'title', text, { emptyIsAbsent: true });
}

function validChoice(item) {
    if (typeof item === 'string' || typeof item === 'number') { return true; }
    return !!item && typeof item === 'object' && !Array.isArray(item)
        && (typeof item.value === 'string' || typeof item.value === 'number');
}

/** Checks a value against the registry definition of a property; returns the value to write or a failure. */
function checkValue(def, value) {
    switch (def.kind) {
        case 'boolean': return typeof value === 'boolean';
        case 'number': return typeof value === 'number' && Number.isFinite(value)
            && (!def.integer || Number.isInteger(value))
            && (def.min === undefined || value >= def.min) && (def.max === undefined || value <= def.max);
        case 'enum': return def.values.includes(value);
        case 'string': case 'localized': case 'html': case 'expression': return typeof value === 'string';
        case 'choices': return Array.isArray(value) && value.every(validChoice);
        case 'value': return def.valueKind === 'boolean' ? typeof value === 'boolean' : typeof value === 'string';
        default: return false;
    }
}

/**
 * Sets a property of a page or element, as the registry describes it. A value equal to the SurveyJS default is not
 * written, and an existing property with the default value is removed; an empty text of an expression or default answer
 * removes it. "name" goes through renameElement. Unknown types and properties are refused, never guessed.
 * @param {{rules?:object}} [options] `rules` (SurveyBuilderRules) is needed when the name changes
 */
export function setProp(doc, path, name, value, options = {}) {
    const node = nodeAt(doc, path);
    if (!node || node.type !== 'object') { return fail('path'); }
    const typeName = typeAt(doc, path);
    if (typeName !== 'page' && !isKnownType(typeName)) { return fail('unknownType'); }
    const def = propDef(typeName, name);
    if (!def) { return fail('unknownProperty'); }
    if (name === 'name') { return renameElement(doc, path, value, options); }

    const present = node.members.some((m) => m.key === name);
    if (value === undefined || value === null) { return ok(present ? removeMember(doc, path, name) : doc); }
    if (!checkValue(def, value)) { return fail('value', { property: name }); }

    if (def.kind === 'localized' || def.kind === 'html') {
        return ok(setLocalized(doc, path, name, value, { emptyIsAbsent: def.emptyIsAbsent === true }));
    }
    const isEmpty = (def.kind === 'expression' || def.kind === 'value') && value === '';
    if (isEmpty || (def.default !== undefined && value === def.default)) {
        return ok(present ? removeMember(doc, path, name) : doc);
    }
    return ok(present ? setValue(doc, [...path, name], value) : insertMember(doc, path, name, value));
}

/**
 * Renames a page or element. Renaming a data field creates a new field for everything collected before (the answer is
 * stored under the name), so the result says so (`changesDataKey`) and lists what refers to the old name (`references`).
 * With `updateReferences` those references are pointed at the new name; otherwise they are left alone.
 * @returns {{ok:true, doc, changesDataKey:boolean, references:object[], updated:number} | {ok:false, code:string}}
 */
export function renameElement(doc, path, newName, { rules, updateReferences = false } = {}) {
    const typeName = typeAt(doc, path);
    if (typeName !== 'page' && !isKnownType(typeName)) { return fail('unknownType'); }
    const oldName = valueAt(doc, [...path, 'name']);
    if (oldName === newName) { return ok(doc, { changesDataKey: false, references: [], updated: 0 }); }
    const verdict = validateName(doc, newName, { kind: typeName, path, rules });
    if (!verdict.ok) { return fail(verdict.code); }

    const isPage = typeName === 'page';
    const references = isPage || typeof oldName !== 'string' ? [] : findReferences(doc, oldName);
    let next = doc;
    let updated = 0;
    if (updateReferences) {
        for (const ref of references) {
            const text = valueAt(next, ref.path);
            if (typeof text === 'string') {
                next = setValue(next, ref.path, replaceReferences(text, oldName, newName));
                updated += 1;
            }
        }
    }
    next = oldName === undefined ? insertMember(next, path, 'name', newName) : setValue(next, [...path, 'name'], newName);
    return ok(next, { changesDataKey: !isPage && typeDef(typeName).hasValue, references, updated });
}

/** Number of elements (panels included): what the server counts against its limit. */
export function countElements(doc) {
    return elementsOf(doc).length;
}

const elementsKeyCount = (path) => path.filter((p) => p === 'elements').length;

/**
 * Adds a new element at `index` into a page or panel (`parentPath`; [] for a survey with elements at the top level).
 * The name is generated (never from the title).
 * @param {{rules:object, texts?:{title?:string, choices?:string[], html?:string}}} options
 * @returns {{ok:true, doc, path, name} | {ok:false, code:string}}
 */
export function addElement(doc, parentPath, index, typeName, { rules, texts } = {}) {
    if (!isKnownType(typeName)) { return fail('unknownType'); }
    const parent = nodeAt(doc, parentPath);
    if (!parent || parent.type !== 'object') { return fail('path'); }
    const parentType = typeAt(doc, parentPath);
    const isRoot = parentPath.length === 0 && nodeAt(doc, ['pages']) === undefined;
    if (!isRoot && parentType !== 'page' && parentType !== 'panel') { return fail('notContainer'); }
    const list = nodeAt(doc, [...parentPath, 'elements']);
    if (list && list.type !== 'array') { return fail('path'); }
    if (!Number.isInteger(index) || index < 0 || index > (list ? list.items.length : 0)) { return fail('index'); }
    if (rules && countElements(doc) + 1 > rules.maxElements) { return fail('tooManyElements'); }
    if (rules && elementsKeyCount(parentPath) > rules.maxDepth) { return fail('tooDeep'); }

    const name = generateName(doc, typeName === 'panel' ? 'panel' : 'question', rules);
    const element = template(typeName, name, texts);
    const next = list ? insertItem(doc, [...parentPath, 'elements'], index, element) : insertMember(doc, parentPath, 'elements', [element]);
    return ok(next, { path: [...parentPath, 'elements', index], name });
}

function elementLocation(doc, path) {
    const last = path[path.length - 1];
    const arrPath = path.slice(0, -1);
    if (!Number.isInteger(last) || arrPath[arrPath.length - 1] !== 'elements') { return null; }
    const arr = nodeAt(doc, arrPath);
    return arr && arr.type === 'array' && arr.items[last] ? { arrPath, index: last, length: arr.items.length } : null;
}

/** Removes an element (with everything inside a panel). Reports what still refers to its name. */
export function removeElement(doc, path) {
    const at = elementLocation(doc, path);
    if (!at) { return fail('path'); }
    const name = valueAt(doc, [...path, 'name']);
    const references = typeof name === 'string' ? findReferences(doc, name) : [];
    return ok(removeItem(doc, at.arrPath, at.index), { references });
}

/** Moves an element to another position in the same page or panel (cross-container moves: AP4). */
export function moveElement(doc, path, to) {
    const at = elementLocation(doc, path);
    if (!at) { return fail('path'); }
    if (!Number.isInteger(to) || to < 0 || to >= at.length) { return fail('index'); }
    return ok(moveItem(doc, at.arrPath, at.index, to), { path: [...at.arrPath, to] });
}

function pagesList(doc) {
    const pages = nodeAt(doc, ['pages']);
    return pages && pages.type === 'array' ? pages : null;
}

/** Adds an empty page. A survey that keeps its elements at the top level has no pages to add to. */
export function addPage(doc, index, { rules, texts } = {}) {
    const pages = pagesList(doc);
    if (!pages && nodeAt(doc, ['elements'])) { return fail('implicitPage'); }
    if (nodeAt(doc, ['pages']) && !pages) { return fail('path'); }
    if (!Number.isInteger(index) || index < 0 || index > (pages ? pages.items.length : 0)) { return fail('index'); }
    const page = { name: generateName(doc, 'page', rules) };
    if (texts?.title) { page.title = texts.title; }
    page.elements = [];
    const next = pages ? insertItem(doc, ['pages'], index, page) : insertMember(doc, [], 'pages', [page]);
    return ok(next, { path: ['pages', index], name: page.name });
}

/** Removes a page with its elements. The last page stays: a survey without pages has no form. */
export function removePage(doc, index) {
    const pages = pagesList(doc);
    if (!pages || !pages.items[index]) { return fail('path'); }
    if (pages.items.length === 1) { return fail('lastPage'); }
    return ok(removeItem(doc, ['pages'], index));
}

export function movePage(doc, from, to) {
    const pages = pagesList(doc);
    if (!pages || !pages.items[from]) { return fail('path'); }
    if (!Number.isInteger(to) || to < 0 || to >= pages.items.length) { return fail('index'); }
    return ok(moveItem(doc, ['pages'], from, to), { path: ['pages', to] });
}

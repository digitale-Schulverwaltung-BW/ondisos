// Live preview (AP4a): what the builder and the preview tell each other about an element. The preview knows the rendered
// survey, the builder knows the text; they meet on kind and name, never on paths:
//   {kind: 'page', index, name?} · {kind: 'question', name} · {kind: 'panel', name} · {kind: 'choice', name, value}
// (a choice is an answer option of the question `name`). Names of elements are unique (the server refuses duplicates),
// pages are counted in the order of the text, as SurveyJS does. Pure logic, no DOM.

import { valueAt } from './json-doc.js';
import { elementsOf } from './names.js';
import { isChoicePath } from './model.js';

export const PROTOCOL = 'ondisos-live-preview';
export const VERSION = 1;

/** A message of the live preview protocol: {ondisos, v, type, …}. */
export function message(type, payload = {}) {
    return { ondisos: PROTOCOL, v: VERSION, type, ...payload };
}

/** Is `data` a message of this protocol (and version)? */
export function isMessage(data) {
    return Boolean(data) && typeof data === 'object' && data.ondisos === PROTOCOL && data.v === VERSION && typeof data.type === 'string';
}

const isPagePath = (path) => path.length === 2 && path[0] === 'pages' && Number.isInteger(path[1]);
const isObject = (v) => Boolean(v) && typeof v === 'object' && !Array.isArray(v);
/** The value of an answer option: "a", 2, or the `value` of {"value": …, "text": …}. */
const choiceValue = (choice) => (isObject(choice) ? choice.value : choice);

/**
 * What the preview should show for the builder's selection, or null if it cannot name it (an element without a name,
 * nothing selected).
 */
export function targetOf(doc, path) {
    if (!doc || !Array.isArray(path)) { return null; }
    if (path.length === 0) { return { kind: 'page', index: 0 }; }
    if (isPagePath(path)) {
        const page = valueAt(doc, path);
        return { kind: 'page', index: path[1], ...(typeof page?.name === 'string' ? { name: page.name } : {}) };
    }
    if (isChoicePath(path)) {
        const question = valueAt(doc, path.slice(0, -2));
        const value = choiceValue(valueAt(doc, path));
        if (typeof question?.name !== 'string' || question.name === '' || value === undefined || value === null) { return null; }
        return { kind: 'choice', name: question.name, value };
    }
    const element = valueAt(doc, path);
    if (!isObject(element) || typeof element.name !== 'string' || element.name === '') { return null; }
    return { kind: element.type === 'panel' ? 'panel' : 'question', name: element.name };
}

/**
 * The builder's path for what was clicked in the preview, or null if the text has no such element (the text changed
 * in the meantime). A click on an answer option selects the option if it holds questions (only those are rows of the
 * tree), else its question.
 */
export function pathOf(doc, target) {
    if (!doc || !isObject(target)) { return null; }
    if (target.kind === 'page') {
        const pages = valueAt(doc, ['pages']);
        if (!Array.isArray(pages)) { return target.index === 0 && Array.isArray(valueAt(doc, ['elements'])) ? [] : null; }
        if (typeof target.name === 'string') {
            const byName = pages.findIndex((p) => p?.name === target.name);
            if (byName > -1) { return ['pages', byName]; }
        }
        return Number.isInteger(target.index) && target.index >= 0 && target.index < pages.length ? ['pages', target.index] : null;
    }
    if (typeof target.name !== 'string' || target.name === '') { return null; }
    const found = elementsOf(doc).find((e) => e.value.name === target.name);
    if (!found) { return null; }
    if (target.kind !== 'choice') { return found.path; }
    const choices = Array.isArray(found.value.choices) ? found.value.choices : [];
    const index = choices.findIndex((c) => choiceValue(c) !== undefined && String(choiceValue(c)) === String(target.value));
    return index > -1 && isObject(choices[index]) && Array.isArray(choices[index].elements) ? [...found.path, 'choices', index] : found.path;
}

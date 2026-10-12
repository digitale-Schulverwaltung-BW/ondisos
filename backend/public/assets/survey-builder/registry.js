// Element registry of the survey builder: which element types the builder can edit and which properties it offers.
//
// "Supported" means "editable", not "allowed": anything the registry does not know (unknown types, unknown properties,
// logic the builder cannot show) stays in the text untouched and remains reachable in the JSON editor. A new type or
// property is an entry in this table; the properties panel is generated from it (AP2).
//
// Facts (defaults, allowed values) are checked against the shipped SurveyJS library by backend/tests/js/registry.test.mjs.
// Texts are not here: `label` is a message key (MessageService), the page supplies the translations.
//
// Clean-room: written against the documented SurveyJS JSON format and the MIT library's own metadata only
// (PLAN-3.2.0.md, "Lizenz und Clean-Room").

/**
 * Property kinds:
 *   string      one line of text
 *   localized   text that may be {"default": …, "de": …}; the builder edits "default" (or "de") and leaves other languages
 *   html        localized text that is HTML (limited to the server's allowlist, see SurveyBuilderRules)
 *   boolean     true/false; written only when it differs from the default
 *   number      number (integer when `integer`), optional min/max
 *   enum        one of `values`
 *   choices     list of answers: strings or {"value", "text"}
 *   expression  SurveyJS expression, shown as text (simple conditions: AP5)
 *   value       the default answer; its kind depends on the type (`valueKind`)
 *
 * `emptyIsAbsent`: an empty string means the same as no property (checked against the library). Not so for every text:
 * an empty dropdown placeholder shows nothing, while a missing one shows SurveyJS' own "Select...".
 *
 * @typedef {{name:string, kind:string, label:string, default?:*, values?:Array<string|number>, min?:number, max?:number,
 *            integer?:boolean, group:string, custom?:boolean, emptyIsAbsent?:boolean, valueKind?:string}} PropDef
 * @typedef {{type:string, label:string, group:string, hasValue:boolean, container:boolean, props:PropDef[]}} TypeDef
 */

const prop = (name, kind, group, extra = {}) => ({ name, kind, label: `builder.prop.${name}`, group, ...extra });

// The properties most question types share, in the order the panel shows them.
const NAME = prop('name', 'string', 'general');
const TITLE = prop('title', 'localized', 'general', { emptyIsAbsent: true });
const DESCRIPTION = prop('description', 'localized', 'general', { emptyIsAbsent: true });
const IS_REQUIRED = prop('isRequired', 'boolean', 'general', { default: false });
const VISIBLE = prop('visible', 'boolean', 'logic', { default: true });
const START_NEW_LINE = prop('startWithNewLine', 'boolean', 'layout', { default: true });
const TITLE_LOCATION = prop('titleLocation', 'enum', 'layout', { values: ['default', 'top', 'bottom', 'left', 'hidden'], default: 'default' });
const SHOW_NUMBER = prop('showNumber', 'boolean', 'layout', { default: true });
const VISIBLE_IF = prop('visibleIf', 'expression', 'logic');
const REQUIRED_IF = prop('requiredIf', 'expression', 'logic');
const PLACEHOLDER = prop('placeholder', 'localized', 'general');
// Added to every question by survey-handler-base.js (Ondisos), so not part of the library itself.
const PLACEHOLDER_EXPRESSION = prop('placeholderExpression', 'expression', 'logic', { custom: true });

const QUESTION = [NAME, TITLE, DESCRIPTION, IS_REQUIRED, VISIBLE, START_NEW_LINE, TITLE_LOCATION, SHOW_NUMBER, VISIBLE_IF, REQUIRED_IF];

const type = (typeName, group, extra, props) => ({
    type: typeName,
    label: `builder.type.${typeName}`,
    group,
    hasValue: true,
    container: false,
    ...extra,
    props,
});

/** @type {Record<string, TypeDef>} */
export const TYPES = Object.freeze({
    text: type('text', 'input', {}, [
        ...QUESTION,
        prop('inputType', 'enum', 'general', { values: ['text', 'email', 'tel', 'date', 'number', 'url', 'time'], default: 'text' }),
        PLACEHOLDER,
        PLACEHOLDER_EXPRESSION,
        prop('inputSize', 'number', 'layout', { integer: true, min: 1 }),
        prop('defaultValue', 'value', 'general', { valueKind: 'string' }),
        prop('setValueExpression', 'expression', 'logic'),
    ]),
    comment: type('comment', 'input', {}, [
        ...QUESTION,
        PLACEHOLDER,
        PLACEHOLDER_EXPRESSION,
        prop('rows', 'number', 'layout', { integer: true, min: 1, max: 50, default: 4 }),
    ]),
    dropdown: type('dropdown', 'choice', {}, [
        ...QUESTION,
        prop('choices', 'choices', 'general'),
        PLACEHOLDER,
        prop('allowClear', 'boolean', 'general', { default: true }),
        prop('defaultValue', 'value', 'general', { valueKind: 'string' }),
    ]),
    tagbox: type('tagbox', 'choice', {}, [
        ...QUESTION,
        prop('choices', 'choices', 'general'),
        PLACEHOLDER,
        prop('closeOnSelect', 'boolean', 'general', { default: false }),
    ]),
    radiogroup: type('radiogroup', 'choice', {}, [
        ...QUESTION,
        prop('choices', 'choices', 'general'),
        prop('colCount', 'number', 'layout', { integer: true, min: 0, max: 5, default: 1 }),
        prop('defaultValue', 'value', 'general', { valueKind: 'string' }),
    ]),
    checkbox: type('checkbox', 'choice', {}, [
        ...QUESTION,
        prop('choices', 'choices', 'general'),
        prop('colCount', 'number', 'layout', { integer: true, min: 0, max: 5, default: 1 }),
    ]),
    boolean: type('boolean', 'choice', {}, [
        ...QUESTION,
        prop('labelTrue', 'localized', 'general'),
        prop('labelFalse', 'localized', 'general'),
        prop('defaultValue', 'value', 'general', { valueKind: 'boolean' }),
    ]),
    file: type('file', 'input', {}, [
        ...QUESTION,
        prop('filePlaceholder', 'localized', 'general'),
        prop('maxSize', 'number', 'general', { integer: true, min: 0, default: 0 }),
    ]),
    signaturepad: type('signaturepad', 'input', {}, [
        ...QUESTION,
        // PNG only: JPEG gives a black background in form and PDF, SVG is refused by the server (SURVEYJS.md).
        prop('dataFormat', 'enum', 'general', { values: ['png'], default: 'png' }),
    ]),
    expression: type('expression', 'logic', {}, [
        NAME,
        TITLE,
        VISIBLE,
        prop('expression', 'expression', 'general'),
    ]),
    html: type('html', 'display', { hasValue: false }, [
        NAME,
        prop('html', 'html', 'general'),
        VISIBLE_IF,
    ]),
    panel: type('panel', 'layout', { hasValue: false, container: true }, [
        NAME,
        TITLE,
        DESCRIPTION,
        VISIBLE_IF,
        START_NEW_LINE,
    ]),
});

/** A page is not an element type, but it is edited the same way and holds elements. */
export const PAGE = Object.freeze({
    type: 'page',
    label: 'builder.type.page',
    group: 'layout',
    hasValue: false,
    container: true,
    props: [NAME, TITLE, DESCRIPTION, VISIBLE_IF],
});

export function isKnownType(typeName) {
    return Object.hasOwn(TYPES, typeName);
}

/** @returns {TypeDef|undefined} */
export function typeDef(typeName) {
    return Object.hasOwn(TYPES, typeName) ? TYPES[typeName] : undefined;
}

/** @returns {PropDef|undefined} */
export function propDef(typeName, propName) {
    const def = typeName === 'page' ? PAGE : typeDef(typeName);
    return def?.props.find((p) => p.name === propName);
}

/** The value SurveyJS uses when the property is absent (undefined = nothing). */
export function defaultOf(typeName, propName) {
    return propDef(typeName, propName)?.default;
}

/**
 * Element types the palette offers, grouped, in a fixed order.
 * @returns {Array<{group:string, types:TypeDef[]}>}
 */
export function palette() {
    const order = ['input', 'choice', 'display', 'layout', 'logic'];
    return order
        .map((group) => ({ group, types: Object.values(TYPES).filter((t) => t.group === group) }))
        .filter((g) => g.types.length > 0);
}

/** Types whose elements are data fields (they store an answer), as the server sees them. */
export function valueTypes() {
    return Object.values(TYPES).filter((t) => t.hasValue).map((t) => t.type);
}

/**
 * JSON for a new element. Key order follows the SurveyJS exports: type, name, title, then the type's own content.
 * @param {string} typeName
 * @param {string} name
 * @param {{title?:string, choices?:string[], html?:string}} [texts] texts come from the page (messages), not from here
 */
export function template(typeName, name, texts = {}) {
    const def = typeDef(typeName);
    if (!def) { throw new Error(`Unknown element type "${typeName}"`); }
    const el = { type: typeName, name };
    if (texts.title && def.props.some((p) => p.name === 'title')) { el.title = texts.title; }
    if (def.props.some((p) => p.name === 'choices')) { el.choices = Array.isArray(texts.choices) ? [...texts.choices] : []; }
    if (typeName === 'html') { el.html = texts.html ?? ''; }
    if (def.container) { el.elements = []; }
    return el;
}

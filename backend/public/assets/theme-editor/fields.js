// Theme editor (3.2.0b AP4): the fields above the JSON, as pure logic (no DOM; runs in the browser and under `node --test`).
//
// The JSON text is the only source. readFields() reads the fields from it; applyField() writes one field back into it
// with the lossless editor of the survey builder (json-doc.js): only the keys of that field change, everything else
// stays byte for byte (unknown keys, order, layout). Writing the value a field already has changes nothing.
//
// Fields write SurveyJS 3 variables (--sjs2-*) only. When a theme still carries the SurveyJS 2 variable of a field
// (--sjs-*), the field shows its value and replaces it on change: applyTheme() maps the old variables (table in
// survey.core, see PLAN-3.2.0b AP0), so leaving them beside the new ones would keep old tones (e.g. the hover colour).
// A value the fields cannot show (var(…), a colour with transparency, radii that differ) makes the field read-only.

import { parseDoc, nodeAt, toValue, setValue, insertMember, removeMember } from '../survey-builder/json-doc.js';

const V = 'cssVariables';

export const BRAND = '--sjs2-color-project-brand-600';
/** SurveyJS 2 variables that applyTheme() turns into brand tones (brand-600, the light and the hover tone). */
export const BRAND_LEGACY = ['--sjs-primary-backcolor', '--sjs-primary-backcolor-light', '--sjs-primary-backcolor-dark'];
export const TITLE = '--sjs2-color-component-survey-header-default-title';
export const TITLE_LEGACY = ['--sjs-font-headertitle-color'];
export const TITLE_BRAND = `var(${BRAND})`;
export const FONT = '--sjs2-typography-font-family-text';
export const FONT_LEGACY = ['--sjs-font-family'];
export const FONT_SIZE = '--sjs2-base-unit-font-size';
export const LINE_HEIGHT = '--sjs2-base-unit-line-height';
export const FONT_SIZE_LEGACY = ['--sjs-font-size'];
export const FONT_SIZES = [14, 15, 16, 17, 18, 19, 20];
export const BACKGROUND = '--sjs2-color-utility-surface-survey';
export const RADII = ['--sjs2-base-unit-radius', '--sjs2-radius-component-panel', '--sjs2-radius-component-action', '--sjs2-radius-component-formbox'];
export const RADIUS_LEGACY = ['--sjs-corner-radius'];
export const SHADOWS = ['--sjs2-border-effect-component-panel-default', '--sjs2-border-effect-component-panel-simple-default'];
/** The soft card shadow of the built-in default (backend/templates/theme/default.json). */
export const DEFAULT_SHADOW = '0px 1px 2px 0px rgba(0, 0, 0, 0.15)';
export const IMAGE_FITS = ['cover', 'contain', 'auto'];

export const FONT_OPEN_SANS = 'Open Sans';
export const FONT_SYSTEM = 'system-ui, sans-serif';

export const FIELD_NAMES = ['brand', 'titleBrand', 'font', 'fontSize', 'background', 'backgroundImage', 'backgroundOpacity', 'backgroundImageFit', 'radius', 'cards'];

// ---- colours ---------------------------------------------------------------------------------------------------------

/**
 * An opaque colour the colour picker can show: #rgb, #rrggbb, rgb(r, g, b), rgba(r, g, b, 1).
 * @returns {{r:number, g:number, b:number, hex:string}|null}
 */
export function parseColor(value) {
    if (typeof value !== 'string') { return null; }
    const s = value.trim().toLowerCase();
    let r; let g; let b;
    let m = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/.exec(s);
    if (m) {
        [r, g, b] = [m[1], m[2], m[3]].map((h) => parseInt(h + h, 16));
    } else if ((m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/.exec(s))) {
        [r, g, b] = [m[1], m[2], m[3]].map((h) => parseInt(h, 16));
    } else if ((m = /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(\d*\.?\d+)\s*)?\)$/.exec(s))) {
        if (m[4] !== undefined && Number(m[4]) !== 1) { return null; } // transparent: not for the picker
        [r, g, b] = [m[1], m[2], m[3]].map(Number);
        if ([r, g, b].some((x) => x > 255)) { return null; }
    } else {
        return null;
    }
    const hex = `#${[r, g, b].map((x) => x.toString(16).padStart(2, '0')).join('')}`;
    return { r, g, b, hex };
}

/** WCAG contrast ratio of a colour against white (white text on the colour, or the colour on a white card); null if unreadable. */
export function contrastWithWhite(value) {
    const c = parseColor(value);
    if (!c) { return null; }
    const lin = (x) => { const v = x / 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
    const lum = 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
    return 1.05 / (lum + 0.05);
}

// ---- reading -----------------------------------------------------------------------------------------------------------

const px = (value) => {
    const m = typeof value === 'string' ? /^\s*(\d+(?:\.\d+)?)px\s*$/.exec(value) : null;
    return m ? Number(m[1]) : null;
};

/** The variables object (plain values), or {} when there is none. */
function variables(doc) {
    const node = nodeAt(doc, [V]);
    return node && node.type === 'object' ? toValue(node) : {};
}

/** First of the keys that is set (new key first, then the old ones). */
function pick(vars, keys) {
    for (const key of keys) {
        if (Object.prototype.hasOwnProperty.call(vars, key)) { return { key, value: vars[key] }; }
    }
    return null;
}

const ok = (value, extra = {}) => ({ state: 'ok', value, ...extra });
const readonly = (raw) => ({ state: 'readonly', value: null, raw: typeof raw === 'string' ? raw : JSON.stringify(raw) });

/**
 * The fields of a theme text.
 * @returns {{ok:true, fields:Object<string,{state:'ok'|'readonly', value:*, raw?:string, legacy?:boolean}>}
 *   |{ok:false, reason:'syntax'|'not_object'|'variables_not_object', message?:string}}
 *   value null means "not set" (SurveyJS's own default applies)
 */
export function readFields(text) {
    let doc;
    try { doc = parseDoc(text); } catch (e) { return { ok: false, reason: 'syntax', message: e.message }; }
    if (doc.root.type !== 'object') { return { ok: false, reason: 'not_object' }; }
    const varsNode = nodeAt(doc, [V]);
    if (varsNode && varsNode.type !== 'object') { return { ok: false, reason: 'variables_not_object' }; }
    const root = toValue(doc.root);
    const vars = variables(doc);
    const fields = {};

    // Markenfarbe
    const brand = pick(vars, [BRAND, BRAND_LEGACY[0]]);
    if (!brand) { fields.brand = ok(null); }
    else if (parseColor(brand.value)) { fields.brand = ok(brand.value, { legacy: brand.key !== BRAND }); }
    else { fields.brand = readonly(brand.value); }

    // Titel in Markenfarbe
    const title = pick(vars, [TITLE, ...TITLE_LEGACY]);
    if (!title) { fields.titleBrand = ok(false); }
    else if (title.key === TITLE && String(title.value).replace(/\s+/g, '') === TITLE_BRAND) { fields.titleBrand = ok(true); }
    else if (title.key !== TITLE && brand && parseColor(title.value) && parseColor(brand.value)
        && parseColor(title.value).hex === parseColor(brand.value).hex) {
        // SurveyJS 2 had no reference: the Theme Editor wrote the brand colour itself as the title colour
        fields.titleBrand = ok(true, { legacy: true });
    } else { fields.titleBrand = readonly(title.value); }

    // Schrift
    const font = pick(vars, [FONT, ...FONT_LEGACY]);
    if (!font) { fields.font = ok(null); }
    else if (typeof font.value === 'string' && font.value.trim() !== '') { fields.font = ok(font.value, { legacy: font.key !== FONT }); }
    else { fields.font = readonly(font.value); }

    // Schriftgröße: SurveyJS 3 keeps half the size in two variables; SurveyJS 2 the whole size in one
    const half = Object.prototype.hasOwnProperty.call(vars, FONT_SIZE) ? px(vars[FONT_SIZE]) : undefined;
    const lineHalf = Object.prototype.hasOwnProperty.call(vars, LINE_HEIGHT) ? px(vars[LINE_HEIGHT]) : undefined;
    if (half === undefined && lineHalf === undefined) {
        const legacy = pick(vars, FONT_SIZE_LEGACY);
        if (!legacy) { fields.fontSize = ok(null); }
        else if (FONT_SIZES.includes(px(legacy.value))) { fields.fontSize = ok(px(legacy.value), { legacy: true }); }
        else { fields.fontSize = readonly(legacy.value); }
    } else if (half !== null && half !== undefined && (lineHalf === undefined || lineHalf === half) && FONT_SIZES.includes(half * 2)) {
        fields.fontSize = ok(half * 2);
    } else {
        fields.fontSize = readonly(`${vars[FONT_SIZE] ?? ''} / ${vars[LINE_HEIGHT] ?? ''}`);
    }

    // Hintergrund
    if (!Object.prototype.hasOwnProperty.call(vars, BACKGROUND)) { fields.background = ok(null); }
    else if (parseColor(vars[BACKGROUND])) { fields.background = ok(vars[BACKGROUND]); }
    else { fields.background = readonly(vars[BACKGROUND]); }

    // Hintergrundbild
    const image = root.backgroundImage;
    fields.backgroundImage = image === undefined ? ok('') : (typeof image === 'string' ? ok(image) : readonly(image));
    const opacity = root.backgroundOpacity;
    fields.backgroundOpacity = opacity === undefined ? ok(null)
        : (typeof opacity === 'number' && opacity >= 0 && opacity <= 1 ? ok(opacity) : readonly(opacity));
    const fit = root.backgroundImageFit;
    fields.backgroundImageFit = fit === undefined ? ok(null) : (IMAGE_FITS.includes(fit) ? ok(fit) : readonly(fit));

    // Ecken: one value for all four, else read-only
    const radii = RADII.filter((k) => Object.prototype.hasOwnProperty.call(vars, k)).map((k) => vars[k]);
    if (radii.length === 0) {
        const legacy = pick(vars, RADIUS_LEGACY);
        if (!legacy) { fields.radius = ok(null); }
        else if (px(legacy.value) !== null) { fields.radius = ok(px(legacy.value), { legacy: true }); }
        else { fields.radius = readonly(legacy.value); }
    } else if (radii.length === RADII.length && radii.every((r) => r === radii[0]) && px(radii[0]) !== null) {
        fields.radius = ok(px(radii[0]));
    } else {
        fields.radius = readonly(RADII.map((k) => vars[k] ?? '–').join(' / '));
    }

    // Fragen als Karten
    const panelless = root.isPanelless;
    fields.cards = panelless === undefined || typeof panelless === 'boolean' ? ok(panelless !== true) : readonly(panelless);

    return { ok: true, fields };
}

// ---- writing -----------------------------------------------------------------------------------------------------------

function ensureVariables(doc) {
    const node = nodeAt(doc, [V]);
    return node ? doc : insertMember(doc, [], V, {});
}

function setVar(doc, key, value) {
    const d = ensureVariables(doc);
    return insertMember(d, [V], key, value); // replaces an existing key in place
}

function removeVars(doc, keys) {
    if (!nodeAt(doc, [V])) { return doc; }
    let d = doc;
    for (const key of keys) { d = removeMember(d, [V], key); }
    return d;
}

function setRoot(doc, key, value) {
    return value === null ? removeMember(doc, [], key) : insertMember(doc, [], key, value);
}

const same = (a, b) => a === b || (a !== null && b !== null && typeof a === 'string' && typeof b === 'string' && a.trim() === b.trim());

/**
 * Writes one field into the theme text and returns the new text. value null = "not set" (removes the keys).
 * Writing the value the field already shows returns the text unchanged.
 * @throws {Error} the text cannot be read, the field is read-only, or the value is not allowed for the field
 */
export function applyField(text, name, value) {
    const read = readFields(text);
    if (!read.ok) { throw new Error(`Theme not readable (${read.reason})`); }
    const field = read.fields[name];
    if (!field) { throw new Error(`Unknown field ${name}`); }
    if (field.state !== 'ok') { throw new Error(`Field ${name} is read-only`); }
    if (same(field.value, value)) { return text; }

    let doc = parseDoc(text);
    switch (name) {
        case 'brand':
            if (value !== null && !parseColor(value)) { throw new Error('Keine Farbe'); }
            doc = removeVars(doc, BRAND_LEGACY);
            doc = value === null ? removeVars(doc, [BRAND]) : setVar(doc, BRAND, value.trim());
            if (read.fields.titleBrand.state === 'ok' && read.fields.titleBrand.legacy) {
                // the SurveyJS 2 title had the brand colour written out: keep it following the brand as a reference
                doc = removeVars(doc, TITLE_LEGACY);
                doc = setVar(doc, TITLE, TITLE_BRAND);
            }
            break;
        case 'titleBrand':
            doc = removeVars(doc, TITLE_LEGACY);
            doc = value ? setVar(doc, TITLE, TITLE_BRAND) : removeVars(doc, [TITLE]);
            break;
        case 'font':
            if (value !== null && (typeof value !== 'string' || value.trim() === '' || /[;{}<>]/.test(value))) { throw new Error('Keine Schriftangabe'); }
            doc = removeVars(doc, FONT_LEGACY);
            doc = value === null ? removeVars(doc, [FONT]) : setVar(doc, FONT, value.trim());
            break;
        case 'fontSize':
            if (value !== null && !FONT_SIZES.includes(value)) { throw new Error('Schriftgröße nicht vorgesehen'); }
            doc = removeVars(doc, FONT_SIZE_LEGACY);
            if (value === null) {
                doc = removeVars(doc, [FONT_SIZE, LINE_HEIGHT]);
            } else {
                doc = setVar(doc, FONT_SIZE, `${value / 2}px`);
                doc = setVar(doc, LINE_HEIGHT, `${value / 2}px`);
            }
            break;
        case 'background':
            if (value !== null && !parseColor(value)) { throw new Error('Keine Farbe'); }
            doc = value === null ? removeVars(doc, [BACKGROUND]) : setVar(doc, BACKGROUND, value.trim());
            break;
        case 'backgroundImage':
            if (typeof value !== 'string') { throw new Error('Keine Adresse'); }
            doc = setRoot(doc, 'backgroundImage', value.trim());
            break;
        case 'backgroundOpacity':
            if (value !== null && !(typeof value === 'number' && value >= 0 && value <= 1)) { throw new Error('Deckkraft zwischen 0 und 1'); }
            doc = setRoot(doc, 'backgroundOpacity', value);
            break;
        case 'backgroundImageFit':
            if (value !== null && !IMAGE_FITS.includes(value)) { throw new Error('Nicht vorgesehen'); }
            doc = setRoot(doc, 'backgroundImageFit', value);
            break;
        case 'radius':
            if (value !== null && !(Number.isInteger(value) && value >= 0 && value <= 40)) { throw new Error('Radius zwischen 0 und 40 px'); }
            doc = removeVars(doc, RADIUS_LEGACY);
            if (value === null) {
                doc = removeVars(doc, RADII);
            } else {
                for (const key of RADII) { doc = setVar(doc, key, `${value}px`); }
            }
            break;
        case 'cards':
            if (typeof value !== 'boolean') { throw new Error('Ja oder nein'); }
            // Without cards the default's shadow on "panel-simple" would stay visible (seen in AP0): remove both shadows.
            // With cards again, the default's soft shadow comes back where none is set.
            doc = insertMember(doc, [], 'isPanelless', !value);
            if (value) {
                for (const key of SHADOWS) {
                    if (!Object.prototype.hasOwnProperty.call(variables(doc), key)) { doc = setVar(doc, key, DEFAULT_SHADOW); }
                }
            } else {
                doc = removeVars(doc, SHADOWS);
            }
            break;
        default:
            throw new Error(`Unknown field ${name}`);
    }
    return doc.text;
}

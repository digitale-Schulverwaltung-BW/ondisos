// Small monochrome icons: of the element types (palette "Element hinzufügen" and the tree) and the building blocks ("Baustein
// einfügen") in a 16×16 box, of the templates and examples of an empty survey in a 24×24 box. Drawn here as plain SVG
// shapes, stroked in the current text color; no icon font, no library, nothing loaded. Decorative: the label next to the
// icon names the thing, so the SVG is aria-hidden.

const NS = 'http://www.w3.org/2000/svg';

// [tag, attributes]; "fill" marks a filled shape, everything else is a 1.5px outline.
const rect = (x, y, w, h, rx = 1.5, extra = {}) => ['rect', { x, y, width: w, height: h, rx, ...extra }];
const path = (d, extra = {}) => ['path', { d, ...extra }];
const circle = (cx, cy, r, extra = {}) => ['circle', { cx, cy, r, ...extra }];
const FILL = { fill: 'currentColor', stroke: 'none' };

/** One drawing per element type of the registry (registry.test.mjs checks that none is missing). */
export const ICONS = Object.freeze({
    text: [rect(1.5, 4.5, 13, 7), path('M4.5 6.5v3')],
    comment: [rect(1.5, 2.5, 13, 11), path('M4.5 5.5h7M4.5 8h7M4.5 10.5h4')],
    dropdown: [rect(1.5, 4.5, 13, 7), path('M9.5 7l1.5 1.5L12.5 7')],
    tagbox: [rect(1.5, 4.5, 13, 7), rect(3.5, 6.5, 3.5, 3, 1, FILL), rect(8, 6.5, 3.5, 3, 1, FILL)],
    radiogroup: [circle(8, 8, 5.5), circle(8, 8, 2.5, FILL)],
    checkbox: [rect(2.5, 2.5, 11, 11, 2), path('M5 8.2l2 2 4-4.4')],
    boolean: [rect(1.5, 4.5, 13, 7, 3.5), circle(11, 8, 2, FILL)],
    file: [path('M4 1.5h5l3 3v10H4z'), path('M8 12.5V7.5M6 9.5l2-2 2 2')],
    signaturepad: [path('M2 10.5c1.5-3 2.5-5 3.5-4s.5 3.5 1.5 3.5 1.5-3 2.5-3 1 2.5 2 2.5 1.5-1 2.5-1.5'), path('M2 13.5h12')],
    expression: [rect(2.5, 1.5, 11, 13), path('M5 6h6M5 9h6')],
    html: [path('M2 3.5h12M2 6.5h12M2 9.5h12M2 12.5h7')],
    panel: [rect(1.5, 1.5, 13, 13, 1.5, { 'stroke-dasharray': '2 1.5' }), rect(4, 4.5, 8, 2.5, .75), rect(4, 9, 8, 2.5, .75)],
});

/** The rows of the tree that are no element: the survey itself, a page, an answer with questions in it. */
export const TREE_ICONS = Object.freeze({
    // clipboard
    survey: [rect(3, 2.5, 10, 12, 1.5), rect(5.5, 1.25, 5, 2.5, .75), path('M5.5 7.5h5M5.5 10.5h5')],
    // page with a folded corner
    page: [path('M3.5 1.5h6l3 3v10h-9z'), path('M9.5 1.5v3h3')],
    // an arrow down and to the right: what hangs below an answer
    choice: [path('M4 2.5v6a1.5 1.5 0 0 0 1.5 1.5H12.5'), path('M10.5 8l2 2-2 2')],
});

/** One drawing per building block (by its "block", the same for every value of a parameter; blocks.test.mjs checks). */
export const BLOCK_ICONS = Object.freeze({
    // shield with a check mark
    datenschutz: [path('M8 1.5l5.5 2v4c0 3.4-2.4 5.7-5.5 7-3.1-1.3-5.5-3.6-5.5-7v-4z'), path('M5.5 8l1.8 1.8 3.2-3.3')],
    // envelope
    name_email: [rect(1.5, 3.5, 13, 9), path('M1.5 4.5L8 9l6.5-4.5')],
    // a person
    person: [circle(8, 5, 2.75), path('M2.5 14.5c0-3.2 2.4-5.25 5.5-5.25s5.5 2.05 5.5 5.25')],
    // globe
    herkunft: [circle(8, 8, 6.5), path('M1.5 8h13'), path('M8 1.5c-1.8 1.9-2.7 4.1-2.7 6.5s.9 4.6 2.7 6.5c1.8-1.9 2.7-4.1 2.7-6.5S9.8 3.4 8 1.5')],
    // house
    anschrift: [path('M1.5 7.5L8 2l6.5 5.5'), path('M3.5 6v8.5h9V6'), path('M6.5 14.5v-4h3v4')],
    // grown-up and child
    erziehungsberechtigte: [circle(5.5, 4, 2), path('M1.5 14.5c0-3 1.7-5 4-5s4 2 4 5'), circle(11.75, 7.25, 1.5), path('M9.5 14.5c.2-2.2 1-3.5 2.25-3.5S13.8 12.3 14 14.5')],
    // mortarboard
    vorbildung: [path('M.75 6.25L8 2.75l7.25 3.5L8 9.75z'), path('M3.75 7.75v3.5c1.1 1 2.6 1.6 4.25 1.6s3.15-.6 4.25-1.6v-3.5'), path('M15.25 6.25v4')],
    // briefcase
    ausbildungsbetrieb: [rect(1.5, 5, 13, 9), path('M5.5 5V3.25a.75.75 0 0 1 .75-.75h3.5a.75.75 0 0 1 .75.75V5'), path('M1.5 9h13')],
    // pen over a line
    unterschrift: [path('M10.5 1.75l2.75 2.75-6.75 6.75H3.75V8.5z'), path('M2 14.5h12')],
    // speech bubble
    anmerkungen: [path('M2.5 2.5h11a1 1 0 0 1 1 1v6.5a1 1 0 0 1-1 1H7l-3 2.5v-2.5H2.5a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1z'), path('M4.5 5.5h7M4.5 8h5')],
});

const BUBBLE_CHECK = [path('M3.5 2.5h11a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H8l-3 2.5v-2.5H3.5a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1z'), path('M6 7l2 2 4-4')];
const SMALL_ENVELOPE = [rect(11.5, 13.5, 10, 7.5, 1), path('M11.5 14.5l5 3.5 5-3.5')];

/** One drawing per template and example of resources/survey-templates/manifest.json (blocks.test.mjs checks). */
export const TEMPLATE_ICONS = Object.freeze({
    // a page with two fields
    leer: [rect(4, 2.5, 16, 19, 2), rect(7, 6.5, 10, 3.5, 1), rect(7, 13, 10, 3.5, 1)],
    // a reply with a check mark, and an e-mail
    rueckmeldung: [...BUBBLE_CHECK, ...SMALL_ENVELOPE],
    // the same, the e-mail crossed out
    rueckmeldung_ohne_email: [...BUBBLE_CHECK, ...SMALL_ENVELOPE, path('M10 22.5l13-10.5')],
    // calendar
    veranstaltung: [rect(3, 4.5, 18, 16.5, 2), path('M3 9.5h18'), path('M8 2.5v4M16 2.5v4'), rect(6.5, 12.5, 3, 3, .5, FILL), path('M13.5 14h4M6.5 18h3M13.5 18h4')],
    // a student ID: photo and lines
    schueleranmeldung: [rect(2.5, 5, 19, 14, 2), circle(8, 10.25, 2), path('M4.75 16.25c.4-1.8 1.7-2.75 3.25-2.75s2.85.95 3.25 2.75'), path('M14 10h5M14 13.5h5')],
    // briefcase
    bs: [rect(2.5, 7.5, 19, 13, 2), path('M8.5 7.5V5a1.5 1.5 0 0 1 1.5-1.5h4A1.5 1.5 0 0 1 15.5 5v2.5'), path('M2.5 13h19'), rect(10.5, 11.5, 3, 3, .5, FILL)],
    // school bag
    gs: [rect(5, 7, 14, 14.5, 3), path('M9.5 7V5a2.5 2.5 0 0 1 5 0v2'), path('M5 12.5h14'), rect(10.5, 14.25, 3, 2.5, .5)],
    // two languages: speech bubbles, one with an "A"
    vabo: [path('M3 3.5h10a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H8l-3 2.5v-2.5H3a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1z'), path('M5.5 10l2.5-5 2.5 5M6.5 8.25h3'),
        path('M11 13h10a1 1 0 0 1 1 1v5.5a1 1 0 0 1-1 1h-1.5V23l-3-2.5H11a1 1 0 0 1-1-1V14a1 1 0 0 1 1-1z'), path('M13 16h6M13 18h4')],
    // mortarboard and a plus
    zq: [path('M1.5 8.5L11 4l9.5 4.5L11 13z'), path('M5.5 10.5v4.25c1.5 1.3 3.5 2 5.5 2s4-.7 5.5-2V10.5'), path('M20 15.5v6M17 18.5h6')],
    // medal
    meister: [circle(12, 15, 5.5), circle(12, 15, 2.5), path('M8.4 10.9L5.5 2.5h4l2.5 5 2.5-5h4l-2.9 8.4')],
});

function draw(shapes, box) {
    const svg = document.createElementNS(NS, 'svg');
    for (const [k, v] of Object.entries({
        class: 'sb-icon', viewBox: `0 0 ${box} ${box}`, width: box, height: box, 'aria-hidden': 'true', focusable: 'false',
        fill: 'none', stroke: 'currentColor', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round',
    })) { svg.setAttribute(k, String(v)); }
    for (const [tag, attrs] of shapes) {
        const el = document.createElementNS(NS, tag);
        for (const [k, v] of Object.entries(attrs)) { el.setAttribute(k, String(v)); }
        svg.append(el);
    }
    return svg;
}

const lookup = (table, key) => (Object.hasOwn(table, key) ? table[key] : null);

/** The icon of an element type as an <svg>, or null when there is none. */
export function typeIcon(typeName) {
    const shapes = lookup(ICONS, typeName);
    return shapes ? draw(shapes, 16) : null;
}

/** The icon of a tree row that is no element ("survey", "page", "choice"), or null when there is none. */
export function treeIcon(kind) {
    const shapes = lookup(TREE_ICONS, kind);
    return shapes ? draw(shapes, 16) : null;
}

/** The icon of a building block (its "block", e.g. "erziehungsberechtigte"), or null when there is none. */
export function blockIcon(block) {
    const shapes = lookup(BLOCK_ICONS, block);
    return shapes ? draw(shapes, 16) : null;
}

/** The icon of a template or example (its key), or null when there is none. */
export function templateIcon(key) {
    const shapes = lookup(TEMPLATE_ICONS, key);
    if (!shapes) { return null; }
    const svg = draw(shapes, 24);
    svg.classList.add('sb-icon-lg');
    return svg;
}

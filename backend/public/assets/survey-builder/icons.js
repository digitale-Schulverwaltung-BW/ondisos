// Small monochrome icons of the element types (palette "Element hinzufügen"). Drawn here as plain SVG shapes in a 16×16
// box, stroked in the current text color; no icon font, no library, nothing loaded. Decorative: the label next to the
// icon names the type, so the SVG is aria-hidden.

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

/** The icon of an element type as an <svg>, or null when there is none. */
export function typeIcon(typeName) {
    const shapes = Object.hasOwn(ICONS, typeName) ? ICONS[typeName] : null;
    if (!shapes) { return null; }
    const svg = document.createElementNS(NS, 'svg');
    for (const [k, v] of Object.entries({
        class: 'sb-icon', viewBox: '0 0 16 16', width: 16, height: 16, 'aria-hidden': 'true', focusable: 'false',
        fill: 'none', stroke: 'currentColor', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round',
    })) { svg.setAttribute(k, String(v)); }
    for (const [tag, attrs] of shapes) {
        const el = document.createElementNS(NS, tag);
        for (const [k, v] of Object.entries(attrs)) { el.setAttribute(k, String(v)); }
        svg.append(el);
    }
    return svg;
}

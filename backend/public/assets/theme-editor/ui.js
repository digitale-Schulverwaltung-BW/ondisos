// Theme editor (3.2.0b AP4): the fields above the JSON (markup in theme_edit.php, #tf-root), kept in step with the text.
//
// The text area #survey-text is the source (the form posts it; the code editor keeps it current without an event). The
// fields read it a few times per second and whenever they wrote it; a field change goes into the text as a small edit
// (text-bridge.js), so Ctrl+Z in the code editor takes it back. A field that has the focus is not overwritten while the
// person types in it. The logic is in fields.js (tested with node --test).

import { readFields, applyField, parseColor, contrastWithWhite, FONT_OPEN_SANS, FONT_SYSTEM } from './fields.js';
import { createTextBridge } from '../survey-builder/text-bridge.js';

const POLL = 300;
const AA = 4.5;

/**
 * @param {object} o
 * @param {HTMLElement} o.root     #tf-root (hidden until this runs)
 * @param {HTMLTextAreaElement} o.area
 * @param {() => object|undefined} o.getEditor the code editor (window.OndisosSurveyEditor.last)
 * @param {Object<string,string>} o.texts
 */
export function initThemeFields({ root, area, getEditor, texts }) {
    const bridge = createTextBridge(area, getEditor);
    const $ = (id) => root.querySelector(`#${id}`);
    const fill = (template, vars) => String(template ?? '').replace(/\{\{(\w+)\}\}/g, (m, k) => (k in vars ? String(vars[k]) : m));
    let lastText = null;
    let current = null; // last readFields() result

    const controls = {
        brand: [$('tf-brand-picker'), $('tf-brand')],
        titleBrand: [$('tf-titleBrand')],
        font: [$('tf-font-choice'), $('tf-font-custom')],
        fontSize: [$('tf-fontSize')],
        background: [$('tf-background-picker'), $('tf-background')],
        backgroundImage: [$('tf-backgroundImage')],
        backgroundOpacity: [$('tf-backgroundOpacity')],
        backgroundImageFit: [$('tf-backgroundImageFit')],
        radius: [$('tf-radius')],
        cards: [$('tf-cards')],
    };
    const focused = (name) => controls[name].includes(document.activeElement);

    function note(name, field) {
        const el = root.querySelector(`[data-field="${name}"] .tf-note`);
        if (!el) { return; }
        if (field.state === 'readonly') {
            el.textContent = fill(texts.readonly, { value: field.raw });
            el.className = 'tf-note form-text text-warning-emphasis';
        } else if (field.legacy) {
            el.textContent = texts.legacy;
            el.className = 'tf-note form-text';
        } else {
            el.textContent = '';
            el.className = 'tf-note form-text';
        }
    }

    function showColor(name, field) {
        const [picker, input] = controls[name];
        const color = field.value !== null ? parseColor(field.value) : null;
        if (!focused(name)) {
            input.value = field.state === 'ok' ? (field.value ?? '') : field.raw;
            input.classList.remove('is-invalid');
        }
        picker.value = color ? color.hex : (picker.dataset.default || '#000000');
        picker.classList.toggle('tf-unset', !color);
    }

    function contrast() {
        const el = $('tf-brand-contrast');
        const field = current?.fields?.brand;
        const ratio = field && field.state === 'ok' && field.value !== null ? contrastWithWhite(field.value) : null;
        if (ratio === null || ratio >= AA) { el.hidden = true; return; }
        el.textContent = fill(texts.contrast, { ratio: ratio.toFixed(1).replace('.', ',') });
        el.hidden = false;
    }

    function render() {
        lastText = area.value;
        current = readFields(lastText);
        const unreadable = $('tf-unreadable');
        if (!current.ok) {
            unreadable.hidden = false;
            Object.values(controls).flat().forEach((c) => { c.disabled = true; });
            return;
        }
        unreadable.hidden = true;
        const f = current.fields;
        for (const [name, list] of Object.entries(controls)) {
            const editable = f[name].state === 'ok';
            list.forEach((c) => { c.disabled = !editable; });
            note(name, f[name]);
        }

        showColor('brand', f.brand);
        showColor('background', f.background);
        $('tf-titleBrand').checked = f.titleBrand.value === true;
        $('tf-cards').checked = f.cards.value === true;

        if (!focused('font')) {
            const v = f.font.value;
            const choice = v === null ? '' : (v === FONT_OPEN_SANS ? 'opensans' : (v === FONT_SYSTEM ? 'system' : 'custom'));
            $('tf-font-choice').value = f.font.state === 'ok' ? choice : 'custom';
            $('tf-font-custom').value = choice === 'custom' ? (v ?? '') : (f.font.state === 'ok' ? '' : f.font.raw);
            $('tf-font-custom').hidden = $('tf-font-choice').value !== 'custom';
        }
        if (!focused('fontSize')) { $('tf-fontSize').value = f.fontSize.value === null ? '' : String(f.fontSize.value); }
        if (!focused('backgroundImage')) { $('tf-backgroundImage').value = f.backgroundImage.state === 'ok' ? f.backgroundImage.value : f.backgroundImage.raw; }
        if (!focused('backgroundOpacity')) {
            $('tf-backgroundOpacity').value = f.backgroundOpacity.value === null ? '' : String(Math.round(f.backgroundOpacity.value * 100));
        }
        if (!focused('backgroundImageFit')) { $('tf-backgroundImageFit').value = f.backgroundImageFit.value ?? ''; }
        if (!focused('radius')) { $('tf-radius').value = f.radius.value === null ? '' : String(f.radius.value); }
        contrast();
    }

    /** Writes a field; a value the field does not accept marks the input instead. */
    function write(name, value, input = null) {
        if (area.value !== lastText) { render(); } // the text changed meanwhile: decide on the current one
        if (!current.ok || current.fields[name].state !== 'ok') { return; }
        try {
            bridge.set(applyField(area.value, name, value));
            if (input) { input.classList.remove('is-invalid'); }
        } catch (e) {
            if (input) { input.classList.add('is-invalid'); }
            return;
        }
        render();
    }

    // colours: the picker writes while it moves, the text field when it is left (or Enter)
    for (const name of ['brand', 'background']) {
        const [picker, input] = controls[name];
        picker.addEventListener('input', () => { input.value = picker.value; write(name, picker.value); });
        input.addEventListener('change', () => write(name, input.value.trim() === '' ? null : input.value.trim(), input));
    }
    $('tf-titleBrand').addEventListener('change', (e) => write('titleBrand', e.target.checked));
    $('tf-cards').addEventListener('change', (e) => write('cards', e.target.checked));

    $('tf-font-choice').addEventListener('change', (e) => {
        const custom = $('tf-font-custom');
        custom.hidden = e.target.value !== 'custom';
        if (e.target.value === 'custom') { custom.focus(); return; } // written once a name is typed
        write('font', { '': null, opensans: FONT_OPEN_SANS, system: FONT_SYSTEM }[e.target.value] ?? null);
    });
    $('tf-font-custom').addEventListener('change', (e) => {
        if (e.target.value.trim() !== '') { write('font', e.target.value.trim(), e.target); }
    });
    $('tf-fontSize').addEventListener('change', (e) => write('fontSize', e.target.value === '' ? null : Number(e.target.value)));
    $('tf-backgroundImage').addEventListener('change', (e) => write('backgroundImage', e.target.value.trim(), e.target));
    $('tf-backgroundOpacity').addEventListener('change', (e) => {
        const v = e.target.value.trim();
        write('backgroundOpacity', v === '' ? null : Number(v) / 100, e.target);
    });
    $('tf-backgroundImageFit').addEventListener('change', (e) => write('backgroundImageFit', e.target.value === '' ? null : e.target.value));
    $('tf-radius').addEventListener('change', (e) => {
        const v = e.target.value.trim();
        write('radius', v === '' ? null : Number(v), e.target);
    });

    // the text changes from the JSON side (typing, undo, "Auf Vorgabe zurücksetzen" reloads anyway)
    setInterval(() => { if (area.value !== lastText) { render(); } }, POLL);
    root.addEventListener('focusout', () => setTimeout(() => { if (!root.contains(document.activeElement)) { render(); } }, 0));

    render();
    root.hidden = false;
    return { render, get state() { return current; } };
}

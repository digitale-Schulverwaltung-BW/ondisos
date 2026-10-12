// The properties panel: generated from the registry. One control per property, by kind; a new property is a registry entry.
//
// A control commits on "change" (leaving the field, or Enter), never on every keystroke: one edit is one change of the
// text. The page is a form, so Enter must never submit it: handled in ui.js for the whole builder.

import { h } from './dom.js';
import { PAGE, typeDef } from './registry.js';
import { displayText, otherProperties } from './model.js';

let uid = 0;
const nextId = (name) => `sb-f-${name}-${(uid += 1)}`;

/**
 * @typedef {object} PropsContext
 * @property {Function} t                      message lookup
 * @property {object} rules                    SurveyBuilderRules
 * @property {string} typeName                 'page' or an element type
 * @property {object} value                    the element as plain JSON (read-only)
 * @property {{path:Array, message:string, severity:string}[]} findings findings of the last check for this element
 * @property {boolean} stale                   the text changed since that check
 * @property {(name:string, value:*) => {ok:boolean, code?:string}} commit  writes a property
 * @property {(name:string) => {ok:boolean, code?:string, cancelled?:boolean}} rename  renames (asks for confirmation)
 * @property {(el:HTMLElement) => void} [onFindingShow]
 */

function errorBox(id) {
    return h('div', { class: 'invalid-feedback d-block sb-error', id, role: 'alert', hidden: true });
}

function setError(box, input, message) {
    if (message) {
        box.textContent = message;
        box.hidden = false;
        input?.classList.add('is-invalid');
        input?.setAttribute('aria-invalid', 'true');
    } else {
        box.textContent = '';
        box.hidden = true;
        input?.classList.remove('is-invalid');
        input?.removeAttribute('aria-invalid');
    }
}

function errorText(ctx, result, def) {
    if (result.cancelled) { return ''; }
    const vars = { max: result.code === 'tooManyElements' ? ctx.rules.maxElements : (def?.max ?? ctx.rules.maxDepth), min: def?.min ?? 0 };
    const key = `err.${result.code}`;
    const text = ctx.t(key, vars);
    return text.startsWith('[') ? ctx.t('err.generic') : text;
}

/** Wraps a control with its label, help text and error area. */
function field(ctx, def, id, control, extra = {}) {
    const label = ctx.t(`prop.${def.name}`);
    const helpKey = `help.${def.name}`;
    const help = ctx.t(helpKey);
    const helpId = `${id}-help`;
    const hasHelp = !help.startsWith('[');
    return h('div', { class: 'mb-3', 'data-prop': def.name },
        h('label', { class: 'form-label', for: id }, label),
        control,
        hasHelp ? h('div', { class: 'form-text', id: helpId }, help) : null,
        extra.notes ?? null);
}

/**
 * A text control commits on "change" (leaving the field), and only when its value differs from the last committed one,
 * so a second "change" for the same value (Enter, then leaving the field) does nothing. When the change is refused the
 * field shows the reason and goes back to the stored value (`stored()` reads the current document, not the one the panel
 * was built from).
 */
function commitOnChange(input, getValue, commit, box, ctx, def, stored) {
    let last = getValue();
    input.addEventListener('change', () => {
        const next = getValue();
        if (next === last) { return; }
        const result = commit(next);
        if (result.ok) {
            last = next;
            setError(box, input, '');
            return;
        }
        setError(box, input, errorText(ctx, result, def));
        if (stored) {
            input.value = stored();
            last = getValue();
        }
    });
}

// ---- controls by kind ---------------------------------------------------------------------------------------------

function nameControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const input = h('input', { type: 'text', class: 'form-control', id, value: ctx.value.name ?? '', autocomplete: 'off', spellcheck: 'false', 'aria-describedby': `${id}-help ${id}-err` });
    commitOnChange(input, () => input.value, (v) => ctx.rename(v), box, ctx, def, () => ctx.value.name ?? '');
    return field(ctx, def, id, input, { notes: box });
}

function localizedControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const raw = ctx.value[def.name];
    const current = displayText(raw);
    const multi = def.name === 'description' || def.name === 'filePlaceholder';
    const input = multi
        ? h('textarea', { class: 'form-control', id, rows: 2, value: current })
        : h('input', { type: 'text', class: 'form-control', id, value: current });
    if (def.name === 'title') { input.setAttribute('placeholder', ctx.value.name ?? ''); }
    commitOnChange(input, () => input.value, (v) => ctx.commit(def.name, v), box, ctx, def, () => displayText(ctx.value[def.name]));
    // other languages are not edited: "default" is, or "de" when there is no "default" (same rule as the model)
    const edited = raw && typeof raw === 'object' ? ('default' in raw || !('de' in raw) ? 'default' : 'de') : null;
    const others = edited ? Object.keys(raw).filter((k) => k !== edited) : [];
    const note = others.length ? h('div', { class: 'form-text' }, ctx.t('other_langs', { list: others.join(', ') })) : null;
    return field(ctx, def, id, input, { notes: [box, note] });
}

const ALLOWED_TAG = /<\/?\s*([a-zA-Z][^\s/>]*)/g;

function htmlControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const input = h('textarea', { class: 'form-control font-monospace', id, rows: 6, value: displayText(ctx.value[def.name]), spellcheck: 'false' });
    const allowed = Object.keys(ctx.rules.htmlTags ?? {});
    const hint = h('div', { class: 'form-text' }, ctx.t('help.html', { tags: allowed.map((x) => `<${x}>`).join(' ') }));
    const warn = h('div', { class: 'form-text text-danger', role: 'status', hidden: true });
    const check = () => {
        const bad = [...new Set([...input.value.matchAll(ALLOWED_TAG)].map((m) => m[1].toLowerCase()).filter((n) => !allowed.includes(n)))];
        warn.hidden = bad.length === 0;
        warn.textContent = bad.length ? ctx.t('help.html_bad', { tags: bad.map((x) => `<${x}>`).join(' ') }) : '';
    };
    input.addEventListener('input', check);
    check();
    commitOnChange(input, () => input.value, (v) => ctx.commit(def.name, v), box, ctx, def, () => displayText(ctx.value[def.name]));
    const label = ctx.t(`prop.${def.name}`);
    return h('div', { class: 'mb-3', 'data-prop': def.name }, h('label', { class: 'form-label', for: id }, label), input, hint, warn, box);
}

function booleanControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const stored = ctx.value[def.name];
    const input = h('input', { type: 'checkbox', class: 'form-check-input', id, checked: typeof stored === 'boolean' ? stored : Boolean(def.default) });
    input.addEventListener('change', () => {
        const result = ctx.commit(def.name, input.checked);
        if (!result.ok) {
            input.checked = !input.checked;
            setError(box, input, errorText(ctx, result, def));
        } else {
            setError(box, input, '');
        }
    });
    const wrapper = h('div', { class: 'mb-2 form-check form-switch', 'data-prop': def.name },
        input, h('label', { class: 'form-check-label', for: id }, ctx.t(`prop.${def.name}`)), box);
    const help = ctx.t(`help.${def.name}`);
    if (!help.startsWith('[')) { wrapper.append(h('div', { class: 'form-text' }, help)); }
    return wrapper;
}

function numberControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const stored = ctx.value[def.name];
    const input = h('input', { type: 'number', class: 'form-control', id, step: 1, min: def.min, max: def.max, value: stored === undefined ? '' : String(stored), placeholder: def.default === undefined ? '' : String(def.default) });
    commitOnChange(input, () => input.value, (raw) => {
        if (raw.trim() === '') { return ctx.commit(def.name, null); }
        const n = Number(raw);
        if (!Number.isInteger(n) || (def.min !== undefined && n < def.min) || (def.max !== undefined && n > def.max)) {
            return { ok: false, code: def.min !== undefined && def.max !== undefined ? 'number_range' : 'number' };
        }
        return ctx.commit(def.name, n);
    }, box, ctx, def, () => (ctx.value[def.name] === undefined ? '' : String(ctx.value[def.name])));
    return field(ctx, def, id, input, { notes: box });
}

function enumControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const stored = ctx.value[def.name];
    const effective = stored === undefined ? def.default : stored;
    const label = (v) => {
        const text = ctx.t(`enum.${def.name}.${v}`);
        return text.startsWith('[') ? String(v) : text;
    };
    const options = def.values.map((v) => h('option', { value: String(v), selected: effective === v }, label(v)));
    if (effective !== undefined && !def.values.includes(effective)) {
        options.unshift(h('option', { value: '__other', selected: true, disabled: true }, ctx.t('other_value', { value: String(effective) })));
    }
    const input = h('select', { class: 'form-select', id }, options);
    input.addEventListener('change', () => {
        const raw = input.value;
        const value = typeof def.values[0] === 'number' ? Number(raw) : raw;
        const result = ctx.commit(def.name, value);
        setError(box, input, result.ok ? '' : errorText(ctx, result, def));
    });
    return field(ctx, def, id, input, { notes: box });
}

function expressionControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const input = h('input', { type: 'text', class: 'form-control font-monospace', id, value: typeof ctx.value[def.name] === 'string' ? ctx.value[def.name] : '', spellcheck: 'false', autocomplete: 'off' });
    commitOnChange(input, () => input.value, (v) => ctx.commit(def.name, v), box, ctx, def, () => (typeof ctx.value[def.name] === 'string' ? ctx.value[def.name] : ''));
    return field(ctx, def, id, input, { notes: box });
}

function valueControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const stored = ctx.value[def.name];
    if (def.valueKind === 'boolean') {
        const input = h('select', { class: 'form-select', id },
            h('option', { value: '', selected: typeof stored !== 'boolean' }, ctx.t('value.unset')),
            h('option', { value: 'true', selected: stored === true }, ctx.t('value.yes')),
            h('option', { value: 'false', selected: stored === false }, ctx.t('value.no')));
        input.addEventListener('change', () => {
            const result = ctx.commit(def.name, input.value === '' ? null : input.value === 'true');
            setError(box, input, result.ok ? '' : errorText(ctx, result, def));
        });
        return field(ctx, def, id, input, { notes: box });
    }
    const input = h('input', { type: 'text', class: 'form-control', id, value: typeof stored === 'string' ? stored : '' });
    commitOnChange(input, () => input.value, (v) => ctx.commit(def.name, v === '' ? null : v), box, ctx, def, () => (typeof ctx.value[def.name] === 'string' ? ctx.value[def.name] : ''));
    return field(ctx, def, id, input, { notes: box });
}

// ---- the list of answers ----------------------------------------------------------------------------------------------

/** One line per answer: "text", or "value | text". */
export function choiceToLine(item) {
    if (typeof item === 'string') { return item; }
    if (typeof item === 'number') { return String(item); }
    const { value, text } = item;
    return typeof text === 'string' && text !== String(value) ? `${value} | ${text}` : String(value);
}

export function lineToChoice(line) {
    const m = /^(.*?)\s*\|\s*(.*)$/.exec(line);
    if (!m) { return line.trim(); }
    const value = m[1].trim();
    const text = m[2].trim();
    return text === '' ? value : { value, text };
}

/** Answers with settings beyond value and text (conditions, …) cannot be written as lines. */
export function hasAdvancedChoices(items) {
    return items.some((item) => item && typeof item === 'object' && Object.keys(item).some((k) => k !== 'value' && k !== 'text'));
}

/**
 * The lines → answers. A line that is unchanged gives back the answer it came from (number values, kinds of entries),
 * only edited and new lines are parsed.
 */
export function linesToChoices(text, original) {
    const pool = new Map();
    original.forEach((item) => {
        const line = choiceToLine(item);
        pool.set(line, [...(pool.get(line) ?? []), item]);
    });
    return text.split('\n').map((l) => l.replace(/\r$/, '')).filter((l) => l.trim() !== '').map((line) => {
        const queue = pool.get(line);
        if (queue && queue.length) { return queue.shift(); }
        return lineToChoice(line);
    });
}

function choicesControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const current = () => (Array.isArray(ctx.value.choices) ? ctx.value.choices : []);
    const items = current();
    const advanced = hasAdvancedChoices(items);
    const input = h('textarea', { class: 'form-control font-monospace', id, rows: Math.min(14, Math.max(4, items.length + 1)), value: items.map(choiceToLine).join('\n'), spellcheck: 'false', readOnly: advanced });
    const count = h('div', { class: 'form-text' }, ctx.t('help.choices_count', { count: items.length }));
    if (!advanced) {
        commitOnChange(input, () => input.value, (v) => {
            const next = linesToChoices(v, current());
            const result = ctx.commit('choices', next);
            if (result.ok) { count.textContent = ctx.t('help.choices_count', { count: next.length }); }
            return result;
        }, box, ctx, def, () => current().map(choiceToLine).join('\n'));
    }
    const label = ctx.t('prop.choices');
    return h('div', { class: 'mb-3', 'data-prop': 'choices' },
        h('label', { class: 'form-label', for: id }, label), input, count,
        h('div', { class: 'form-text' }, ctx.t(advanced ? 'help.choices_advanced' : 'help.choices')), box);
}

const CONTROLS = {
    string: nameControl,
    localized: localizedControl,
    html: htmlControl,
    boolean: booleanControl,
    number: numberControl,
    enum: enumControl,
    choices: choicesControl,
    expression: expressionControl,
    value: valueControl,
};

// ---- the panel ----------------------------------------------------------------------------------------------------

/** True when the element states anything for one of these properties (decides whether a group is open). */
function hasAny(value, props) {
    return props.some((p) => value[p.name] !== undefined);
}

/**
 * @param {PropsContext} ctx
 * @returns {HTMLElement}
 */
export function buildProperties(ctx) {
    const def = ctx.typeName === 'page' ? PAGE : typeDef(ctx.typeName);
    const root = h('div', { class: 'sb-props-body' });
    const typeLabel = ctx.t(`type.${ctx.typeName}`);
    root.append(h('h2', { class: 'h5 mb-1' }, def ? typeLabel : String(ctx.typeName ?? '?')));

    if (ctx.findings.length) {
        root.append(h('div', { class: `alert alert-${ctx.findings.some((f) => f.severity === 'error') ? 'danger' : 'warning'} py-2 small sb-findings`, role: 'status' },
            h('strong', {}, ctx.t('findings.here')), h('div', { class: 'text-muted', 'data-sb-stale': true, hidden: !ctx.stale }, ctx.t('findings.stale')),
            h('ul', { class: 'mb-0 ps-3' }, ctx.findings.map((f) => h('li', {}, f.message)))));
    }

    if (!def) {
        root.append(h('p', { class: 'text-muted' }, ctx.t('unknown_type', { type: String(ctx.typeName ?? '?') })));
    } else {
        const groups = ['general', 'layout', 'logic'];
        for (const group of groups) {
            const props = def.props.filter((p) => p.group === group);
            if (props.length === 0) { continue; }
            const controls = props.map((p) => CONTROLS[p.kind]?.(ctx, p)).filter(Boolean);
            if (group === 'general') {
                root.append(h('section', { class: 'mt-3', 'aria-label': ctx.t('group.general') }, controls));
            } else {
                root.append(h('details', { class: 'mt-2 sb-group', open: hasAny(ctx.value, props) },
                    h('summary', { class: 'fw-semibold mb-2' }, ctx.t(`group.${group}`)), controls));
            }
        }
    }

    const others = def ? otherProperties(ctx.value, ctx.typeName) : Object.keys(ctx.value).filter((k) => k !== 'elements');
    if (others.length) {
        root.append(h('p', { class: 'small text-muted mt-3 mb-0' }, ctx.t('unknown_props', { list: others.join(', ') })));
    }
    return root;
}

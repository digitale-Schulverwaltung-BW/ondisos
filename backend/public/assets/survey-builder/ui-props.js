// The properties panel: generated from the registry. One control per property, by kind; a new property is a registry entry.
//
// A control commits on "change" (leaving the field, or Enter), never on every keystroke: one edit is one change of the
// text. The page is a form, so Enter must never submit it: handled in ui.js for the whole builder.

import { h } from './dom.js';
import { PAGE, SURVEY, typeDef } from './registry.js';
import { displayText, otherProperties, supportsChoiceElements } from './model.js';
import { CONDITION_PROPS, checkExpression, describeSimple, parseSimple, suggest } from './conditions.js';

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
 * @property {(message:string) => boolean} confirm  asks the user (window.confirm)
 * @property {() => Map<string, object>} [fields]  what conditions can refer to (conditions.js fieldsOf)
 * @property {{path:Array, label:string, prop:string}[]} [usedIn]  elements whose expressions or texts refer to this one
 * @property {(path:Array) => void} [select]  selects another element (tree and panel)
 * @property {(index:number) => {ok:boolean, code?:string}} [openChoice]  lets answer `index` hold questions (selects it)
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

// ---- explanations behind an (i) ----------------------------------------------------------------------------------------
//
// A static explanation sits behind a small (i) button next to its control (a "toggletip"): hovering shows it, a click
// keeps it open, Esc or a click elsewhere closes it. The text stays in the DOM (hidden), so the control still names it via
// aria-describedby. What reports state (errors, counts, a disallowed tag, other languages) stays visible below the control.

const openTips = new Set();
let outsideListener = false;

function closeTip(tip) {
    tip.el.hidden = true;
    tip.pinned = false;
    tip.button.setAttribute('aria-expanded', 'false');
    openTips.delete(tip);
}

function listenOutside() {
    if (outsideListener) { return; }
    outsideListener = true;
    document.addEventListener('pointerdown', (e) => {
        for (const tip of [...openTips]) {
            if (!tip.button.contains(e.target) && !tip.el.contains(e.target)) { closeTip(tip); }
        }
    });
}

/** The (i) button and its hidden text; `id` is the text's id (for aria-describedby). */
export function infoTip(ctx, id, text) {
    const el = h('div', { class: 'sb-tip', id, role: 'note', hidden: true }, text);
    const button = h('button', { type: 'button', class: 'sb-info', 'aria-label': ctx.t('info'), 'aria-expanded': 'false', 'aria-controls': id }, 'i');
    const tip = { el, button, pinned: false };
    let timer = 0;
    const show = () => {
        clearTimeout(timer);
        el.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        openTips.add(tip);
        listenOutside();
    };
    const hideSoon = () => {
        clearTimeout(timer);
        if (!tip.pinned) { timer = setTimeout(() => { if (!tip.pinned) { closeTip(tip); } }, 150); }
    };
    button.addEventListener('mouseenter', show);
    button.addEventListener('mouseleave', hideSoon);
    el.addEventListener('mouseenter', () => clearTimeout(timer));
    el.addEventListener('mouseleave', hideSoon);
    button.addEventListener('click', () => {
        if (tip.pinned) { closeTip(tip); return; }
        show();
        tip.pinned = true;
    });
    button.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !el.hidden) {
            e.preventDefault();
            e.stopPropagation();
            closeTip(tip);
        }
    });
    return { button, el };
}

/**
 * The explanation of a property, or null when there is none: help_<type>.<name> where a property means something else for
 * that type (the survey's title), else help.<name>.
 */
function helpOf(ctx, def) {
    for (const key of [`help_${ctx.typeName}.${def.name}`, `help.${def.name}`]) {
        const help = ctx.t(key);
        if (!help.startsWith('[')) { return help; }
    }
    return null;
}

function describe(control, ...ids) {
    const list = [...(control.getAttribute('aria-describedby') ?? '').split(' '), ...ids].filter(Boolean);
    if (list.length) { control.setAttribute('aria-describedby', [...new Set(list)].join(' ')); }
}

/**
 * One row of the panel: label | control | (i), notes (errors, state) below the control.
 * `help` replaces the property's own explanation (null: none); `label: null` leaves the label column empty.
 */
function field(ctx, def, id, control, extra = {}) {
    const label = extra.label === undefined ? ctx.t(`prop.${def.name}`) : extra.label;
    const help = extra.help === undefined ? helpOf(ctx, def) : extra.help;
    const tip = help ? infoTip(ctx, `${id}-help`, help) : null;
    const notes = [extra.notes ?? []].flat().filter(Boolean);
    notes.forEach((n) => n.classList.add('sb-note'));
    const target = control.matches?.('input, select, textarea') ? control : control.querySelector?.('input, select, textarea');
    if (target) { describe(target, tip ? `${id}-help` : null, ...notes.map((n) => n.id).filter(Boolean)); }
    return h('div', { class: 'sb-field', 'data-prop': extra.prop ?? def.name },
        label === null ? h('span', { class: 'sb-field-label' }) : h('label', { class: 'sb-field-label', for: id }, label),
        h('div', { class: 'sb-field-control' }, control),
        tip ? tip.button : h('span', { class: 'sb-info-slot' }),
        notes,
        tip ? tip.el : null);
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
    const input = h('input', { type: 'text', class: 'form-control', id, value: ctx.value.name ?? '', autocomplete: 'off', spellcheck: 'false', 'aria-describedby': `${id}-err` });
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
    return field(ctx, def, id, input, { notes: [box, otherLangsNote(ctx, raw)] });
}

/** Other languages are not edited: "default" is, or "de" when there is no "default" (same rule as the model). */
function otherLangsNote(ctx, raw) {
    const edited = raw && typeof raw === 'object' ? ('default' in raw || !('de' in raw) ? 'default' : 'de') : null;
    const others = edited ? Object.keys(raw).filter((k) => k !== edited) : [];
    return others.length ? h('div', { class: 'form-text' }, ctx.t('other_langs', { list: others.join(', ') })) : null;
}

const ALLOWED_TAG = /<\/?\s*([a-zA-Z][^\s/>]*)/g;

function htmlControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const input = h('textarea', { class: 'form-control font-monospace', id, rows: 6, value: displayText(ctx.value[def.name]), spellcheck: 'false' });
    const allowed = Object.keys(ctx.rules.htmlTags ?? {});
    const hint = [def.name === 'html' ? null : helpOf(ctx, def), ctx.t('help.html', { tags: allowed.map((x) => `<${x}>`).join(' ') })].filter(Boolean).join(' ');
    const warn = h('div', { class: 'form-text text-danger', role: 'status', hidden: true });
    const check = () => {
        const bad = [...new Set([...input.value.matchAll(ALLOWED_TAG)].map((m) => m[1].toLowerCase()).filter((n) => !allowed.includes(n)))];
        warn.hidden = bad.length === 0;
        warn.textContent = bad.length ? ctx.t('help.html_bad', { tags: bad.map((x) => `<${x}>`).join(' ') }) : '';
    };
    input.addEventListener('input', check);
    check();
    commitOnChange(input, () => input.value, (v) => ctx.commit(def.name, v), box, ctx, def, () => displayText(ctx.value[def.name]));
    return field(ctx, def, id, input, { help: hint, notes: [warn, box, otherLangsNote(ctx, ctx.value[def.name])] });
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
    const sw = h('div', { class: 'form-check form-switch mb-0' }, input, h('label', { class: 'form-check-label', for: id }, ctx.t(`prop.${def.name}`)));
    return field(ctx, def, id, sw, { label: null, notes: box });
}

/** The "required" switch next to the heading (same behaviour as booleanControl, compact). */
function requiredSwitch(ctx, def) {
    const row = booleanControl(ctx, def);
    const sw = row.querySelector('.form-switch');
    const box = row.querySelector('.sb-error');
    const tip = row.querySelector('.sb-info');
    return h('div', { class: 'sb-required', 'data-prop': def.name }, sw, tip, box, row.querySelector('.sb-tip'));
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

// ---- expressions and conditions (AP5) -----------------------------------------------------------------------------
//
// The expression stays text: the control suggests what fits at the cursor (a combobox: "{" lists the fields, then
// operators, the answers of the field, and/or), reads a simple condition back in plain language and warns about field
// names and answers that do not exist. It never rewrites what the user typed.

/** Kinds of suggestions that open by themselves while typing; and/or only on request or once a letter is typed. */
const AUTO_OPEN = new Set(['field', 'operator', 'value']);

function expressionControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const stored = () => (typeof ctx.value[def.name] === 'string' ? ctx.value[def.name] : '');
    const listId = `${id}-list`;
    const input = h('input', {
        type: 'text', class: 'form-control font-monospace', id, value: stored(), spellcheck: 'false', autocomplete: 'off',
        role: 'combobox', 'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': listId,
    });
    const list = h('ul', { class: 'sb-suggest', id: listId, role: 'listbox', 'aria-label': ctx.t('cond.suggestions'), hidden: true });
    const isCondition = CONDITION_PROPS.includes(def.name);
    const reads = isCondition ? h('div', { class: 'form-text sb-cond-reads', id: `${id}-reads`, hidden: true }) : null;
    const warn = h('ul', { class: 'form-text sb-cond-warn', id: `${id}-warn`, hidden: true });
    let fields = null;
    const getFields = () => { fields ??= ctx.fields ? ctx.fields() : new Map(); return fields; };
    const self = typeof ctx.value.name === 'string' ? ctx.value.name : null;

    // ---- plain language and warnings ----
    const typing = () => document.activeElement === input;
    function update() {
        const text = input.value;
        if (reads) {
            const parsed = parseSimple(text);
            reads.hidden = !(parsed.ok || (parsed.reason === 'complex' && !typing()));
            reads.textContent = parsed.ok ? ctx.t('cond.reads', { text: describeSimple(parsed, getFields(), ctx.t) })
                : parsed.reason === 'complex' ? ctx.t('cond.complex') : '';
        }
        // while typing, an open brace or quote is not finished yet, not a mistake
        const found = checkExpression(text, getFields())
            .filter((w) => !(typing() && (w.code === 'unclosedBrace' || w.code === 'unclosedString' || w.code === 'parens')));
        warn.replaceChildren(...found.map((w) => h('li', {}, ctx.t(`cond.warn.${w.code}`, {
            ...w,
            list: (getFields().get(w.field)?.values ?? []).map((v) => (typeof v === 'string' ? `'${v}'` : String(v))).join(', '),
        }))));
        warn.hidden = found.length === 0;
    }

    // ---- suggestions (combobox) ----
    let current = null; // the result of suggest() on display
    let active = -1;
    function close() {
        current = null;
        active = -1;
        list.hidden = true;
        list.replaceChildren();
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }
    function setActive(i) {
        active = i;
        [...list.children].forEach((li, k) => li.setAttribute('aria-selected', k === i ? 'true' : 'false'));
        const li = list.children[i];
        if (li) {
            input.setAttribute('aria-activedescendant', li.id);
            li.scrollIntoView?.({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }
    function open(force) {
        const result = suggest(input.value, input.selectionStart ?? input.value.length, getFields(), { self });
        const wanted = force || AUTO_OPEN.has(result.kind) || (result.kind === 'join' && result.query !== '');
        if (!wanted || result.items.length === 0) { close(); return; }
        current = result;
        list.replaceChildren(...result.items.slice(0, 50).map((item, k) => h('li', {
            id: `${listId}-${k}`, role: 'option', class: 'sb-suggest-item', 'aria-selected': 'false',
            onmousedown: (e) => { e.preventDefault(); accept(k); },
        }, h('code', {}, item.label), item.detail ? h('span', { class: 'sb-suggest-detail' }, item.detail) : null)));
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        setActive(0);
    }
    function accept(k) {
        const item = current?.items[k];
        if (!item) { return; }
        const text = input.value;
        input.value = text.slice(0, current.from) + item.insert + text.slice(current.to);
        const caret = current.from + item.insert.length;
        input.setSelectionRange(caret, caret);
        update();
        open(false); // the next step: after a field the operators, after "=" the answers
    }

    input.addEventListener('input', () => { update(); open(false); });
    input.addEventListener('click', () => { if (current) { open(false); } });
    input.addEventListener('blur', () => { close(); update(); });
    input.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && (e.key === ' ' || e.code === 'Space')) {
            e.preventDefault();
            open(true);
            return;
        }
        if (!current) { return; }
        const count = list.children.length;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((active + (e.key === 'ArrowDown' ? 1 : count - 1)) % count);
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (active < 0) { return; }
            e.preventDefault();
            e.stopPropagation(); // Enter would otherwise commit the field (ui.js)
            accept(active);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation(); // Esc would otherwise go back to the tree (ui.js)
            close();
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Home' || e.key === 'End') {
            close();
        }
    });

    commitOnChange(input, () => input.value, (v) => ctx.commit(def.name, v), box, ctx, def, stored);
    update();
    const control = h('div', { class: 'sb-combo' }, input, list);
    return field(ctx, def, id, control, { notes: [reads, warn, box] });
}

/** "Used in": the elements whose conditions, expressions or texts refer to this one, each a link to it. */
function usedInSection(ctx) {
    const id = nextId('used-in');
    const tip = infoTip(ctx, `${id}-help`, ctx.t('cond.used_in_help'));
    return h('section', { class: 'mt-3 sb-used-in', 'aria-labelledby': id },
        h('div', { class: 'd-flex align-items-center gap-2 position-relative' }, h('h3', { class: 'h6 mb-0', id }, ctx.t('cond.used_in')), tip.button, tip.el),
        h('ul', { class: 'list-unstyled small mb-0 mt-1' }, ctx.usedIn.map((u) => h('li', {},
            h('button', { type: 'button', class: 'btn btn-link btn-sm p-0 align-baseline', onclick: () => ctx.select?.(u.path) }, u.label),
            h('span', { class: 'text-muted' }, ` · ${u.prop}`)))));
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

/** The settings of an answer beyond value and text (questions in it, conditions, …). */
const extrasOf = (item) => (item && typeof item === 'object' && !Array.isArray(item)
    ? Object.keys(item).filter((k) => k !== 'value' && k !== 'text') : []);

/**
 * An edited line onto the answer it came from: value and text from the line, everything else (questions in the answer,
 * conditions, a translated text the line does not show) stays. An answer with only value and text is simply the line.
 */
function mergeChoice(item, line) {
    const parsed = lineToChoice(line);
    const value = typeof parsed === 'string' ? parsed : parsed.value;
    const text = typeof parsed === 'string' ? undefined : parsed.text;
    const oldValue = item && typeof item === 'object' ? item.value : item;
    const keepValue = oldValue !== undefined && String(oldValue) === value ? oldValue : value;
    if (extrasOf(item).length === 0) {
        if (text === undefined) { return keepValue; }
        return { value: keepValue, text };
    }
    const out = {};
    for (const [k, v] of Object.entries(item)) {
        if (k === 'value') { out.value = keepValue; } else if (k !== 'text' || text !== undefined || typeof v !== 'string') { out[k] = v; }
    }
    if (!('value' in out)) { out.value = keepValue; }
    if (text !== undefined) { out.text = text; }
    return out;
}

/**
 * The lines → answers, and which answers were kept. A line that is unchanged gives back the answer it came from (number
 * values, kinds of entries). An edited line takes the place of the answer it came from: the one with the same value, else
 * the next unused answer between its unchanged neighbours. So an answer keeps its questions and conditions when its value
 * or text changes. Answers that no line took are gone (`dropped`, indexes into `original`).
 */
export function matchChoices(text, original) {
    const lines = text.split('\n').map((l) => l.replace(/\r$/, '')).filter((l) => l.trim() !== '');
    const used = new Array(original.length).fill(false);
    const from = new Array(lines.length).fill(-1);
    const pool = new Map();
    original.forEach((item, i) => {
        const line = choiceToLine(item);
        pool.set(line, [...(pool.get(line) ?? []), i]);
    });
    lines.forEach((line, li) => {
        const queue = pool.get(line);
        if (queue && queue.length) { from[li] = queue.shift(); used[from[li]] = true; }
    });
    lines.forEach((line, li) => {
        if (from[li] >= 0) { return; }
        const parsed = lineToChoice(line);
        const value = typeof parsed === 'string' ? parsed : parsed.value;
        const same = original.findIndex((item, i) => !used[i] && String(item && typeof item === 'object' ? item.value : item) === value);
        if (same >= 0) { from[li] = same; used[same] = true; }
    });
    lines.forEach((line, li) => {
        if (from[li] >= 0) { return; }
        let lo = -1;
        for (let k = li - 1; k >= 0; k--) { if (from[k] >= 0) { lo = from[k]; break; } }
        let hi = original.length;
        for (let k = li + 1; k < lines.length; k++) { if (from[k] >= 0) { hi = from[k]; break; } }
        for (let i = lo + 1; i < hi; i++) {
            if (!used[i]) { from[li] = i; used[i] = true; break; }
        }
    });
    const items = lines.map((line, li) => {
        if (from[li] < 0) { return lineToChoice(line); }
        const item = original[from[li]];
        return choiceToLine(item) === line ? item : mergeChoice(item, line);
    });
    const dropped = original.map((_, i) => i).filter((i) => !used[i]);
    return { items, dropped };
}

/** The lines → answers (see matchChoices). */
export function linesToChoices(text, original) {
    return matchChoices(text, original).items;
}

/** Number of elements inside an answer, at any depth (panels, answers of questions in it). */
function elementsIn(item) {
    let n = 0;
    const walk = (v) => {
        const own = Array.isArray(v?.elements) ? v.elements : [];
        const inChoices = (Array.isArray(v?.choices) ? v.choices : []).flatMap((c) => (Array.isArray(c?.elements) ? c.elements : []));
        [...own, ...inChoices].forEach((c) => { n += 1; walk(c); });
    };
    walk(item);
    return n;
}

function choicesControl(ctx, def) {
    const id = nextId(def.name);
    const box = errorBox(`${id}-err`);
    const current = () => (Array.isArray(ctx.value.choices) ? ctx.value.choices : []);
    const items = current();
    const advanced = hasAdvancedChoices(items);
    const nested = items.some((item) => Array.isArray(item?.elements));
    const input = h('textarea', { class: 'form-control font-monospace', id, rows: Math.min(14, Math.max(4, items.length + 1)), value: items.map(choiceToLine).join('\n'), spellcheck: 'false' });
    const count = h('div', { class: 'form-text' }, ctx.t('help.choices_count', { count: items.length }));
    commitOnChange(input, () => input.value, (v) => {
        const before = current();
        const { items: next, dropped } = matchChoices(v, before);
        const lost = dropped.map((i) => before[i]).filter((item) => elementsIn(item) > 0);
        if (lost.length) {
            const message = ctx.t('confirm.choices_nested', {
                answers: lost.map((item) => `„${choiceToLine(item)}"`).join(', '),
                count: lost.reduce((sum, item) => sum + elementsIn(item), 0),
            });
            if (!ctx.confirm(message)) { return { ok: false, cancelled: true }; }
        }
        const result = ctx.commit('choices', next);
        if (result.ok) { count.textContent = ctx.t('help.choices_count', { count: next.length }); }
        return result;
    }, box, ctx, def, () => current().map(choiceToLine).join('\n'));
    const help = ctx.t(nested ? 'help.choices_nested' : advanced ? 'help.choices_advanced' : 'help.choices');
    const row = field(ctx, def, id, input, { help, notes: [count, box] });
    if (!(ctx.openChoice && supportsChoiceElements(ctx.typeName))) { return row; }
    return h('div', {}, row, openChoiceControl(ctx, current));
}

/** Answers without questions, as [index, line]: the ones that can get questions. */
export function plainChoices(items) {
    return items.map((item, i) => [i, item]).filter(([, item]) => !Array.isArray(item?.elements)).map(([i, item]) => [i, choiceToLine(item)]);
}

/** "Questions for an answer": pick a plain answer, it becomes one that holds questions (tree row, new elements go in). */
function openChoiceControl(ctx, current) {
    const id = nextId('open-choice');
    const box = errorBox(`${id}-err`);
    const fill = () => {
        const keep = select.value;
        select.replaceChildren(...plainChoices(current()).map(([i, line]) => h('option', { value: String(i) }, line)));
        if ([...select.options].some((o) => o.value === keep)) { select.value = keep; }
        button.disabled = select.options.length === 0;
    };
    const select = h('select', { class: 'form-select form-select-sm', id });
    const button = h('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary text-nowrap' }, ctx.t('choices_open.button'));
    select.addEventListener('focus', fill);
    button.addEventListener('click', () => {
        if (select.value === '') { return; }
        const result = ctx.openChoice(Number(select.value));
        if (!result.ok) { setError(box, select, errorText(ctx, result, null)); }
    });
    fill();
    const row = field(ctx, { name: 'open-choice' }, id, h('div', { class: 'input-group input-group-sm' }, select, button),
        { label: ctx.t('choices_open.label'), help: ctx.t('choices_open.help'), notes: box, prop: 'open-choice' });
    row.removeAttribute('data-prop');
    row.setAttribute('data-sb-open-choice', '');
    return row;
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
    const def = ctx.typeName === 'page' ? PAGE : ctx.typeName === 'survey' ? SURVEY : typeDef(ctx.typeName);
    const root = h('div', { class: 'sb-props-body' });
    const typeLabel = ctx.t(`type.${ctx.typeName}`);
    const head = h('div', { class: 'sb-props-head' }, h('h2', { class: 'h5 mb-0' }, def ? typeLabel : String(ctx.typeName ?? '?')));
    root.append(head);
    // "required" is the one switch nearly every question needs: it sits next to the heading, not in the list
    const required = def?.props.find((p) => p.name === 'isRequired');
    if (required) { head.append(requiredSwitch(ctx, required)); }

    if (ctx.findings.length) {
        root.append(h('div', { class: `alert alert-${ctx.findings.some((f) => f.severity === 'error') ? 'danger' : 'warning'} py-2 small sb-findings`, role: 'status' },
            h('strong', {}, ctx.t('findings.here')), h('div', { class: 'text-muted', 'data-sb-stale': true, hidden: !ctx.stale }, ctx.t('findings.stale')),
            h('ul', { class: 'mb-0 ps-3' }, ctx.findings.map((f) => h('li', {}, f.message)))));
    }

    if (!def) {
        root.append(h('p', { class: 'text-muted' }, ctx.t('unknown_type', { type: String(ctx.typeName ?? '?') })));
    } else {
        const groups = ['general', 'flow', 'layout', 'logic'];
        for (const group of groups) {
            const props = def.props.filter((p) => p.group === group && p !== required);
            if (props.length === 0) { continue; }
            const controls = props.map((p) => CONTROLS[p.kind]?.(ctx, p)).filter(Boolean);
            if (group === 'general') {
                root.append(h('section', { class: 'mt-3 sb-fields', 'aria-label': ctx.t('group.general') }, controls));
            } else {
                root.append(h('details', { class: 'mt-2 sb-group', open: hasAny(ctx.value, props) },
                    h('summary', { class: 'fw-semibold mb-2' }, ctx.t(`group.${group}`)), h('div', { class: 'sb-fields' }, controls)));
            }
        }
    }

    if (ctx.usedIn?.length) { root.append(usedInSection(ctx)); }

    const others = def ? otherProperties(ctx.value, ctx.typeName) : Object.keys(ctx.value).filter((k) => k !== 'elements');
    if (others.length) {
        root.append(h('p', { class: 'small text-muted mt-3 mb-0' }, ctx.t('unknown_props', { list: others.join(', ') })));
    }
    return root;
}

// The graphical survey builder: structure tree, toolbar and properties panel on top of the survey text.
//
// The text stays the single source of truth (decision 1 of PLAN-3.2.0): the builder reads it when its tab opens and writes
// every change back through the text bridge. Nothing here decides what is valid; the model refuses what the registry and the
// server's rules do not allow, and the server checks the text when it is saved.
//
// Keyboard: arrow keys move the selection, Alt+Up/Down moves the element, Delete removes it, Enter goes to its properties,
// Escape comes back to the list. Everything is built with createElement/textContent (see dom.js).

import { h, createT } from './dom.js';
import {
    loadModel, outline, setProp, renameElement, addElement, removeElement, moveElement, addPage, removePage, movePage,
    countElements, formatPath, displayText,
} from './model.js';
import { palette, typeDef } from './registry.js';
import { valueAt } from './json-doc.js';
import { findReferences, validateName } from './names.js';
import { buildProperties } from './ui-props.js';

const EMPTY_SURVEY = '{\n  "pages": [\n    {\n      "name": "page1",\n      "elements": []\n    }\n  ]\n}';

const key = (path) => JSON.stringify(path);
const samePath = (a, b) => key(a) === key(b);
const isPagePath = (path) => path.length === 2 && path[0] === 'pages';
const stripTags = (html) => String(html).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

/**
 * @param {{root:HTMLElement, bridge:{get:Function,set:Function}, config:object, page?:HTMLElement|null}} options
 */
export function createBuilder({ root, bridge, config, page = null }) {
    const t = createT(config.messages ?? {});
    const rules = config.rules;
    const findings = Array.isArray(config.findings) ? config.findings : [];
    const taken = new Set(config.taken ?? []); // names of the published survey
    const removed = new Set();                 // names removed in this editing session

    const state = { doc: null, error: null, selected: null, collapsed: new Set(), stale: false, active: false };

    // ---- skeleton ------------------------------------------------------------------------------------------------

    const alerts = h('div', { class: 'sb-alerts' });
    const live = h('div', { class: 'visually-hidden', role: 'status', 'aria-live': 'polite' });
    const tree = h('div', { class: 'sb-tree', role: 'tree', 'aria-label': t('tree_label') });
    const props = h('div', { class: 'sb-props', 'aria-label': t('props_label') });
    const size = h('div', { class: 'sb-size small text-muted ms-auto' });

    const btn = (label, onClick, extra = {}) => h('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', onclick: onClick, ...extra }, label);
    const addPageBtn = btn(t('toolbar.add_page'), () => doAddPage());
    const upBtn = btn(`↑ ${t('toolbar.move_up')}`, () => doMove(-1));
    const downBtn = btn(`↓ ${t('toolbar.move_down')}`, () => doMove(1));
    const delBtn = btn(t('toolbar.delete'), () => doDelete(), { class: 'btn btn-sm btn-outline-danger' });

    const menu = h('ul', { class: 'dropdown-menu sb-palette' });
    for (const group of palette()) {
        menu.append(h('li', {}, h('h6', { class: 'dropdown-header' }, t(`palette_group.${group.group}`))));
        for (const type of group.types) {
            menu.append(h('li', {}, h('button', { type: 'button', class: 'dropdown-item', 'data-type': type.type }, t(`type.${type.type}`))));
        }
    }
    menu.addEventListener('click', (event) => {
        const item = event.target.closest('[data-type]');
        if (item) { doAddElement(item.getAttribute('data-type')); }
    });
    const addElementBtn = h('div', { class: 'dropdown' },
        h('button', { type: 'button', class: 'btn btn-sm btn-primary dropdown-toggle', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false' }, t('toolbar.add_element')),
        menu);

    const toolbar = h('div', { class: 'sb-toolbar d-flex flex-wrap gap-2 align-items-center mb-3', role: 'toolbar', 'aria-label': t('toolbar.label') },
        addElementBtn, addPageBtn, upBtn, downBtn, delBtn, size);
    const hints = h('div', { class: 'form-text mt-2' }, h('p', { class: 'mb-1' }, t('keys_hint')), h('p', { class: 'mb-0' }, t('add_hint')));
    const main = h('div', { class: 'sb-main' },
        h('div', { class: 'sb-tree-col' }, h('h2', { class: 'h6 text-muted' }, t('structure')), tree, hints),
        h('div', { class: 'sb-props-col' }, props));
    root.replaceChildren(alerts, toolbar, main, live);

    // The builder sits inside the survey form: Enter in a field must commit that field, never submit the form.
    root.addEventListener('keydown', (event) => {
        const el = event.target;
        if (event.key === 'Enter' && el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'number')) {
            event.preventDefault();
            el.blur(); // fires "change" when the value changed
            el.focus();
        }
        if (event.key === 'Escape' && props.contains(el)) {
            event.preventDefault();
            focusSelected();
        }
    });

    // ---- helpers -------------------------------------------------------------------------------------------------

    function say(message) {
        live.textContent = '';
        live.textContent = message;
    }

    function showMessage(text, kind = 'danger') {
        alerts.querySelectorAll('[data-sb-transient]').forEach((el) => el.remove());
        alerts.append(h('div', { class: `alert alert-${kind} py-2 alert-dismissible`, role: 'alert', 'data-sb-transient': true },
            text, h('button', { type: 'button', class: 'btn-close', 'aria-label': 'Close', 'data-bs-dismiss': 'alert' })));
    }

    function errorMessage(result) {
        const text = t(`err.${result.code}`, { max: result.code === 'tooDeep' ? rules.maxDepth : rules.maxElements });
        return text.startsWith('[') ? t('err.generic') : text;
    }

    /** The element (or page) a finding belongs to: the deepest page or element on its path. */
    function ownerOf(path) {
        let owner = null;
        for (let i = 0; i < path.length; i++) {
            if (path[i] === 'elements' && Number.isInteger(path[i + 1])) { owner = path.slice(0, i + 2); }
            else if (i === 0 && path[0] === 'pages' && Number.isInteger(path[1])) { owner = path.slice(0, 2); }
        }
        return owner;
    }

    const findingsByOwner = new Map();
    const globalFindings = [];
    for (const f of findings) {
        const owner = ownerOf(f.path ?? []);
        if (owner === null) { globalFindings.push(f); continue; }
        const k = key(owner);
        findingsByOwner.set(k, [...(findingsByOwner.get(k) ?? []), f]);
    }

    function currentValue(path) {
        return state.doc ? valueAt(state.doc, path) : undefined;
    }

    function typeNameOf(path, value) {
        return isPagePath(path) ? 'page' : value?.type;
    }

    // ---- tree ----------------------------------------------------------------------------------------------------

    function label(value) {
        const name = typeof value.name === 'string' && value.name !== '' ? value.name : null;
        const title = value.type === 'html' ? stripTags(displayText(value.html)) : displayText(value.title);
        return title !== '' ? title : (name ?? t('untitled'));
    }

    /** The visible rows of the tree, in order. */
    function rows() {
        const out = [];
        const walk = (elements, level) => {
            for (const e of elements) {
                const open = !state.collapsed.has(key(e.path));
                out.push({ path: e.path, kind: 'element', level, type: e.type, name: e.name, title: e.title, children: e.children.length, open });
                if (e.children.length && open) { walk(e.children, level + 1); }
            }
        };
        for (const pg of outline(state.doc)) {
            const open = !state.collapsed.has(key(pg.path));
            out.push({ path: pg.path, kind: 'page', level: 1, implicit: pg.implicit, name: pg.name, title: pg.title, children: pg.elements.length, open });
            if (pg.elements.length && open) { walk(pg.elements, 2); }
        }
        return out;
    }

    function renderTree() {
        const hadFocus = tree.contains(document.activeElement);
        const list = rows();
        const selectedKey = state.selected ? key(state.selected) : null;
        const hasSelected = list.some((r) => key(r.path) === selectedKey);
        tree.replaceChildren(...list.map((r, i) => {
            const k = key(r.path);
            const selected = hasSelected ? k === selectedKey : i === 0;
            const own = findingsByOwner.get(k) ?? [];
            const value = r.kind === 'element' ? valueAt(state.doc, r.path) : null;
            const text = r.kind === 'page'
                ? (r.implicit ? t('page') : (displayText(valueAt(state.doc, r.path)?.title) || r.name || t('unnamed')))
                : label(value);
            const typeLabel = r.kind === 'page' ? t('page') : t(`type.${r.type}`).startsWith('[') ? String(r.type) : t(`type.${r.type}`);
            const el = h('div', {
                class: `sb-item sb-${r.kind}${selected && hasSelected ? ' sb-selected' : ''}${own.length ? ' sb-flagged' : ''}`,
                role: 'treeitem', 'aria-level': r.level, 'aria-selected': String(selected && hasSelected),
                'aria-expanded': r.children ? String(r.open) : null, tabindex: selected ? 0 : -1, 'data-path': k,
                title: own.map((f) => f.message).join('\n') || null,
            },
            r.children ? h('span', { class: 'sb-toggle', 'aria-hidden': 'true', 'data-toggle': k }, r.open ? '▾' : '▸') : h('span', { class: 'sb-toggle', 'aria-hidden': 'true' }, ''),
            h('span', { class: 'sb-type' }, typeLabel),
            h('span', { class: 'sb-label' }, text),
            r.kind === 'page' && !r.implicit ? h('span', { class: 'sb-count text-muted' }, t('elements_count', { count: r.children })) : null,
            own.length ? h('span', { class: 'sb-mark', 'aria-label': t('findings.here') }, own.some((f) => f.severity === 'error') ? '✖' : '⚠') : null);
            el.style.setProperty('--sb-level', String(r.level));
            return el;
        }));
        if (hadFocus) { focusSelected(); }
    }

    function focusSelected() {
        const el = tree.querySelector('[tabindex="0"]');
        if (el) { el.focus(); }
    }

    tree.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-toggle]');
        const item = event.target.closest('.sb-item');
        if (toggle) {
            const k = toggle.getAttribute('data-toggle');
            state.collapsed.has(k) ? state.collapsed.delete(k) : state.collapsed.add(k);
            renderTree();
            return;
        }
        if (item) { select(JSON.parse(item.getAttribute('data-path')), true); }
    });

    tree.addEventListener('keydown', (event) => {
        if (!state.doc) { return; }
        const list = rows();
        const index = Math.max(0, list.findIndex((r) => state.selected && samePath(r.path, state.selected)));
        const go = (i) => { if (list[i]) { select(list[i].path, true); } };
        const row = list[index];
        let handled = true;
        switch (event.key) {
        case 'ArrowDown': event.altKey ? doMove(1) : go(index + 1); break;
        case 'ArrowUp': event.altKey ? doMove(-1) : go(index - 1); break;
        case 'Home': go(0); break;
        case 'End': go(list.length - 1); break;
        case 'ArrowRight':
            if (row.children && !row.open) { state.collapsed.delete(key(row.path)); renderTree(); } else if (row.children) { go(index + 1); }
            break;
        case 'ArrowLeft':
            if (row.children && row.open) { state.collapsed.add(key(row.path)); renderTree(); } else {
                for (let i = index - 1; i >= 0; i--) { if (list[i].level < row.level) { go(i); break; } }
            }
            break;
        case 'Enter': {
            const first = props.querySelector('input, textarea, select');
            if (first) { first.focus(); }
            break;
        }
        case 'Delete': doDelete(); break;
        default: handled = false;
        }
        if (handled) { event.preventDefault(); }
    });

    // ---- selection and properties --------------------------------------------------------------------------------

    function select(path, focus = false) {
        state.selected = path;
        renderTree();
        renderProps();
        renderToolbar();
        if (focus) { focusSelected(); }
    }

    function renderProps() {
        props.replaceChildren();
        if (!state.doc) { return; }
        const path = state.selected;
        if (!path) { props.append(h('p', { class: 'text-muted' }, t('select_hint'))); return; }
        const value = currentValue(path);
        if (path.length === 0) { props.append(h('p', { class: 'text-muted' }, t('implicit_page'))); return; }
        if (!value || typeof value !== 'object') { props.append(h('p', { class: 'text-muted' }, t('select_hint'))); return; }
        const typeName = typeNameOf(path, value);
        const ctx = {
            t,
            rules,
            typeName,
            get value() { return currentValue(path) ?? {}; },
            findings: findingsByOwner.get(key(path)) ?? [],
            stale: state.stale,
            commit(name, v) {
                const result = setProp(state.doc, path, name, v, { rules });
                if (result.ok) { adopt(result.doc); }
                return result;
            },
            rename(newName) { return doRename(path, typeName, newName); },
        };
        props.append(buildProperties(ctx));
    }

    function doRename(path, typeName, newName) {
        const old = currentValue(path)?.name;
        if (newName === old) { return { ok: true }; }
        const verdict = validateName(state.doc, newName, { kind: typeName, path, rules });
        if (!verdict.ok) { return { ok: false, code: verdict.code }; }
        const isField = typeName !== 'page' && Boolean(typeDef(typeName)?.hasValue);
        if (isField && typeof old === 'string' && old !== '') {
            const refs = findReferences(state.doc, old).length;
            const message = t('confirm.rename', { old, new: newName, refs: refs ? t('confirm.rename_refs', { count: refs }) : '' });
            if (!window.confirm(message)) { return { ok: false, cancelled: true }; }
        }
        const result = renameElement(state.doc, path, newName, { rules, updateReferences: true });
        if (result.ok) {
            adopt(result.doc);
            say(t('announce.renamed', { name: newName }));
        }
        return result;
    }

    /** A change of the text made by a property: the tree and the size follow, the panel stays (the field has the focus). */
    function adopt(doc) {
        state.doc = doc;
        bridge.set(doc.text);
        state.stale = true;
        props.querySelectorAll('[data-sb-stale]').forEach((el) => { el.hidden = false; });
        renderAlerts();
        renderTree();
        renderSize();
    }

    // ---- toolbar -------------------------------------------------------------------------------------------------

    function location() {
        const path = state.selected;
        if (!path || path.length === 0 || isPagePath(path)) { return null; }
        return { arr: path.slice(0, -1), index: path[path.length - 1] };
    }

    function renderToolbar() {
        const ok = Boolean(state.doc);
        const loc = ok ? location() : null;
        const sel = ok && state.selected ? state.selected : null;
        const pagesCount = ok ? (valueAt(state.doc, ['pages'])?.length ?? 0) : 0;
        let canUp = false;
        let canDown = false;
        if (loc) {
            const length = valueAt(state.doc, loc.arr)?.length ?? 0;
            canUp = loc.index > 0;
            canDown = loc.index < length - 1;
        } else if (sel && isPagePath(sel)) {
            canUp = sel[1] > 0;
            canDown = sel[1] < pagesCount - 1;
        }
        const deletable = Boolean(sel) && (Boolean(loc) || (isPagePath(sel) && pagesCount > 1));
        toolbar.querySelectorAll('button').forEach((b) => { b.disabled = !ok && !b.classList.contains('dropdown-toggle'); });
        addElementBtn.querySelector('button').disabled = !ok;
        upBtn.disabled = !canUp;
        downBtn.disabled = !canDown;
        delBtn.disabled = !deletable;
        addPageBtn.disabled = !ok;
    }

    function renderSize() {
        if (!state.doc) { size.textContent = ''; return; }
        const bytes = new Blob([state.doc.text]).size;
        const count = countElements(state.doc);
        size.textContent = t('size', { kb: (bytes / 1024).toFixed(1), max: Math.round(rules.maxBytes / 1024), count, max_elements: rules.maxElements });
        const near = bytes > rules.maxBytes * 0.8 || count > rules.maxElements * 0.8;
        size.classList.toggle('text-danger', near);
        size.classList.toggle('text-muted', !near);
        size.title = near ? t('size_warn') : '';
    }

    function renderAlerts() {
        alerts.replaceChildren();
        if (state.error) {
            if (state.error.empty) {
                alerts.append(h('div', { class: 'alert alert-info d-flex align-items-center gap-3' }, t('empty'),
                    btn(t('create_empty'), () => { bridge.set(EMPTY_SURVEY); reload(); }, { class: 'btn btn-sm btn-primary' })));
            } else {
                alerts.append(h('div', { class: 'alert alert-danger d-flex align-items-center gap-3', role: 'alert' },
                    t('invalid', { message: state.error.message, line: state.error.line, column: state.error.column }),
                    btn(t('goto_json'), () => showJsonTab(), { class: 'btn btn-sm btn-outline-danger' })));
            }
            return;
        }
        for (const d of state.doc?.duplicates ?? []) {
            alerts.append(h('div', { class: 'alert alert-warning py-2', role: 'alert' }, t('duplicate_key', { key: d.key, path: formatPath(d.path) })));
        }
        if (globalFindings.length) {
            alerts.append(h('div', { class: 'alert alert-warning py-2 small' },
                h('strong', {}, t('findings.title')), state.stale ? h('div', { class: 'text-muted' }, t('findings.stale')) : null,
                h('ul', { class: 'mb-0 ps-3' }, globalFindings.map((f) => h('li', {}, f.message)))));
        }
    }

    function showJsonTab() {
        const tab = document.getElementById('se-tab-json');
        if (tab && window.bootstrap?.Tab) { window.bootstrap.Tab.getOrCreateInstance(tab).show(); } else { tab?.click(); }
    }

    // ---- operations ----------------------------------------------------------------------------------------------

    function commitStructure(result, { select: nextPath, announce } = {}) {
        if (!result.ok) { showMessage(errorMessage(result)); return false; }
        alerts.querySelectorAll('[data-sb-transient]').forEach((el) => el.remove());
        state.doc = result.doc;
        bridge.set(result.doc.text);
        state.stale = true;
        if (nextPath !== undefined) { state.selected = nextPath; }
        render();
        focusSelected();
        if (announce) { say(announce); }
        return true;
    }

    function insertionPoint() {
        const list = valueAt(state.doc, ['pages']);
        const sel = state.selected;
        if (!sel) {
            return Array.isArray(list) && list.length ? { parent: ['pages', 0], index: list[0].elements?.length ?? 0 } : { parent: [], index: valueAt(state.doc, ['elements'])?.length ?? 0 };
        }
        if (sel.length === 0) { return { parent: [], index: valueAt(state.doc, ['elements'])?.length ?? 0 }; }
        const value = valueAt(state.doc, sel);
        if (isPagePath(sel) || value?.type === 'panel') { return { parent: sel, index: value?.elements?.length ?? 0 }; }
        return { parent: sel.slice(0, -2), index: sel[sel.length - 1] + 1 };
    }

    function takenNames() {
        return [...taken, ...removed];
    }

    function doAddElement(type) {
        if (!state.doc) { return; }
        const { parent, index } = insertionPoint();
        const texts = type === 'panel' ? { title: t('new.panel') }
            : type === 'html' ? { html: t('new.html') }
                : { title: t('new.title'), choices: [1, 2, 3].map((n) => t('new.choice', { n })) };
        const result = addElement(state.doc, parent, index, type, { rules, texts, taken: takenNames() });
        // make sure the new element is visible: its parents are open
        if (result.ok) { state.collapsed.delete(key(parent)); }
        commitStructure(result, { select: result.path, announce: result.ok ? t('announce.added', { name: result.name }) : undefined });
    }

    function doAddPage() {
        if (!state.doc) { return; }
        const sel = state.selected;
        const index = sel && sel[0] === 'pages' ? sel[1] + 1 : (valueAt(state.doc, ['pages'])?.length ?? 0);
        const result = addPage(state.doc, index, { rules, taken: takenNames() });
        commitStructure(result, { select: result.path, announce: result.ok ? t('announce.page_added', { name: result.name }) : undefined });
    }

    function namesIn(value) {
        const out = [];
        const walk = (v) => {
            if (typeof v?.name === 'string') { out.push(v.name); }
            (v?.elements ?? []).forEach(walk);
        };
        walk(value);
        return out;
    }

    function countIn(value) {
        let n = 0;
        const walk = (v) => (v?.elements ?? []).forEach((c) => { n += 1; walk(c); });
        walk(value);
        return n;
    }

    function doDelete() {
        const sel = state.selected;
        if (!state.doc || !sel || sel.length === 0) { return; }
        const value = valueAt(state.doc, sel);
        if (!value) { return; }
        const name = label(value);
        if (isPagePath(sel)) {
            if (!window.confirm(t('confirm.page', { name: displayText(value.title) || value.name || t('unnamed'), count: countIn(value) }))) { return; }
            const pages = valueAt(state.doc, ['pages']);
            const result = removePage(state.doc, sel[1]);
            if (result.ok) { namesIn(value).forEach((n) => removed.add(n)); }
            commitStructure(result, { select: ['pages', Math.min(sel[1], pages.length - 2)], announce: result.ok ? t('announce.deleted', { name }) : undefined });
            return;
        }
        const references = typeof value.name === 'string' ? findReferences(state.doc, value.name).length : 0;
        const children = countIn(value);
        const text = (value.type === 'panel' ? t('confirm.panel', { name, count: children }) : t('confirm.element', { name }))
            + (references ? t('confirm.refs', { count: references }) : '');
        if (!window.confirm(text)) { return; }
        const arr = sel.slice(0, -1);
        const index = sel[sel.length - 1];
        const length = valueAt(state.doc, arr).length;
        const result = removeElement(state.doc, sel);
        if (result.ok) { namesIn(value).forEach((n) => removed.add(n)); }
        const next = index < length - 1 ? [...arr, index] : (index > 0 ? [...arr, index - 1] : arr.slice(0, -1));
        commitStructure(result, { select: next, announce: result.ok ? t('announce.deleted', { name }) : undefined });
    }

    function doMove(delta) {
        const sel = state.selected;
        if (!state.doc || !sel) { return; }
        const value = valueAt(state.doc, sel);
        if (isPagePath(sel)) {
            const total = valueAt(state.doc, ['pages']).length;
            const to = sel[1] + delta;
            if (to < 0 || to >= total) { return; }
            const moved = movePage(state.doc, sel[1], to);
            commitStructure(moved, { select: moved.path, announce: moved.ok ? t('announce.moved', { name: displayText(value?.title) || value?.name || t('page'), pos: to + 1, total }) : undefined });
            return;
        }
        const loc = location();
        if (!loc) { return; }
        const total = valueAt(state.doc, loc.arr).length;
        const to = loc.index + delta;
        if (to < 0 || to >= total) { return; }
        const result = moveElement(state.doc, sel, to);
        commitStructure(result, { select: result.path, announce: result.ok ? t('announce.moved', { name: label(value), pos: to + 1, total }) : undefined });
    }

    // ---- loading -------------------------------------------------------------------------------------------------

    function render() {
        renderAlerts();
        main.hidden = !state.doc;
        toolbar.hidden = false;
        if (state.doc) {
            if (!state.selected || (state.selected.length && currentValue(state.selected) === undefined)) {
                const first = outline(state.doc)[0];
                state.selected = first ? (first.implicit ? [] : first.path) : null;
            }
            renderTree();
            renderProps();
        } else {
            tree.replaceChildren();
            props.replaceChildren();
        }
        renderToolbar();
        renderSize();
    }

    /** Reads the text again (the JSON tab may have changed it) and shows it. */
    function reload() {
        const text = bridge.get();
        if (text.trim() === '') {
            state.doc = null;
            state.error = { empty: true };
        } else {
            const model = loadModel(text);
            if (model.ok) {
                state.doc = model.doc;
                state.error = null;
            } else {
                state.doc = null;
                state.error = model.error;
            }
        }
        render();
    }

    return {
        /** The builder's tab is shown: take over the text, use the whole width. */
        activate() {
            state.active = true;
            page?.classList.replace('container', 'container-fluid');
            page?.classList.add('px-lg-4');
            reload();
        },
        /** The "JSON" tab is shown. */
        deactivate() {
            state.active = false;
            page?.classList.replace('container-fluid', 'container');
            page?.classList.remove('px-lg-4');
            bridge.refresh?.();
        },
        reload,
        select: (path) => select(path, false),
        getState: () => ({ doc: state.doc, selected: state.selected, error: state.error }),
    };
}

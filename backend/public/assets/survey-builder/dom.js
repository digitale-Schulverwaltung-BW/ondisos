// Small DOM helpers of the survey builder. Everything is built with createElement/textContent, never innerHTML: titles and
// texts of a survey are data and must not be able to bring markup into the page.

/**
 * h('div', {class: 'x', onclick: fn, 'aria-label': '…'}, 'text', childNode, …)
 * Attributes: false/null/undefined are skipped, true sets an empty attribute, on<event> adds a listener.
 */
export function h(tag, attrs, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs ?? {})) {
        if (value === false || value === null || value === undefined) { continue; }
        if (key.startsWith('on') && typeof value === 'function') { el.addEventListener(key.slice(2), value); }
        else if (key === 'class') { el.className = value; }
        else if (key === 'value') { el.value = value; }
        else if (key === 'checked' || key === 'disabled' || key === 'selected' || key === 'open' || key === 'readOnly') { el[key] = Boolean(value); }
        else { el.setAttribute(key, value === true ? '' : String(value)); }
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) { continue; }
        el.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return el;
}

/** Message lookup: t('err.duplicate'), t('size', {kb: 3}) with {{kb}} placeholders. A missing key shows up as [key]. */
export function createT(messages) {
    return function t(key, vars) {
        let value = messages;
        for (const part of key.split('.')) {
            value = value && typeof value === 'object' ? value[part] : undefined;
        }
        if (typeof value !== 'string') { return `[${key}]`; }
        return value.replace(/\{\{(\w+)\}\}/g, (m, name) => (vars && name in vars ? String(vars[name]) : m));
    };
}

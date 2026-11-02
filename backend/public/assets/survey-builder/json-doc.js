// Lossless JSON documents for the survey builder.
//
// parseDoc() reads JSON text into a tree that remembers where every value sits in the text. Edits (setValue,
// insertMember, removeMember, insertItem, removeItem) splice the text at exactly that place and return a new document:
// everything outside the edited span stays byte for byte (indentation, tabs, CRLF, compact choice lists, key order).
// Re-serializing with JSON.stringify would reformat hand-written surveys and bury a one-word change in the history.
//
// Pure logic, no DOM: runs in the browser and under `node --test`. The server stays the authority on validity
// (SurveyValidator); this parser only has to agree with JSON.parse, which the tests check.

const WS = ' \t\n\r';
const MAX_DEPTH = 100;

export class JsonSyntaxError extends Error {
    constructor(message, pos, text) {
        super(message);
        this.name = 'JsonSyntaxError';
        this.pos = pos;
        const before = text.slice(0, pos);
        this.line = before.split('\n').length;
        this.column = pos - (before.lastIndexOf('\n') + 1) + 1;
    }
}

/**
 * @typedef {{type:'object', start:number, end:number, members:Member[]}
 *   | {type:'array', start:number, end:number, items:Node[]}
 *   | {type:'string'|'number'|'literal', start:number, end:number, value:*}} Node
 * @typedef {{key:string, keyStart:number, keyEnd:number, node:Node}} Member
 * @typedef {{text:string, root:Node, duplicates:{path:Array<string|number>, key:string}[], indentUnit:string, eol:string}} Doc
 */

/** @returns {Doc} @throws {JsonSyntaxError} */
export function parseDoc(text) {
    if (typeof text !== 'string') {
        throw new TypeError('text must be a string');
    }
    let i = 0; // like JSON.parse and PHP's json_decode: a BOM at the start is a syntax error
    const duplicates = [];
    const fail = (message, pos = i) => { throw new JsonSyntaxError(message, pos, text); };
    const ws = () => { while (i < text.length && WS.includes(text[i])) { i++; } };

    function str() {
        const start = i;
        i++; // opening quote
        while (i < text.length) {
            const c = text.charCodeAt(i);
            if (c === 0x22) { // closing quote
                i++;
                try {
                    return { start, end: i, value: JSON.parse(text.slice(start, i)) };
                } catch (e) {
                    return fail('Invalid string', start);
                }
            }
            if (c < 0x20) { fail('Control character in string'); }
            i += c === 0x5C ? 2 : 1; // backslash: skip the escaped character
        }
        return fail('Unterminated string', start);
    }

    function value(path, depth) {
        if (depth > MAX_DEPTH) { fail('Nesting too deep'); }
        ws();
        const start = i;
        const c = text[i];
        if (c === '{') { return object(path, depth); }
        if (c === '[') { return array(path, depth); }
        if (c === '"') { const s = str(); return { type: 'string', ...s }; }
        const num = /-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/y;
        num.lastIndex = i;
        const m = num.exec(text);
        if (m) { i += m[0].length; return { type: 'number', start, end: i, value: Number(m[0]) }; }
        for (const [word, v] of [['true', true], ['false', false], ['null', null]]) {
            if (text.startsWith(word, i)) { i += word.length; return { type: 'literal', start, end: i, value: v }; }
        }
        return fail(c === undefined ? 'Unexpected end of text' : `Unexpected character "${c}"`);
    }

    function object(path, depth) {
        const start = i;
        i++; // {
        const members = [];
        const seen = new Set();
        ws();
        if (text[i] === '}') { i++; return { type: 'object', start, end: i, members }; }
        for (;;) {
            ws();
            if (text[i] !== '"') { fail('Expected a property name'); }
            const k = str();
            ws();
            if (text[i] !== ':') { fail('Expected ":"'); }
            i++;
            if (seen.has(k.value)) { duplicates.push({ path, key: k.value }); }
            seen.add(k.value);
            const node = value([...path, k.value], depth + 1);
            members.push({ key: k.value, keyStart: k.start, keyEnd: k.end, node });
            ws();
            if (text[i] === ',') { i++; continue; }
            if (text[i] === '}') { i++; return { type: 'object', start, end: i, members }; }
            fail('Expected "," or "}"');
        }
    }

    function array(path, depth) {
        const start = i;
        i++; // [
        const items = [];
        ws();
        if (text[i] === ']') { i++; return { type: 'array', start, end: i, items }; }
        for (;;) {
            items.push(value([...path, items.length], depth + 1));
            ws();
            if (text[i] === ',') { i++; continue; }
            if (text[i] === ']') { i++; return { type: 'array', start, end: i, items }; }
            fail('Expected "," or "]"');
        }
    }

    const root = value([], 0);
    ws();
    if (i < text.length) { fail('Unexpected text after the JSON value'); }
    return { text, root, duplicates, indentUnit: detectIndentUnit(text), eol: text.includes('\r\n') ? '\r\n' : '\n' };
}

function detectIndentUnit(text) {
    const m = /\n([ \t]+)\S/.exec(text);
    if (!m) { return '  '; }
    // The first indented line is one level deep in pretty-printed JSON.
    return m[1];
}

/** Plain JS value of a node (like JSON.parse; the last of duplicate keys wins). */
export function toValue(node) {
    switch (node.type) {
        case 'object': {
            const obj = {};
            for (const m of node.members) {
                // defineProperty: "__proto__" must stay an ordinary key, as with JSON.parse
                Object.defineProperty(obj, m.key, { value: toValue(m.node), enumerable: true, writable: true, configurable: true });
            }
            return obj;
        }
        case 'array': return node.items.map(toValue);
        default: return node.value;
    }
}

/** @returns {Node|undefined} the node at path (keys and indexes), or undefined */
export function nodeAt(doc, path) {
    let node = doc.root;
    for (const step of path) {
        if (node.type === 'object' && typeof step === 'string') {
            let found;
            for (const m of node.members) { if (m.key === step) { found = m.node; } } // last duplicate wins
            node = found;
        } else if (node.type === 'array' && Number.isInteger(step)) {
            node = node.items[step];
        } else {
            return undefined;
        }
        if (!node) { return undefined; }
    }
    return node;
}

export function valueAt(doc, path) {
    const node = nodeAt(doc, path);
    return node === undefined ? undefined : toValue(node);
}

// ---- editing ---------------------------------------------------------------------------------------------------

function lineIndentAt(text, pos) {
    const lineStart = text.lastIndexOf('\n', pos - 1) + 1;
    return /^[ \t]*/.exec(text.slice(lineStart, pos))[0];
}

/** JSON text for a value that will sit on a line indented with `indent` (new lines use the document's line ending). */
function render(doc, value, indent) {
    if (value === undefined) { throw new TypeError('undefined is not a JSON value'); }
    const text = JSON.stringify(value, null, doc.indentUnit);
    return text.split('\n').join(doc.eol + indent);
}

/** One-line JSON in the style of hand-written lists: {"value": "AFG", "text": "Afghanistan"}. */
export function inlineJson(value) {
    if (Array.isArray(value)) { return `[${value.map(inlineJson).join(', ')}]`; }
    if (value && typeof value === 'object') {
        return `{${Object.entries(value).map(([k, v]) => `${JSON.stringify(k)}: ${inlineJson(v)}`).join(', ')}}`;
    }
    return JSON.stringify(value);
}

function splice(doc, from, to, replacement) {
    return parseDoc(doc.text.slice(0, from) + replacement + doc.text.slice(to));
}

function container(doc, path, type) {
    const node = nodeAt(doc, path);
    if (!node || node.type !== type) { throw new Error(`No ${type} at ${JSON.stringify(path)}`); }
    return node;
}

/** Is the container written over several lines? (decides how a new entry is laid out) */
function isMultiline(doc, node, firstStart) {
    return doc.text.slice(node.start, firstStart).includes('\n');
}

/** Replaces the value at path; the rest of the text stays as it is. */
export function setValue(doc, path, value) {
    const node = nodeAt(doc, path);
    if (!node) { throw new Error(`Nothing at ${JSON.stringify(path)}`); }
    const indent = lineIndentAt(doc.text, node.start);
    return splice(doc, node.start, node.end, render(doc, value, indent));
}

/** Adds a property at the end of the object at objPath (or replaces it when the key exists). */
export function insertMember(doc, objPath, key, value) {
    const obj = container(doc, objPath, 'object');
    if (obj.members.some((m) => m.key === key)) { return setValue(doc, [...objPath, key], value); }
    const { eol } = doc;
    const entry = (indent) => `${JSON.stringify(key)}: ${render(doc, value, indent)}`;
    if (obj.members.length === 0) {
        const indent = lineIndentAt(doc.text, obj.start);
        return splice(doc, obj.start, obj.end, `{${eol}${indent}${doc.indentUnit}${entry(indent + doc.indentUnit)}${eol}${indent}}`);
    }
    const first = obj.members[0];
    const last = obj.members[obj.members.length - 1];
    if (isMultiline(doc, obj, first.keyStart)) {
        const indent = lineIndentAt(doc.text, first.keyStart);
        return splice(doc, last.node.end, last.node.end, `,${eol}${indent}${entry(indent)}`);
    }
    return splice(doc, last.node.end, last.node.end, `, ${entry(lineIndentAt(doc.text, obj.start))}`);
}

/** Removes a property (all duplicates of the key). */
export function removeMember(doc, objPath, key) {
    let current = doc;
    for (;;) {
        const obj = container(current, objPath, 'object');
        const idx = obj.members.findIndex((m) => m.key === key);
        if (idx < 0) { return current; }
        const m = obj.members[idx];
        if (obj.members.length === 1) {
            current = splice(current, obj.start, obj.end, '{}');
        } else if (idx < obj.members.length - 1) {
            current = splice(current, m.keyStart, obj.members[idx + 1].keyStart, ''); // "key": value, + gap up to the next key
        } else {
            current = splice(current, obj.members[idx - 1].node.end, m.node.end, ''); // , "key": value
        }
    }
}

/** Inserts an item into the array at arrPath (index = items.length appends). */
export function insertItem(doc, arrPath, index, value) {
    const arr = container(doc, arrPath, 'array');
    if (index < 0 || index > arr.items.length) { throw new RangeError('index out of range'); }
    const { eol } = doc;
    if (arr.items.length === 0) {
        const indent = lineIndentAt(doc.text, arr.start);
        return splice(doc, arr.start, arr.end, `[${eol}${indent}${doc.indentUnit}${render(doc, value, indent + doc.indentUnit)}${eol}${indent}]`);
    }
    const multiline = isMultiline(doc, arr, arr.items[0].start);
    const indent = lineIndentAt(doc.text, arr.items[0].start);
    // an object or array among neighbours written on one line each is written on one line too
    const structured = arr.items.filter((it) => it.type === 'object' || it.type === 'array');
    const oneLine = value !== null && typeof value === 'object' && structured.length > 0
        && structured.every((it) => !doc.text.slice(it.start, it.end).includes('\n'));
    const item = (ind) => (oneLine ? inlineJson(value) : render(doc, value, ind));
    if (index === arr.items.length) {
        const last = arr.items[arr.items.length - 1];
        return splice(doc, last.end, last.end, multiline ? `,${eol}${indent}${item(indent)}` : `, ${item(indent)}`);
    }
    const at = arr.items[index];
    return splice(doc, at.start, at.start, multiline ? `${item(indent)},${eol}${indent}` : `${item(indent)}, `);
}

export function removeItem(doc, arrPath, index) {
    const arr = container(doc, arrPath, 'array');
    if (index < 0 || index >= arr.items.length) { throw new RangeError('index out of range'); }
    if (arr.items.length === 1) { return splice(doc, arr.start, arr.end, '[]'); }
    if (index < arr.items.length - 1) { return splice(doc, arr.items[index].start, arr.items[index + 1].start, ''); }
    return splice(doc, arr.items[index - 1].end, arr.items[index].end, '');
}

/**
 * Moves an item to another position in the same array. The item texts change places while the separators (comma,
 * line break, indentation) stay where they are, so the layout is exactly as before.
 */
export function moveItem(doc, arrPath, from, to) {
    const arr = container(doc, arrPath, 'array');
    const n = arr.items.length;
    if (!Number.isInteger(from) || from < 0 || from >= n) { throw new RangeError('from out of range'); }
    if (!Number.isInteger(to) || to < 0 || to >= n) { throw new RangeError('to out of range'); }
    if (from === to) { return doc; }
    const order = arr.items.map((it) => doc.text.slice(it.start, it.end));
    order.splice(to, 0, order.splice(from, 1)[0]);
    let out = '';
    order.forEach((text, i) => {
        out += text + (i < n - 1 ? doc.text.slice(arr.items[i].end, arr.items[i + 1].start) : '');
    });
    return splice(doc, arr.items[0].start, arr.items[n - 1].end, out);
}

// Tests for the lossless JSON layer of the survey builder. Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { parseDoc, toValue, nodeAt, valueAt, setValue, insertMember, removeMember, insertItem, removeItem, JsonSyntaxError }
    from '../../public/assets/survey-builder/json-doc.js';

const SURVEYS = new URL('../../../frontend/surveys/', import.meta.url);
const files = readdirSync(SURVEYS).filter((f) => f.endsWith('.json')).sort();
const read = (f) => readFileSync(new URL(f, SURVEYS), 'utf8');

/** Every string leaf of a document: [path, node] */
function stringLeaves(doc) {
    const out = [];
    const walk = (node, path) => {
        if (node.type === 'string') { out.push([path, node]); }
        if (node.type === 'object') { node.members.forEach((m) => walk(m.node, [...path, m.key])); }
        if (node.type === 'array') { node.items.forEach((n, i) => walk(n, [...path, i])); }
    };
    walk(doc.root, []);
    return out;
}

function setAt(obj, path, value) {
    const copy = structuredClone(obj);
    let cur = copy;
    path.slice(0, -1).forEach((p) => { cur = cur[p]; });
    cur[path[path.length - 1]] = value;
    return copy;
}

test('parses every survey file exactly like JSON.parse', () => {
    for (const f of files) {
        const text = read(f);
        assert.deepEqual(toValue(parseDoc(text).root), JSON.parse(text), f);
    }
});

test('agrees with JSON.parse on what is valid JSON', () => {
    const snippets = [
        '{}', '[]', '"a"', '1', '-0', '1e5', '1.5E-3', 'true', ' null ', '{"a":[1,2,{"b":null}]}', '"\\u00e4\\n"', '{"__proto__":1}',
        '\t[1,\r\n2]\n',
        '', ' ', '{', '[', '{"a":1,}', '[1,]', "{'a':1}", '01', '1.', '+1', '.5', '{"a" 1}', '{"a":}', '"x\ny"', 'nul', '{"a":1} x',
        '[1 2]', '"\\x"', 'NaN', 'undefined', ' 1', '﻿{}', '{"a":1 "b":2}', '"\\u12"', '[}',
    ];
    for (const s of snippets) {
        let expected;
        try { JSON.parse(s); expected = true; } catch { expected = false; }
        let actual;
        try { parseDoc(s); actual = true; } catch (e) { assert.ok(e instanceof JsonSyntaxError, `${JSON.stringify(s)}: ${e}`); actual = false; }
        assert.equal(actual, expected, `validity of ${JSON.stringify(s)}`);
    }
});

test('reports line and column of a syntax error', () => {
    assert.throws(() => parseDoc('{\n  "a": 1,\n  "b": ?\n}'), (e) => e instanceof JsonSyntaxError && e.line === 3 && e.column === 8);
});

test('refuses absurd nesting instead of overflowing the stack', () => {
    assert.throws(() => parseDoc('['.repeat(5000) + ']'.repeat(5000)), JsonSyntaxError);
});

test('duplicate keys are reported; the last one wins like in JSON.parse', () => {
    const doc = parseDoc('{"a": 1, "b": {"c": 1, "c": 2}, "a": 3}');
    assert.deepEqual(doc.duplicates.map((d) => [d.path, d.key]), [[['b'], 'c'], [[], 'a']]);
    assert.deepEqual(toValue(doc.root), JSON.parse('{"a": 1, "b": {"c": 1, "c": 2}, "a": 3}'));
    assert.equal(valueAt(doc, ['a']), 3);
});

test('"__proto__" stays an ordinary key', () => {
    const v = toValue(parseDoc('{"__proto__": {"x": 1}}').root);
    assert.ok(Object.hasOwn(v, '__proto__'));
    assert.equal(Object.getPrototypeOf(v), Object.prototype);
});

test('replacing any string of any survey changes nothing but that string (byte-exact)', () => {
    for (const f of files) {
        const text = read(f);
        const doc = parseDoc(text);
        const original = JSON.parse(text);
        for (const [path, node] of stringLeaves(doc)) {
            const next = setValue(doc, path, 'Ä "x" \\ \n');
            assert.equal(next.text.slice(0, node.start), text.slice(0, node.start), `${f} ${path}: text before`);
            assert.equal(next.text.slice(next.text.length - (text.length - node.end)), text.slice(node.end), `${f} ${path}: text after`);
            assert.deepEqual(toValue(next.root), setAt(original, path, 'Ä "x" \\ \n'), `${f} ${path}`);
        }
    }
});

test('setting a value back restores the original text exactly', () => {
    for (const f of files) {
        const text = read(f);
        const doc = parseDoc(text);
        // sample: every 7th string keeps the run short on the big surveys
        stringLeaves(doc).filter((_, i) => i % 7 === 0).forEach(([path, node]) => {
            const there = setValue(doc, path, 'changed');
            assert.equal(setValue(there, path, node.value).text, text, `${f} ${path}`);
        });
    }
});

test('keeps tabs, CRLF and compact lists as they are', () => {
    const text = '{\r\n\t"a": [\r\n\t\t{"value": "x", "text": "X"},\r\n\t\t{"value": "y", "text": "Y"}\r\n\t],\r\n\t"b": "old"\r\n}';
    const doc = parseDoc(text);
    assert.equal(setValue(doc, ['b'], 'new').text, text.replace('"old"', '"new"'));
    // new lines use the line ending and indentation of the document, never a mix
    assert.equal(insertMember(doc, [], 'c', true).text, text.replace('"old"', '"old",\r\n\t"c": true'));
    assert.equal(insertMember(doc, [], 'c', { d: 1 }).text, text.replace('"old"', '"old",\r\n\t"c": {\r\n\t\t"d": 1\r\n\t}'));
    assert.equal(insertItem(doc, ['a'], 2, 7).text, text.replace('"Y"}', '"Y"},\r\n\t\t7'));
});

test('insertMember follows the layout of the object (multi-line, compact, empty)', () => {
    const multi = parseDoc('{\n  "a": 1,\n  "b": {\n    "c": 2\n  }\n}');
    assert.equal(insertMember(multi, ['b'], 'd', 'x').text, '{\n  "a": 1,\n  "b": {\n    "c": 2,\n    "d": "x"\n  }\n}');
    assert.equal(insertMember(multi, [], 'e', { f: [1] }).text, '{\n  "a": 1,\n  "b": {\n    "c": 2\n  },\n  "e": {\n    "f": [\n      1\n    ]\n  }\n}');
    const compact = parseDoc('{"value": "AFG", "text": "Afghanistan"}');
    assert.equal(insertMember(compact, [], 'visibleIf', '{a} = 1').text, '{"value": "AFG", "text": "Afghanistan", "visibleIf": "{a} = 1"}');
    assert.equal(insertMember(parseDoc('{"o": {}}'), ['o'], 'k', 1).text, '{"o": {\n  "k": 1\n}}');
    assert.equal(insertMember(multi, [], 'a', 9).text, '{\n  "a": 9,\n  "b": {\n    "c": 2\n  }\n}', 'existing key is replaced');
});

test('removeMember leaves valid JSON with the neighbours untouched', () => {
    const doc = parseDoc('{\n  "a": 1,\n  "b": 2,\n  "c": 3\n}');
    assert.equal(removeMember(doc, [], 'a').text, '{\n  "b": 2,\n  "c": 3\n}');
    assert.equal(removeMember(doc, [], 'b').text, '{\n  "a": 1,\n  "c": 3\n}');
    assert.equal(removeMember(doc, [], 'c').text, '{\n  "a": 1,\n  "b": 2\n}');
    assert.equal(removeMember(parseDoc('{"only": 1}'), [], 'only').text, '{}');
    assert.equal(removeMember(doc, [], 'missing').text, doc.text);
    assert.equal(removeMember(parseDoc('{"a":1,"a":2,"b":3}'), [], 'a').text, '{"b":3}', 'duplicates go together');
});

test('insertMember then removeMember at the end restores the text', () => {
    for (const f of files.filter((x) => x !== 'bs.json')) {
        const text = read(f);
        const doc = parseDoc(text);
        const back = removeMember(insertMember(doc, [], 'zzz', { a: 1 }), [], 'zzz');
        assert.equal(back.text, text, f);
    }
});

test('insertItem / removeItem keep the layout of the array', () => {
    const multi = parseDoc('{\n  "l": [\n    1,\n    2\n  ]\n}');
    assert.equal(insertItem(multi, ['l'], 2, 3).text, '{\n  "l": [\n    1,\n    2,\n    3\n  ]\n}');
    assert.equal(insertItem(multi, ['l'], 0, 0).text, '{\n  "l": [\n    0,\n    1,\n    2\n  ]\n}');
    assert.equal(insertItem(multi, ['l'], 1, { a: 1 }).text, '{\n  "l": [\n    1,\n    {\n      "a": 1\n    },\n    2\n  ]\n}');
    assert.equal(removeItem(multi, ['l'], 0).text, '{\n  "l": [\n    2\n  ]\n}');
    assert.equal(removeItem(multi, ['l'], 1).text, '{\n  "l": [\n    1\n  ]\n}');
    const compact = parseDoc('{"l": [1, 2, 3]}');
    assert.equal(insertItem(compact, ['l'], 3, 4).text, '{"l": [1, 2, 3, 4]}');
    assert.equal(insertItem(compact, ['l'], 1, 9).text, '{"l": [1, 9, 2, 3]}');
    assert.equal(removeItem(compact, ['l'], 1).text, '{"l": [1, 3]}');
    assert.equal(removeItem(parseDoc('{"l": [1]}'), ['l'], 0).text, '{"l": []}');
    assert.equal(insertItem(parseDoc('{"l": []}'), ['l'], 0, 1).text, '{"l": [\n  1\n]}');
    assert.throws(() => insertItem(compact, ['l'], 9, 1), RangeError);
});

test('every edit result parses with JSON.parse (no broken text)', () => {
    for (const f of files) {
        const doc = parseDoc(read(f));
        const arrays = [];
        const walk = (n, path) => {
            if (n.type === 'array') { arrays.push([path, n]); n.items.forEach((x, i) => walk(x, [...path, i])); }
            if (n.type === 'object') { n.members.forEach((m) => walk(m.node, [...path, m.key])); }
        };
        walk(doc.root, []);
        for (const [path, arr] of arrays.slice(0, 40)) {
            const a = insertItem(doc, path, 0, { type: 'text', name: 'neu' });
            assert.equal(valueAt(a, [...path, 0]).name, 'neu', `${f} ${path}`);
            JSON.parse(a.text);
            const b = removeItem(doc, path, arr.items.length - 1);
            JSON.parse(b.text);
            assert.equal(valueAt(b, path).length, arr.items.length - 1, `${f} ${path}`);
        }
    }
});

test('nodeAt returns undefined for paths that do not exist', () => {
    const doc = parseDoc('{"a": [1]}');
    assert.equal(nodeAt(doc, ['a', 5]), undefined);
    assert.equal(nodeAt(doc, ['b']), undefined);
    assert.equal(nodeAt(doc, ['a', 'x']), undefined);
});

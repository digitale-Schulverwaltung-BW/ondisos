// Tests for the survey model (outline, titles). Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { loadModel, outline, flatten, setTitle, displayText, formatPath } from '../../public/assets/survey-builder/model.js';
import { valueAt } from '../../public/assets/survey-builder/json-doc.js';

const require = createRequire(import.meta.url);
// The runtime that shows the forms (the same file the frontend ships): the outline must agree with what it builds.
const Survey = require('../../../frontend/public/assets/survey.core.min.js');

const SURVEYS = new URL('../../../frontend/surveys/', import.meta.url);
const surveyFiles = readdirSync(SURVEYS).filter((f) => f.endsWith('.json') && f !== 'survey_theme.json').sort();
const read = (f) => readFileSync(new URL(f, SURVEYS), 'utf8');

function load(text) {
    const m = loadModel(text);
    assert.ok(m.ok, JSON.stringify(m.error));
    return m;
}

/** Lines that differ between two texts that have the same number of lines. */
function changedLines(a, b) {
    const x = a.split('\n');
    const y = b.split('\n');
    assert.equal(x.length, y.length, 'same number of lines');
    return x.reduce((n, line, i) => n + (line === y[i] ? 0 : 1), 0);
}

test('the outline lists the same questions in the same order as SurveyJS builds them', () => {
    for (const f of surveyFiles) {
        const text = read(f);
        const { doc } = load(text);
        const fromModel = flatten(outline(doc)).filter((e) => e.type !== 'panel').map((e) => e.name);
        const fromSurveyJs = new Survey.Model(JSON.parse(text)).getAllQuestions(false).map((q) => q.name);
        assert.deepEqual(fromModel, fromSurveyJs, f);
        assert.ok(fromModel.length > 0, f);
    }
});

test('the outline gets pages, panels and types right', () => {
    const { doc } = load(read('bs.json'));
    const pages = outline(doc);
    assert.equal(pages.length, JSON.parse(read('bs.json')).pages.length);
    const all = flatten(pages);
    assert.ok(all.some((e) => e.type === 'panel' && e.children.length > 0), 'a panel with children');
    assert.ok(all.every((e) => typeof e.type === 'string' && Array.isArray(e.path)));
    assert.deepEqual(valueAt(doc, all[0].path).name, all[0].name, 'paths point at the element');
});

test('a survey with elements at the top level has one implicit page', () => {
    const { doc } = load('{"elements":[{"type":"text","name":"a","title":"A"}]}');
    const pages = outline(doc);
    assert.equal(pages.length, 1);
    assert.equal(pages[0].implicit, true);
    assert.deepEqual(pages[0].elements.map((e) => e.name), ['a']);
});

test('unknown element types are listed, not rejected', () => {
    const { doc } = load('{"pages":[{"elements":[{"type":"mywidget","name":"w","x":1},{"name":"notype"}]}]}');
    const [page] = outline(doc);
    assert.deepEqual(page.elements.map((e) => e.type), ['mywidget', '(unknown)']);
});

test('setTitle changes only the title: every element and page of every survey, byte-exact', () => {
    for (const f of surveyFiles) {
        const text = read(f);
        const { doc } = load(text);
        const targets = [...outline(doc), ...flatten(outline(doc))].filter((x) => !x.implicit);
        for (const t of targets) {
            const had = valueAt(doc, [...t.path, 'title']);
            const next = setTitle(doc, t.path, 'Neuer Titel');
            const read2 = outline(next).concat(flatten(outline(next))).find((x) => formatPath(x.path) === formatPath(t.path));
            assert.equal(read2.title, 'Neuer Titel', `${f} ${formatPath(t.path)}`);
            // localized titles: "default" changes (or "de" when there is no "default"), the other languages stay as they are
            if (had && typeof had === 'object') {
                const now = valueAt(next, [...t.path, 'title']);
                const edited = 'default' in had || !('de' in had) ? 'default' : 'de';
                assert.equal(now[edited], 'Neuer Titel', `${f} ${formatPath(t.path)} ${edited}`);
                for (const [k, v] of Object.entries(had)) { if (k !== edited) { assert.equal(now[k], v, `${f} ${formatPath(t.path)} ${k}`); } }
            }
            // everything outside the title is untouched: put the title back and the text is the original again
            if (typeof had === 'string' || (had && typeof had === 'object')) {
                const back = setTitle(next, t.path, displayText(had));
                assert.equal(back.text, text, `${f} ${formatPath(t.path)} restores the text`);
            }
        }
    }
});

test('one title in the hand-formatted bs.json changes one line, not the whole file', () => {
    const text = read('bs.json');
    const { doc } = load(text);
    const target = flatten(outline(doc)).find((e) => typeof valueAt(doc, [...e.path, 'title']) === 'string');
    const next = setTitle(doc, target.path, 'Anderer Titel');
    assert.equal(changedLines(text, next.text), 1);
    // re-serializing instead would touch most of the file (the reason for the lossless layer)
    const reformatted = JSON.stringify(JSON.parse(text), null, 2);
    assert.ok(reformatted.split('\n').length > 1.5 * text.split('\n').length, 'JSON.stringify would reformat the file');
});

test('a localized title keeps its other languages; "default" is the one that changes', () => {
    const { doc } = load('{"elements":[{"type":"text","name":"a","title":{"de":"DE","default":"STD","fr":"FR"}}]}');
    const next = setTitle(doc, ['elements', 0], 'NEU');
    assert.deepEqual(valueAt(next, ['elements', 0, 'title']), { de: 'DE', default: 'NEU', fr: 'FR' });
    const onlyDe = load('{"elements":[{"type":"text","name":"a","title":{"de":"DE"}}]}').doc;
    assert.deepEqual(valueAt(setTitle(onlyDe, ['elements', 0], 'NEU'), ['elements', 0, 'title']), { de: 'NEU' });
});

test('an element without a title gets one, in the layout of its neighbours', () => {
    const { doc } = load('{\n  "elements": [\n    {\n      "type": "text",\n      "name": "a"\n    }\n  ]\n}');
    const next = setTitle(doc, ['elements', 0], 'Hallo');
    assert.equal(next.text, '{\n  "elements": [\n    {\n      "type": "text",\n      "name": "a",\n      "title": "Hallo"\n    }\n  ]\n}');
});

test('loadModel reports errors with a position and warns about duplicate keys', () => {
    const bad = loadModel('{\n  "pages": [,]\n}');
    assert.equal(bad.ok, false);
    assert.equal(bad.error.line, 2);
    assert.equal(loadModel('[1]').ok, false, 'a survey is an object');
    const dup = loadModel('{"title": "a", "title": "b"}');
    assert.equal(dup.ok, true);
    assert.equal(dup.warnings.length, 1);
    assert.match(dup.warnings[0], /title/);
});

test('displayText prefers default, then de', () => {
    assert.equal(displayText('x'), 'x');
    assert.equal(displayText({ de: 'D', default: 'S' }), 'S');
    assert.equal(displayText({ de: 'D' }), 'D');
    assert.equal(displayText({ fr: 'F' }), 'F');
    assert.equal(displayText(undefined), '');
});

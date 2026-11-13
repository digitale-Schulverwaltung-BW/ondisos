// Tests for the fields of the theme editor (3.2.0b AP4). Run: node --test "backend/tests/js/*.test.mjs"
//
// The JSON stays the source: reading and writing back the same values changes nothing (round trip), and a field change
// touches only that field's keys, byte for byte elsewhere.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import {
    readFields, applyField, parseColor, contrastWithWhite, FIELD_NAMES,
    BRAND, BRAND_LEGACY, TITLE, TITLE_LEGACY, TITLE_BRAND, FONT, FONT_LEGACY, FONT_SIZE, LINE_HEIGHT, FONT_SIZE_LEGACY,
    BACKGROUND, RADII, RADIUS_LEGACY, SHADOWS, DEFAULT_SHADOW, FONT_SYSTEM,
} from '../../public/assets/theme-editor/fields.js';

const SURVEYS = new URL('../../../frontend/surveys/', import.meta.url);
const themes = {
    // every theme of the frontend (SurveyJS 2 format), the built-in default (SurveyJS 3) and a Theme Editor export (SurveyJS 2)
    ...Object.fromEntries(readdirSync(SURVEYS).filter((f) => /theme.*\.json$/.test(f)).map((f) => [`frontend/${f}`, readFileSync(new URL(f, SURVEYS), 'utf8')])),
    'templates/theme/default.json': readFileSync(new URL('../../templates/theme/default.json', import.meta.url), 'utf8'),
    'fixtures/theme-editor-export.json': readFileSync(new URL('fixtures/themes/theme-editor-export.json', import.meta.url), 'utf8'),
};

/** The keys a field may touch: [variables, root keys]. */
const OWN = {
    brand: [[BRAND, ...BRAND_LEGACY, TITLE, ...TITLE_LEGACY], []], // the title only where it was the brand colour written out (test below)
    titleBrand: [[TITLE, ...TITLE_LEGACY], []],
    font: [[FONT, ...FONT_LEGACY], []],
    fontSize: [[FONT_SIZE, LINE_HEIGHT, ...FONT_SIZE_LEGACY], []],
    background: [[BACKGROUND], []],
    backgroundImage: [[], ['backgroundImage']],
    backgroundOpacity: [[], ['backgroundOpacity']],
    backgroundImageFit: [[], ['backgroundImageFit']],
    radius: [[...RADII, ...RADIUS_LEGACY], []],
    cards: [SHADOWS, ['isPanelless']],
};
/** A value different from the current one, per field. */
const OTHER = {
    brand: (v) => (v === '#123456' ? '#654321' : '#123456'),
    titleBrand: (v) => !v,
    font: (v) => (v === FONT_SYSTEM ? 'Open Sans' : FONT_SYSTEM),
    fontSize: (v) => (v === 18 ? 20 : 18),
    background: (v) => (v === '#eeeeee' ? '#dddddd' : '#eeeeee'),
    backgroundImage: (v) => (v === '/bild.png' ? '' : '/bild.png'),
    backgroundOpacity: (v) => (v === 0.5 ? 0.8 : 0.5),
    backgroundImageFit: (v) => (v === 'contain' ? 'cover' : 'contain'),
    radius: (v) => (v === 8 ? 0 : 8),
    cards: (v) => !v,
};

function without(text, [vars, keys]) {
    const t = JSON.parse(text);
    keys.forEach((k) => delete t[k]);
    if (t.cssVariables) { vars.forEach((k) => delete t.cssVariables[k]); }
    if (t.cssVariables && Object.keys(t.cssVariables).length === 0) { delete t.cssVariables; }
    return t;
}

for (const [name, text] of Object.entries(themes)) {
    test(`${name}: every field can be read`, () => {
        const r = readFields(text);
        assert.equal(r.ok, true);
        for (const f of FIELD_NAMES) {
            assert.equal(r.fields[f].state, 'ok', `${f}: ${JSON.stringify(r.fields[f])}`);
        }
    });

    test(`${name}: writing back what the fields show changes nothing`, () => {
        const r = readFields(text);
        let out = text;
        for (const f of FIELD_NAMES) { out = applyField(out, f, r.fields[f].value); }
        assert.equal(out, text);
    });

    for (const f of FIELD_NAMES) {
        test(`${name}: changing "${f}" touches only its own keys and reads back`, () => {
            const value = OTHER[f](readFields(text).fields[f].value);
            const out = applyField(text, f, value);
            assert.notEqual(out, text);
            assert.deepEqual(without(out, OWN[f]), without(text, OWN[f]));
            assert.deepEqual(readFields(out).fields[f].value, value);
            assert.equal(readFields(out).fields[f].legacy ?? false, false, 'after a change the field uses the SurveyJS 3 key');
        });
    }
}

test('a change keeps the layout of everything else (only the changed lines differ)', () => {
    const text = themes['templates/theme/default.json'];
    const out = applyField(text, 'brand', '#c81e3c');
    const a = text.split('\n');
    const b = out.split('\n');
    assert.equal(a.length, b.length);
    assert.deepEqual(a.map((l, i) => (l === b[i] ? null : i)).filter((i) => i !== null).length, 1);
    assert.ok(out.includes(`"${BRAND}": "#c81e3c"`));
});

test('a SurveyJS 2 brand colour is shown and replaced on change, with its light and hover tones; the text colour on it stays', () => {
    const text = themes['fixtures/theme-editor-export.json'];
    const r = readFields(text).fields.brand;
    assert.equal(r.value, 'rgba(25, 179, 148, 1)');
    assert.equal(r.legacy, true);

    const vars = JSON.parse(applyField(text, 'brand', '#c81e3c')).cssVariables;
    assert.equal(vars[BRAND], '#c81e3c');
    BRAND_LEGACY.forEach((k) => assert.equal(k in vars, false, k));
    assert.equal(vars['--sjs-primary-forecolor'], 'rgba(255, 255, 255, 1)');
});

test('a SurveyJS 2 title in the brand colour keeps following the brand after a change; otherwise the title stays untouched', () => {
    const legacy = applyField(themes['fixtures/theme-editor-export.json'], 'brand', '#c81e3c');
    assert.equal(readFields(legacy).fields.titleBrand.value, true);
    assert.equal(JSON.parse(legacy).cssVariables[TITLE], TITLE_BRAND);

    const plain = JSON.stringify({ cssVariables: { [BRAND]: '#111111', '--sjs-font-headertitle-color': '#222222' } });
    assert.equal(JSON.parse(applyField(plain, 'brand', '#333333')).cssVariables['--sjs-font-headertitle-color'], '#222222');
});

test('the font size is stored halved in two variables, and replaces the SurveyJS 2 size', () => {
    const text = themes['fixtures/theme-editor-export.json'];
    assert.equal(readFields(text).fields.fontSize.value, 16);
    const vars = JSON.parse(applyField(text, 'fontSize', 18)).cssVariables;
    assert.equal(vars[FONT_SIZE], '9px');
    assert.equal(vars[LINE_HEIGHT], '9px');
    assert.equal(FONT_SIZE_LEGACY[0] in vars, false);
});

test('one radius for all four, replacing the SurveyJS 2 corner radius', () => {
    const vars = JSON.parse(applyField(themes['fixtures/theme-editor-export.json'], 'radius', 10)).cssVariables;
    RADII.forEach((k) => assert.equal(vars[k], '10px'));
    assert.equal(RADIUS_LEGACY[0] in vars, false);
});

test('without cards the card shadows go; with cards again the default shadow comes back', () => {
    const off = applyField(themes['templates/theme/default.json'], 'cards', false);
    const t = JSON.parse(off);
    assert.equal(t.isPanelless, true);
    SHADOWS.forEach((k) => assert.equal(k in t.cssVariables, false, k));
    assert.equal('--sjs2-border-effect-component-action-brand-tertiary-surface-default' in t.cssVariables, true, 'the button shadow is not a card shadow');

    const on = JSON.parse(applyField(off, 'cards', true));
    assert.equal(on.isPanelless, false);
    SHADOWS.forEach((k) => assert.equal(on.cssVariables[k], DEFAULT_SHADOW));
});

test('"title in brand colour" is a reference to the brand variable; off removes it', () => {
    const text = themes['templates/theme/default.json'];
    assert.equal(readFields(text).fields.titleBrand.value, true);
    const off = applyField(text, 'titleBrand', false);
    assert.equal(TITLE in JSON.parse(off).cssVariables, false);
    assert.equal(JSON.parse(applyField(off, 'titleBrand', true)).cssVariables[TITLE], TITLE_BRAND);
});

test('null resets a field to SurveyJS\'s default by removing its keys', () => {
    const text = themes['templates/theme/default.json'];
    const t = JSON.parse(applyField(applyField(text, 'fontSize', null), 'radius', null));
    [FONT_SIZE, LINE_HEIGHT, ...RADII].forEach((k) => assert.equal(k in t.cssVariables, false, k));
    assert.equal(readFields(JSON.stringify(t)).fields.radius.value, null);
});

test('a theme without variables gets them when a field is set', () => {
    const out = applyField('{\n    "themeName": "x"\n}', 'brand', '#112233');
    assert.deepEqual(JSON.parse(out), { themeName: 'x', cssVariables: { [BRAND]: '#112233' } });
    assert.equal(readFields('{}').fields.brand.value, null);
});

test('values the fields cannot show make the field read-only, and writing it is refused', () => {
    const t = (vars, extra = {}) => JSON.stringify({ ...extra, cssVariables: vars });
    assert.equal(readFields(t({ [BRAND]: 'var(--x)' })).fields.brand.state, 'readonly');
    assert.equal(readFields(t({ [BRAND]: 'rgba(1, 2, 3, 0.5)' })).fields.brand.state, 'readonly');
    assert.equal(readFields(t({ [TITLE]: '#333' })).fields.titleBrand.state, 'readonly');
    assert.equal(readFields(t({ [RADII[0]]: '4px', [RADII[1]]: '12px' })).fields.radius.state, 'readonly');
    assert.equal(readFields(t({ [FONT_SIZE]: '8px', [LINE_HEIGHT]: '10px' })).fields.fontSize.state, 'readonly');
    assert.equal(readFields(t({ [FONT_SIZE]: '5px' })).fields.fontSize.state, 'readonly');
    assert.equal(readFields(t({}, { backgroundImageFit: 'tile' })).fields.backgroundImageFit.state, 'readonly');
    assert.equal(readFields(t({ [BRAND]: 'var(--x)' })).fields.brand.raw, 'var(--x)');
    assert.throws(() => applyField(t({ [BRAND]: 'var(--x)' }), 'brand', '#000000'), /read-only/);
});

test('unreadable or wrongly shaped text has no fields', () => {
    assert.equal(readFields('{"a": ').ok, false);
    assert.equal(readFields('[]').reason, 'not_object');
    assert.equal(readFields('{"cssVariables": []}').reason, 'variables_not_object');
    assert.throws(() => applyField('{"a": ', 'brand', '#000'), /not readable/);
});

test('invalid values are refused', () => {
    const text = themes['templates/theme/default.json'];
    assert.throws(() => applyField(text, 'brand', 'rot'));
    assert.throws(() => applyField(text, 'fontSize', 13));
    assert.throws(() => applyField(text, 'font', 'x; color: red'));
    assert.throws(() => applyField(text, 'radius', -1));
    assert.throws(() => applyField(text, 'backgroundOpacity', 2));
});

test('colours: picker form and contrast against white', () => {
    assert.equal(parseColor('rgba(0, 163, 215, 1)').hex, '#00a3d7');
    assert.equal(parseColor('#ABC').hex, '#aabbcc');
    assert.equal(parseColor('rgb(300, 0, 0)'), null);
    assert.equal(parseColor('hsl(0 0% 0%)'), null);
    assert.ok(Math.abs(contrastWithWhite('#000000') - 21) < 0.01);
    assert.ok(Math.abs(contrastWithWhite('#ffffff') - 1) < 0.01);
    assert.ok(contrastWithWhite('rgba(0, 163, 215, 1)') < 4.5, 'the default blue is below AA for normal text');
    assert.ok(contrastWithWhite('#0056b3') >= 4.5);
});

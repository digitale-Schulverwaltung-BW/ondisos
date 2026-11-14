// Prefill links (F6): data in the fragment (#prefill=, base64url) instead of the query; older ?prefill= links
// (plain base64) keep working. Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// survey-handler-base.js is a browser script: load it with a stub of the SurveyJS globals it touches at load time
const source = readFileSync(new URL('../../../frontend/public/js/survey-handler-base.js', import.meta.url), 'utf8');
const context = vm.createContext({
    Survey: { Serializer: { addProperty() {} }, surveyLocalization: {} },
    console: { error() {}, log() {}, warn() {} },
    atob, TextDecoder, URLSearchParams, Uint8Array,
});
vm.runInContext(source + '\n;globalThis.SurveyHandlerBase = SurveyHandlerBase;', context);
const Base = context.SurveyHandlerBase;

const names = new Set(['Ausbildungsbetrieb', 'Ausbilder', 'Klasse']);
const b64url = (obj) => Buffer.from(JSON.stringify(obj)).toString('base64url');
const b64 = (obj) => Buffer.from(JSON.stringify(obj)).toString('base64');
const plain = (v) => JSON.parse(JSON.stringify(v)); // objects from the vm context have another prototype

test('reads base64url data from the fragment', () => {
    const data = Base.readPrefill({ search: '?form=bs', hash: '#prefill=' + b64url({ Ausbildungsbetrieb: 'Müller GmbH' }) }, names);
    assert.deepEqual(plain(data), { Ausbildungsbetrieb: 'Müller GmbH' });
});

test('still reads plain base64 from the query of older links, also when "+" became a space', () => {
    // A value whose base64 contains "+" (URLSearchParams turns it into " ")
    const value = { Ausbilder: '>>>?' };
    const encoded = b64(value);
    assert.ok(encoded.includes('+') || encoded.includes('/'), 'test value should need + or /');
    const data = Base.readPrefill({ search: '?prefill=' + encoded, hash: '' }, names);
    assert.deepEqual(plain(data), value);
});

test('the fragment wins over the query', () => {
    const data = Base.readPrefill({
        search: '?prefill=' + b64({ Klasse: 'alt' }),
        hash: '#prefill=' + b64url({ Klasse: 'neu' }),
    }, names);
    assert.deepEqual(plain(data), { Klasse: 'neu' });
});

test('only question names of the survey are taken', () => {
    const data = Base.readPrefill({ search: '', hash: '#prefill=' + b64url({ Klasse: '5a', _consents: ['x'], fremd: 1 }) }, names);
    assert.deepEqual(plain(data), { Klasse: '5a' });
});

test('plain field=value params still work and ignore unknown params', () => {
    const data = Base.readPrefill({ search: '?form=bs&Klasse=5a&utm_source=x', hash: '' }, names);
    assert.deepEqual(plain(data), { Klasse: '5a' });
});

test('broken or non-object data is ignored', () => {
    assert.equal(Base.readPrefill({ search: '', hash: '#prefill=%%%' }, names), null);
    assert.equal(Base.decodePrefill(Buffer.from('[1,2]').toString('base64url')), null);
    assert.equal(Base.decodePrefill(Buffer.from('"text"').toString('base64url')), null);
});

test('decodes what the PHP side produces (base64url without padding, JSON with \\u escapes)', () => {
    // AnmeldungService::base64UrlEncode(json_encode(['Ausbilder' => 'Jörg'])): json_encode writes the umlaut as \u00f6
    const php = Buffer.from('{"Ausbilder":"J\\u00f6rg"}').toString('base64url');
    assert.deepEqual(plain(Base.decodePrefill(php)), { Ausbilder: 'Jörg' });
});

test('recognises a prefill fragment', () => {
    assert.equal(Base.hasPrefillHash('#prefill=abc'), true);
    assert.equal(Base.hasPrefillHash('#x=1&prefill=abc'), true);
    assert.equal(Base.hasPrefillHash('#abschnitt-2'), false);
    assert.equal(Base.hasPrefillHash(''), false);
});

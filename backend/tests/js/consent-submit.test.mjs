// The server checks required consents (ConsentCheck, 3.2.0) and drops the answers itself. The browser handlers must
// therefore send consent_* fields; removing them made every submission with a required consent fail (found in the
// acceptance test of 3.2.0). Run: node --test "backend/tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const handlers = {
    standalone: '../../../frontend/public/js/survey-handler.js',
    wordpress: '../../../wordpress-plugin/assets/js/survey-handler-wp.js',
};

for (const [name, path] of Object.entries(handlers)) {
    test(`${name} handler does not strip consent_* fields before sending`, () => {
        const source = readFileSync(new URL(path, import.meta.url), 'utf8');
        assert.doesNotMatch(source, /startsWith\(\s*['"]consent_['"]\s*\)/);
    });
}

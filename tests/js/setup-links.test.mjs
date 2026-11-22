import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const crawlers = readFileSync(new URL('../../resources/js/Pages/Brands/Crawlers.vue', import.meta.url), 'utf8');
const show = readFileSync(new URL('../../resources/js/Pages/Brands/Show.vue', import.meta.url), 'utf8');

test('the crawler empty states open integrations, not the identity redirect', () => {
    assert.match(crawlers, /route\('brands\.setup\.integrations'/);
    assert.doesNotMatch(crawlers, /brands\.settings/);
});

test('the brand page opens the measurement plan where settings used to hold it', () => {
    assert.match(show, /route\('brands\.setup\.markets'/);
    assert.doesNotMatch(show, /brands\.settings/);
});

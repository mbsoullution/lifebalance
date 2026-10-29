import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { calculateGuide } from './guide.js';

const fixtureUrl = new URL('../tests/fixtures/guide-cases.json', import.meta.url);
const fixture = JSON.parse(readFileSync(fixtureUrl, 'utf8'));

test('JavaScript guide matches the PHP fixture', () => {
  assert.equal(fixture.formula_version, 'v1');
  for (const item of fixture.cases) {
    const result = calculateGuide(item.mode, item.answers);
    assert.deepEqual(
      result,
      { guide: item.guide, answered: item.answered, precise: item.precise },
      item.name,
    );
  }
});

import assert from 'node:assert/strict';
import test from 'node:test';
import { selectScenario } from './proposed-dependencies.mjs';

const manifest = { require: { php: '^8.2', 'illuminate/support': '^12.0|^13.0|^14.0' }, 'require-dev': { 'pestphp/pest': '^3.0|^4.0' } };

test('forces the proposed Laravel branch while preserving every other manifest setting', () => {
    const selected = selectScenario(manifest, { section: 'require', package: 'illuminate/support', constraint: '^14.0' });
    assert.deepEqual(selected, { require: { php: '^8.2', 'illuminate/support': '^14.0' }, 'require-dev': { 'pestphp/pest': '^3.0|^4.0' } });
    assert.equal(manifest.require['illuminate/support'], '^12.0|^13.0|^14.0');
});

test('normal CI resolves the unmodified manifest when no update is proposed', () => {
    assert.deepEqual(selectScenario(manifest, { section: '', package: '', constraint: '' }), manifest);
});

test('rejects a target range absent from the proposed manifest', () => {
    assert.throws(() => selectScenario(manifest, { section: 'require', package: 'illuminate/support', constraint: '^15.0' }));
});

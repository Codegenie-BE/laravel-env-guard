import assert from 'node:assert/strict';

const workflows = new Set(['tests', 'distribution', 'release', 'renovate-automerge'].map(name => `.github/workflows/${name}.yml`));
const sections = ['require', 'require-dev'];

// Deliberately restricted to the registry ranges this library maintains.
// An unfamiliar syntax stays open for human review rather than widening the gate.
function alternatives(value) {
    assert.equal(typeof value, 'string');
    const ranges = value.split(/\|\|?/).map(part => part.trim());
    assert(ranges.length > 0 && ranges.every(part => /^[~^]\d+(?:\.\d+){0,2}$/.test(part)), 'Unsupported Composer range');
    return ranges;
}

function normalizeManifest(before, after) {
    const left = structuredClone(before);
    const right = structuredClone(after);
    for (const section of sections) {
        for (const name of Object.keys(before[section] ?? {})) {
            if (!name.includes('/')) continue; // PHP/extensions are compatibility policy, not automatic upgrades.
            const oldRanges = alternatives(before[section][name]);
            const newRanges = alternatives(after[section]?.[name]);
            assert(oldRanges.every(range => newRanges.includes(range)), 'Existing support was removed');
            left[section][name] = right[section][name] = 'VERSION';
        }
    }
    assert.deepEqual(left, right, 'Manifest changed more than existing dependency ranges');
}

function normalizeWorkflow(text) {
    return text.replace(/^(\s*-?\s*uses:\s*[\w.-]+\/[\w./-]+)@[a-f0-9]{40}(?:[ \t]+#[ \t]*v\d+(?:\.\d+){0,2})?[ \t]*$/gm, '$1@VERSION');
}

export function dependencyOnly(files) {
    try {
        assert(Array.isArray(files) && files.length > 0 && files.length < 100);
        for (const file of files) {
            assert.equal(file.status, 'modified');
            assert.equal(typeof file.before, 'string');
            assert.equal(typeof file.after, 'string');
            if (file.filename === 'composer.json') normalizeManifest(JSON.parse(file.before), JSON.parse(file.after));
            else {
                assert(workflows.has(file.filename), 'Not a maintained dependency workflow');
                assert.equal(normalizeWorkflow(file.before), normalizeWorkflow(file.after), 'Workflow logic changed');
            }
        }
        return true;
    } catch { return false; }
}

export function dependencyScenarios(before, after) {
    const scenarios = [];
    for (const section of sections) {
        for (const [name, value] of Object.entries(after[section] ?? {})) {
            if (!name.includes('/') || value === before[section]?.[name]) continue;
            const oldRanges = before[section]?.[name] ? alternatives(before[section][name]) : [];
            for (const constraint of alternatives(value)) {
                if (!oldRanges.includes(constraint)) scenarios.push({ section, package: name, constraint });
            }
        }
    }
    assert(scenarios.length <= 20, 'Too many proposed dependency scenarios; review manually');
    return scenarios.length ? scenarios : [{ section: '', package: '', constraint: '' }];
}

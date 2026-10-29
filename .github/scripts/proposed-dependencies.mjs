import assert from 'node:assert/strict';
import { appendFile, readFile, writeFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { dependencyScenarios } from './dependency-policy.mjs';

export function selectScenario(manifest, scenario) {
    const result = structuredClone(manifest);
    if (!scenario.package) {
        assert(scenario.section === '' && scenario.constraint === '');
        return result;
    }
    assert(['require', 'require-dev'].includes(scenario.section));
    assert(/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/.test(scenario.package));
    assert(typeof result[scenario.section]?.[scenario.package] === 'string');
    assert(result[scenario.section][scenario.package].split(/\|\|?/).map(value => value.trim()).includes(scenario.constraint), 'Scenario is not a proposed range');
    result[scenario.section][scenario.package] = scenario.constraint;
    return result;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const manifest = JSON.parse(await readFile('composer.json', 'utf8'));
    const mode = process.argv[2];
    if (mode === 'plan') {
        let before = manifest;
        const base = process.env.PLAN_BASE;
        if (base && /^[a-f0-9]{40}$/.test(base) && !/^0+$/.test(base)) {
            before = JSON.parse(execFileSync('git', ['show', `${base}:composer.json`], { encoding: 'utf8' }));
        } else {
            assert(process.env.GITHUB_EVENT_NAME !== 'pull_request', 'A PR must have a valid comparison base');
        }
        const scenarios = dependencyScenarios(before, manifest).map(scenario => ({
            ...scenario, name: scenario.package ? `${scenario.package} ${scenario.constraint}` : 'manifest',
        }));
        await appendFile(process.env.GITHUB_OUTPUT, `proposed=${JSON.stringify({ include: scenarios })}\n`);
    } else {
        assert.equal(mode, 'select');
        const scenario = JSON.parse(process.env.DEPENDENCY_SCENARIO);
        const result = selectScenario(manifest, scenario);
        await writeFile('composer.json', `${JSON.stringify(result, null, 4)}\n`);
        console.log(scenario.package ? `Testing ${scenario.package} at ${scenario.constraint}` : 'Testing the unmodified manifest');
    }
}

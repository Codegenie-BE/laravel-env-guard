/** Privileged API-only gate. Execute from main, never from a PR checkout. */
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { dependencyOnly, dependencyScenarios } from './dependency-policy.mjs';

const shaPattern = /^[a-f0-9]{40}$/;
const workflowNames = ['tests', 'distribution'];

function eligible(pr, sha, repository) {
    return pr.state === 'open' && !pr.draft && pr.user?.type === 'Bot'
        && pr.user.login === 'renovate[bot]' && pr.base?.ref === 'main'
        && pr.base.repo?.full_name === repository && pr.head?.repo?.full_name === repository
        && pr.head.ref.startsWith('renovate/') && pr.head.sha === sha
        && Number.isSafeInteger(pr.number) && pr.number > 0
        && !(pr.labels ?? []).some(({ name }) => ['hold', 'do-not-merge'].includes(name));
}

function completeList(response, key) {
    assert(Array.isArray(response[key]) && response[key].length < 100
        && response.total_count === response[key].length, `Incomplete ${key} response`);
    return response[key];
}

function requiredTestJobs(scenarios) {
    const jobs = new Map([
        ['CI plan', ['Determine required coverage']],
        ['Dependency automation', ['Test dependency automation']],
        ['Quality / Linux x64 / PHP 8.5 / Laravel 13', ['Install dependencies', 'Run fast quality gate']],
        ['Dependency review', ['Review dependency changes']],
        ['Coverage / PHP 8.5 / Laravel 13', ['Install dependencies', 'Enforce coverage baseline']],
        ['CI gate', ['Verify every required job succeeded or was intentionally skipped']],
        ['Required CI', ['Preserve the current ruleset check during migration']],
    ]);
    for (const [php, laravel] of [['8.2', '12'], ['8.3', '12'], ['8.4', '12'], ['8.5', '12'], ['8.3', '13'], ['8.4', '13'], ['8.5', '13']]) {
        jobs.set(`Compatibility / Linux x64 / PHP ${php} / Laravel ${laravel}`, ['Install dependencies', 'Run package tests']);
    }
    for (const [php, laravel] of [['8.2', '12'], ['8.3', '13']]) {
        jobs.set(`Minimum dependencies / PHP ${php} / Laravel ${laravel}`, ['Install minimum supported dependencies', 'Validate minimum dependency runtime']);
        jobs.set(`E2E / Linux x64 / PHP ${php} / Laravel ${laravel}`, ['Install package development dependencies', 'Run fresh Laravel application scenarios']);
    }
    for (const platform of ['Windows x64', 'macOS ARM64', 'Linux ARM64']) {
        jobs.set(`Portability / ${platform} / PHP 8.5 / Laravel 13`, ['Install dependencies', 'Run package tests']);
    }
    for (const scenario of scenarios) {
        const name = scenario.package ? `${scenario.package} ${scenario.constraint}` : 'manifest';
        jobs.set(`Proposed dependencies / ${name}`, ['Select proposed dependency range', 'Install proposed dependencies', 'Test proposed dependencies']);
    }
    return jobs;
}

function verifyJobs(jobs, required, sha) {
    assert(jobs.length === required.size, 'Missing or unexpected validation jobs');
    for (const [name, steps] of required) {
        const matches = jobs.filter(job => job.name === name && job.head_sha === sha);
        assert(matches.length === 1 && matches[0].conclusion === 'success', `Required job did not pass: ${name}`);
        for (const step of steps) {
            assert(matches[0].steps?.some(item => item.name === step && item.conclusion === 'success'), `Missing/skipped step: ${name}: ${step}`);
        }
    }
}

function verifyStatuses(response) {
    assert(Array.isArray(response.statuses) && response.statuses.length < 100
        && response.total_count === response.statuses.length, 'Incomplete commit statuses');
    assert(response.statuses.every(status => typeof status.context === 'string'
        && ['success', 'pending', 'failure', 'error'].includes(status.state)
        && (!status.context.startsWith('renovate/') || status.state === 'success')), 'Renovate status is pending or failed');
}

function verifyProtection(ruleset) {
    assert(ruleset.enforcement === 'active' && ruleset.bypass_actors?.length === 0
        && ruleset.conditions?.ref_name?.include?.includes('~DEFAULT_BRANCH')
        && ruleset.conditions.ref_name.exclude?.length === 0, 'Main protection changed');
    assert(ruleset.rules.some(rule => rule.type === 'pull_request')
        && ruleset.rules.some(rule => rule.type === 'required_linear_history')
        && ruleset.rules.some(rule => rule.type === 'required_status_checks'
            && rule.parameters.strict_required_status_checks_policy === true
            && rule.parameters.required_status_checks.some(check => check.context === 'CI gate' && check.integration_id === 15368)), 'Strict CI protection is required for race-safe squash merges');
}

export async function mergeForEvent({ api, repository, event, log = console.log }) {
    assert.equal(repository, 'Codegenie-BE/laravel-env-guard');
    let sha;
    if (event.workflow_run) {
        // Treat webhook data only as a pointer; all evidence below is fetched again.
        const run = event.workflow_run;
        if (run.event !== 'pull_request' || run.conclusion !== 'success'
            || run.head_repository?.full_name !== repository || !run.head_branch?.startsWith('renovate/')) return;
        sha = run.head_sha;
    } else {
        if (event.state !== 'success' || !event.context?.startsWith('renovate/')) return;
        sha = event.sha;
    }
    assert(shaPattern.test(sha), 'Invalid validation SHA');
    const candidates = await api(`/commits/${sha}/pulls?per_page=100`);
    assert(Array.isArray(candidates) && candidates.length < 100, 'Incomplete candidate PRs');
    const eligiblePRs = candidates.filter(pr => eligible(pr, sha, repository));
    if (eligiblePRs.length !== 1) return log('No unique eligible Renovate PR; nothing to merge.');
    // List endpoints omit changed_files and mergeability; fetch the full PR before use.
    const pr = await api(`/pulls/${eligiblePRs[0].number}`);
    assert(eligible(pr, sha, repository), 'PR changed during discovery');
    const main = (await api('/git/ref/heads/main')).object.sha;
    assert(shaPattern.test(main));
    const comparison = await api(`/compare/${main}...${sha}`);
    assert(comparison.behind_by === 0 && comparison.ahead_by > 0, 'Branch must be up to date with main');
    const files = await api(`/pulls/${pr.number}/files?per_page=100`);
    assert(files.length === pr.changed_files && files.length > 0 && files.length < 100, 'Incomplete changed files');
    const getContent = async (filename, ref) => {
        const response = await api(`/contents/${filename}?ref=${ref}`);
        assert.equal(response.encoding, 'base64');
        return Buffer.from(response.content, 'base64').toString('utf8');
    };
    for (const file of files) {
        // Never fetch arbitrary paths or execute any code obtained from the PR.
        assert(file.filename === 'composer.json' || /^\.github\/workflows\/(tests|distribution|release|renovate-automerge)\.yml$/.test(file.filename), 'Not a dependency file');
        file.before = await getContent(file.filename, main);
        file.after = await getContent(file.filename, sha);
    }
    assert(dependencyOnly(files), 'Diff changes more than dependency versions or action pins');
    const before = JSON.parse(await getContent('composer.json', main));
    const after = JSON.parse(await getContent('composer.json', sha));
    const scenarios = dependencyScenarios(before, after);

    const latestRuns = async () => {
        const results = [];
        for (const workflow of workflowNames) {
            const response = await api(`/actions/workflows/${workflow}.yml/runs?event=pull_request&head_sha=${sha}&per_page=100`);
            const runs = completeList(response, 'workflow_runs').filter(run => run.head_sha === sha);
            const run = runs.sort((a, b) => b.id - a.id)[0];
            assert(run && Number.isSafeInteger(run.id) && Number.isSafeInteger(run.run_attempt)
                && run.path === `.github/workflows/${workflow}.yml` && run.event === 'pull_request'
                && run.head_sha === sha && run.head_branch === pr.head.ref && run.head_repository?.full_name === repository
                && run.status === 'completed' && run.conclusion === 'success', `Latest ${workflow} validation did not pass`);
            results.push(run);
        }
        return results;
    };
    const runs = await latestRuns();
    for (let index = 0; index < runs.length; index++) {
        const run = runs[index];
        const jobs = completeList(await api(`/actions/runs/${run.id}/attempts/${run.run_attempt}/jobs?per_page=100`), 'jobs');
        const required = index === 0 ? requiredTestJobs(scenarios) : new Map([
            ['Composer archive / PHP 8.5 / Laravel 13', ['Build Composer release archive', 'Validate release archive contents', 'Install archive into a fresh Laravel application']],
        ]);
        verifyJobs(jobs, required, sha);
    }
    verifyStatuses(await api(`/commits/${sha}/status?per_page=100`));
    const reviews = await api(`/pulls/${pr.number}/reviews?per_page=100`);
    assert(Array.isArray(reviews) && reviews.length < 100, 'Incomplete reviews');
    const decisions = new Map();
    for (const review of reviews) {
        if (['APPROVED', 'CHANGES_REQUESTED', 'DISMISSED'].includes(review.state)) decisions.set(review.user.login, review.state);
    }
    assert(![...decisions.values()].includes('CHANGES_REQUESTED'), 'Reviewer requested changes');
    const [owner, name] = repository.split('/');
    const threadsResponse = await api('/graphql', { method: 'POST', body: {
        query: 'query($owner:String!,$name:String!,$number:Int!){repository(owner:$owner,name:$name){pullRequest(number:$number){reviewThreads(first:100){totalCount nodes{isResolved} pageInfo{hasNextPage}}}}}',
        variables: { owner, name, number: pr.number },
    } });
    assert(!threadsResponse.errors, 'Unable to read review conversations');
    const threads = threadsResponse.data?.repository?.pullRequest?.reviewThreads;
    assert(threads && threads.pageInfo?.hasNextPage === false && threads.nodes?.length === threads.totalCount
        && threads.nodes.every(thread => thread.isResolved === true), 'Unresolved or incomplete review conversations');
    verifyProtection(await api('/rulesets/20992379'));
    const fresh = await api(`/pulls/${pr.number}`);
    assert(eligible(fresh, sha, repository) && fresh.mergeable === true && fresh.mergeable_state === 'clean', 'PR changed or is blocked');
    assert.deepEqual((await latestRuns()).map(run => [run.id, run.run_attempt]), runs.map(run => [run.id, run.run_attempt]), 'Validation changed during verification');
    assert.equal((await api('/git/ref/heads/main')).object.sha, main, 'Main moved; rebase and retest');
    // GitHub atomically enforces strict, no-bypass branch protection; sha guards the PR head.
    const result = await api(`/pulls/${pr.number}/merge`, { method: 'PUT', body: { sha, merge_method: 'squash' } });
    assert.equal(result.merged, true, 'GitHub refused the protected squash merge');
    log(`Squash-merged verified Renovate PR #${pr.number}.`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const repository = process.env.GITHUB_REPOSITORY;
    const token = process.env.GH_TOKEN;
    assert(repository === 'Codegenie-BE/laravel-env-guard' && token, 'Missing GitHub environment');
    const event = JSON.parse(await readFile(process.env.GITHUB_EVENT_PATH, 'utf8'));
    const api = async (path, options = {}) => {
        const url = path === '/graphql' ? 'https://api.github.com/graphql' : `https://api.github.com/repos/${repository}${path}`;
        const response = await fetch(url, {
            method: options.method ?? 'GET', redirect: 'error', signal: AbortSignal.timeout(20000),
            headers: { Accept: 'application/vnd.github+json', Authorization: `Bearer ${token}`, 'X-GitHub-Api-Version': '2022-11-28', 'Content-Type': 'application/json' },
            body: options.body ? JSON.stringify(options.body) : undefined,
        });
        assert(response.ok, `GitHub ${response.status}: ${path}`);
        return response.json();
    };
    await mergeForEvent({ api, repository, event });
}

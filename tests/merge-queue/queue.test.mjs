import test from 'node:test';
import assert from 'node:assert/strict';
import {
    checkHealth,
    eligible,
    githubClient,
    mainHealth,
    reconcile,
    reviewedForSnapshot,
    validateProtection,
} from '../../scripts/merge-queue/queue.mjs';

const head = 'a'.repeat(40),
    base = 'b'.repeat(40),
    merge = 'c'.repeat(40);
const authors = new Set(['Nils-Fredrik']);
const protection = () => ({
    required_status_checks: {
        strict: true,
        checks: [
            { context: 'test', app_id: 15368 },
            { context: 'container build', app_id: 15368 },
            { context: 'agent-review', app_id: 5773706 },
        ],
    },
    enforce_admins: { enabled: true },
    allow_force_pushes: { enabled: false },
    allow_deletions: { enabled: false },
});
const pull = () => ({
    number: 61,
    state: 'open',
    draft: false,
    title: '[Codex] T39-F1 – Test',
    user: { login: 'Nils-Fredrik' },
    author_association: 'OWNER',
    base: { sha: base, ref: 'main', repo: { full_name: 'Nils-Fredrik/ekko' } },
    head: { sha: head, repo: { full_name: 'Nils-Fredrik/ekko' } },
    mergeable: true,
    mergeable_state: 'clean',
    merge_commit_sha: merge,
});
const checks = () => [
    { id: 1, name: 'test', app: { id: 15368 }, status: 'completed', conclusion: 'success' },
    { id: 2, name: 'container build', app: { id: 15368 }, status: 'completed', conclusion: 'success' },
];
const statuses = () => [
    { context: 'agent-review', state: 'success', target_url: 'https://github.com/Nils-Fredrik/ekko/actions/runs/123' },
];
const comment = (override = {}) => ({
    user: { id: 357077641 },
    body: `<!-- ekko-review-round:v1 ${JSON.stringify({ head, base, result: 'success', run: '123', ...override })} -->`,
});
function client({
    pr = pull(),
    checkRuns = checks(),
    statusList = statuses(),
    comments = [comment()],
    protect = protection(),
    movingBase = false,
    changedHead = false,
    changedTitle = false,
    mainConclusion = 'success',
    deployConclusion = 'success',
} = {}) {
    const calls = [];
    let branchReads = 0,
        pullReads = 0;
    return {
        calls,
        async pages(path) {
            if (path.startsWith('/pulls?')) return [pr];
            if (path.includes('/comments')) return comments;
            throw Error(path);
        },
        async api(path, options = {}) {
            calls.push({ path, ...options });
            if (path === '/branches/main/protection') return protect;
            if (path === '/branches/main')
                return { commit: { sha: movingBase && ++branchReads > 1 ? 'd'.repeat(40) : base } };
            if (path.startsWith('/actions/workflows/ci.yml/runs?'))
                return {
                    total_count: 1,
                    workflow_runs: [
                        { id: 123, head_sha: base, event: 'push', status: 'completed', conclusion: mainConclusion },
                    ],
                };
            if (path === '/actions/runs/123/jobs?per_page=100')
                return {
                    total_count: 3,
                    jobs: ['test', 'container build', 'deploy'].map((name, id) => ({
                        id,
                        name,
                        status: 'completed',
                        conclusion: name === 'deploy' ? deployConclusion : 'success',
                    })),
                };
            if (path === '/pulls/61') {
                pullReads++;
                if (changedHead && pullReads > 1) return { ...pr, head: { ...pr.head, sha: 'd'.repeat(40) } };
                if (changedTitle && pullReads > 1) return { ...pr, title: '[Claude Code] T39 – Changed reviewer' };
                return pr;
            }
            if (path.includes('/check-runs')) return { total_count: checkRuns.length, check_runs: checkRuns };
            if (path.includes('/status?')) return { statuses: statusList };
            if (path.endsWith('/update-branch')) return { message: 'queued' };
            if (path.endsWith('/merge')) return { merged: true };
            throw Error(path);
        },
    };
}
const mutations = (c) => c.calls.filter((x) => x.method);

test('requires exact protected status sources and administrators are not exempt', () => {
    validateProtection(protection());
    const p = protection();
    p.required_status_checks.checks[2].app_id = 15368;
    assert.throws(() => validateProtection(p));
    const q = protection();
    q.enforce_admins.enabled = false;
    assert.throws(() => validateProtection(q));
});

test('rejects drafts, forks, untrusted authors and unknown titles', () => {
    const p = pull();
    assert.equal(eligible(p, authors), true);
    assert.equal(eligible({ ...p, draft: true }, authors), false);
    assert.equal(eligible({ ...p, head: { ...p.head, repo: { full_name: 'attacker/fork' } } }, authors), false);
    assert.equal(eligible({ ...p, user: { login: 'stranger' } }, authors), false);
    assert.equal(eligible({ ...p, title: 'urgent fix' }, authors), false);
});

test('only the newest successful required checks from GitHub Actions count', () => {
    assert.equal(checkHealth(checks(), statuses()), 'success');
    assert.equal(
        checkHealth(
            [...checks(), { id: 4, name: 'test', app: { id: 15368 }, status: 'completed', conclusion: 'failure' }],
            statuses(),
        ),
        'failure',
    );
    assert.equal(checkHealth(checks().slice(0, 1), statuses()), 'pending');
    assert.equal(checkHealth(checks(), [{ context: 'agent-review', state: 'pending' }]), 'pending');
    assert.equal(
        checkHealth(
            [
                ...checks(),
                { id: 9, name: 'security scan', app: { id: 42 }, status: 'completed', conclusion: 'failure' },
            ],
            statuses(),
        ),
        'failure',
    );
    assert.equal(
        checkHealth(
            [...checks(), { id: 9, name: 'deploy', app: { id: 15368 }, status: 'completed', conclusion: 'skipped' }],
            statuses(),
        ),
        'success',
    );
});

test('review receipt must match the exact head, base, bot identity and status run', () => {
    assert.equal(reviewedForSnapshot([comment()], statuses(), head, base), true);
    assert.equal(reviewedForSnapshot([comment({ base: 'd'.repeat(40) })], statuses(), head, base), false);
    assert.equal(reviewedForSnapshot([{ ...comment(), user: { id: 1 } }], statuses(), head, base), false);
    assert.equal(reviewedForSnapshot([comment({ run: '124' })], statuses(), head, base), false);
    assert.equal(reviewedForSnapshot([comment(), comment({ result: 'failure' })], statuses(), head, base), false);
});

test('a behind PR is updated once with an optimistic head lease and never merged in the same run', async () => {
    const c = client({ pr: { ...pull(), mergeable_state: 'behind', base: { ...pull().base, sha: 'd'.repeat(40) } } });
    assert.deepEqual(await reconcile(c, authors), { action: 'updated', number: 61 });
    assert.deepEqual(mutations(c), [
        { path: '/pulls/61/update-branch', method: 'PUT', body: { expected_head_sha: head } },
    ]);
});

test('does not update a behind PR after its title or head changes', async () => {
    for (const change of [{ changedHead: true }, { changedTitle: true }]) {
        const pr = { ...pull(), mergeable_state: 'behind', base: { ...pull().base, sha: 'd'.repeat(40) } };
        const c = client({ pr, ...change });
        assert.equal((await reconcile(c, authors)).action, 'retry');
        assert.deepEqual(mutations(c), []);
    }
});

test('clean reviewed PR is merged with its exact head and protection is rechecked', async () => {
    const c = client();
    assert.deepEqual(await reconcile(c, authors), { action: 'merged', number: 61 });
    assert.deepEqual(mutations(c), [
        { path: '/pulls/61/merge', method: 'PUT', body: { sha: head, merge_method: 'merge' } },
    ]);
    assert.equal(c.calls.filter((x) => x.path === '/branches/main/protection').length, 2);
});

test('does not merge on failures, stale approval, conflicts or pending checks', async () => {
    for (const [opts, result] of [
        [{ checkRuns: checks().slice(0, 1) }, 'pending'],
        [{ statusList: [{ context: 'agent-review', state: 'failure' }] }, 'failure'],
        [{ comments: [comment({ base: 'd'.repeat(40) })] }, 'review'],
        [{ pr: { ...pull(), mergeable: false, mergeable_state: 'dirty' } }, 'conflict'],
    ]) {
        const c = client(opts);
        assert.equal((await reconcile(c, authors)).action, result);
        assert.deepEqual(mutations(c), []);
    }
});

test('does not merge when main, PR head or reviewer-routing title changes during the checks', async () => {
    for (const opts of [{ movingBase: true }, { changedHead: true }, { changedTitle: true }]) {
        const c = client(opts);
        assert.equal((await reconcile(c, authors)).action, 'retry');
        assert.deepEqual(mutations(c), []);
    }
});

test('an untrusted or draft PR is ignored without mutations', async () => {
    const c = client({ pr: { ...pull(), draft: true } });
    assert.deepEqual(await reconcile(c, authors), { action: 'empty' });
    assert.deepEqual(mutations(c), []);
});

test('waits for the current main CI and stops if deploy failed or was skipped', async () => {
    for (const opts of [
        { mainConclusion: 'failure' },
        { deployConclusion: 'failure' },
        { deployConclusion: 'skipped' },
    ]) {
        const c = client(opts);
        assert.equal(await mainHealth(c, base), 'failure');
        assert.equal((await reconcile(c, authors)).action, 'main-failure');
        assert.deepEqual(mutations(c), []);
    }
    const c = { api: async () => ({ total_count: 0, workflow_runs: [] }) };
    assert.equal(await mainHealth(c, base), 'pending');
});

test('GitHub client fails closed on errors without revealing its token', async () => {
    const c = githubClient('synthetic-private-token', async () => ({ ok: false, status: 403 }));
    await assert.rejects(
        () => c.api('/branches/main/protection'),
        (error) => error.message.includes('403') && !error.message.includes('synthetic-private-token'),
    );
    assert.throws(() => githubClient(''), /Missing/);
});

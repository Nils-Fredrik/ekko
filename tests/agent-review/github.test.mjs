import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { runReview } from '../../scripts/agent-review/github.mjs';
import { postReviewBlocker } from '../../scripts/agent-review/board.mjs';
import { publishClaudeReview, claudeBody } from '../../scripts/agent-review/claude-comment.mjs';
import { claudeVerdict, reviewRounds } from '../../scripts/agent-review/policy.mjs';

const head = 'a'.repeat(40),
    base = 'b'.repeat(40),
    merge = 'c'.repeat(40);
const pr = {
    state: 'open',
    draft: false,
    mergeable: true,
    changed_files: 1,
    head: { sha: head },
    base: { sha: base, ref: 'main' },
    merge_commit_sha: merge,
    title: '[Codex] T42 – Review',
    body: 'task:42',
};
const snapshot = (required = { claude: true, codex: false }) =>
    Buffer.from(JSON.stringify({ head, base, merge, title: pr.title, required })).toString('base64');
const clean = {
    user: { id: 199175422 },
    updated_at: '2026-01-01T00:00:01Z',
    body: `<!-- codex-pull-request-review-summary -->\n<!-- codex-review-state:v1 ${JSON.stringify({ code_review: { status: 'completed', commit_sha: head, has_any_findings: false, has_unresolved_findings: false } })} -->`,
};

async function harness(t, overrides = {}) {
    const dir = await mkdtemp(join(tmpdir(), 'ekko-review-test-'));
    t.after(() => rm(dir, { recursive: true, force: true }));
    const calls = [];
    const env = {
        GITHUB_REPOSITORY: 'Nils-Fredrik/ekko',
        PR_NUMBER: '42',
        GH_TOKEN: 'test-actions-token',
        CODEX_TRIGGER_TOKEN: 'test-trigger-token',
        BOARD_MCP_TOKEN: 'test-board-token',
        GITHUB_OUTPUT: join(dir, 'output'),
        GITHUB_WORKSPACE: dir,
        AGENT_REVIEW_BOT_ID: '12345678',
        RUNNER_TEMP: dir,
        GITHUB_SERVER_URL: 'https://github.com',
        GITHUB_RUN_ID: '10',
        EVENT_HEAD: head,
        EVENT_BASE: base,
        SNAPSHOT: snapshot(),
        CLAUDE_RESULT: 'success',
        REQUESTED_AT: '2026-01-01T00:00:00Z',
        ...overrides,
    };
    let time = 0;
    const receipt = {
        user: { id: 199175422 },
        created_at: '2026-01-01T00:00:01Z',
        body: `<!-- codex-review-receipt:v1 -->\n**Reviewed commit:** \`${head}\``,
    };
    const state = {
        pr,
        comments: [clean, receipt],
        files: [{ filename: 'README.md' }],
        diff: 'safe diff',
        mutateAfterDiff: false,
    };
    const request = async (url, options = {}) => {
        const body = options.body ? JSON.parse(options.body) : undefined;
        calls.push({ url, options, body });
        if (url === 'https://board.maksimer.com/mcp') {
            if (body.method === 'notifications/initialized') return new Response(null, { status: 202 });
            return Response.json({
                jsonrpc: '2.0',
                id: body.id,
                result: body.method === 'initialize' ? { protocolVersion: '2025-03-26' } : { content: [] },
            });
        }
        if (options.method === 'POST') return Response.json({ created_at: '2026-01-01T00:00:00Z' });
        if (url.includes('/files?')) return Response.json(state.files);
        if (url.includes('/comments?')) return Response.json(state.comments);
        if (url.includes('/reviews?')) return Response.json([]);
        if (options.headers?.accept?.endsWith('.diff')) {
            if (state.mutateAfterDiff) state.pr = { ...pr, head: { sha: 'd'.repeat(40) } };
            return new Response(state.diff);
        }
        return Response.json(state.pulls?.length ? state.pulls.shift() : state.pr);
    };
    return {
        env,
        state,
        calls,
        dir,
        run: (command) =>
            runReview(command, {
                env,
                request,
                now: () => time,
                sleep: async (ms) => {
                    time += ms;
                },
            }),
    };
}

test('request invalidates an existing approval without needing reviewer credentials or PR code', async (t) => {
    const h = await harness(t, { CODEX_TRIGGER_TOKEN: '', BOARD_MCP_TOKEN: '' });
    await h.run('request');
    assert.equal(h.calls.length, 1);
    assert.equal(h.calls[0].body.state, 'pending');
    assert.ok(h.calls[0].url.endsWith(`/statuses/${head}`));
    const invalid = await harness(t, { EVENT_HEAD: 'invalid' });
    await assert.rejects(invalid.run('request'), /Invalid event head/);
    assert.equal(invalid.calls.length, 0);
});

test('prepare binds requirements to the full file list and uses a separate Codex trigger identity', async (t) => {
    const h = await harness(t);
    h.state.files = [{ filename: 'README.md', previous_filename: 'app/Tenancy/Old.php' }];
    await h.run('prepare');
    const output = await readFile(h.env.GITHUB_OUTPUT, 'utf8');
    assert.match(output, /claude=true/);
    assert.match(output, /codex=true/);
    const trigger = h.calls.find((call) => call.body?.body?.startsWith('@codex review'));
    assert.equal(trigger.options.headers.authorization, 'Bearer test-trigger-token');
    assert.ok(h.calls.some((call) => call.body?.state === 'pending' && call.url.endsWith(`/statuses/${head}`)));
});

test('prepare fails closed for stale events, missing credentials and truncated file lists', async (t) => {
    for (const mutation of [
        (h) => {
            h.env.EVENT_HEAD = 'd'.repeat(40);
        },
        (h) => {
            h.state.pr = { ...pr, mergeable: false };
        },
        (h) => {
            h.state.pr = { ...pr, changed_files: 2 };
        },
        (h) => {
            h.state.pr = { ...pr, title: 'Unlabelled' };
        },
    ]) {
        const h = await harness(t);
        mutation(h);
        await assert.rejects(h.run('prepare'));
        assert.ok(!h.calls.some((call) => call.body?.state === 'success'));
    }
});

test('diff detects a push during download and never executes or writes that diff', async (t) => {
    const h = await harness(t);
    h.state.mutateAfterDiff = true;
    await assert.rejects(h.run('diff'), /changed while fetching/);
    await assert.rejects(readFile(join(h.dir, '.review/review.diff')));
});

test('gate approves only the captured merge after both exact-head reviews', async (t) => {
    const h = await harness(t, { SNAPSHOT: snapshot({ claude: true, codex: true }) });
    await h.run('gate');
    assert.ok(h.calls.some((call) => call.body?.state === 'success' && call.url.endsWith(`/statuses/${head}`)));
});

test('gate rejects changed base, title, merge, draft state or failed Claude result', async (t) => {
    for (const change of [
        { base: { ...pr.base, sha: 'd'.repeat(40) } },
        { title: '[Claude Code] T42 – Changed' },
        { merge_commit_sha: 'e'.repeat(40) },
        { draft: true },
        {},
    ]) {
        const h = await harness(t, Object.keys(change).length ? {} : { CLAUDE_RESULT: 'failure' });
        h.state.pr = { ...pr, ...change };
        await assert.rejects(h.run('gate'));
        assert.ok(!h.calls.some((call) => call.body?.state === 'success'));
    }
});

test('a missing or finding-bearing Codex receipt times out without approval', async (t) => {
    const h = await harness(t, { SNAPSHOT: snapshot({ claude: false, codex: true }) });
    h.state.comments = [];
    await assert.rejects(h.run('gate'), /timeout/);
    assert.ok(!h.calls.some((call) => call.body?.state === 'success'));
    assert.ok(h.calls.some((call) => call.body?.state === 'failure'));
});

test('structured Claude verdict requires a genuine conclusion and nonempty review', () => {
    assert.equal(claudeVerdict('{"conclusion":"GODKJENT","summary":"Reviewed full diff"}').conclusion, 'GODKJENT');
    for (const raw of [
        'GODKJENT',
        '{}',
        '{"conclusion":"GODKJENT","summary":""}',
        '{"conclusion":"maybe","summary":"ok"}',
    ])
        assert.throws(() => claudeVerdict(raw));
});

test('three rounds count distinct failed heads, ignore authors, and reset after approval', () => {
    const record = (head, result = 'failure', id = 12345678) => ({
        user: { id },
        body: `<!-- ekko-review-round:v1 ${JSON.stringify({ head, result })} -->`,
    });
    assert.equal(reviewRounds([record(head), record(head), record(base)], 12345678).failures, 2);
    assert.equal(reviewRounds([record(head), record(base), record(merge)], 12345678).failures, 3);
    assert.equal(reviewRounds([record(head), record(base, 'success'), record(merge)], 12345678).failures, 1);
    assert.equal(reviewRounds([record(head, 'failure', 1)], 12345678).failures, 0);
});

test('Board escalation initializes MCP, reads snapshot, and posts a bounded blocker without secrets', async () => {
    const calls = [];
    await postReviewBlocker({
        token: 'synthetic-secret',
        number: 42,
        request: async (url, options) => {
            assert.equal(url, 'https://board.maksimer.com/mcp');
            assert.equal(options.redirect, 'error');
            const body = JSON.parse(options.body);
            calls.push(body);
            if (body.method === 'notifications/initialized') return new Response(null, { status: 202 });
            const result = body.method === 'initialize' ? { protocolVersion: '2025-03-26' } : { content: [] };
            return new Response(
                `event: message\ndata: ${JSON.stringify({ jsonrpc: '2.0', id: body.id, result })}\n\n`,
                { headers: { 'content-type': 'text/event-stream', 'mcp-session-id': 'test-session' } },
            );
        },
    });
    assert.deepEqual(
        calls.map((call) => call.method),
        ['initialize', 'notifications/initialized', 'tools/call', 'tools/call'],
    );
    assert.equal(calls[2].params.arguments.mode, 'snapshot');
    assert.equal(calls[3].params.arguments.type, 'blocker');
    assert.ok(calls[3].params.arguments.body.length <= 500);
    assert.ok(!JSON.stringify(calls).includes('synthetic-secret'));
});

test('third failed code revision posts Board blocker and records successful escalation', async (t) => {
    const h = await harness(t, { CLAUDE_RESULT: 'failure' });
    h.state.comments = [base, merge].map((sha) => ({
        user: { id: 12345678 },
        body: `<!-- ekko-review-round:v1 ${JSON.stringify({ head: sha, result: 'failure' })} -->`,
    }));
    await assert.rejects(h.run('gate'), /Claude review did not approve/);
    assert.equal(h.calls.filter((call) => call.body?.params?.name === 'board_post').length, 1);
    assert.ok(h.calls.some((call) => call.body?.body?.includes('"blocker_sent":true')));
    assert.ok(!h.calls.some((call) => call.body?.state === 'success'));
});

test('Claude publication is bound to the PR and revision and uses the authenticated app identity', async () => {
    const input = { conclusion: 'GODKJENT', summary: 'Reviewed the full diff.' };
    const env = {
        GITHUB_REPOSITORY: 'Nils-Fredrik/ekko',
        PR_NUMBER: '42',
        GH_TOKEN: 'test-claude-app',
        SNAPSHOT: snapshot(),
    };
    const calls = [];
    const request = async (url, options) => {
        calls.push({ url, options });
        if (options.method === 'POST') {
            assert.equal(JSON.parse(options.body).body, claudeBody(input, head));
            return Response.json({ id: 123, user: { id: 209825114 } });
        }
        return Response.json(pr);
    };
    assert.deepEqual(await publishClaudeReview(input, { env, request }), { ...input, comment_id: 123 });
    assert.equal(calls[1].url, 'https://api.github.com/repos/Nils-Fredrik/ekko/issues/42/comments');
    await assert.rejects(publishClaudeReview(input, { env: { ...env, GITHUB_REPOSITORY: 'someone/else' }, request }));
    await assert.rejects(
        publishClaudeReview(input, { env, request: async () => Response.json({ ...pr, head: { sha: base } }) }),
        /changed/,
    );
});

test('the canonical publication result passes verdict unchanged and rejects a rephrased summary', async (t) => {
    const h = await harness(t);
    const captured = JSON.parse(Buffer.from(h.env.SNAPSHOT, 'base64').toString());
    captured.started_at = '2026-01-01T00:00:00Z';
    h.env.SNAPSHOT = Buffer.from(JSON.stringify(captured)).toString('base64');
    const input = { conclusion: 'GODKJENT', summary: 'Reviewed the complete diff.\nNo findings.' };
    let comment;
    const request = async (url, options) => {
        if (options.method === 'POST') {
            comment = {
                id: 123,
                user: { id: 209825114 },
                issue_url: 'https://api.github.com/repos/Nils-Fredrik/ekko/issues/42',
                created_at: '2026-01-01T00:00:01Z',
                body: JSON.parse(options.body).body,
            };
            return Response.json(comment);
        }
        return Response.json(url.endsWith('/issues/comments/123') ? comment : pr);
    };
    const published = await publishClaudeReview(input, { env: h.env, request });
    await runReview('verdict', { env: { ...h.env, REVIEW_OUTPUT: JSON.stringify(published) }, request });
    assert.match(await readFile(h.env.GITHUB_OUTPUT, 'utf8'), /verdict=success/);
    await assert.rejects(
        runReview('verdict', {
            env: { ...h.env, REVIEW_OUTPUT: JSON.stringify({ ...published, summary: 'A rewritten summary.' }) },
            request,
        }),
        /receipt does not match/,
    );
});

test('protection audit rejects unbound status sources and unprotected credentials', () => {
    const fixture = {
        app_id: 12345,
        protection: {
            required_status_checks: {
                strict: true,
                checks: [
                    { context: 'test', app_id: 15368 },
                    { context: 'container build', app_id: 15368 },
                    { context: 'agent-review', app_id: 12345 },
                ],
            },
            enforce_admins: { enabled: true },
        },
        environment: { name: 'agent-review', deployment_branch_policy: { custom_branch_policies: true } },
        policies: { branch_policies: [{ name: 'main', type: 'branch' }] },
    };
    const check = (input) =>
        spawnSync(process.execPath, ['scripts/agent-review/check-protection.mjs'], {
            input: JSON.stringify(input),
            encoding: 'utf8',
        }).status;
    assert.equal(check(fixture), 0);
    assert.notEqual(check({ ...fixture, app_id: 15368 }), 0);
    assert.notEqual(check({ ...fixture, policies: { branch_policies: [{ name: '*', type: 'branch' }] } }), 0);
    const unbound = structuredClone(fixture);
    unbound.protection.required_status_checks.checks[1].app_id = null;
    assert.notEqual(check(unbound), 0);
    const missingBuild = structuredClone(fixture);
    missingBuild.protection.required_status_checks.checks.splice(1, 1);
    assert.notEqual(check(missingBuild), 0);
    const wrongBuildApp = structuredClone(fixture);
    wrongBuildApp.protection.required_status_checks.checks[1].app_id = 12345;
    assert.notEqual(check(wrongBuildApp), 0);
    assert.notEqual(check({ ...fixture, environment: {} }), 0);
});

test('Claude publication refuses environment secrets and recognizable credentials before HTTP', async () => {
    let calls = 0;
    const env = { GH_TOKEN: 'synthetic-credential-value-for-tests' };
    for (const summary of ['Leaked ' + env.GH_TOKEN, 'Token ghs_fake', 'Token github_pat_fake', 'Token sk-ant-fake']) {
        await assert.rejects(
            publishClaudeReview(
                { conclusion: 'GODKJENT', summary },
                {
                    env,
                    request: async () => {
                        calls++;
                    },
                },
            ),
            /credentials/,
        );
    }
    assert.equal(calls, 0);
});

test('the shared GitHub Actions bot cannot forge or reset review rounds', () => {
    const comment = {
        user: { id: 41898282 },
        body: `<!-- ekko-review-round:v1 ${JSON.stringify({ head, result: 'success' })} -->`,
    };
    assert.equal(reviewRounds([comment], 12345678).failures, 0);
    assert.throws(() => reviewRounds([], 41898282));
});

test('ordinary repository metadata is safe to publish while credentials remain blocked', async () => {
    const env = {
        GH_TOKEN: 'synthetic-credential-value-for-tests',
        GITHUB_REPOSITORY: 'Nils-Fredrik/ekko',
        PR_NUMBER: '42',
        SNAPSHOT: snapshot(),
        GITHUB_WORKSPACE: '/home/runner/work/ekko/ekko',
        GITHUB_SHA: base,
        GITHUB_SERVER_URL: 'https://github.com',
        GITHUB_HEAD_REF: 't42-agent-review',
    };
    const summary = `pr:${env.GITHUB_REPOSITORY}#42 ${env.GITHUB_WORKSPACE} ${env.GITHUB_SHA} ${env.GITHUB_SERVER_URL} ${env.GITHUB_HEAD_REF}`;
    const result = await publishClaudeReview(
        { conclusion: 'GODKJENT', summary },
        {
            env,
            request: async (_url, options) =>
                Response.json(options.method === 'POST' ? { id: 123, user: { id: 209825114 } } : pr),
        },
    );
    assert.equal(result.comment_id, 123);
});

test('prepare invalidates earlier approval before mergeability retries and tolerates temporary null', async (t) => {
    const h = await harness(t);
    h.state.pulls = [{ ...pr, mergeable: null }, { ...pr, mergeable: null }, pr];
    await h.run('prepare');
    assert.equal(h.calls[0].body.state, 'pending');
    assert.ok(h.calls[0].url.endsWith(`/statuses/${head}`));
    assert.equal(h.state.pulls.length, 0);
    const failed = await harness(t);
    failed.state.pr = { ...pr, mergeable: null };
    await assert.rejects(failed.run('prepare'), /mergeable/);
    assert.equal(failed.calls[0].body.state, 'pending');
    assert.equal(failed.calls.filter((call) => call.url.endsWith('/pulls/42')).length, 6);
    assert.ok(!failed.calls.some((call) => call.body?.state === 'success'));
});

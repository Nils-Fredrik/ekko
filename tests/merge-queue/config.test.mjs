import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { runInNewContext } from 'node:vm';
import { auditEnvironment, reviewBots, verifyQueueBot } from '../../scripts/merge-queue/config.mjs';

test('only one exact queue App slug extends the existing bot allowlist', () => {
    assert.equal(reviewBots(), 'claude,chatgpt-codex-connector,cursor');
    assert.equal(reviewBots('ekko-queue'), 'claude,chatgpt-codex-connector,cursor,ekko-queue');
    for (const slug of ['*', 'ekko[bot]', 'one,two', 'ekko\nother', '-ekko', 'ekko-']) {
        assert.throws(() => reviewBots(slug));
    }
    verifyQueueBot('ekko-queue', 'ekko-queue');
    for (const configured of ['', undefined, '*', 'different-app']) {
        assert.throws(() => verifyQueueBot(configured, 'ekko-queue'));
    }
    assert.throws(() => verifyQueueBot('ekko-queue', undefined));
});

test('queue credential audit rejects absent environments, wildcards, tags and extra branches', () => {
    const fixture = {
        environment: {
            name: 'merge-queue',
            deployment_branch_policy: { custom_branch_policies: true, protected_branches: false },
        },
        policies: { branch_policies: [{ name: 'main', type: 'branch' }] },
    };
    auditEnvironment(fixture);
    for (const environment of [{}, { ...fixture.environment, name: 'agent-review' }, { name: 'merge-queue' }]) {
        assert.throws(() => auditEnvironment({ ...fixture, environment }));
    }
    for (const branch_policies of [
        [],
        [{ name: '*', type: 'branch' }],
        [{ name: 'main', type: 'tag' }],
        [...fixture.policies.branch_policies, { name: 'feature', type: 'branch' }],
    ]) {
        assert.throws(() => auditEnvironment({ ...fixture, policies: { branch_policies } }));
    }
    const result = spawnSync(process.execPath, ['scripts/merge-queue/config.mjs', 'audit-environment'], {
        input: JSON.stringify(fixture),
        encoding: 'utf8',
    });
    assert.equal(result.status, 0, result.stderr);
});

test('workflows share bot configuration and verify the minted App before mutation', () => {
    const review = readFileSync('.github/workflows/agent-review-execute.yml', 'utf8');
    const queue = readFileSync('.github/workflows/merge-queue.yml', 'utf8');
    assert.match(review, /run: node scripts\/merge-queue\/config.mjs review-bots/);
    assert.match(review, /allowed_bots: \$\{\{ steps.review-bots.outputs.allowed-bots \}\}/);
    for (const workflow of [review, queue]) {
        assert.match(workflow, /MERGE_QUEUE_BOT_SLUG: \$\{\{ vars.MERGE_QUEUE_BOT_SLUG \}\}/);
    }
    assert.match(queue, /MERGE_QUEUE_TOKEN_APP_SLUG: \$\{\{ steps.app-token.outputs.app-slug \}\}/);
    const validation = queue.indexOf('run: node scripts/merge-queue/config.mjs verify-token');
    assert.ok(validation > 0 && validation < queue.indexOf('run: node scripts/merge-queue/queue.mjs'));
});

test('completed CI and review wake the enabled queue without trusting source code or artifacts', () => {
    const workflow = readFileSync('.github/workflows/merge-queue.yml', 'utf8');
    assert.match(workflow, /workflow_run:\s+workflows: \[CI, Agent review execution\]\s+types: \[completed\]/);
    assert.match(workflow, /schedule:\s+- cron:/);
    assert.match(workflow, /cancel-in-progress: false/);
    assert.match(workflow, /ref: refs\/heads\/main\s+persist-credentials: false/);
    assert.doesNotMatch(workflow, /download-artifact|workflow_run\.head_sha|workflow_run\.head_branch/);

    const expression = /if: >-\n([\s\S]*?)\n        environment:/.exec(workflow)?.[1];
    assert.ok(expression);
    const repository = 'Nils-Fredrik/ekko';
    const run = { conclusion: 'success', head_repository: { full_name: repository } };
    const enabled = (eventName, source = run, ref = 'refs/heads/main', flag = 'true') =>
        runInNewContext(expression.replace('.head_repository.full_name', '.head_repository?.full_name'), {
            github: { ref, repository, event_name: eventName, event: { workflow_run: source } },
            vars: { EKKO_MERGE_QUEUE_ENABLED: flag },
        });

    for (const head_branch of ['main', 't37-dashboard-performance']) {
        assert.equal(enabled('workflow_run', { ...run, head_branch }), true);
    }
    for (const conclusion of ['failure', 'cancelled', 'skipped', 'timed_out', undefined]) {
        assert.equal(enabled('workflow_run', { ...run, conclusion }), false);
    }
    for (const head_repository of [{ full_name: 'untrusted/fork' }, {}, null]) {
        assert.equal(enabled('workflow_run', { ...run, head_repository }), false);
    }
    for (const event of ['workflow_run', 'push', 'schedule', 'workflow_dispatch']) {
        assert.equal(enabled(event), true);
        assert.equal(enabled(event, run, 'refs/heads/untrusted'), false);
        assert.equal(enabled(event, run, 'refs/heads/main', 'false'), false);
    }
});

test('ignored workflow completions cannot replace a pending queue reconciliation', () => {
    const workflow = readFileSync('.github/workflows/merge-queue.yml', 'utf8');
    const expression = /group: \$\{\{ (.*?) \}\}/.exec(workflow)?.[1];
    assert.ok(expression);
    const repository = 'Nils-Fredrik/ekko';
    const run = { conclusion: 'success', head_repository: { full_name: repository } };
    const group = (eventName, source = run, runId = 123) =>
        runInNewContext(expression.replace('.head_repository.full_name', '.head_repository?.full_name'), {
            github: { repository, event_name: eventName, run_id: runId, event: { workflow_run: source } },
            format: (_, id) => `ekko-merge-queue-ignored-${id}`,
        });

    for (const event of ['workflow_run', 'push', 'schedule', 'workflow_dispatch']) {
        assert.equal(group(event), 'ekko-merge-queue');
    }
    for (const conclusion of ['failure', 'cancelled', 'skipped', 'timed_out', undefined]) {
        assert.equal(group('workflow_run', { ...run, conclusion }), 'ekko-merge-queue-ignored-123');
    }
    for (const head_repository of [{ full_name: 'untrusted/fork' }, {}, null]) {
        assert.notEqual(group('workflow_run', { ...run, head_repository }), group('workflow_run'));
    }
    assert.notEqual(group('workflow_run', {}, 123), group('workflow_run', {}, 124));
});

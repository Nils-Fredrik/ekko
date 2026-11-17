import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
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

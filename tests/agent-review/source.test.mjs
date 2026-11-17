import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { reviewSource } from '../../scripts/agent-review/source.mjs';

const head = 'a'.repeat(40),
    base = 'b'.repeat(40);
function fixture() {
    const repo = { id: 123, full_name: 'Nils-Fredrik/ekko' };
    const event = { repository: repo, workflow_run: { id: 50, run_attempt: 1 } };
    const env = {
        GITHUB_EVENT_NAME: 'workflow_run',
        GITHUB_REPOSITORY: repo.full_name,
        GITHUB_SHA: base,
        GH_TOKEN: 'test-token',
    };
    const workflow = { id: 90, path: '.github/workflows/agent-review.yml' };
    const source = { number: 42, base: { ref: 'main', sha: base, repo }, head: { sha: head } };
    const run = {
        id: 50,
        run_attempt: 1,
        event: 'pull_request_target',
        status: 'completed',
        conclusion: 'success',
        path: workflow.path,
        workflow_id: 90,
        repository: repo,
        head_sha: head,
        pull_requests: [source],
    };
    const pr = { ...structuredClone(source), state: 'open', draft: false };
    const calls = [];
    const request = async (url, options) => {
        calls.push({ url, options });
        if (url.endsWith('/actions/runs/50')) return Response.json(run);
        if (url.endsWith('/actions/workflows/agent-review.yml')) return Response.json(workflow);
        if (url.endsWith('/pulls/42')) return Response.json(pr);
        throw new Error('Unexpected request');
    };
    return { event, env, run, workflow, pr, source, calls, check: () => reviewSource(event, { env, request }) };
}

test('review execution resolves a trusted request via API and binds it to current main and head', async () => {
    const f = fixture();
    assert.deepEqual(await f.check(), { number: 42, head, base });
    assert.equal(f.calls.length, 3);
    assert.ok(f.calls.every(({ options }) => options.redirect === 'error'));
    assert.ok(f.calls.every(({ url }) => url.startsWith('https://api.github.com/repos/Nils-Fredrik/ekko/')));
});

test('a same-named PR workflow, untrusted path, failed run or stale attempt cannot trigger review', async () => {
    const mutations = [
        (f) => {
            f.run.event = 'pull_request';
        },
        (f) => {
            f.run.path = '.github/workflows/untrusted.yml';
        },
        (f) => {
            f.run.workflow_id = 91;
        },
        (f) => {
            f.workflow.path = '.github/workflows/renamed.yml';
        },
        (f) => {
            f.run.conclusion = 'failure';
        },
        (f) => {
            f.run.status = 'in_progress';
        },
        (f) => {
            f.run.run_attempt = 2;
        },
        (f) => {
            f.run.repository = { id: 999, full_name: 'attacker/ekko' };
        },
        (f) => {
            f.run.pull_requests = [];
        },
        (f) => {
            f.run.pull_requests.push(structuredClone(f.source));
        },
    ];
    for (const mutate of mutations) {
        const f = fixture();
        mutate(f);
        await assert.rejects(f.check());
    }
});

test('obsolete head, base, draft or closed PR stops without granting any review', async () => {
    for (const mutate of [
        (f) => {
            f.pr.head.sha = 'c'.repeat(40);
        },
        (f) => {
            f.run.head_sha = 'c'.repeat(40);
        },
        (f) => {
            f.pr.base.sha = 'c'.repeat(40);
        },
        (f) => {
            f.env.GITHUB_SHA = 'c'.repeat(40);
        },
        (f) => {
            f.pr.draft = true;
        },
        (f) => {
            f.pr.state = 'closed';
        },
    ]) {
        const f = fixture();
        mutate(f);
        assert.equal(await f.check(), null);
    }
});

test('a main merge before execution captures a fresh base without trusting PR code', async () => {
    const f = fixture();
    f.pr.base.sha = f.env.GITHUB_SHA = 'c'.repeat(40);
    assert.deepEqual(await f.check(), { number: 42, head, base: 'c'.repeat(40) });
    assert.ok(f.calls.every(({ options }) => !options.method || options.method === 'GET'));
});

test('malformed source and wrong repository/base still fail closed', async () => {
    for (const mutate of [
        (f) => {
            f.source.base.ref = 'untrusted';
        },
        (f) => {
            f.source.base.sha = 'untrusted';
        },
        (f) => {
            f.pr.base.repo.id = 999;
        },
        (f) => {
            f.pr.base.ref = 'untrusted';
        },
        (f) => {
            f.pr.number = 43;
        },
        (f) => {
            f.source.number = '42\nhead=untrusted';
        },
    ]) {
        const f = fixture();
        mutate(f);
        await assert.rejects(f.check());
    }
});

test('invalid workflow context fails before making any API request', async () => {
    for (const mutate of [
        (f) => {
            f.env.GITHUB_EVENT_NAME = 'pull_request';
        },
        (f) => {
            f.event.workflow_run.id = '50/../../secrets';
        },
        (f) => {
            f.event.workflow_run.run_attempt = 0;
        },
        (f) => {
            f.env.GITHUB_REPOSITORY = 'attacker/ekko';
        },
        (f) => {
            f.env.GH_TOKEN = '';
        },
    ]) {
        const f = fixture();
        mutate(f);
        await assert.rejects(f.check());
        assert.equal(f.calls.length, 0);
    }
});

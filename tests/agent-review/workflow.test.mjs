import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { readFile, readdir } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import { interactiveSource } from '../../scripts/agent-review/interactive-source.mjs';
import { trustedPullRequest } from '../../scripts/agent-review/policy.mjs';

test('every external workflow action is pinned to a full immutable commit', async () => {
    for (const file of await readdir('.github/workflows')) {
        if (!/\.ya?ml$/.test(file)) continue;
        const workflow = await readFile(`.github/workflows/${file}`, 'utf8');
        for (const [, action] of workflow.matchAll(/\buses:\s*(\S+)/g)) {
            if (action.startsWith('./')) continue;
            assert.match(action, /^[\w.-]+\/[\w./-]+@[a-f0-9]{40}$/, `${file}: ${action}`);
        }
    }
});

test('ignored source runs cannot cancel an active review for the same PR', async () => {
    const workflow = await readFile('.github/workflows/agent-review-execute.yml', 'utf8');
    const expression = /group: agent-review-execution-\$\{\{ (.*?) \}\}/.exec(workflow)?.[1];
    assert.ok(expression);
    const group = (run) =>
        runInNewContext(expression.replace('.pull_requests[0].number', '.pull_requests[0]?.number'), {
            github: { event: { workflow_run: run } },
            format: (_, id) => `ignored-${id}`,
        });
    const valid = { id: 123, event: 'pull_request_target', conclusion: 'success', pull_requests: [{ number: 78 }] };
    assert.equal(group(valid), 78);
    assert.equal(group({ ...valid, id: 124 }), group(valid));
    for (const conclusion of ['skipped', 'cancelled', 'failure', 'timed_out']) {
        assert.notEqual(group({ ...valid, conclusion }), group(valid));
    }
    assert.notEqual(group({ ...valid, event: 'pull_request' }), group(valid));
    assert.equal(group({ ...valid, pull_requests: [] }), 'ignored-123');
    assert.notEqual(group({ ...valid, id: 78, conclusion: 'skipped' }), group(valid));
});

const repo = 'Nils-Fredrik/ekko';
const trusted = {
    author_association: 'OWNER',
    head: { repo: { full_name: repo } },
    base: { repo: { full_name: repo }, ref: 'main' },
};
test('automatic reviews require both a trusted author and same-repository branches', () => {
    for (const author_association of ['OWNER', 'MEMBER', 'COLLABORATOR']) {
        assert.equal(trustedPullRequest({ ...trusted, author_association }, repo), true);
    }
    for (const author_association of ['NONE', 'CONTRIBUTOR', 'FIRST_TIME_CONTRIBUTOR', undefined]) {
        assert.equal(trustedPullRequest({ ...trusted, author_association }, repo), false);
    }
    assert.equal(trustedPullRequest({ ...trusted, head: { repo: { full_name: 'attacker/ekko' } } }, repo), false);
    assert.equal(trustedPullRequest({ ...trusted, head: { repo: null } }, repo), false);
});

function mentionFixture() {
    const event = {
        repository: { full_name: repo },
        issue: { number: 78, pull_request: {} },
        comment: { author_association: 'OWNER', body: '@claude review' },
    };
    const env = { GITHUB_REPOSITORY: repo, GITHUB_EVENT_NAME: 'issue_comment', GH_TOKEN: 'fixture-token' };
    let calls = 0;
    let pull = { ...trusted, number: 78 };
    const request = async (url, options) => {
        calls++;
        assert.equal(url, `https://api.github.com/repos/${repo}/pulls/78`);
        assert.equal(options.redirect, 'error');
        return Response.json(pull);
    };
    return {
        event,
        env,
        check: () => interactiveSource(event, { env, request }),
        calls: () => calls,
        setPull: (value) => {
            pull = value;
        },
    };
}

test('untrusted mentions never reach the PR API or Claude action', async () => {
    const f = mentionFixture();
    f.event.comment.author_association = 'NONE';
    assert.equal(await f.check(), false);
    assert.equal(f.calls(), 0);
});

test('trusted issue comments on fork PRs cannot invoke Claude', async () => {
    const f = mentionFixture();
    assert.equal(await f.check(), true);
    f.setPull({ ...trusted, number: 78, head: { repo: { full_name: 'attacker/ekko' } } });
    assert.equal(await f.check(), false);
});

test('trusted mentions on issues and inline reviews retain their supported behavior', async () => {
    const f = mentionFixture();
    delete f.event.issue.pull_request;
    assert.equal(await f.check(), true);
    assert.equal(f.calls(), 0);
    f.env.GITHUB_EVENT_NAME = 'pull_request_review';
    f.event.review = f.event.comment;
    f.event.pull_request = { number: 78 };
    assert.equal(await f.check(), true);
});

test('interactive credentials are gated after a checkout of trusted main', async () => {
    const workflow = await readFile('.github/workflows/claude.yml', 'utf8');
    assert.match(workflow, /ref: main/);
    assert.match(workflow, /persist-credentials: false/);
    assert.match(workflow, /if: steps.source.outputs.trusted == 'true'\s+id: claude/);
    assert.ok(workflow.indexOf('interactive-source.mjs') < workflow.indexOf('secrets.CLAUDE_CODE_OAUTH_TOKEN'));
});

function automaticMentionFixture(title = '[Claude Code] T07-F1 – Authentication') {
    const event = {
        repository: { full_name: repo },
        issue: { number: 83, pull_request: {} },
        comment: { author_association: 'OWNER', body: '@claude review' },
    };
    const env = { GITHUB_REPOSITORY: repo, GITHUB_EVENT_NAME: 'issue_comment', GH_TOKEN: 'fixture-token' };
    const pr = {
        ...trusted,
        number: 83,
        title,
        state: 'open',
        draft: false,
        changed_files: 1,
        head: { ...trusted.head, sha: 'a'.repeat(40) },
        base: { ...trusted.base, sha: 'b'.repeat(40) },
    };
    let files = [{ filename: 'app/Tenancy/Access.php' }];
    let reads = 0;
    let changedHead = false;
    const calls = [];
    const request = async (url) => {
        calls.push(url);
        const base = `https://api.github.com/repos/${repo}/pulls/83`;
        if (url === base) {
            reads++;
            return Response.json(changedHead && reads > 1 ? { ...pr, head: { ...pr.head, sha: 'c'.repeat(40) } } : pr);
        }
        assert.ok(url.startsWith(base + '/files?per_page=100&page='));
        const page = Number(new URL(url).searchParams.get('page'));
        return Response.json(files.slice((page - 1) * 100, page * 100));
    };
    return {
        event,
        pr,
        calls,
        check: () => interactiveSource(event, { env, request }),
        setFiles: (value) => {
            files = value;
        },
        changeHead: () => {
            changedHead = true;
        },
    };
}

test('plain review commands do not launch a second Claude review for Codex or Cursor PRs', async () => {
    for (const agent of ['Codex', 'Cursor']) {
        const f = automaticMentionFixture(`[${agent}] T42-F1 – Review`);
        f.event.comment.body = '  @claude review\n';
        assert.equal(await f.check(), false);
        assert.equal(f.calls.length, 1);
    }
});

test('Claude-authored PRs suppress duplicates only when the automatic policy requires Claude', async () => {
    const sensitive = automaticMentionFixture();
    assert.equal(await sensitive.check(), false);
    const ordinary = automaticMentionFixture();
    ordinary.setFiles([{ filename: 'docs/example.md' }]);
    assert.equal(await ordinary.check(), true);
    const renamed = automaticMentionFixture();
    renamed.setFiles([{ filename: 'docs/example.md', previous_filename: 'app/Tenancy/Access.php' }]);
    assert.equal(await renamed.check(), false);
});

test('the interactive duplicate guard checks every changed file and rejects incomplete or changing data', async () => {
    const paged = automaticMentionFixture();
    paged.pr.changed_files = 101;
    paged.setFiles([
        ...Array.from({ length: 100 }, (_, i) => ({ filename: `docs/${i}.md` })),
        { filename: 'config/auth.php' },
    ]);
    assert.equal(await paged.check(), false);
    assert.equal(paged.calls.filter((url) => url.includes('/files?')).length, 2);
    const incomplete = automaticMentionFixture();
    incomplete.pr.changed_files = 2;
    await assert.rejects(incomplete.check(), /incomplete/);
    const changed = automaticMentionFixture();
    changed.changeHead();
    await assert.rejects(changed.check(), /PR changed/);
});

test('targeted requests and PRs outside automatic review retain interactive Claude', async () => {
    for (const body of [
        '@claude explain this migration',
        '@claude review only the query plan',
        '@claude fix the failing test',
    ]) {
        const f = automaticMentionFixture('[Codex] T37 – Performance');
        f.event.comment.body = body;
        assert.equal(await f.check(), true);
        assert.equal(f.calls.length, 1);
    }
    for (const attributes of [
        { title: 'Manual change' },
        { draft: true },
        { state: 'closed' },
        { author_association: 'NONE' },
    ]) {
        const f = automaticMentionFixture();
        Object.assign(f.pr, attributes);
        assert.equal(await f.check(), true);
        assert.equal(f.calls.length, 1);
    }
});

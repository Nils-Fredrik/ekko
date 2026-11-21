import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { codexApproved, codexReviewState, requiredReviewers } from '../../scripts/agent-review/policy.mjs';

const paths = ['app/Tenancy/**', 'infra/**'];
const sha = 'a'.repeat(40);
const request = '2026-01-01T00:00:00Z';
const receipt = {
    user: { id: 199175422 },
    created_at: '2026-01-01T00:00:01Z',
    body: '<!-- codex-review-receipt:v1 -->\n**Reviewed commit:** `' + sha + '`',
};
const summary = (overrides = {}, id = 199175422, updated_at = '2026-01-01T00:00:01Z') => ({
    user: { id },
    updated_at,
    body: `<!-- codex-pull-request-review-summary -->\n<!-- codex-review-state:v1 ${JSON.stringify({ code_review: { status: 'completed', commit_sha: sha, has_any_findings: false, has_unresolved_findings: false, ...overrides } })} -->`,
});

test('review routing includes both reviewers for sensitive changes', () => {
    assert.deepEqual(requiredReviewers('[Codex] T42 – Test', ['README.md'], paths), { claude: true, codex: false });
    assert.deepEqual(requiredReviewers('[Claude Code] T03 – Test', ['README.md'], paths), {
        claude: false,
        codex: true,
    });
    assert.deepEqual(requiredReviewers('[Cursor] T02 – Test', ['infra/main.tf'], paths), { claude: true, codex: true });
    assert.deepEqual(requiredReviewers('[Codex] T42 – Test', ['app/Tenancy/.hidden/config'], paths), {
        claude: true,
        codex: true,
    });
    assert.throws(() => requiredReviewers('Test', ['README.md'], paths));
    // UI work packages use their own U number (docs/ui-forbedring.md).
    assert.deepEqual(requiredReviewers('[Claude Code] U02 – Test', ['README.md'], paths), { claude: false, codex: true });
    assert.deepEqual(requiredReviewers('[Cursor] U05-F1 – Test', ['README.md'], paths), { claude: true, codex: false });
    assert.throws(() => requiredReviewers('[Cursor] X05 – Test', ['README.md'], paths));
    assert.throws(() => requiredReviewers('[Codex] T42 – Test', [], paths));
});

test('repository review policy routes local action changes to both reviewers', () => {
    const configuredPaths = JSON.parse(readFileSync(new URL('../../.github/review-paths.yml', import.meta.url), 'utf8')).sensitive;
    for (const title of ['[Codex] T10 – Test', '[Claude Code] T10 – Test']) {
        for (const file of ['.github/actions/download-verified-images/action.yml', '.github/actions/.hidden/nested/script.sh']) {
            assert.deepEqual(requiredReviewers(title, [file], configuredPaths), { claude: true, codex: true });
        }
    }
    assert.deepEqual(requiredReviewers('[Codex] T10 – Test', ['.github/actionss/example/action.yml'], configuredPaths), {
        claude: true,
        codex: false,
    });
});

test('only a fresh complete clean result from the authenticated bot on the exact head passes', () => {
    assert.equal(codexApproved([summary(), receipt], sha, request), true);
    assert.equal(codexApproved([summary()], sha, request), false);
    assert.equal(codexApproved([summary(), { ...receipt, created_at: request }], sha, request), false);
    assert.equal(codexApproved([summary({}, 1), receipt], sha, request), false);
    assert.equal(codexApproved([summary({ commit_sha: 'b'.repeat(40) }), receipt], sha, request), false);
    assert.equal(codexApproved([summary({ has_unresolved_findings: true }), receipt], sha, request), false);
    assert.equal(codexApproved([summary({ has_any_findings: true }), receipt], sha, request), false);
    assert.equal(codexApproved([summary({ status: 'running' }), receipt], sha, request), false);
    assert.equal(codexApproved([summary({}, 199175422, request), receipt], sha, request), false);
    assert.equal(codexApproved([summary(), summary(), receipt], sha, request), false);
});

test('unknown and stale Codex data cannot be misclassified as a factual rejection', () => {
    for (const comments of [
        [summary({ has_any_findings: true })],
        [summary({ has_any_findings: true }, 1), receipt],
        [summary({ has_any_findings: true, commit_sha: 'b'.repeat(40) }), receipt],
        [summary({ has_any_findings: 'true' }), receipt],
        [summary({ has_any_findings: true, status: 'running' }), receipt],
    ])
        assert.equal(codexReviewState(comments, sha, request), 'pending');
    assert.equal(codexReviewState([summary({ has_any_findings: true }), receipt], sha, request), 'changes_requested');
});

test('resolved Codex findings on earlier commits do not block a clean result on the current head', () => {
    const old = 'c'.repeat(40);
    const clean = { ...receipt, body: receipt.body.replace('\n', "\n\nCodex Review: Didn't find any major issues. Swish!\n\n") };
    const findings = (commit, submitted_at = '2025-12-31T00:00:00Z') => ({
        user: { id: 199175422 },
        commit_id: commit,
        submitted_at,
        state: 'COMMENTED',
        body: '<!-- codex-review-receipt:v1 -->\n### Codex Review\n**Reviewed commit:** `' + commit.slice(0, 10) + '`',
    });
    const resolved = summary({ has_any_findings: true, has_unresolved_findings: false });

    // Findings on an earlier commit, all resolved, and a fresh clean receipt on this head.
    assert.equal(codexReviewState([resolved, clean], sha, request, [findings(old)]), 'approved');
    assert.equal(codexReviewState([resolved, clean], sha, request), 'approved');

    // The clean result must be fresh, on this head, from the Codex bot, and in the clean format.
    for (const other of [
        { ...clean, created_at: request },
        { ...clean, body: clean.body.replace(sha, old) },
        { ...clean, user: { id: 1 } },
        receipt,
        { ...clean, body: clean.body.replace("Didn't find any major issues", 'Found some issues') },
    ])
        assert.equal(codexReviewState([resolved, other, receipt], sha, request, [findings(old)]), 'changes_requested');

    // Unresolved findings still block, also with a clean receipt on the head.
    assert.equal(
        codexReviewState([summary({ has_any_findings: true, has_unresolved_findings: true }), clean], sha, request, [
            findings(old),
        ]),
        'changes_requested',
    );
});

test('Codex findings on the current head block even when their threads are resolved', () => {
    const clean = { ...receipt, body: receipt.body.replace('\n', "\n\nCodex Review: Didn't find any major issues.\n\n") };
    const resolved = summary({ has_any_findings: true, has_unresolved_findings: false });
    const onHead = {
        user: { id: 199175422 },
        commit_id: sha,
        submitted_at: '2026-01-01T00:00:02Z',
        state: 'COMMENTED',
        body: '<!-- codex-review-receipt:v1 -->\n**Reviewed commit:** `' + sha.slice(0, 10) + '`',
    };
    // Fresh findings review on the head is its own receipt.
    assert.equal(codexReviewState([resolved], sha, request, [onHead]), 'changes_requested');
    // A later clean re-run on the same head does not clear findings posted on it, even old ones.
    assert.equal(codexReviewState([resolved, clean], sha, request, [onHead]), 'changes_requested');
    assert.equal(
        codexReviewState([resolved, clean], sha, request, [{ ...onHead, submitted_at: '2025-12-31T00:00:00Z' }]),
        'changes_requested',
    );
    // The head is matched by the review's commit or by its reviewed-commit line.
    assert.equal(codexReviewState([resolved, clean], sha, request, [{ ...onHead, commit_id: undefined }]), 'changes_requested');
    assert.equal(
        codexReviewState([resolved, clean], sha, request, [{ ...onHead, body: 'Reply without receipt' }]),
        'changes_requested',
    );
    // Also when the summary claims there never were any findings.
    assert.equal(codexReviewState([summary(), clean], sha, request, [onHead]), 'changes_requested');
    // Reviews from other accounts are not Codex findings.
    assert.equal(codexReviewState([resolved, clean], sha, request, [{ ...onHead, user: { id: 1 } }]), 'approved');
});

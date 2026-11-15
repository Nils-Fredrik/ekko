import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { codexApproved, requiredReviewers } from '../../scripts/agent-review/policy.mjs';

const paths = ['app/Tenancy/**', 'infra/**'];
const sha = 'a'.repeat(40);
const request = '2026-01-01T00:00:00Z';
const summary = (overrides = {}, id = 199175422, updated_at = '2026-01-01T00:00:01Z') => ({
    user: { id }, updated_at,
    body: `<!-- codex-pull-request-review-summary -->\n<!-- codex-review-state:v1 ${JSON.stringify({ code_review: { status: 'completed', commit_sha: sha, has_any_findings: false, has_unresolved_findings: false, ...overrides } })} -->`,
});

test('review routing includes both reviewers for sensitive changes', () => {
    assert.deepEqual(requiredReviewers('[Codex] T42 – Test', ['README.md'], paths), { claude: true, codex: false });
    assert.deepEqual(requiredReviewers('[Claude Code] T03 – Test', ['README.md'], paths), { claude: false, codex: true });
    assert.deepEqual(requiredReviewers('[Cursor] T02 – Test', ['infra/main.tf'], paths), { claude: true, codex: true });
    assert.deepEqual(requiredReviewers('[Codex] T42 – Test', ['app/Tenancy/.hidden/config'], paths), { claude: true, codex: true });
    assert.throws(() => requiredReviewers('Test', ['README.md'], paths));
    assert.throws(() => requiredReviewers('[Codex] T42 – Test', [], paths));
});

test('only a fresh complete clean result from the authenticated bot on the exact head passes', () => {
    assert.equal(codexApproved([summary()], sha, request), true);
    assert.equal(codexApproved([summary({}, 1)], sha, request), false);
    assert.equal(codexApproved([summary({ commit_sha: 'b'.repeat(40) })], sha, request), false);
    assert.equal(codexApproved([summary({ has_unresolved_findings: true })], sha, request), false);
    assert.equal(codexApproved([summary({ has_any_findings: true })], sha, request), false);
    assert.equal(codexApproved([summary({ status: 'running' })], sha, request), false);
    assert.equal(codexApproved([summary({}, 199175422, request)], sha, request), false);
    assert.equal(codexApproved([summary(), summary()], sha, request), false);
});

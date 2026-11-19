import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';
import yaml from 'js-yaml';

const action = yaml.load(readFileSync('.github/actions/download-verified-images/action.yml', 'utf8'));
const workflow = yaml.load(readFileSync('.github/workflows/ci.yml', 'utf8'));
const downloadAction = 'actions/download-artifact@d3f86a106a0bac45b974a628896c90dbdf5c8093';

// Model the runner's outcome/continue-on-error behavior while executing the real ID
// validation shell. Download failures are injected; no GitHub service is contacted.
function simulate(artifactId, downloadResults) {
    const outcomes = {};
    const calls = [];
    const waits = [];
    for (const step of action.runs.steps) {
        if (step.if) {
            const match = /^steps\.([a-z]+)\.outcome == 'failure'$/.exec(step.if);
            assert.ok(match, `Unexpected retry condition: ${step.if}`);
            if (outcomes[match[1]] !== 'failure') continue;
        }
        let result;
        if (step.uses) {
            assert.equal(step.uses, downloadAction);
            assert.equal(step.with['artifact-ids'], '${{ inputs.artifact-id }}');
            assert.equal(step.with.path, '${{ inputs.path }}');
            assert.equal(step.with['merge-multiple'], true);
            result = downloadResults[calls.length];
            assert.ok(['success', 'failure'].includes(result), 'Unexpected download attempt');
            calls.push(result);
        } else if (step.env?.ARTIFACT_ID) {
            assert.equal(step.env.ARTIFACT_ID, '${{ inputs.artifact-id }}');
            const shell = spawnSync('bash', ['-c', step.run], {
                env: { ...process.env, ARTIFACT_ID: artifactId },
            });
            assert.equal(shell.error, undefined);
            result = shell.status === 0 ? 'success' : 'failure';
        } else {
            const match = /^sleep ([0-9]+)$/.exec(step.run);
            assert.ok(match, `Unexpected shell step: ${step.run}`);
            waits.push(Number(match[1]));
            result = 'success';
        }
        if (step.id) outcomes[step.id] = result;
        if (result === 'failure' && !step['continue-on-error']) {
            return { success: false, calls, waits };
        }
    }
    return { success: true, calls, waits };
}

test('retry transient failures but stop after the final failed download', () => {
    for (const [results, expected] of [
        [['success'], { success: true, calls: ['success'], waits: [] }],
        [['failure', 'success'], { success: true, calls: ['failure', 'success'], waits: [5] }],
        [['failure', 'failure', 'success'], { success: true, calls: ['failure', 'failure', 'success'], waits: [5, 10] }],
        [['failure', 'failure', 'failure'], { success: false, calls: ['failure', 'failure', 'failure'], waits: [5, 10] }],
    ]) {
        assert.deepEqual(simulate('14566169618', results), expected);
    }
});

test('reject absent, malformed or multiple IDs before any download', () => {
    for (const id of ['', '1,2', '123x', '-123', ' 123', '1\n2']) {
        assert.deepEqual(simulate(id, []), { success: false, calls: [], waits: [] }, id);
    }
});

test('both image verification and deployment require the exact artifact and retain integrity checks', () => {
    const image = workflow.jobs.image.steps;
    const deploy = workflow.jobs.deploy.steps;
    for (const [steps, source, integrity] of [
        [image, '${{ steps.images.outputs.artifact-id }}', 'Verify restored image identities and runtime contents'],
        [deploy, '${{ needs.image.outputs.artifact-id }}', 'Publish verified images without rebuilding'],
    ]) {
        const downloadIndex = steps.findIndex((step) => step.uses === './.github/actions/download-verified-images');
        assert.notEqual(downloadIndex, -1);
        assert.equal(steps[downloadIndex].with['artifact-id'], source);
        const integrityIndex = steps.findIndex((step) => step.name === integrity);
        assert.ok(integrityIndex > downloadIndex);
        assert.match(steps[integrityIndex].run, /sha256sum --check images\.sha256/);
        assert.match(steps[integrityIndex].run, /docker load --input/);
    }
    const verify = image.find((step) => step.name === 'Verify restored image identities and runtime contents').run;
    assert.match(verify, /\$expected_web/);
    assert.match(verify, /\$expected_jobs/);
    assert.equal((verify.match(/test-docker-context\.sh verify/g) || []).length, 2);
});

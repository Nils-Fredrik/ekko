import { trustedPullRequest } from './policy.mjs';
import { appendFile, readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

// A workflow name is not a trust boundary: a PR can add a workflow with that name.
// Resolve the source via GitHub, require the exact base-branch request workflow,
// and never download source artifacts or check out its head (which is PR code).
export async function reviewSource(event, { env = process.env, request = fetch } = {}) {
    const repo = env.GITHUB_REPOSITORY;
    const runId = event.workflow_run?.id;
    const attempt = event.workflow_run?.run_attempt;
    if (
        env.GITHUB_EVENT_NAME !== 'workflow_run' ||
        repo !== 'Nils-Fredrik/ekko' ||
        event.repository?.full_name !== repo ||
        !Number.isSafeInteger(event.repository?.id) ||
        !Number.isSafeInteger(runId) ||
        runId < 1 ||
        !Number.isSafeInteger(attempt) ||
        attempt < 1 ||
        !/^[a-f0-9]{40}$/.test(env.GITHUB_SHA || '') ||
        !env.GH_TOKEN
    )
        throw new Error('Invalid review source context');

    async function api(path) {
        const response = await request(`https://api.github.com/repos/${repo}${path}`, {
            redirect: 'error',
            signal: AbortSignal.timeout(30_000),
            headers: {
                authorization: `Bearer ${env.GH_TOKEN}`,
                accept: 'application/vnd.github+json',
            },
        });
        if (!response.ok) throw new Error(`Review source API returned ${response.status}`);
        return response.json();
    }
    const [run, workflow] = await Promise.all([
        api(`/actions/runs/${runId}`),
        api('/actions/workflows/agent-review.yml'),
    ]);
    if (
        run.id !== runId ||
        run.run_attempt !== attempt ||
        run.event !== 'pull_request_target' ||
        run.status !== 'completed' ||
        run.conclusion !== 'success' ||
        run.path !== '.github/workflows/agent-review.yml' ||
        workflow.path !== run.path ||
        !Number.isSafeInteger(workflow.id) ||
        run.workflow_id !== workflow.id ||
        run.repository?.id !== event.repository.id ||
        run.repository?.full_name !== repo ||
        !Array.isArray(run.pull_requests) ||
        run.pull_requests.length !== 1
    )
        throw new Error('Source must be a successful trusted review request');

    const source = run.pull_requests[0];
    if (
        !Number.isSafeInteger(source.number) ||
        source.number < 1 ||
        source.base?.repo?.id !== event.repository.id ||
        source.base.ref !== 'main' ||
        !/^[a-f0-9]{40}$/.test(source.base.sha || '') ||
        !/^[a-f0-9]{40}$/.test(source.head?.sha || '') ||
        !/^[a-f0-9]{40}$/.test(run.head_sha || '')
    )
        throw new Error('Source does not match the trusted main checkout');
    const pr = await api(`/pulls/${source.number}`);
    if (pr.number !== source.number || pr.base?.repo?.id !== event.repository.id || pr.base.ref !== 'main')
        throw new Error('PR does not belong to the trusted repository and base');
    if (!trustedPullRequest(pr, repo)) throw new Error('PR author and source repository are not trusted');
    // GitHub can update the PR metadata attached to an older run. The run's
    // immutable head remains authoritative. Never review newer code on its behalf.
    if (
        pr.state !== 'open' ||
        pr.draft ||
        run.head_sha !== source.head.sha ||
        pr.head?.sha !== run.head_sha ||
        pr.base.sha !== env.GITHUB_SHA
    )
        return null;
    // A merge into main while the request was queued is safe: this execution
    // checks out current trusted main and captures a NEW snapshot before review.
    return { number: source.number, head: run.head_sha, base: pr.base.sha };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const event = JSON.parse(await readFile(process.env.GITHUB_EVENT_PATH, 'utf8'));
    const result = await reviewSource(event);
    await appendFile(
        process.env.GITHUB_OUTPUT,
        result ? `ready=true\nnumber=${result.number}\nhead=${result.head}\nbase=${result.base}\n` : 'ready=false\n',
    );
    if (!result)
        console.log(
            'Review request is obsolete; no review or status was published. Update the PR branch to request a fresh review.',
        );
}

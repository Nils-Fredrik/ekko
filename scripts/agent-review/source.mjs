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
        source.base.sha !== env.GITHUB_SHA ||
        !/^[a-f0-9]{40}$/.test(source.head?.sha || '') ||
        run.head_sha !== source.head.sha
    )
        throw new Error('Source does not match the trusted main checkout');
    const pr = await api(`/pulls/${source.number}`);
    if (
        pr.number !== source.number ||
        pr.state !== 'open' ||
        pr.draft ||
        pr.base?.repo?.id !== event.repository.id ||
        pr.base.ref !== 'main' ||
        pr.base.sha !== source.base.sha ||
        pr.head?.sha !== source.head.sha
    )
        throw new Error('PR changed after the review request');
    return { number: source.number, head: source.head.sha, base: source.base.sha };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const event = JSON.parse(await readFile(process.env.GITHUB_EVENT_PATH, 'utf8'));
    const result = await reviewSource(event);
    await appendFile(process.env.GITHUB_OUTPUT, `number=${result.number}\nhead=${result.head}\nbase=${result.base}\n`);
}

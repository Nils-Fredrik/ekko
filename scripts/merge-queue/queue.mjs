import { appendFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

const REPO = 'Nils-Fredrik/ekko';
const REVIEW_APP_ID = 5773706;
const REVIEW_BOT_ID = 357077641;
const ACTIONS_APP_ID = 15368;
const SHA = /^[a-f0-9]{40}$/;
const AGENT_TITLE = /^\[(?:Codex|Claude Code|Cursor)\] [TU]\d+(?:-[A-Za-z0-9]+)?\s*[–-]\s*\S/;
const ROUND = /^<!-- ekko-review-round:v1 (\{[^\n]+\}) -->/;

export function validateProtection(protection) {
    const checks = protection?.required_status_checks;
    const has = (context, appId) => checks?.checks?.some((c) => c.context === context && c.app_id === appId);
    if (
        checks?.strict !== true ||
        protection?.enforce_admins?.enabled !== true ||
        !has('test', ACTIONS_APP_ID) ||
        !has('container build', ACTIONS_APP_ID) ||
        !has('agent-review', REVIEW_APP_ID) ||
        protection.allow_force_pushes?.enabled !== false ||
        protection.allow_deletions?.enabled !== false
    )
        throw new Error('Required branch protections are missing');
}

export function eligible(pr, allowedAuthors) {
    return (
        pr?.state === 'open' &&
        pr.draft === false &&
        pr.base?.ref === 'main' &&
        pr.base?.repo?.full_name === REPO &&
        pr.head?.repo?.full_name === REPO &&
        Number.isSafeInteger(pr.number) &&
        SHA.test(pr.head?.sha || '') &&
        ['OWNER', 'MEMBER', 'COLLABORATOR'].includes(pr.author_association) &&
        allowedAuthors.has(pr.user?.login) &&
        AGENT_TITLE.test(pr.title || '')
    );
}

// GitHub can retain multiple check runs for the same SHA after reruns. Only the
// newest run of each required job counts; a previously green run is not enough.
export function checkHealth(runs, statuses) {
    const latest = new Map();
    for (const run of runs) {
        const key = `${run.app?.id}:${run.name}`;
        if (!latest.has(key) || latest.get(key).id < run.id) latest.set(key, run);
    }
    // A pending unrelated check must not conceal an already failed review or test.
    for (const check of latest.values()) {
        if (check.status === 'completed' && !['success', 'skipped', 'neutral'].includes(check.conclusion))
            return 'failure';
    }
    if (statuses.some((status) => !['success', 'pending'].includes(status.state))) return 'failure';
    if (
        [...latest.values()].some((check) => check.status !== 'completed') ||
        statuses.some((s) => s.state === 'pending')
    )
        return 'pending';
    for (const name of ['test', 'container build']) {
        const check = runs
            .filter((r) => r.name === name && r.app?.id === ACTIONS_APP_ID)
            .sort((a, b) => b.id - a.id)[0];
        if (!check || check.status !== 'completed') return 'pending';
        if (check.conclusion !== 'success') return 'failure';
    }
    const review = statuses.find((s) => s.context === 'agent-review');
    if (!review || review.state === 'pending') return 'pending';
    return review.state === 'success' ? 'success' : 'failure';
}

// A green commit status alone may be stale after the base moves. The review App
// publishes a signed-by-identity receipt for both SHAs and its Actions run.
export function reviewedForSnapshot(comments, statuses, head, base) {
    const status = statuses.find((s) => s.context === 'agent-review');
    if (status?.state !== 'success') return false;
    const rounds = comments.filter((c) => c.user?.id === REVIEW_BOT_ID && ROUND.test(c.body || ''));
    const last = rounds.at(-1);
    if (!last) return false;
    try {
        const record = JSON.parse(ROUND.exec(last.body)[1]);
        return (
            record.head === head &&
            record.base === base &&
            record.result === 'success' &&
            Number.isSafeInteger(Number(record.run)) &&
            Number(record.run) > 0 &&
            status.target_url === `https://github.com/${REPO}/actions/runs/${record.run}`
        );
    } catch {
        return false;
    }
}

export function githubClient(token, request = fetch) {
    if (!token) throw new Error('Missing merge queue App token');
    async function api(path, { method = 'GET', body } = {}) {
        const response = await request(`https://api.github.com/repos/${REPO}${path}`, {
            method,
            redirect: 'error',
            signal: AbortSignal.timeout(30000),
            headers: {
                authorization: `Bearer ${token}`,
                accept: 'application/vnd.github+json',
                'x-github-api-version': '2022-11-28',
                ...(body ? { 'content-type': 'application/json' } : {}),
            },
            ...(body ? { body: JSON.stringify(body) } : {}),
        });
        if (!response.ok) throw new Error(`GitHub ${method} ${path.split('?')[0]} returned ${response.status}`);
        return response.status === 204 ? null : response.json();
    }
    async function pages(path) {
        const result = [];
        for (let page = 1; page <= 20; page++) {
            const rows = await api(`${path}${path.includes('?') ? '&' : '?'}per_page=100&page=${page}`);
            if (!Array.isArray(rows)) throw new Error('Unexpected paginated response');
            result.push(...rows);
            if (rows.length < 100) return result;
        }
        throw new Error('Pagination limit reached');
    }
    return { api, pages };
}

export async function mainHealth(client, sha) {
    const data = await client.api(`/actions/workflows/ci.yml/runs?branch=main&event=push&head_sha=${sha}&per_page=100`);
    if (!Array.isArray(data?.workflow_runs) || data.total_count > 100) throw new Error('Incomplete main CI data');
    const run = data.workflow_runs
        .filter((r) => r.head_sha === sha && r.event === 'push')
        .sort((a, b) => b.id - a.id)[0];
    if (!run || run.status !== 'completed') return 'pending';
    if (run.conclusion !== 'success') return 'failure';
    const result = await client.api(`/actions/runs/${run.id}/jobs?per_page=100`);
    if (!Array.isArray(result?.jobs) || result.total_count > 100) throw new Error('Incomplete main job data');
    for (const name of ['test', 'container build', 'deploy']) {
        const job = result.jobs.filter((j) => j.name === name).sort((a, b) => b.id - a.id)[0];
        if (!job || job.status !== 'completed' || job.conclusion !== 'success') return 'failure';
    }
    return 'success';
}

export async function reconcile(client, allowedAuthors) {
    if (!(allowedAuthors instanceof Set) || !allowedAuthors.size) throw new Error('Missing trusted author allowlist');
    validateProtection(await client.api('/branches/main/protection'));
    const branch = await client.api('/branches/main');
    const base = branch?.commit?.sha;
    if (!SHA.test(base || '')) throw new Error('Invalid main SHA');
    const mainStatus = await mainHealth(client, base);
    if (mainStatus !== 'success') return { action: `main-${mainStatus}` };
    const list = await client.pages('/pulls?state=open&base=main&sort=created&direction=asc');
    // Native auto-merge runs independently. Give those PRs priority and help
    // an eligible behind PR update, but leave its eventual merge to GitHub.
    const native = list.filter((pr) => pr.auto_merge != null);
    for (const item of native.length ? native : list) {
        if (!eligible(item, allowedAuthors)) {
            if (native.length) return { action: 'native-auto-merge-pending', number: item.number };
            continue;
        }
        const pr = await client.api(`/pulls/${item.number}`);
        if (
            !eligible(pr, allowedAuthors) ||
            pr.head.sha !== item.head.sha ||
            (pr.auto_merge != null) !== !!native.length
        )
            return { action: 'retry', number: item.number };
        if (pr.mergeable === null || pr.mergeable_state === 'unknown') return { action: 'wait', number: pr.number };
        if (pr.mergeable === false || pr.mergeable_state === 'dirty') return { action: 'conflict', number: pr.number };
        const [checks, combined] = await Promise.all([
            client.api(`/commits/${pr.head.sha}/check-runs?per_page=100`),
            client.api(`/commits/${pr.head.sha}/status?per_page=100`),
        ]);
        if (!Array.isArray(checks?.check_runs) || checks.total_count > 100 || !Array.isArray(combined?.statuses))
            throw new Error('Incomplete check data');
        const health = checkHealth(checks.check_runs, combined.statuses);
        if (health !== 'success') return { action: health, number: pr.number };
        if (pr.mergeable_state === 'behind' || pr.base.sha !== base) {
            const [current, again, open] = await Promise.all([
                client.api('/branches/main'),
                client.api(`/pulls/${pr.number}`),
                client.pages('/pulls?state=open&base=main&sort=created&direction=asc'),
            ]);
            if (!native.length && open.some((candidate) => candidate.auto_merge != null))
                return { action: 'native-auto-merge-pending' };
            if (
                current?.commit?.sha !== base ||
                !eligible(again, allowedAuthors) ||
                (again.auto_merge != null) !== !!native.length ||
                again.head.sha !== pr.head.sha ||
                again.title !== pr.title
            )
                return { action: 'retry', number: pr.number };
            const [freshChecks, freshStatus] = await Promise.all([
                client.api(`/commits/${pr.head.sha}/check-runs?per_page=100`),
                client.api(`/commits/${pr.head.sha}/status?per_page=100`),
            ]);
            if (
                !Array.isArray(freshChecks?.check_runs) ||
                freshChecks.total_count > 100 ||
                !Array.isArray(freshStatus?.statuses)
            )
                throw new Error('Incomplete check data');
            const freshHealth = checkHealth(freshChecks.check_runs, freshStatus.statuses);
            if (freshHealth !== 'success') return { action: freshHealth, number: pr.number };
            await client.api(`/pulls/${pr.number}/update-branch`, {
                method: 'PUT',
                body: { expected_head_sha: pr.head.sha },
            });
            return { action: 'updated', number: pr.number };
        }
        if (native.length) return { action: 'native-auto-merge-pending', number: pr.number };
        const comments = await client.pages(`/issues/${pr.number}/comments`);
        if (!reviewedForSnapshot(comments, combined.statuses, pr.head.sha, base))
            return { action: 'review', number: pr.number };
        if (pr.mergeable_state !== 'clean') return { action: 'wait', number: pr.number };
        validateProtection(await client.api('/branches/main/protection'));
        const [latest, again, open] = await Promise.all([
            client.api('/branches/main'),
            client.api(`/pulls/${pr.number}`),
            client.pages('/pulls?state=open&base=main&sort=created&direction=asc'),
        ]);
        if (open.some((candidate) => candidate.auto_merge != null)) return { action: 'native-auto-merge-pending' };
        if (
            latest?.commit?.sha !== base ||
            !eligible(again, allowedAuthors) ||
            again.title !== pr.title ||
            again.head.sha !== pr.head.sha ||
            again.base.sha !== base ||
            again.merge_commit_sha !== pr.merge_commit_sha ||
            again.mergeable_state !== 'clean'
        )
            return { action: 'retry', number: pr.number };
        const result = await client.api(`/pulls/${pr.number}/merge`, {
            method: 'PUT',
            body: { sha: pr.head.sha, merge_method: 'merge' },
        });
        if (result?.merged !== true) throw new Error('GitHub did not confirm merge');
        return { action: 'merged', number: pr.number };
    }
    return { action: 'empty' };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const authors = new Set(
        (process.env.MERGE_QUEUE_ALLOWED_AUTHORS || '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean),
    );
    if (!authors.size || [...authors].some((s) => !/^[a-zA-Z0-9-]+(?:\[bot\])?$/.test(s)))
        throw new Error('Configure trusted GitHub author logins');
    const result = await reconcile(githubClient(process.env.MERGE_QUEUE_TOKEN), authors);
    const message = `Merge queue: ${result.action}${result.number ? ` (PR #${result.number})` : ''}`;
    console.log(message);
    if (process.env.GITHUB_STEP_SUMMARY) await appendFile(process.env.GITHUB_STEP_SUMMARY, `${message}\n`);
    if (['failure', 'conflict', 'review', 'main-failure'].includes(result.action)) {
        console.error(`::error::${message}; inspect the PR before retrying`);
        process.exitCode = 1;
    }
}

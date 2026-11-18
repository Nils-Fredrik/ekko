import { appendFile, readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { agentFromTitle, requiredReviewers, trustedPullRequest } from './policy.mjs';

export async function interactiveSource(event, { env = process.env, request = fetch, sensitivePaths } = {}) {
    const repo = env.GITHUB_REPOSITORY;
    if (repo !== 'Nils-Fredrik/ekko' || event.repository?.full_name !== repo) return false;
    const subject = ['issue_comment', 'pull_request_review_comment'].includes(env.GITHUB_EVENT_NAME)
        ? event.comment
        : env.GITHUB_EVENT_NAME === 'pull_request_review'
          ? event.review
          : env.GITHUB_EVENT_NAME === 'issues'
            ? event.issue
            : null;
    if (
        !subject ||
        !['OWNER', 'MEMBER', 'COLLABORATOR'].includes(subject.author_association) ||
        !`${subject.body || ''}\n${subject.title || ''}`.includes('@claude')
    )
        return false;
    const number = event.pull_request?.number ?? (event.issue?.pull_request ? event.issue.number : null);
    if (number === null) return env.GITHUB_EVENT_NAME === 'issues' || env.GITHUB_EVENT_NAME === 'issue_comment';
    if (!Number.isSafeInteger(number) || number < 1 || !env.GH_TOKEN) return false;
    async function api(path) {
        const response = await request(`https://api.github.com/repos/${repo}/pulls/${number}${path}`, {
            redirect: 'error',
            signal: AbortSignal.timeout(30_000),
            headers: { authorization: `Bearer ${env.GH_TOKEN}`, accept: 'application/vnd.github+json' },
        });
        if (!response.ok) throw new Error(`PR trust lookup returned ${response.status}`);
        return response.json();
    }
    const pr = await api('');
    const trusted =
        pr.number === number &&
        pr.head?.repo?.full_name === repo &&
        pr.base?.repo?.full_name === repo &&
        pr.base.ref === 'main';
    if (!trusted) return false;

    // A plain review command is redundant only when this PR already requires automatic Claude review.
    // Keep targeted questions/fixes, issue mentions and reviews outside that policy available.
    if (
        !/^\s*@claude\s+review[.!]?\s*$/i.test(subject.body || '') ||
        pr.state !== 'open' ||
        pr.draft ||
        !trustedPullRequest(pr, repo) ||
        !agentFromTitle(pr.title)
    )
        return true;
    if (agentFromTitle(pr.title) !== 'Claude Code') return false;

    if (!Number.isSafeInteger(pr.changed_files) || pr.changed_files < 1 || pr.changed_files >= 3000)
        throw new Error('Changed file list is incomplete');
    const files = [];
    for (let page = 1; page <= 30; page++) {
        const rows = await api(`/files?per_page=100&page=${page}`);
        if (!Array.isArray(rows)) throw new Error('Changed file list is invalid');
        files.push(...rows);
        if (rows.length < 100) break;
    }
    if (files.length !== pr.changed_files) throw new Error('Changed file list is incomplete');
    const current = await api('');
    if (current.head?.sha !== pr.head.sha || current.base?.sha !== pr.base.sha || current.title !== pr.title)
        throw new Error('PR changed while selecting its automatic reviewer');
    const paths =
        sensitivePaths ??
        JSON.parse(await readFile(new URL('../../.github/review-paths.yml', import.meta.url), 'utf8')).sensitive;
    const names = files.flatMap((file) => [file.filename, file.previous_filename].filter(Boolean));
    return !requiredReviewers(pr.title, names, paths).claude;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const event = JSON.parse(await readFile(process.env.GITHUB_EVENT_PATH, 'utf8'));
    await appendFile(process.env.GITHUB_OUTPUT, `trusted=${await interactiveSource(event)}\n`);
}

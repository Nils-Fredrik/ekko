import { appendFile, readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

export async function interactiveSource(event, { env = process.env, request = fetch } = {}) {
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
    const response = await request(`https://api.github.com/repos/${repo}/pulls/${number}`, {
        redirect: 'error',
        signal: AbortSignal.timeout(30_000),
        headers: { authorization: `Bearer ${env.GH_TOKEN}`, accept: 'application/vnd.github+json' },
    });
    if (!response.ok) throw new Error(`PR trust lookup returned ${response.status}`);
    const pr = await response.json();
    return (
        pr.number === number &&
        pr.head?.repo?.full_name === repo &&
        pr.base?.repo?.full_name === repo &&
        pr.base.ref === 'main'
    );
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const event = JSON.parse(await readFile(process.env.GITHUB_EVENT_PATH, 'utf8'));
    await appendFile(process.env.GITHUB_OUTPUT, `trusted=${await interactiveSource(event)}\n`);
}

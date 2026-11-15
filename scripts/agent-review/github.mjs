import { appendFile, readFile, writeFile } from 'node:fs/promises';
import { codexApproved, requiredReviewers } from './policy.mjs';

const repo = process.env.GITHUB_REPOSITORY;
const number = Number(process.env.PR_NUMBER);
const token = process.env.GH_TOKEN;
if (!/^[\w.-]+\/[\w.-]+$/.test(repo || '') || !Number.isSafeInteger(number) || number < 1 || !token) {
    throw new Error('Missing or invalid GitHub context');
}
const root = `https://api.github.com/repos/${repo}`;
const pull = `/pulls/${number}`;

async function api(path, { method = 'GET', body, auth = token, accept = 'application/vnd.github+json' } = {}) {
    const response = await fetch(`https://api.github.com${path}`, {
        method,
        headers: { authorization: `Bearer ${auth}`, accept, 'content-type': 'application/json', 'x-github-api-version': '2022-11-28' },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (!response.ok) throw new Error(`GitHub API ${method} ${path.split('?')[0]} returned ${response.status}`);
    return accept.endsWith('.diff') ? response.text() : response.json();
}

async function allPages(path) {
    const items = [];
    for (let page = 1; page <= 31; page++) {
        const data = await api(`/repos/${repo}${path}${path.includes('?') ? '&' : '?'}per_page=100&page=${page}`);
        if (!Array.isArray(data)) throw new Error('GitHub API returned unexpected pagination');
        items.push(...data);
        if (data.length < 100) return items;
    }
    throw new Error('GitHub API pagination limit reached');
}

async function getPull() {
    const pr = await api(`/repos/${repo}${pull}`);
    if (pr.state !== 'open' || pr.base.ref !== 'main' || pr.draft || !/^[a-f0-9]{40}$/.test(pr.head.sha) || !/^[a-f0-9]{40}$/.test(pr.base.sha) || !/^[a-f0-9]{40}$/.test(pr.merge_commit_sha || '')) {
        throw new Error('PR must be open, ready, mergeable, and target main');
    }
    return pr;
}

async function status(sha, state, description) {
    await api(`/repos/${repo}/statuses/${sha}`, {
        method: 'POST',
        body: { state, context: 'agent-review', description, target_url: `${process.env.GITHUB_SERVER_URL}/${repo}/actions/runs/${process.env.GITHUB_RUN_ID}` },
    });
}

function sameSnapshot(pr, expected) {
    return pr.head.sha === expected.head && pr.base.sha === expected.base && pr.merge_commit_sha === expected.merge && pr.title === expected.title;
}

async function prepare() {
    const pr = await getPull();
    if (pr.head.sha !== process.env.EVENT_HEAD || pr.base.sha !== process.env.EVENT_BASE) throw new Error('Stale event');
    // Set pending before review work starts. If anything fails, the gate stays closed.
    await status(pr.merge_commit_sha, 'pending', 'Venter på kryssreview');
    const files = await allPages(`${pull}/files`);
    if (files.length !== pr.changed_files || files.length >= 3000) throw new Error('Changed file list is incomplete');
    const names = files.flatMap((file) => [file.filename, file.previous_filename].filter(Boolean));
    const config = JSON.parse(await readFile('.github/review-paths.yml', 'utf8'));
    const required = requiredReviewers(pr.title, names, config.sensitive);
    const snapshot = { head: pr.head.sha, base: pr.base.sha, merge: pr.merge_commit_sha, title: pr.title, required };
    if (!sameSnapshot(await getPull(), snapshot)) throw new Error('PR changed while calculating review requirements');
    let requestedAt = '';
    if (required.codex) {
        if (!process.env.CODEX_TRIGGER_TOKEN) throw new Error('Codex trigger token missing');
        const comment = await api(`/repos/${repo}/issues/${number}/comments`, {
            method: 'POST', auth: process.env.CODEX_TRIGGER_TOKEN,
            body: { body: `@codex review\n\nPlease review the changes at commit ${snapshot.head}.` },
        });
        requestedAt = comment.created_at;
    }
    await appendFile(process.env.GITHUB_OUTPUT, `snapshot=${Buffer.from(JSON.stringify(snapshot)).toString('base64')}\nclaude=${required.claude}\ncodex=${required.codex}\nrequested_at=${requestedAt}\n`);
}

async function diff() {
    const snapshot = JSON.parse(Buffer.from(process.env.SNAPSHOT, 'base64').toString());
    if (!sameSnapshot(await getPull(), snapshot)) throw new Error('PR changed before Claude review');
    const body = await api(`/repos/${repo}${pull}`, { accept: 'application/vnd.github.v3.diff' });
    // The diff is data. It is never checked out, interpreted, or executed.
    if (Buffer.byteLength(body) > 5_000_000) throw new Error('Diff is too large for automatic review');
    await writeFile(`${process.env.RUNNER_TEMP}/review.diff`, body);
}

async function gate() {
    const snapshot = JSON.parse(Buffer.from(process.env.SNAPSHOT, 'base64').toString());
    try {
        if (snapshot.required.claude && process.env.CLAUDE_RESULT !== 'success') throw new Error('Claude review did not approve');
        const deadline = Date.now() + 90 * 60_000;
        do {
            if (!sameSnapshot(await getPull(), snapshot)) throw new Error('PR changed during review');
            if (!snapshot.required.codex || codexApproved(await allPages(`/issues/${number}/comments`), snapshot.head, process.env.REQUESTED_AT)) {
                if (!sameSnapshot(await getPull(), snapshot)) throw new Error('PR changed before publishing status');
                await status(snapshot.merge, 'success', 'Påkrevd kryssreview er godkjent');
                return;
            }
            await new Promise((resolve) => setTimeout(resolve, 30_000));
        } while (Date.now() < deadline);
        throw new Error('Codex review was not approved before timeout');
    } catch (error) {
        await status(snapshot.merge, 'failure', 'Kryssreview mangler eller feilet');
        throw error;
    }
}

switch (process.argv[2]) {
    case 'prepare': await prepare(); break;
    case 'diff': await diff(); break;
    case 'gate': await gate(); break;
    default: throw new Error('Unknown command');
}

import { appendFile, readFile, writeFile } from 'node:fs/promises';
import { codexApproved, requiredReviewers, sameSnapshot, claudeVerdict, reviewRounds } from './policy.mjs';
import { postReviewBlocker } from './board.mjs';
import { claudeBody } from './claude-comment.mjs';
import { pathToFileURL } from 'node:url';

export async function runReview(
    command,
    {
        env = process.env,
        request = fetch,
        now = Date.now,
        sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
    } = {},
) {
    const repo = env.GITHUB_REPOSITORY;
    const number = Number(env.PR_NUMBER);
    const token = env.GH_TOKEN;
    if (!/^[\w.-]+\/[\w.-]+$/.test(repo || '') || !Number.isSafeInteger(number) || number < 1 || !token) {
        throw new Error('Missing or invalid GitHub context');
    }
    const pull = `/pulls/${number}`;

    async function api(path, { method = 'GET', body, auth = token, accept = 'application/vnd.github+json' } = {}) {
        const response = await request(`https://api.github.com${path}`, {
            method,
            redirect: 'error',
            signal: AbortSignal.timeout(30_000),
            headers: {
                authorization: `Bearer ${auth}`,
                accept,
                'content-type': 'application/json',
                'x-github-api-version': '2022-11-28',
            },
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
        if (
            pr.state !== 'open' ||
            pr.base.ref !== 'main' ||
            pr.draft ||
            pr.mergeable === false ||
            !/^[a-f0-9]{40}$/.test(pr.head.sha) ||
            !/^[a-f0-9]{40}$/.test(pr.base.sha) ||
            !/^[a-f0-9]{40}$/.test(pr.merge_commit_sha || '')
        ) {
            throw new Error('PR must be open, ready, mergeable, and target main');
        }
        return pr;
    }

    async function status(sha, state, description) {
        await api(`/repos/${repo}/statuses/${sha}`, {
            method: 'POST',
            body: {
                state,
                context: 'agent-review',
                description,
                target_url: `${env.GITHUB_SERVER_URL}/${repo}/actions/runs/${env.GITHUB_RUN_ID}`,
            },
        });
    }

    async function prepare() {
        const pr = await getPull();
        if (pr.head.sha !== env.EVENT_HEAD || pr.base.sha !== env.EVENT_BASE) throw new Error('Stale event');
        // Set pending before review work starts. If anything fails, the gate stays closed.
        await status(pr.merge_commit_sha, 'pending', 'Venter på kryssreview');
        if (!env.BOARD_MCP_TOKEN) throw new Error('Board escalation credential missing');
        const files = await allPages(`${pull}/files`);
        if (files.length !== pr.changed_files || files.length >= 3000)
            throw new Error('Changed file list is incomplete');
        const names = files.flatMap((file) => [file.filename, file.previous_filename].filter(Boolean));
        const config = JSON.parse(await readFile('.github/review-paths.yml', 'utf8'));
        const required = requiredReviewers(pr.title, names, config.sensitive);
        const snapshot = {
            head: pr.head.sha,
            base: pr.base.sha,
            merge: pr.merge_commit_sha,
            title: pr.title,
            required,
            started_at: new Date(now()).toISOString(),
        };
        if (!sameSnapshot(await getPull(), snapshot))
            throw new Error('PR changed while calculating review requirements');
        let requestedAt = '';
        if (required.codex) {
            if (!env.CODEX_TRIGGER_TOKEN) throw new Error('Codex trigger token missing');
            const comment = await api(`/repos/${repo}/issues/${number}/comments`, {
                method: 'POST',
                auth: env.CODEX_TRIGGER_TOKEN,
                body: { body: `@codex review\n\nPlease review the changes at commit ${snapshot.head}.` },
            });
            requestedAt = comment.created_at;
        }
        await appendFile(
            env.GITHUB_OUTPUT,
            `snapshot=${Buffer.from(JSON.stringify(snapshot)).toString('base64')}\nclaude=${required.claude}\ncodex=${required.codex}\nrequested_at=${requestedAt}\n`,
        );
    }

    async function diff() {
        const snapshot = JSON.parse(Buffer.from(env.SNAPSHOT, 'base64').toString());
        if (!sameSnapshot(await getPull(), snapshot)) throw new Error('PR changed before Claude review');
        const body = await api(`/repos/${repo}${pull}`, { accept: 'application/vnd.github.v3.diff' });
        // The diff is data. It is never checked out, interpreted, or executed.
        if (Buffer.byteLength(body) > 5_000_000) throw new Error('Diff is too large for automatic review');
        if (!sameSnapshot(await getPull(), snapshot)) throw new Error('PR changed while fetching diff');
        await writeFile(`${env.RUNNER_TEMP}/review.diff`, body);
        const pr = await getPull();
        await writeFile(
            `${env.RUNNER_TEMP}/review-context.json`,
            JSON.stringify({ title: pr.title, body: pr.body, head: snapshot.head, base: snapshot.base }),
        );
    }

    async function gate() {
        const snapshot = JSON.parse(Buffer.from(env.SNAPSHOT, 'base64').toString());
        try {
            if (snapshot.required.claude && env.CLAUDE_RESULT !== 'success')
                throw new Error('Claude review did not approve');
            const deadline = now() + 90 * 60_000;
            do {
                if (!sameSnapshot(await getPull(), snapshot)) throw new Error('PR changed during review');
                const [comments, reviews] = snapshot.required.codex
                    ? await Promise.all([allPages(`/issues/${number}/comments`), allPages(`${pull}/reviews`)])
                    : [[], []];
                if (!snapshot.required.codex || codexApproved(comments, snapshot.head, env.REQUESTED_AT, reviews)) {
                    if (!sameSnapshot(await getPull(), snapshot))
                        throw new Error('PR changed before publishing status');
                    await status(snapshot.merge, 'success', 'Påkrevd kryssreview er godkjent');
                    await recordRound(snapshot, 'success');
                    return;
                }
                await sleep(30_000);
            } while (now() < deadline);
            throw new Error('Codex review was not approved before timeout');
        } catch (error) {
            await status(snapshot.merge, 'failure', 'Kryssreview mangler eller feilet');
            // Stale/cancelled code does not consume a new review round.
            if (sameSnapshot(await getPull(), snapshot)) await recordRound(snapshot, 'failure');
            throw error;
        }
    }

    async function recordRound(snapshot, result) {
        const round = { head: snapshot.head, base: snapshot.base, result, run: env.GITHUB_RUN_ID, blocker_sent: false };
        const comments = await allPages(`/issues/${number}/comments`);
        const prior = reviewRounds(comments);
        const updated = reviewRounds([
            ...comments,
            { user: { id: 41898282 }, body: `<!-- ekko-review-round:v1 ${JSON.stringify(round)} -->` },
        ]);
        if (result === 'failure' && updated.failures >= 3 && !prior.reported) {
            await postReviewBlocker({ token: env.BOARD_MCP_TOKEN, number, request });
            round.blocker_sent = true;
        }
        await api(`/repos/${repo}/issues/${number}/comments`, {
            method: 'POST',
            body: {
                body: `<!-- ekko-review-round:v1 ${JSON.stringify(round)} -->\nKryssreview for ${snapshot.head}: ${result === 'success' ? 'GODKJENT' : 'ENDRINGER KREVES / manglende review'}. Se Actions-kjøringen for resultat fra hver reviewer.`,
            },
        });
    }

    async function verdict() {
        const snapshot = JSON.parse(Buffer.from(env.SNAPSHOT, 'base64').toString());
        if (!sameSnapshot(await getPull(), snapshot)) throw new Error('PR changed before Claude verdict');
        const result = claudeVerdict(env.REVIEW_OUTPUT);
        if (!Number.isSafeInteger(result.comment_id) || result.comment_id < 1)
            throw new Error('Missing Claude review receipt');
        const comment = await api(`/repos/${repo}/issues/comments/${result.comment_id}`);
        if (
            comment.user?.id !== 209825114 ||
            comment.issue_url !== `https://api.github.com/repos/${repo}/issues/${number}` ||
            comment.body !== claudeBody(result, snapshot.head) ||
            !(Date.parse(comment.created_at) > Date.parse(snapshot.started_at))
        ) {
            throw new Error('Claude receipt does not match the structured verdict and revision');
        }
        await appendFile(env.GITHUB_OUTPUT, `verdict=${result.conclusion === 'GODKJENT' ? 'success' : 'failure'}\n`);
    }

    switch (command) {
        case 'prepare':
            await prepare();
            break;
        case 'diff':
            await diff();
            break;
        case 'gate':
            await gate();
            break;
        case 'verdict':
            await verdict();
            break;
        default:
            throw new Error('Unknown command');
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    await runReview(process.argv[2]);
}

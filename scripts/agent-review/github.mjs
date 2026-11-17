import { appendFile, mkdir, readFile, writeFile } from 'node:fs/promises';
import { codexReviewState, requiredReviewers, sameSnapshot, claudeVerdict, reviewRounds } from './policy.mjs';
import { postReviewBlocker } from './board.mjs';
import { claudeBody } from './claude-comment.mjs';
import { pathToFileURL } from 'node:url';

class ReviewFailure extends Error {
    constructor(kind, message) {
        super(message);
        this.kind = kind;
    }
}

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
        let pr;
        for (let attempt = 0; attempt < 6; attempt++) {
            pr = await api(`/repos/${repo}${pull}`);
            if (pr.mergeable !== null) break;
            await sleep(5000);
        }
        if (
            pr.state !== 'open' ||
            pr.base.ref !== 'main' ||
            pr.draft ||
            pr.mergeable !== true ||
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

    async function requestReview() {
        // Invalidate an earlier success even if mergeability lookup subsequently fails.
        if (!/^[a-f0-9]{40}$/.test(env.EVENT_HEAD || '')) throw new Error('Invalid event head');
        await status(env.EVENT_HEAD, 'pending', 'Venter på kryssreview');
    }

    async function prepare() {
        const current = await api(`/repos/${repo}${pull}`);
        if (
            current.head.sha !== env.EVENT_HEAD ||
            current.base.sha !== env.EVENT_BASE ||
            current.state !== 'open' ||
            current.draft
        ) {
            await report('stale', 'Utdatert reviewforespørsel; ingen ny status publisert.');
            return;
        }
        await requestReview();
        const pr = await getPull();
        if (pr.head.sha !== env.EVENT_HEAD || pr.base.sha !== env.EVENT_BASE) throw new Error('Stale event');
        reviewRounds([], Number(env.AGENT_REVIEW_BOT_ID));
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
        await mkdir(`${env.GITHUB_WORKSPACE}/.review`, { recursive: true });
        await writeFile(`${env.GITHUB_WORKSPACE}/.review/review.diff`, body);
        const pr = await getPull();
        if (!sameSnapshot(pr, snapshot)) throw new Error('PR changed before context capture');
        await writeFile(
            `${env.GITHUB_WORKSPACE}/.review/review-context.json`,
            JSON.stringify({ title: pr.title, body: pr.body, head: snapshot.head, base: snapshot.base }),
        );
    }

    async function ownsStatus(sha) {
        const statuses = await allPages(`/commits/${sha}/statuses`);
        // A queued/older execution must not replace a newer request or verdict.
        const latest = statuses.find(
            (item) => item.context === 'agent-review' && item.creator?.id === Number(env.AGENT_REVIEW_BOT_ID),
        );
        return latest?.target_url === `${env.GITHUB_SERVER_URL}/${repo}/actions/runs/${env.GITHUB_RUN_ID}`;
    }

    async function report(kind, description) {
        if (env.GITHUB_STEP_SUMMARY)
            await appendFile(env.GITHUB_STEP_SUMMARY, `### agent-review: ${kind}\n\n${description}\n`);
    }

    async function gate() {
        const snapshot = JSON.parse(Buffer.from(env.SNAPSHOT, 'base64').toString());
        async function assertCurrent() {
            const current = await getPull();
            if (!sameSnapshot(current, snapshot) || !(await ownsStatus(snapshot.head)))
                throw new ReviewFailure('stale', 'PR or review request changed during review');
        }
        try {
            await assertCurrent();
            if (snapshot.required.claude) {
                if (env.CLAUDE_JOB_RESULT !== 'success' || !['success', 'failure'].includes(env.CLAUDE_RESULT))
                    throw new ReviewFailure('technical', 'Claude execution failed or produced no verified verdict');
                if (env.CLAUDE_RESULT === 'failure')
                    throw new ReviewFailure('changes_requested', 'Claude review requested changes');
            }
            if (!env.BOARD_MCP_TOKEN) throw new Error('Board escalation credential missing');
            const deadline = now() + 45 * 60_000;
            do {
                await assertCurrent();
                const [comments, reviews] = snapshot.required.codex
                    ? await Promise.all([allPages(`/issues/${number}/comments`), allPages(`${pull}/reviews`)])
                    : [[], []];
                const codex = snapshot.required.codex
                    ? codexReviewState(comments, snapshot.head, env.REQUESTED_AT, reviews)
                    : 'approved';
                if (codex === 'changes_requested')
                    throw new ReviewFailure('changes_requested', 'Codex review requested changes');
                if (codex === 'approved') {
                    await assertCurrent();
                    await recordRound(snapshot, 'success');
                    await assertCurrent();
                    await status(snapshot.head, 'success', 'Påkrevd kryssreview er godkjent');
                    await report('approved', 'Alle påkrevde reviewere har godkjent aktuell kode og base.');
                    return;
                }
                await sleep(30_000);
            } while (now() < deadline);
            throw new ReviewFailure('missing', 'Codex review was not verified before timeout');
        } catch (error) {
            const current = await api(`/repos/${repo}${pull}`);
            const owned = await ownsStatus(snapshot.head);
            const stale = !sameSnapshot(current, snapshot) || !owned;
            const kind = stale ? 'stale' : error.kind || 'technical';
            const descriptions = {
                changes_requested: 'ENDRINGER KREVES: se reviewerens funn på PR-en',
                technical: 'Teknisk reviewfeil: ingen gyldig godkjenning; se Actions-loggen',
                missing: 'Review mangler eller kan ikke verifiseres; prøv ny kjøring',
                stale: 'Review er foreldet; oppdater PR-grenen for ny review',
            };
            // Preserve a newer status, including a new review of the same head.
            // If only main/title changed, invalidate our own obsolete approval.
            if (owned && current.head.sha === snapshot.head) {
                await status(snapshot.head, kind === 'changes_requested' ? 'failure' : 'error', descriptions[kind]);
            }
            if (kind === 'changes_requested') await recordRound(snapshot, 'failure');
            await report(kind, descriptions[kind]);
            if (kind !== 'stale') throw error;
        }
    }

    async function recordRound(snapshot, result) {
        const round = { head: snapshot.head, base: snapshot.base, result, run: env.GITHUB_RUN_ID, blocker_sent: false };
        const comments = await allPages(`/issues/${number}/comments`);
        const prior = reviewRounds(comments, Number(env.AGENT_REVIEW_BOT_ID));
        const updated = reviewRounds(
            [
                ...comments,
                {
                    user: { id: Number(env.AGENT_REVIEW_BOT_ID) },
                    body: `<!-- ekko-review-round:v1 ${JSON.stringify(round)} -->`,
                },
            ],
            Number(env.AGENT_REVIEW_BOT_ID),
        );
        if (result === 'failure' && updated.failures >= 3 && !prior.reported) {
            await postReviewBlocker({ token: env.BOARD_MCP_TOKEN, number, request });
            round.blocker_sent = true;
        }
        await api(`/repos/${repo}/issues/${number}/comments`, {
            method: 'POST',
            body: {
                body: `<!-- ekko-review-round:v1 ${JSON.stringify(round)} -->\nKryssreview for ${snapshot.head}: ${result === 'success' ? 'GODKJENT' : 'ENDRINGER KREVES'}. Se Actions-kjøringen for resultat fra hver reviewer.`,
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
        case 'request':
            await requestReview();
            break;
        case 'prepare':
            try {
                await prepare();
            } catch (error) {
                const current = await api(`/repos/${repo}${pull}`);
                if (current.head.sha === env.EVENT_HEAD && (await ownsStatus(env.EVENT_HEAD)))
                    await status(env.EVENT_HEAD, 'error', 'Review kunne ikke starte; kontroller PR og Actions-loggen');
                await report('technical', 'Review kunne ikke starte. Ingen faglig avslagsrunde er registrert.');
                throw error;
            }
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

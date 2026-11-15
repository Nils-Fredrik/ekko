import { matchesGlob } from 'node:path';

const CODEX_ID = 199175422;
const marker = /^<!-- codex-pull-request-review-summary -->\s*\n<!-- codex-review-state:v1 (\{[^\n]*\}) -->/;

// A malformed title or an incomplete file list must never waive a reviewer.
export function requiredReviewers(title, files, paths) {
    if (!Array.isArray(files) || !files.length || !Array.isArray(paths) || !paths.length) {
        throw new Error('Missing changed files or sensitive-path configuration');
    }
    const match = /^\[(Codex|Cursor|Claude Code)\] T\d+\s*[–-]\s*\S/.exec(title);
    if (!match) throw new Error('Invalid agent title');
    if (files.some((file) => typeof file !== 'string' || !file || file.startsWith('/'))) {
        throw new Error('Invalid changed-file path');
    }
    // node:path skips hidden segments for wildcards. Normalize leading dots in
    // both operands so security paths cover hidden files as well.
    const normalizeDots = (value) => value.replace(/(^|\/)\./g, '$1_');
    const sensitive = files.some((file) => paths.some((path) => matchesGlob(normalizeDots(file), normalizeDots(path))));
    return {
        claude: sensitive || match[1] !== 'Claude Code',
        codex: sensitive || match[1] === 'Claude Code',
    };
}

export function codexApproved(comments, sha, requestedAt, reviews = []) {
    if (!/^[0-9a-f]{40}$/.test(sha) || !Number.isFinite(Date.parse(requestedAt))) return false;
    const summaries = comments.filter((comment) => comment.user?.id === CODEX_ID && marker.test(comment.body || ''));
    if (summaries.length !== 1) return false;
    const summary = summaries[0];
    if (!(Date.parse(summary.updated_at) > Date.parse(requestedAt))) return false;
    // The sticky summary also changes for security reviews. Require a new code-review receipt,
    // not merely an edited timestamp on an older clean code result.
    const receipt = [...comments, ...reviews].some((item) => {
        if (
            item.user?.id !== CODEX_ID ||
            !item.body?.startsWith('<!-- codex-review-receipt:v1 -->') ||
            !(Date.parse(item.created_at ?? item.submitted_at) > Date.parse(requestedAt))
        )
            return false;
        const commit = /\*\*Reviewed commit:\*\* `([a-f0-9]{10,40})`/.exec(item.body)?.[1];
        return commit && sha.startsWith(commit) && (!item.commit_id || item.commit_id === sha);
    });
    if (!receipt) return false;
    try {
        const state = JSON.parse(marker.exec(summary.body)[1]).code_review;
        return (
            state?.status === 'completed' &&
            state.commit_sha === sha &&
            state.has_any_findings === false &&
            state.has_unresolved_findings === false
        );
    } catch {
        return false;
    }
}

export function sameSnapshot(pr, expected) {
    return (
        pr.state === 'open' &&
        !pr.draft &&
        pr.base.ref === 'main' &&
        pr.head.sha === expected.head &&
        pr.base.sha === expected.base &&
        pr.merge_commit_sha === expected.merge &&
        pr.title === expected.title
    );
}

export function claudeVerdict(raw) {
    const result = JSON.parse(raw);
    if (
        !['GODKJENT', 'ENDRINGER KREVES'].includes(result.conclusion) ||
        typeof result.summary !== 'string' ||
        !result.summary.trim() ||
        result.summary.length > 50_000
    ) {
        throw new Error('Invalid structured Claude verdict');
    }
    return result;
}

export function reviewRounds(comments, actorId) {
    if (!Number.isSafeInteger(actorId) || actorId < 1 || actorId === 41898282)
        throw new Error('A dedicated review App identity is required');
    const prefix = /^<!-- ekko-review-round:v1 (\{[^\n]*\}) -->/;
    const rounds = [];
    for (const comment of comments) {
        if (comment.user?.id !== actorId) continue; // Dedicated App, never the shared GitHub Actions bot.
        const match = prefix.exec(comment.body || '');
        if (!match) continue;
        try {
            const round = JSON.parse(match[1]);
            if (/^[a-f0-9]{40}$/.test(round.head) && ['success', 'failure'].includes(round.result)) rounds.push(round);
        } catch {
            /* Malformed records cannot grant approval. */
        }
    }
    const lastSuccess = rounds.findLastIndex((round) => round.result === 'success');
    const failures = rounds.slice(lastSuccess + 1).filter((round) => round.result === 'failure');
    // Edited events and re-runs on the same code are one review round, not three.
    return {
        failures: new Set(failures.map((round) => round.head)).size,
        reported: failures.some((round) => round.blocker_sent === true),
    };
}

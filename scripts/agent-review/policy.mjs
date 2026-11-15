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
    const sensitive = files.some((file) => paths.some((path) =>
        matchesGlob(normalizeDots(file), normalizeDots(path))
    ));
    return {
        claude: sensitive || match[1] !== 'Claude Code',
        codex: sensitive || match[1] === 'Claude Code',
    };
}

export function codexApproved(comments, sha, requestedAt) {
    if (!/^[0-9a-f]{40}$/.test(sha) || !Number.isFinite(Date.parse(requestedAt))) return false;
    const summaries = comments.filter((comment) => comment.user?.id === CODEX_ID && marker.test(comment.body || ''));
    if (summaries.length !== 1) return false;
    const summary = summaries[0];
    if (!(Date.parse(summary.updated_at) > Date.parse(requestedAt))) return false;
    try {
        const state = JSON.parse(marker.exec(summary.body)[1]).code_review;
        return state?.status === 'completed' && state.commit_sha === sha &&
            state.has_any_findings === false && state.has_unresolved_findings === false;
    } catch {
        return false;
    }
}

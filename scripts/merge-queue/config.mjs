import { appendFileSync, readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export function reviewBots(slug = '') {
    if (slug !== '' && !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
        throw new Error('Require one exact GitHub App slug without [bot]');
    }
    return ['claude', 'chatgpt-codex-connector', 'cursor', ...(slug ? [slug] : [])].join(',');
}

export function verifyQueueBot(configured, actual) {
    reviewBots(configured);
    if (!configured || configured !== actual) {
        throw new Error('Queue token App must match the configured review bot slug');
    }
}

export function auditEnvironment({ environment, policies }) {
    if (
        environment?.name !== 'merge-queue' ||
        environment.deployment_branch_policy?.custom_branch_policies !== true ||
        environment.deployment_branch_policy?.protected_branches !== false ||
        policies?.branch_policies?.length !== 1 ||
        policies.branch_policies[0].name !== 'main' ||
        policies.branch_policies[0].type !== 'branch'
    ) {
        throw new Error('Require the merge-queue environment with only the main branch allowed');
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    switch (process.argv[2]) {
        case 'review-bots':
            appendFileSync(process.env.GITHUB_OUTPUT, `allowed-bots=${reviewBots(process.env.MERGE_QUEUE_BOT_SLUG)}\n`);
            break;
        case 'verify-token':
            verifyQueueBot(process.env.MERGE_QUEUE_BOT_SLUG, process.env.MERGE_QUEUE_TOKEN_APP_SLUG);
            break;
        case 'audit-environment':
            auditEnvironment(JSON.parse(readFileSync(0, 'utf8')));
            console.log('Merge queue credential environment allows only main.');
            break;
        default:
            throw new Error('Unknown merge queue configuration command');
    }
}

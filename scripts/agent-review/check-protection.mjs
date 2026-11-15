import { readFileSync } from 'node:fs';

// Read-only audit: pipe `gh api repos/Nils-Fredrik/ekko/branches/main/protection` here.
const protection = JSON.parse(readFileSync(0, 'utf8'));
const checks = protection.required_status_checks;
const contexts = new Set([...(checks?.contexts || []), ...(checks?.checks || []).map((check) => check.context)]);
if (
    checks?.strict !== true ||
    protection.enforce_admins?.enabled !== true ||
    !contexts.has('test') ||
    !contexts.has('agent-review') ||
    protection.allow_force_pushes?.enabled === true ||
    protection.allow_deletions?.enabled === true
) {
    throw new Error(
        'main must enforce current-base CI + agent-review for administrators and disallow force pushes/deletion',
    );
}
console.log('main protection satisfies the required review and CI checks.');

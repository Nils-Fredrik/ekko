import { readFileSync } from 'node:fs';

// Read-only audit. Input combines protection, environment and branch-policy metadata.
const { protection, environment, policies, app_id: appId } = JSON.parse(readFileSync(0, 'utf8'));
const checks = protection?.required_status_checks;
const contexts = new Set([...(checks?.contexts || []), ...(checks?.checks || []).map((check) => check.context)]);
if (
    !Number.isSafeInteger(appId) ||
    appId < 1 ||
    appId === 15368 ||
    checks?.strict !== true ||
    protection?.enforce_admins?.enabled !== true ||
    !contexts.has('test') ||
    !checks?.checks?.some((check) => check.context === 'test' && check.app_id === 15368) ||
    !checks?.checks?.some((check) => check.context === 'container build' && check.app_id === 15368) ||
    !checks?.checks?.some((check) => check.context === 'agent-review' && check.app_id === appId) ||
    protection.allow_force_pushes?.enabled === true ||
    protection.allow_deletions?.enabled === true ||
    environment?.name !== 'agent-review' ||
    environment?.deployment_branch_policy?.custom_branch_policies !== true ||
    policies?.branch_policies?.length !== 1 ||
    policies.branch_policies[0].name !== 'main' ||
    policies.branch_policies[0].type !== 'branch'
) {
    throw new Error(
        'Require strict CI, dedicated-App agent-review, admin enforcement and a main-only review environment',
    );
}
console.log('main protection and review credential environment satisfy the required controls.');

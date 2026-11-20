export function criticalViolations(scan) {
    return scan.violations.filter((violation) => violation.impact === 'critical');
}

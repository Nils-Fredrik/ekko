import { execFileSync } from 'node:child_process';

export default function setup() {
    const fixture = JSON.parse(
        execFileSync('php', ['tests/Browser/seed.php'], {
            env: { ...process.env, EKKO_BROWSER_TEST: '1' },
            encoding: 'utf8',
        }),
    );
    Object.assign(process.env, fixture);
}

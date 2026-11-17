import { execFileSync } from 'node:child_process';

// Load only the designated test database from Laravel's environment, never credentials.
const database = execFileSync(
    'php',
    [
        '-r',
        'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo env("DB_TEST_DATABASE");',
    ],
    { encoding: 'utf8' },
).trim();
if (!/^[A-Za-z0-9_-]+_testing$/.test(database)) {
    throw new Error('DB_TEST_DATABASE must name a dedicated _testing database');
}
export const databaseEnv = { DB_DATABASE: database };

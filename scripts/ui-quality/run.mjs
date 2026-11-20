import { execFileSync, spawn } from 'node:child_process';
import { readFileSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';

if (process.env.CI && process.argv.some((arg) => arg.startsWith('--update-snapshots') || arg === '-u'))
    throw new Error('CI must not update visual baselines');

const image = readFileSync('tests/Browser/visual/browser-image.txt', 'utf8').trim();
if (!/^mcr\.microsoft\.com\/playwright:v[\d.]+-noble@sha256:[a-f0-9]{64}$/.test(image))
    throw new Error('Pin the official Playwright image by digest');
const version = JSON.parse(readFileSync('node_modules/playwright/package.json', 'utf8')).version;
if (!image.includes(`:v${version}-`)) throw new Error('Playwright package and browser image versions must match');
const container = `ekko-ui-${process.pid}`;
const docker = (args) => execFileSync('docker', args, { encoding: 'utf8' }).trim();
let child;
let interrupted = false;
const stop = () => {
    interrupted = true;
    child?.kill('SIGINT');
};
process.once('SIGINT', stop);
process.once('SIGTERM', stop);
try {
    docker([
        'run',
        '--platform',
        'linux/amd64',
        '--name',
        container,
        '--rm',
        '--init',
        '-d',
        '--ipc=host',
        '-p',
        '127.0.0.1::3000',
        '--mount',
        `type=bind,source=${resolve('node_modules')},target=/runner/node_modules,readonly`,
        '-w',
        '/runner',
        image,
        'node',
        'node_modules/playwright/cli.js',
        'run-server',
        '--host',
        '0.0.0.0',
        '--port',
        '3000',
    ]);
    if (interrupted) throw new Error('UI run interrupted');
    const port = docker(['port', container, '3000/tcp']).split(':').at(-1);
    let ready = false;
    for (let i = 0; i < 60; i++) {
        if (interrupted) throw new Error('UI run interrupted');
        if (docker(['logs', container]).includes('Listening on')) {
            ready = true;
            break;
        }
        await delay(500);
    }
    if (!ready) throw new Error('Pinned browser did not start');
    child = spawn(
        process.execPath,
        ['node_modules/playwright/cli.js', 'test', '-c', 'playwright.visual.config.mjs', ...process.argv.slice(2)],
        {
            stdio: 'inherit',
            env: {
                ...process.env,
                BROWSER_PORT: process.env.BROWSER_PORT ?? '8013',
                UI_BROWSER_WS: `ws://127.0.0.1:${port}/`,
            },
        },
    );
    process.exitCode = await new Promise((ok, fail) => {
        child.once('error', fail);
        child.once('exit', (code) => ok(code ?? 1));
    });
} finally {
    try {
        docker(['stop', container]);
    } catch {
        /* Already removed after an interrupted run. */
    }
    rmSync('.ui-auth', { recursive: true, force: true });
}

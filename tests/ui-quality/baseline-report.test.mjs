import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { PNG } from 'pngjs';

test('baseline report shows added, changed and removed images against the base commit', () => {
    const cwd = mkdtempSync(join(tmpdir(), 'ekko-ui-report-'));
    const script = resolve('scripts/ui-quality/compare-baselines.mjs');
    const git = (args) =>
        execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
    try {
        const root = join(cwd, 'tests/Browser/visual/snapshots/desktop-dark');
        mkdirSync(root, { recursive: true });
        const png = new PNG({ width: 2, height: 2 });
        png.data.fill(255);
        for (const name of ['changed', 'removed', 'same'])
            writeFileSync(join(root, `${name}.png`), PNG.sync.write(png));
        git(['init']);
        git(['add', '.']);
        git([
            '-c',
            'user.name=UI fixture',
            '-c',
            'user.email=fixture@example.test',
            'commit',
            '-m',
            'Initial synthetic images',
        ]);
        const base = git(['rev-parse', 'HEAD']);
        png.data.fill(0);
        for (const name of ['changed', 'added']) writeFileSync(join(root, `${name}.png`), PNG.sync.write(png));
        rmSync(join(root, 'removed.png'));
        execFileSync(process.execPath, [script], { cwd, env: { ...process.env, UI_BASE_SHA: base } });
        const report = readFileSync(join(cwd, 'ui-quality-results/baseline-changes/index.html'), 'utf8');
        assert.match(report, /changed.png: 4 changed pixels/);
        assert.match(report, /added.png: Added/);
        assert.match(report, /removed.png: Removed/);
        assert.doesNotMatch(report, /same.png/);
    } finally {
        rmSync(cwd, { recursive: true, force: true });
    }
});

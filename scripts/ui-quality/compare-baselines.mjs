import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

const root = 'tests/Browser/visual/snapshots';
const output = 'ui-quality-results/baseline-changes';
mkdirSync(output, { recursive: true });
const base = process.env.UI_BASE_SHA;
const git = (args) => execFileSync('git', args, { maxBuffer: 20 * 1024 * 1024 });
const validBase = base && /^[a-f0-9]{40}$/.test(base) && !/^0+$/.test(base);
if (base && !/^[a-f0-9]{40}$/.test(base)) throw new Error('UI_BASE_SHA must be a full commit SHA');
if (validBase) {
    try {
        git(['cat-file', '-e', `${base}^{commit}`]);
    } catch {
        git(['fetch', '--no-tags', '--depth=1', 'origin', base]);
    }
}
const current = existsSync(root)
    ? readdirSync(root, { recursive: true })
          .filter((p) => p.endsWith('.png'))
          .map((p) => `${root}/${p}`)
    : [];
const previous = validBase
    ? git(['ls-tree', '-r', '--name-only', base, '--', root]).toString().trim().split('\n').filter(Boolean)
    : [];
const rows = [];
for (const path of [...new Set([...current, ...previous])].sort()) {
    if (!/^tests\/Browser\/visual\/snapshots\/[a-z-]+\/[a-z-]+\.png$/.test(path))
        throw new Error(`Unexpected snapshot path: ${path}`);
    const before = previous.includes(path) ? git(['show', `${base}:${path}`]) : null;
    const after = current.includes(path) ? readFileSync(path) : null;
    if (before && after && before.equals(after)) continue;
    const name = path.slice(root.length + 1).replace('/', '-');
    let difference = 'Added';
    if (before) writeFileSync(join(output, `before-${name}`), before);
    if (after) writeFileSync(join(output, `after-${name}`), after);
    let diffLink = '';
    if (before && after) {
        const a = PNG.sync.read(before),
            b = PNG.sync.read(after);
        if (a.width === b.width && a.height === b.height) {
            const diff = new PNG({ width: a.width, height: a.height });
            const pixels = pixelmatch(a.data, b.data, diff.data, a.width, a.height, { threshold: 0.1 });
            difference = `${pixels} changed pixels`;
            writeFileSync(join(output, `diff-${name}`), PNG.sync.write(diff));
            diffLink = `<a href="diff-${name}"><img src="diff-${name}" alt="Pixel difference"></a>`;
        } else difference = `Dimensions changed: ${a.width}×${a.height} → ${b.width}×${b.height}`;
    } else if (!after) difference = 'Removed';
    rows.push(
        `<h2>${name}: ${difference}</h2><div>${before ? `<a href="before-${name}"><img src="before-${name}" alt="Base branch"></a>` : '<p>No previous baseline</p>'}${after ? `<a href="after-${name}"><img src="after-${name}" alt="PR baseline"></a>` : '<p>Removed baseline</p>'}${diffLink}</div>`,
    );
}
writeFileSync(
    join(output, 'index.html'),
    `<!doctype html><html lang="en"><meta charset="utf-8"><title>UI baseline changes</title><style>body{font:16px system-ui;margin:2rem}div{display:flex;gap:1rem;align-items:start}a{width:32%}img{width:100%;border:1px solid #aaa}h2{margin-top:3rem}</style><h1>UI baseline changes</h1><p>${validBase ? `Compared with ${base}.` : 'Initial baseline: no previous commit supplied.'} These are committed reference changes; actual test failures are in the Playwright report.</p>${rows.join('\n') || '<p>No baseline changes.</p>'}</html>`,
);
console.log(`${rows.length} baseline changes: ${output}/index.html`);

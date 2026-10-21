#!/usr/bin/env node
/**
 * Asset reference scanner.
 *
 * Builds an inventory of static files and tells which of them are referenced
 * from the project sources. Used for the "cleanup unused files" stage: the
 * report is a *hint*, every candidate must be reviewed manually.
 *
 * Usage:
 *   node tools/asset-references.mjs            # human report
 *   node tools/asset-references.mjs --json     # machine report
 *   node tools/asset-references.mjs --dir img  # only one subtree
 *
 * Heuristics:
 *   - a file is "used" when its project-relative path appears in any source;
 *   - a file is "name-used" when its basename appears in any source
 *     (covers dynamically composed paths, e.g. img/modules/<name>.png);
 *   - CSS url(...) references are resolved relative to the CSS file;
 *   - references are searched in source files only (php/html/js/css/json/md/
 *     sql/tpl/inc/yml/xml/txt), never in the asset itself.
 */

import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');

const SOURCE_EXT = new Set([
    '.php', '.html', '.htm', '.js', '.mjs', '.css', '.json', '.md', '.sql',
    '.tpl', '.inc', '.yml', '.yaml', '.xml', '.txt', '.sh', '.bat', '.service',
    '.example', '.sample', '.conf',
]);

const ASSET_EXT = new Set([
    '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.ico', '.bmp',
    '.woff', '.woff2', '.ttf', '.eot', '.otf',
    '.css', '.js', '.mjs', '.map',
    '.mp3', '.ogg', '.mp4', '.swf', '.wav',
    '.exe', '.bat', '.au3', '.pem',
]);

const SKIP_DIR = new Set([
    'node_modules', '.git', '.idea', '.vite', 'docs', '.codex', '.agents', '.aia',
]);

const args = process.argv.slice(2);
const asJson = args.includes('--json');
const onlyDir = (() => {
    const i = args.indexOf('--dir');
    return i >= 0 && args[i + 1] ? args[i + 1].replace(/[\\/]+$/, '') : null;
})();

/** @type {Map<string, string>} relative posix path -> content */
const sources = new Map();
/** @type {string[]} */
const assets = [];

function walk(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (entry.isSymbolicLink()) continue;
        const abs = path.join(dir, entry.name);
        const rel = path.relative(ROOT, abs).split(path.sep).join('/');
        if (entry.isDirectory()) {
            if (SKIP_DIR.has(entry.name)) continue;
            if (rel === 'public' && entry.name === 'public') {
                walk(abs);
                continue;
            }
            walk(abs);
            continue;
        }
        const ext = path.extname(entry.name).toLowerCase();
        if (SOURCE_EXT.has(ext)) {
            try {
                sources.set(rel, fs.readFileSync(abs, 'utf8'));
            } catch {
                // binary or unreadable
            }
        }
        if (ASSET_EXT.has(ext)) {
            assets.push(rel);
        }
    }
}

walk(ROOT);

// Index: every source string, lowercased, for cheap substring lookups.
const haystack = new Map();
for (const [rel, text] of sources) {
    haystack.set(rel, text.toLowerCase());
}

const cssUrlRefs = [];
for (const [rel, text] of sources) {
    if (!rel.endsWith('.css')) continue;
    const dir = path.posix.dirname(rel);
    for (const m of text.matchAll(/url\(\s*['"]?([^'")]+)['"]?\s*\)/gi)) {
        let target = m[1].trim();
        if (/^(?:data:|[a-z]+:|\/\/)/i.test(target)) continue;
        target = target.split(/[?#]/)[0];
        const resolved = target.startsWith('/')
            ? target.replace(/^\/+/, '')
            : path.posix.normalize(path.posix.join(dir, target));
        cssUrlRefs.push({ from: rel, target: resolved });
    }
}

function findPathRefs(rel) {
    const needle = rel.toLowerCase();
    const hits = [];
    for (const [src, text] of haystack) {
        if (src === rel) continue;
        if (text.includes(needle)) hits.push(src);
    }
    return hits;
}

function findNameRefs(rel, base) {
    const needle = base.toLowerCase();
    const hits = [];
    for (const [src, text] of haystack) {
        if (src === rel) continue;
        if (text.includes(needle)) hits.push(src);
    }
    return hits;
}

const cssTargets = new Map();
for (const ref of cssUrlRefs) {
    if (!cssTargets.has(ref.target)) cssTargets.set(ref.target, []);
    cssTargets.get(ref.target).push(ref.from);
}

const rows = [];
for (const rel of assets) {
    if (onlyDir && !rel.startsWith(onlyDir + '/')) continue;
    const base = path.posix.basename(rel);
    const pathRefs = findPathRefs(rel);
    const nameRefs = pathRefs.length ? [] : findNameRefs(rel, base);
    const fromCss = cssTargets.get(rel) || [];
    rows.push({
        file: rel,
        size: fs.statSync(path.join(ROOT, rel)).size,
        pathRefs,
        nameRefs,
        fromCss,
        status: pathRefs.length || fromCss.length
            ? 'used'
            : nameRefs.length
                ? 'name-only'
                : 'orphan',
    });
}

rows.sort((a, b) => a.status.localeCompare(b.status) || a.file.localeCompare(b.file));

if (asJson) {
    console.log(JSON.stringify(rows, null, 2));
} else {
    const groups = { orphan: [], 'name-only': [], used: [] };
    for (const row of rows) groups[row.status].push(row);

    const kb = (n) => `${(n / 1024).toFixed(1)} KB`;
    const total = (list) => list.reduce((sum, r) => sum + r.size, 0);

    console.log(`Scanned ${sources.size} source files, ${rows.length} assets.\n`);

    console.log(`ORPHAN (no path and no basename reference): ${groups.orphan.length} files, ${kb(total(groups.orphan))}`);
    for (const row of groups.orphan) console.log(`  ${row.file}  (${kb(row.size)})`);

    console.log(`\nNAME-ONLY (referenced by basename somewhere — verify): ${groups['name-only'].length} files, ${kb(total(groups['name-only']))}`);
    for (const row of groups['name-only']) {
        console.log(`  ${row.file}  (${kb(row.size)})  <- ${row.nameRefs.slice(0, 3).join(', ')}`);
    }

    console.log(`\nUSED: ${groups.used.length} files, ${kb(total(groups.used))}`);
}

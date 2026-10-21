// Generates the Tabler Icons catalog used by the UI Kit icon browser.
// Usage: npm run icons
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const cssPath = path.join(root, 'node_modules', '@tabler', 'icons-webfont', 'dist', 'tabler-icons.min.css');
const outPath = path.join(root, 'lib', 'ui', 'tabler-icons.json');

const css = readFileSync(cssPath, 'utf8');
const names = [...css.matchAll(/\.ti-([a-z0-9-]+):before/g)].map((match) => match[1]);
const unique = [...new Set(names)].sort((a, b) => a.localeCompare(b, 'en'));

mkdirSync(path.dirname(outPath), { recursive: true });
writeFileSync(outPath, JSON.stringify(unique, null, 0) + '\n', 'utf8');

console.log(`Tabler Icons catalog: ${unique.length} icons -> ${outPath}`);

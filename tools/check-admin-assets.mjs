import { existsSync, statSync } from 'node:fs';

const required = [
  'assets/admin/admin.js',
  'assets/admin/admin.css',
  'assets/admin/codeeditor.js',
  'assets/admin/codeeditor.css',
  'assets/admin/charts.js',
  'assets/admin/charts.css',
];

const missing = required.filter((file) => !existsSync(file) || statSync(file).size === 0);

if (missing.length) {
  console.error('Admin assets are incomplete:');
  for (const file of missing) {
    console.error('  - ' + file);
  }
  console.error('Run `npm run build` (admin bundle + code editor chunk + charts chunk) or the matching build:* script.');
  process.exit(1);
}

console.log('Admin assets OK: ' + required.join(', '));

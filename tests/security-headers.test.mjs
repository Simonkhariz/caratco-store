// The security headers every storefront and admin page sends (next.config.mjs), added after the
// 22 Nov 2026 penetration test, and the startup file that keeps visitor addresses honest on
// shared hosting (deploy/cpanel/server-entry.js).
// Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
import os from 'node:os';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

async function headersFor(env) {
  const before = process.env.NODE_ENV;
  process.env.NODE_ENV = env;
  try {
    const { default: config } = await import(`../next.config.mjs?env=${env}`);
    const rules = await config.headers();
    const all = rules.find((r) => r.source === '/:path*');
    return { config, headers: Object.fromEntries(all.headers.map((h) => [h.key, h.value])) };
  } finally {
    process.env.NODE_ENV = before;
  }
}

test('no page can be framed by another site, and types are never guessed', async () => {
  const { headers, config } = await headersFor('production');
  assert.equal(headers['X-Frame-Options'], 'DENY');
  assert.match(headers['Content-Security-Policy'], /frame-ancestors 'none'/);
  assert.match(headers['Content-Security-Policy'], /object-src 'none'/);
  assert.match(headers['Content-Security-Policy'], /base-uri 'self'/);
  assert.equal(headers['X-Content-Type-Options'], 'nosniff');
  assert.equal(headers['Referrer-Policy'], 'strict-origin-when-cross-origin');
  assert.match(headers['Permissions-Policy'], /geolocation=\(self\)/);
  assert.match(headers['Strict-Transport-Security'], /max-age=31536000/);
  assert.equal(config.poweredByHeader, false);
});

test('https is only demanded in production builds', async () => {
  const { headers } = await headersFor('development');
  assert.equal(headers['Strict-Transport-Security'], undefined);
});

test('the hosting startup file adds the real visitor address after any made-up one', async () => {
  // Run server-entry.js against a stand-in "next-server.js" that answers with the header it saw.
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'shopco-entry-'));
  fs.copyFileSync(path.join(root, 'deploy', 'cpanel', 'server-entry.js'), path.join(dir, 'server.js'));
  fs.writeFileSync(
    path.join(dir, 'next-server.js'),
    "const http = require('http');\n" +
      "const s = http.createServer((req, res) => res.end(String(req.headers['x-forwarded-for'] || '')));\n" +
      "s.listen(0, '127.0.0.1', () => { module.exports.port = s.address().port; module.exports.ready(); });\n" +
      'module.exports.server = s;\n'
  );
  const require = createRequire(path.join(dir, 'server.js'));
  const stub = require('./next-server.js');
  const ready = new Promise((resolve) => {
    stub.ready = resolve;
  });
  require('./server.js');
  await ready;
  const ask = (headers) =>
    new Promise((resolve, reject) => {
      http.get({ host: '127.0.0.1', port: stub.port, path: '/', headers }, (res) => {
        let body = '';
        res.on('data', (c) => (body += c));
        res.on('end', () => resolve(body));
      }).on('error', reject);
    });
  try {
    assert.equal(await ask({}), '127.0.0.1');
    assert.equal(await ask({ 'X-Forwarded-For': '6.6.6.6' }), '6.6.6.6, 127.0.0.1');
  } finally {
    stub.server.close();
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

// 2026-10-23 — только GET /collections по официальному контракту Qdrant.
const http = require('http');

const json = (res, status, body) => {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(body));
};

http.createServer((req, res) => {
  if (req.method !== 'GET' || !req.url.endsWith('/collections')) return json(res, 501, { error: 'Endpoint not modeled' });
  const mode = req.url.split('/').filter(Boolean)[0];
  if (mode.startsWith('status-')) return json(res, Number(mode.slice(7)), { status: { error: 'fixture-secret-do-not-leak' } });
  if (mode === 'redirect') {
    res.writeHead(302, { Location: 'http://127.0.0.1:1/never-follow' });
    return res.end();
  }
  if (mode === 'timeout') return setTimeout(() => json(res, 200, {}), 1500);
  if (mode === 'invalid-json') {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end('<html>fixture-secret-do-not-leak</html>');
  }
  if (mode === 'invalid-shape') return json(res, 200, { status: 'ok', result: { collections: {} } });
  if (mode === 'collections') return json(res, 200, { status: 'ok', result: { collections: [] }, time: 0.001 });
  return json(res, 501, { error: 'Endpoint not modeled' });
}).listen(process.env.PORT);

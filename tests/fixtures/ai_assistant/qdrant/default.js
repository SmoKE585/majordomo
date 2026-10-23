// 2026-10-23 — только GET /collections по официальному контракту Qdrant.
const http = require('http');

const json = (res, status, body) => {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(body));
};

const collections = new Map();
http.createServer((req, res) => {
  if (req.url.startsWith('/index/collections')) {
    const path = new URL(req.url, 'http://127.0.0.1').pathname;
    const parts = path.split('/').filter(Boolean);
    const name = parts[2];
    if (!name && req.method === 'GET') return json(res, 200, {status: 'ok', result: {collections: [...collections.keys()].map(name => ({name}))}});
    if (parts.length === 3 && req.method === 'PUT') { collections.set(name, new Map()); return json(res, 200, {status: 'ok', result: true}); }
    const points = collections.get(name);
    if (!points) return json(res, 404, {status: {error: 'Collection not found'}});
    if (req.method === 'DELETE' && parts.length === 3) { collections.delete(name); return json(res, 200, {status: 'ok', result: true}); }
    if (req.method === 'GET') return json(res, 200, {status: 'ok', result: {points_count: points.size}});
    const body = req.body || {};
    if (req.method === 'PUT' && path.endsWith('/points')) {
      for (const point of body.points || []) points.set(point.id, point);
      return json(res, 200, {status: 'ok', result: {status: 'completed'}});
    }
    const filter = body.filter || {};
    const matches = point => (filter.must || []).every(c => point.payload[c.key] === c.match.value)
      && (filter.must_not || []).every(c => point.payload[c.key] !== c.match.value);
    if (path.endsWith('/scroll')) return json(res, 200, {status: 'ok', result: {points: [...points.values()].filter(matches), next_page_offset: null}});
    if (path.endsWith('/delete')) { for (const [id, point] of points) if (matches(point)) points.delete(id); return json(res, 200, {status: 'ok', result: {status: 'completed'}}); }
    return json(res, 501, {error: 'Endpoint not modeled'});
  }
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

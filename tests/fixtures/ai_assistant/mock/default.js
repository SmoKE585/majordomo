// 2026-10-23 — локальный контракт Chat Completions/embeddings для проверок Neuron.
const http = require('http');

const json = (res, status, body) => {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(body));
};

http.createServer((req, res) => {
  if (req.method !== 'POST' || !['/v1/chat/completions', '/v1/embeddings'].includes(req.url)) {
    return json(res, 501, { error: { message: 'Endpoint not modeled' } });
  }
  const body = req.body;
  if (!body || typeof body.model !== 'string') return json(res, 400, { error: { message: 'model required' } });
  if (body.model.startsWith('stream-')) {
    if (body.model === 'stream-timeout') return setTimeout(() => json(res, 200, {}), 1500);
    const toolRounds = body.model === 'stream-loop-30' ? body.messages.filter(message => message.role === 'tool').length : 0;
    if (body.model === 'stream-loop-30' && toolRounds >= 30 && body.tools?.length) return json(res, 400, {});
    res.writeHead(200, {'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache'});
    const chunk = (delta, finish = null) => res.write('data: ' + JSON.stringify({id: 'stream-fixture', object: 'chat.completion.chunk', model: body.model, choices: [{index: 0, delta, finish_reason: finish}]}) + '\n\n');
    if (body.model === 'stream-loop-30' && toolRounds < 30) {
      chunk({role: 'assistant', tool_calls: [{index: 0, id: 'call-' + toolRounds, type: 'function', function: {name: 'find_objects', arguments: '{"query":"Lamp"}'}}]});
      chunk({}, 'tool_calls');
      return res.end('data: [DONE]\n\n');
    }
    chunk({role: 'assistant', content: 'Часть ответа'});
    if (body.model === 'stream-drop') return setTimeout(() => res.end(), 20);
    chunk({content: '. Готово.'}); chunk({}, 'stop');
    return res.end('data: [DONE]\n\n');
  }
  if (body.model.startsWith('status-')) {
    return json(res, Number(body.model.slice(7)), { error: { message: 'upstream-fixture-secret-must-not-leak' } });
  }
  if (body.model === 'redirect') {
    res.writeHead(302, { Location: 'http://127.0.0.1:1/never-follow' });
    return res.end();
  }
  if (body.model === 'same-origin-redirect') {
    res.writeHead(307, { Location: '/provider/v1/chat/completions' });
    return res.end();
  }
  if (body.model === 'timeout') return setTimeout(() => json(res, 200, {}), 1500);
  if (body.model === 'invalid-json') {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end('<html>upstream-fixture-secret-must-not-leak</html>');
  }
  if (body.model === 'invalid-shape') return json(res, 200, {});
  if (body.model === 'without-token' && req.headers.authorization) return json(res, 401, {});
  if (req.url.endsWith('/embeddings')) {
    if (!(typeof body.input === 'string' || Array.isArray(body.input)) || body.encoding_format !== 'float') return json(res, 400, {});
    return json(res, 200, { data: (Array.isArray(body.input) ? body.input : [body.input]).map((_, index) => ({index, embedding: [0.1, 0.2, 0.3]})), model: body.model, usage: { prompt_tokens: 4, total_tokens: 4 } });
  }
  if (!Array.isArray(body.messages) || body.messages[0]?.role !== 'user') return json(res, 400, {});
  const tool = body.tools?.[0];
  const wantsTool = tool && body.model !== 'no-tools';
  if (wantsTool && (tool.type !== 'function' || tool.function.name !== 'majordomo_connection_probe')) return json(res, 400, {});
  return json(res, 200, {
    id: 'fixture-completion', object: 'chat.completion', created: 1, model: body.model,
    choices: [{ index: 0, finish_reason: wantsTool ? 'tool_calls' : 'stop', message: wantsTool
      ? { role: 'assistant', content: null, tool_calls: [{ id: 'fixture-call', type: 'function', function: { name: tool.function.name, arguments: '{}' } }] }
      : { role: 'assistant', content: 'OK' } }],
    usage: { prompt_tokens: 4, completion_tokens: 1, total_tokens: 5 }
  });
}).listen(process.env.PORT);

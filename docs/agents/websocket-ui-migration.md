# WebSocket UI Migration Guide

This document describes the updated MajorDoMo websocket protocol and what UI code must change.
It is written for an AI/code assistant that needs to update frontend templates or JS without re-analyzing the backend.

## Runtime Endpoint

Browser clients connect to:

```text
ws://<host>:<WEBSOCKETS_PORT>/majordomo
wss://<host>:<WEBSOCKETS_PORT>/majordomo
```

If websocket authorization token is configured, append it as query string:

```text
ws://<host>:<WEBSOCKETS_PORT>/majordomo?token=<urlencoded-token>
```

Token sources, in priority order:

1. `SETTINGS_SYSTEM_WEBSOCKETS_TOKEN`
2. `WEBSOCKETS_AUTH_TOKEN`
3. `WEBSOCKETS_TOKEN`

In templates, use already prepared constant:

```js
var wsAuthToken = <#WEBSOCKETS_AUTH_TOKEN_JSON#>;
if (wsAuthToken) {
  serverUrl += '?token=' + encodeURIComponent(wsAuthToken);
}
```

If token is empty, auth is disabled and old connections still work.

## Message Envelope

All websocket requests use this JSON envelope:

```json
{
  "action": "ActionName",
  "data": {}
}
```

All backend responses also use:

```json
{
  "action": "response_action",
  "data": "json-string"
}
```

Important: `response.data` is usually a JSON-encoded string, so UI should parse it when it expects structured data:

```js
var data = typeof response.data === 'string'
  ? JSON.parse(response.data)
  : response.data;
```

## Default Property Subscription

Old behavior: UI sent a comma-separated list of properties:

```js
payload.action = 'Subscribe';
payload.data = {};
payload.data.TYPE = 'properties';
payload.data.PROPERTIES = 'Object.prop1,Object.prop2';
wsSocket.send(JSON.stringify(payload));
```

New behavior: do not send `PROPERTIES`.

```js
payload.action = 'Subscribe';
payload.data = {};
payload.data.TYPE = 'properties';
wsSocket.send(JSON.stringify(payload));
```

Backend subscribes every new client to all property updates automatically on connect. Explicit `Subscribe` with `TYPE='properties'` and no `PROPERTIES` is still allowed and returns `subscribed`.

UI migration rule:

- Remove all client-side property list building for websocket subscription.
- Keep local filtering in UI if a widget only cares about specific properties.
- Continue listening for `response.action === 'properties'` for live property updates.

Live property update payload is still a JSON array:

```json
[
  {"PROPERTY":"Object.prop1","VALUE":"123"},
  {"PROPERTY":"Object.prop2","VALUE":"456"}
]
```

Example handler:

```js
$.subscribe('wsData', function (_, response) {
  if (response.action !== 'properties') return;

  var updates = JSON.parse(response.data);
  updates.forEach(function (item) {
    var property = String(item.PROPERTY || '').toLowerCase();
    var value = item.VALUE;
    // Update widgets that are interested in property.
  });
});
```

## Request Current Property Values

Use this when UI needs one-time values immediately, without waiting for live updates.

Supported actions:

- `GetProperty`
- `GetProperties`
- `GetValues`

Accepted input shapes:

```json
{"PROPERTY":"Object.prop"}
```

```json
{"PROPERTIES":["Object.prop1", "Object.prop2"]}
```

```json
{"PROPERTIES":"Object.prop1,Object.prop2"}
```

Optional `REQUEST_ID` is echoed back.

Request example:

```js
wsSocket.send(JSON.stringify({
  action: 'GetProperties',
  data: {
    REQUEST_ID: 'init-values-1',
    PROPERTIES: ['Object.prop1', 'Object.prop2']
  }
}));
```

Response action is `property_values`, not `properties`. This is intentional so one-time responses do not conflict with live update handlers.

Response example:

```json
{
  "action": "property_values",
  "data": "{\"REQUEST_ID\":\"init-values-1\",\"DATA\":[{\"PROPERTY\":\"Object.prop1\",\"VALUE\":\"123\"}]}"
}
```

UI handler:

```js
$.subscribe('wsData', function (_, response) {
  if (response.action !== 'property_values') return;

  var result = JSON.parse(response.data);
  if (result.SUCCESS === 0) {
    console.error('WebSocket property request failed:', result.ERROR);
    return;
  }

  (result.DATA || []).forEach(function (item) {
    // item.PROPERTY, item.VALUE
  });
});
```

## Set a Property

Use action `SetGlobal` to call backend `setGlobal`.

Request:

```js
wsSocket.send(JSON.stringify({
  action: 'SetGlobal',
  data: {
    REQUEST_ID: 'set-1',
    PROPERTY: 'Object.prop',
    VALUE: '123'
  }
}));
```

Optional fields:

```json
{
  "NO_LINKED": 0,
  "SOURCE": "websocket"
}
```

Response action: `setGlobal`.

```json
{
  "SUCCESS": 1,
  "PROPERTY": "Object.prop",
  "VALUE": "123",
  "RESULT": 1,
  "REQUEST_ID": "set-1"
}
```

UI should treat `SUCCESS: 0` as failure and read `ERROR`.

## Call an Object Method

Use action `CallMethod` to call backend `callMethod`.

Request:

```js
wsSocket.send(JSON.stringify({
  action: 'CallMethod',
  data: {
    REQUEST_ID: 'method-1',
    METHOD: 'Object.method',
    PARAMS: { value: 123 }
  }
}));
```

Response action: `callMethod`.

```json
{
  "SUCCESS": 1,
  "METHOD": "Object.method",
  "RESULT": "...",
  "REQUEST_ID": "method-1"
}
```

## Run a Script

Use action `RunScript` to call backend `runScript`.

`SCRIPT` can be script id or script title, following existing MajorDoMo `runScript` behavior.

Request:

```js
wsSocket.send(JSON.stringify({
  action: 'RunScript',
  data: {
    REQUEST_ID: 'script-1',
    SCRIPT: 'MyScript',
    PARAMS: { value: 123 }
  }
}));
```

Response action: `runScript`.

```json
{
  "SUCCESS": 1,
  "SCRIPT": "MyScript",
  "RESULT": "...",
  "REQUEST_ID": "script-1"
}
```

## Recommended UI Helper

Add one small helper instead of duplicating message assembly:

```js
function wsSend(action, data) {
  if (!window.wsSocket || wsSocket.readyState !== WebSocket.OPEN) {
    return false;
  }
  wsSocket.send(JSON.stringify({
    action: action,
    data: data || {}
  }));
  return true;
}
```

Optional request id helper:

```js
function wsRequest(action, data) {
  data = data || {};
  data.REQUEST_ID = data.REQUEST_ID || ('ws-' + Date.now() + '-' + Math.random().toString(16).slice(2));
  wsSend(action, data);
  return data.REQUEST_ID;
}
```

## UI Refactor Checklist

1. Find all websocket property subscriptions.
2. Remove `payload.data.PROPERTIES = ...` from `TYPE='properties'` subscriptions.
3. Keep `Subscribe` calls for other types: `scenes`, `plans`, `commands`, `devices`, `devices_data`, `objects`, `events`.
4. Add token to websocket URL using `<#WEBSOCKETS_AUTH_TOKEN_JSON#>`.
5. Add a handler for `property_values` for one-time value reads.
6. Keep existing handler for `properties` live updates.
7. Replace AJAX calls for simple operations with websocket actions where appropriate:
   - read values: `GetProperty` / `GetProperties`
   - set property: `SetGlobal`
   - method action: `CallMethod`
   - script action: `RunScript`
8. Use `REQUEST_ID` when UI needs to match a response to a specific request.
9. Do not rely on `property_values` for live updates; use `properties` for live updates.
10. Restart websocket cycle after backend changes.

## Current Backend Files

Protocol implementation:

```text
lib/websockets/server/lib/WebSocket/Application/MajordomoApplication.php
lib/websockets/server/lib/WebSocket/Connection.php
lib/websockets/server/server.php
```

Browser websocket bootstrap:

```text
templates/websockets.html
```

Autogenerated property placeholders:

```text
lib/utils/postprocess_result.inc.php
```

Internal PHP publisher:

```text
lib/caching.class.php
```

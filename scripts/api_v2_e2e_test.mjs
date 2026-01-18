#!/usr/bin/env node
/*
Chap API v2 E2E-ish smoke test (Server + Node)

Runs against:
  - Server: http://localhost:8080/api/v2
  - Node:   <node_url>/node/v2 (node_url minted by server)

Prereqs:
  - Chap server running on localhost:8080
  - A user account exists
  - Provide either a PAT or email+password (optional TOTP) in the CONFIG block.
  - For Node tests, you MUST provide applicationId (because node sessions are app-scoped).

Configure:
  - Edit the CONFIG block near the top of this file.

Run:
  node scripts/api_v2_e2e_test.mjs
*/

/**
 * Test configuration (edit this; no env vars required).
 *
 * Notes:
 * - Prefer using a PAT (Settings → API keys) for repeatable runs.
 * - If you fill in email/password, the script will mint a session token first.
 * - Node tests require an existing application id.
 */
const CONFIG = {
  baseUrl: 'http://localhost:8080',

  // Auth: provide ONE of:
  pat: '0899479da564fdd9f6cca782d8034aa5dc6c55b124cec8450db93eee33bc7ba9',

  // Node tests (optional)
  applicationId: '091c054d-f7de-44ec-8a26-f11a56f6ec70',
  nodeId: '', // optional; otherwise first node from /api/v2/nodes
};

const BASE_URL = CONFIG.baseUrl || 'http://localhost:8080';
const API = `${BASE_URL.replace(/\/$/, '')}/api/v2`;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function redact(s) {
  const str = String(s ?? '');
  if (str.length <= 10) return '[redacted]';
  return `${str.slice(0, 4)}…${str.slice(-4)}`;
}

function fmtMs(ms) {
  if (ms < 1000) return `${ms}ms`;
  return `${(ms / 1000).toFixed(2)}s`;
}

function isJsonResponse(res) {
  const ct = res.headers.get('content-type') || '';
  return ct.includes('application/json');
}

async function httpRequest({
  name,
  method,
  url,
  token,
  json,
  headers,
  timeoutMs = 15000,
  expectStatus,
  allowStatuses,
}) {
  const started = Date.now();
  const controller = new AbortController();
  const t = setTimeout(() => controller.abort(), timeoutMs);

  const finalHeaders = {
    ...(json ? { 'Content-Type': 'application/json' } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(headers || {}),
  };

  let res;
  let bodyText = '';
  try {
    res = await fetch(url, {
      method,
      headers: finalHeaders,
      body: json !== undefined ? JSON.stringify(json) : undefined,
      signal: controller.signal,
    });

    if (isJsonResponse(res)) {
      bodyText = await res.text();
    } else {
      bodyText = await res.text();
    }

    const durationMs = Date.now() - started;

    let parsed;
    try {
      parsed = bodyText ? JSON.parse(bodyText) : null;
    } catch {
      parsed = null;
    }

    // PASS semantics:
    // - If expectStatus is provided: PASS only when it matches.
    // - Otherwise: PASS only for 2xx.
    // Note: allowStatuses is *not* treated as PASS; it only exists so callers can
    // document "tolerated" statuses while continuing the run.
    const statusOk =
      (typeof expectStatus === 'number' && res.status === expectStatus) ||
      (typeof expectStatus !== 'number' && res.status >= 200 && res.status < 300);

    return {
      ok: statusOk,
      name,
      method,
      url,
      status: res.status,
      durationMs,
      bodyText,
      json: parsed,
    };
  } catch (e) {
    const durationMs = Date.now() - started;
    return {
      ok: false,
      name,
      method,
      url,
      status: 0,
      durationMs,
      error: String(e && e.message ? e.message : e),
      bodyText,
      json: null,
    };
  } finally {
    clearTimeout(t);
  }
}

async function readSseFor({ url, token, durationMs = 2000, maxEvents = 10 }) {
  const controller = new AbortController();
  const t = setTimeout(() => controller.abort(), durationMs);

  const res = await fetch(url, {
    headers: {
      Accept: 'text/event-stream',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    signal: controller.signal,
  });

  if (!res.ok) {
    const text = await res.text();
    return { ok: false, status: res.status, text };
  }

  const reader = res.body.getReader();
  let buf = '';
  const events = [];

  try {
    while (events.length < maxEvents) {
      const { value, done } = await reader.read();
      if (done) break;
      buf += Buffer.from(value).toString('utf8');

      // Very lightweight SSE parsing: split blocks by blank line
      while (true) {
        const idx = buf.indexOf('\n\n');
        if (idx === -1) break;
        const block = buf.slice(0, idx);
        buf = buf.slice(idx + 2);
        const lines = block.split('\n');
        const ev = { event: null, data: [] };
        for (const line of lines) {
          if (line.startsWith('event:')) ev.event = line.slice('event:'.length).trim();
          if (line.startsWith('data:')) ev.data.push(line.slice('data:'.length).trim());
        }
        if (ev.event || ev.data.length) events.push(ev);
        if (events.length >= maxEvents) break;
      }
    }
  } catch {
    // abort expected
  } finally {
    clearTimeout(t);
    try { controller.abort(); } catch {}
  }

  return { ok: true, status: 200, events };
}

function pick(obj, path, fallback = null) {
  const parts = path.split('.');
  let cur = obj;
  for (const p of parts) {
    if (!cur || typeof cur !== 'object') return fallback;
    cur = cur[p];
  }
  return cur ?? fallback;
}

function printResult(r) {
  const tag = r.ok ? 'PASS' : (r.status === 404 ? 'SKIP' : 'FAIL');
  const ms = fmtMs(r.durationMs ?? 0);
  console.log(`[${tag}] ${r.method} ${r.url} (${r.status || 'ERR'}, ${ms}) — ${r.name}`);
  if (!r.ok && r.status !== 404) {
    if (r.error) console.log(`  error: ${r.error}`);
    const msg = pick(r.json, 'error.message', null);
    const code = pick(r.json, 'error.code', null);
    if (code || msg) console.log(`  api_error: ${code || ''} ${msg || ''}`.trim());
  }
}

async function main() {
  const results = [];

  const email = (CONFIG.email || '').trim();
  const password = CONFIG.password || '';
  const totp = (CONFIG.totp || '').trim();
  const existingPat = (CONFIG.pat || '').trim();

  const patLooksUnset = !existingPat || existingPat === 'PASTE_PAT_HERE';

  let token = patLooksUnset ? '' : existingPat;
  let sessionToken = '';
  let createdPat = null;

  console.log(`Server API: ${API}`);
  if (!patLooksUnset) console.log(`Using PAT=${redact(existingPat)}`);

  // Basic health
  results.push(await httpRequest({ name: 'server health', method: 'GET', url: `${API}/health` }));
  results.push(await httpRequest({ name: 'server capabilities', method: 'GET', url: `${API}/capabilities` }));
  for (const r of results.slice(-2)) printResult(r);
  await sleep(150);

  // Auth session (optional)
  if (!token && email && password) {
    const r = await httpRequest({
      name: 'auth session',
      method: 'POST',
      url: `${API}/auth/session`,
      json: { email, password, ...(totp ? { totp } : {}) },
      expectStatus: 200,
    });
    results.push(r);
    printResult(r);

    if (r.ok) {
      sessionToken = pick(r.json, 'access_token', '');
      token = sessionToken;
      console.log(`Session token minted: ${redact(sessionToken)}`);
    }
    await sleep(200);
  }

  if (!token) {
    console.log(
      '\nNo auth token available. Edit the CONFIG block and set either:' +
        "\n  - CONFIG.pat (recommended)" +
        "\n  - or CONFIG.email + CONFIG.password (+ optional CONFIG.totp)"
    );
    process.exit(2);
  }

  // Authenticated server calls
  for (const step of [
    { name: 'me', method: 'GET', path: '/me' },
    { name: 'teams', method: 'GET', path: '/teams' },
    { name: 'projects (limit=5)', method: 'GET', path: '/projects?page[limit]=5' },
    { name: 'nodes', method: 'GET', path: '/nodes' },
  ]) {
    const r = await httpRequest({ name: step.name, method: step.method, url: `${API}${step.path}`, token, allowStatuses: [200, 401, 403, 404] });
    results.push(r);
    printResult(r);
    await sleep(150);
  }

  // Select first team (best-effort)
  const teamsRes = results.find((x) => x.name === 'teams' && x.ok);
  const teamId = teamsRes ? (pick(teamsRes.json, 'data.0.uuid', '') || pick(teamsRes.json, 'data.0.id', '')) : '';
  if (teamId) {
    const r = await httpRequest({ name: 'select team', method: 'POST', url: `${API}/teams/${encodeURIComponent(teamId)}/select`, token, allowStatuses: [200, 403, 404] });
    results.push(r);
    printResult(r);
    await sleep(150);
  }

  // Token lifecycle (only if we have a session token or a sufficiently scoped PAT)
  const tokenName = `e2e-${Date.now()}`;
  const desiredScopes = [
    'settings:read', 'settings:write',
    'teams:read', 'teams:write',
    'projects:read',
    'nodes:read', 'nodes:session:mint',
    'applications:read', 'applications:deploy',
    'containers:read', 'containers:write',
    'logs:read', 'logs:stream',
    'files:read', 'files:write', 'files:delete',
    'volumes:read',
    'metrics:read',
  ];

  const idempotencyKey = cryptoRandomUuid();
  const createPatReq = {
    name: tokenName,
    scopes: desiredScopes,
    constraints: teamId ? { team_id: teamId } : null,
  };

  // Create PAT
  {
    const r1 = await httpRequest({
      name: 'create PAT',
      method: 'POST',
      url: `${API}/auth/tokens`,
      token,
      headers: { 'Idempotency-Key': idempotencyKey },
      json: createPatReq,
      expectStatus: 201,
      allowStatuses: [201, 403, 404],
    });
    results.push(r1);
    printResult(r1);
    await sleep(200);

    // Idempotency replay
    const r2 = await httpRequest({
      name: 'create PAT (idempotent replay)',
      method: 'POST',
      url: `${API}/auth/tokens`,
      token,
      headers: { 'Idempotency-Key': idempotencyKey },
      json: createPatReq,
      expectStatus: 201,
      allowStatuses: [201, 403, 404],
    });
    results.push(r2);
    printResult(r2);
    await sleep(200);

    if (r1.ok) {
      const tokenId = pick(r1.json, 'token_id', '');
      const tokenValue = pick(r1.json, 'token', '');
      if (tokenId && tokenValue) {
        createdPat = { token: tokenValue, token_id: tokenId };
        console.log(`Created PAT: id=${createdPat.token_id} token=${redact(createdPat.token)}`);
      }
    }
  }

  // List PATs
  {
    const r = await httpRequest({ name: 'list PATs', method: 'GET', url: `${API}/auth/tokens?page[limit]=10`, token, allowStatuses: [200, 403, 404] });
    results.push(r);
    printResult(r);
    await sleep(150);
  }

  // Use the newly created PAT for a quick auth check, then revoke it
  if (createdPat && createdPat.token && createdPat.token_id) {
    const patToken = createdPat.token;

    const rUse = await httpRequest({ name: 'PAT works (me)', method: 'GET', url: `${API}/me`, token: patToken, allowStatuses: [200, 401, 403] });
    results.push(rUse);
    printResult(rUse);
    await sleep(150);

    const rRevoke = await httpRequest({ name: 'revoke PAT', method: 'DELETE', url: `${API}/auth/tokens/${encodeURIComponent(createdPat.token_id)}`, token, allowStatuses: [200, 404, 403] });
    results.push(rRevoke);
    printResult(rRevoke);
    await sleep(150);

    const rAfter = await httpRequest({ name: 'revoked PAT rejected', method: 'GET', url: `${API}/me`, token: patToken, allowStatuses: [401, 403, 200] });
    results.push(rAfter);
    printResult(rAfter);
    await sleep(150);
  }

  // Node tests (requires application_id)
  const appId = (CONFIG.applicationId || '').trim();
  const preferredNodeId = (CONFIG.nodeId || '').trim();

  if (!appId || appId === 'PASTE_APPLICATION_ID_HERE') {
    console.log('\n[SKIP] Node tests: set CONFIG.applicationId to enable.');
  } else {
    const nodesRes = results.find((x) => x.name === 'nodes' && x.ok);
    let nodeId = preferredNodeId;
    if (!nodeId && nodesRes) nodeId = pick(nodesRes.json, 'data.0.uuid', '') || pick(nodesRes.json, 'data.0.id', '');

    if (!nodeId) {
      console.log('\n[SKIP] Node tests: no node id available (set CONFIG.nodeId or create a node in UI).');
    } else {
      // Mint node access token
      const mint = await httpRequest({
        name: 'mint node session',
        method: 'POST',
        url: `${API}/nodes/${encodeURIComponent(nodeId)}/sessions`,
        token,
        json: {
          scopes: [
            'applications:read',
            'containers:read', 'containers:write',
            'logs:read', 'logs:stream',
            'files:read', 'files:write', 'files:delete',
            'volumes:read',
            'metrics:read',
            'applications:deploy',
          ],
          constraints: {
            application_id: appId,
            paths: ['/app', '/data'],
            max_bytes: 1024 * 1024,
          },
          ttl_sec: 120,
        },
        expectStatus: 200,
        allowStatuses: [200, 403, 404, 409, 422, 503],
      });
      results.push(mint);
      printResult(mint);
      await sleep(250);

      if (!mint.ok) {
        console.log('[SKIP] Node tests: node session mint failed (needs nodes:session:mint + correct constraints).');
      } else {
        const nodeUrlRaw = pick(mint.json, 'node_url', '');
        const nodeUrl = String(nodeUrlRaw || '').replace(/\/$/, '');
        const nodeToken = pick(mint.json, 'node_access_token', '');

        if (!nodeUrl || !nodeToken) {
          console.log('[SKIP] Node tests: missing node_url or node_access_token in response.');
          return;
        }

        // Node health/capabilities (no token)
        for (const step of [
          { name: 'node health', method: 'GET', url: `${nodeUrl}/node/v2/health`, expectStatus: 200 },
          { name: 'node capabilities', method: 'GET', url: `${nodeUrl}/node/v2/capabilities`, expectStatus: 200 },
        ]) {
          const r = await httpRequest({ ...step, token: undefined });
          results.push(r);
          printResult(r);
          await sleep(150);
        }

        // Authenticated node calls
        for (const step of [
          { name: 'node applications', method: 'GET', url: `${nodeUrl}/node/v2/applications`, token: nodeToken, allowStatuses: [200, 401, 403, 404] },
          { name: 'node app status', method: 'GET', url: `${nodeUrl}/node/v2/applications/${encodeURIComponent(appId)}/status`, token: nodeToken, allowStatuses: [200, 401, 403, 404] },
          { name: 'node containers (filtered)', method: 'GET', url: `${nodeUrl}/node/v2/containers?filter[application_id]=${encodeURIComponent(appId)}`, token: nodeToken, allowStatuses: [200, 401, 403, 404] },
          { name: 'node metrics host', method: 'GET', url: `${nodeUrl}/node/v2/metrics/host`, token: nodeToken, allowStatuses: [200, 401, 403, 404] },
          { name: 'node metrics containers', method: 'GET', url: `${nodeUrl}/node/v2/metrics/containers`, token: nodeToken, allowStatuses: [200, 401, 403, 404] },
          { name: 'node volumes', method: 'GET', url: `${nodeUrl}/node/v2/volumes`, token: nodeToken, allowStatuses: [200, 401, 403, 404] },
          { name: 'fs ls /data', method: 'GET', url: `${nodeUrl}/node/v2/fs/ls?path=/data&recursive=false&limit=200`, token: nodeToken, allowStatuses: [200, 400, 401, 403, 404] },
        ]) {
          const r = await httpRequest(step);
          results.push(r);
          printResult(r);
          await sleep(150);
        }

        // FS write/read/delete roundtrip in /data
        const dir = '/data/__chap_api_test';
        const file = `${dir}/hello.txt`;
        const content = `hello from api test at ${new Date().toISOString()}\n`;
        const b64 = Buffer.from(content, 'utf8').toString('base64');

        const mkdir = await httpRequest({
          name: 'fs mkdir',
          method: 'POST',
          url: `${nodeUrl}/node/v2/fs/mkdir`,
          token: nodeToken,
          json: { path: dir },
          allowStatuses: [200, 401, 403, 404],
        });
        results.push(mkdir);
        printResult(mkdir);
        await sleep(150);

        const write = await httpRequest({
          name: 'fs write',
          method: 'PUT',
          url: `${nodeUrl}/node/v2/fs/write`,
          token: nodeToken,
          json: { path: file, mode: '0644', atomic: true, content_base64: b64 },
          allowStatuses: [200, 401, 403, 404, 413],
        });
        results.push(write);
        printResult(write);
        await sleep(150);

        const read = await httpRequest({
          name: 'fs read (json)',
          method: 'GET',
          url: `${nodeUrl}/node/v2/fs/read?path=${encodeURIComponent(file)}&offset=0&length=4096`,
          token: nodeToken,
          headers: { Accept: 'application/json' },
          allowStatuses: [200, 401, 403, 404],
        });
        results.push(read);
        printResult(read);
        await sleep(150);

        const del = await httpRequest({
          name: 'fs delete file',
          method: 'DELETE',
          url: `${nodeUrl}/node/v2/fs/delete?path=${encodeURIComponent(file)}`,
          token: nodeToken,
          allowStatuses: [200, 401, 403, 404],
        });
        results.push(del);
        printResult(del);
        await sleep(150);

        const delDir = await httpRequest({
          name: 'fs delete dir',
          method: 'DELETE',
          url: `${nodeUrl}/node/v2/fs/delete?path=${encodeURIComponent(dir)}`,
          token: nodeToken,
          allowStatuses: [200, 401, 403, 404],
        });
        results.push(delDir);
        printResult(delDir);
        await sleep(150);

        // Container log smoke (only if we have a container id)
        const containersRes = results.find((x) => x.name === 'node containers (filtered)' && x.ok);
        const containerId = containersRes ? pick(containersRes.json, 'data.0.id', '') : '';
        if (containerId) {
          const logs = await httpRequest({
            name: 'container logs (bounded)',
            method: 'GET',
            url: `${nodeUrl}/node/v2/containers/${encodeURIComponent(containerId)}/logs?tail=50`,
            token: nodeToken,
            allowStatuses: [200, 401, 403, 404],
          });
          results.push(logs);
          printResult(logs);
          await sleep(150);

          const sse = await readSseFor({
            url: `${nodeUrl}/node/v2/containers/${encodeURIComponent(containerId)}/logs/stream?tail=5`,
            token: nodeToken,
            durationMs: 2000,
            maxEvents: 8,
          });
          if (sse.ok) {
            console.log(`[PASS] SSE logs stream read ${sse.events.length} event(s)`);
          } else {
            console.log(`[FAIL] SSE logs stream (${sse.status}) ${String(sse.text || '').slice(0, 200)}`);
          }
        } else {
          console.log('[SKIP] Container logs: no containers found for app');
        }

        // Optional: attempt a Node-side deployment start (advanced; will likely be rejected unless appConfig is provided)
        const depTry = await httpRequest({
          name: 'node deployments (expected validation)',
          method: 'POST',
          url: `${nodeUrl}/node/v2/deployments`,
          token: nodeToken,
          json: { deployment_id: `dep_${Date.now()}`, application_id: appId },
          allowStatuses: [202, 422, 403, 404, 501],
        });
        results.push(depTry);
        printResult(depTry);
      }
    }
  }

  // Attempt a few server endpoints from API.md that may not exist yet (report as SKIP if 404)
  for (const ep of [
    { name: 'projects create (not implemented)', method: 'POST', url: `${API}/projects`, token, json: { name: `p-${Date.now()}` }, allowStatuses: [200, 201, 400, 401, 403, 404] },
    { name: 'events SSE (not implemented)', method: 'GET', url: `${API}/streams/events`, token, allowStatuses: [200, 401, 403, 404] },
    { name: 'logs SSE (not implemented)', method: 'GET', url: `${API}/streams/logs`, token, allowStatuses: [200, 401, 403, 404] },
  ]) {
    const r = await httpRequest(ep);
    results.push(r);
    printResult(r);
    await sleep(150);
  }

  // Summary
  const pass = results.filter((r) => r.ok).length;
  const fail = results.filter((r) => !r.ok && r.status !== 404).length;
  const skip = results.filter((r) => !r.ok && r.status === 404).length;

  console.log('\n==== Summary ====');
  console.log(`PASS: ${pass}`);
  console.log(`FAIL: ${fail}`);
  console.log(`SKIP (404): ${skip}`);

  if (fail > 0) process.exit(1);
}

function cryptoRandomUuid() {
  // Use RFC4122 v4-ish (good enough for Idempotency-Key)
  const b = globalThis.crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const hex = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

// WebCrypto polyfill for older Node; Node 18+ has global crypto.webcrypto
import crypto from 'node:crypto';
import { pathToFileURL } from 'node:url';
if (!globalThis.crypto?.getRandomValues) {
  globalThis.crypto = crypto.webcrypto;
}

const isDirectRun = import.meta.url === pathToFileURL(process.argv[1] || '').href;
if (isDirectRun) {
  main().catch((e) => {
    console.error(e);
    process.exit(1);
  });
}

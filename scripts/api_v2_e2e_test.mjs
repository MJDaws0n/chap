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
  - For Node tests, you can provide applicationId, but the script can also auto-discover one via GET /api/v2/applications.

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
 * - Node tests require an application id; leave blank to attempt auto-discovery.
 */
const CONFIG = {
  baseUrl: 'http://localhost:8080',

  // How strict should this runner be?
  // - If you provide an "admin" PAT with broad scopes, set these to true.
  // - If you provide a limited PAT, keep them false so 401/403 become SKIP.
  expectAuthorized: true,
  expectImplemented: true,

  // Auth: provide ONE of:
  pat: '',

  // Node tests (optional)
  applicationId: '',
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
  const tag = r.tag || (r.ok ? 'PASS' : (r.status === 404 ? 'SKIP' : 'FAIL'));
  const ms = fmtMs(r.durationMs ?? 0);
  console.log(`[${tag}] ${r.method} ${r.url} (${r.status || 'ERR'}, ${ms}) — ${r.name}`);
  if (!r.ok && r.status !== 404) {
    if (r.error) console.log(`  error: ${r.error}`);
    const msg = pick(r.json, 'error.message', null);
    const code = pick(r.json, 'error.code', null);
    if (code || msg) console.log(`  api_error: ${code || ''} ${msg || ''}`.trim());
  }
}

function tagFor({ ok, status }, { allow404Skip, allowAuthSkip }) {
  if (ok) return 'PASS';
  if (status === 404 && allow404Skip) return 'SKIP';
  if ((status === 401 || status === 403) && allowAuthSkip) return 'SKIP';
  return 'FAIL';
}

async function runStep(opts, policy = {}) {
  const r = await httpRequest(opts);
  const allow404Skip = policy.allow404Skip ?? !CONFIG.expectImplemented;
  const allowAuthSkip = policy.allowAuthSkip ?? !CONFIG.expectAuthorized;
  r.tag = tagFor(r, { allow404Skip, allowAuthSkip });
  r.ok = r.tag === 'PASS';
  printResult(r);
  await sleep(policy.sleepMs ?? 150);
  return r;
}

function requireId(id, label) {
  if (id) return { ok: true, id };
  console.log(`[SKIP] Missing required id for ${label}`);
  return { ok: false, id: '' };
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
  results.push(await runStep({ name: 'server health', method: 'GET', url: `${API}/health` }, { allowAuthSkip: false }));
  results.push(await runStep({ name: 'server capabilities', method: 'GET', url: `${API}/capabilities` }, { allowAuthSkip: false }));

  // Auth session (optional)
  if (!token && email && password) {
    const r = await runStep({
      name: 'auth session',
      method: 'POST',
      url: `${API}/auth/session`,
      json: { email, password, ...(totp ? { totp } : {}) },
      expectStatus: 200,
    }, { allowAuthSkip: false });
    results.push(r);

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

  // Authenticated server calls (context discovery)
  for (const step of [
    { name: 'me', method: 'GET', path: '/me' },
    { name: 'teams', method: 'GET', path: '/teams' },
    { name: 'projects (limit=5)', method: 'GET', path: '/projects?page[limit]=5' },
    { name: 'nodes', method: 'GET', path: '/nodes' },
  ]) {
    const r = await runStep({ name: step.name, method: step.method, url: `${API}${step.path}`, token });
    results.push(r);
  }

  // Select first team (best-effort)
  const teamsRes = results.find((x) => x.name === 'teams' && x.ok);
  const teamId = teamsRes ? (pick(teamsRes.json, 'data.0.uuid', '') || pick(teamsRes.json, 'data.0.id', '')) : '';
  if (teamId) {
    const r = await runStep({ name: 'select team', method: 'POST', url: `${API}/teams/${encodeURIComponent(teamId)}/select`, token });
    results.push(r);
  }

  const projectsRes = results.find((x) => x.name === 'projects (limit=5)' && x.ok);
  const projectId = projectsRes ? (pick(projectsRes.json, 'data.0.id', '') || pick(projectsRes.json, 'data.0.uuid', '')) : '';

  const nodesRes = results.find((x) => x.name === 'nodes' && x.ok);
  const nodeFromList = nodesRes ? (pick(nodesRes.json, 'data.0.id', '') || pick(nodesRes.json, 'data.0.uuid', '')) : '';
  const nodeUrlFromList = nodesRes ? (pick(nodesRes.json, 'data.0.node_url', '') || '') : '';

  // Token lifecycle (only if we have a session token or a sufficiently scoped PAT)
  const tokenName = `e2e-${Date.now()}`;
  // Keep the requested scopes minimal here: this section is about *token management*.
  // (Requesting broader scopes will 403 unless the caller token already has them.)
  const desiredScopes = ['settings:read', 'settings:write'];

  const idempotencyKey = cryptoRandomUuid();
  const createPatReq = {
    name: tokenName,
    scopes: desiredScopes,
    constraints: teamId ? { team_id: teamId } : null,
  };

  // Create PAT
  {
    const r1 = await runStep({
      name: 'create PAT',
      method: 'POST',
      url: `${API}/auth/tokens`,
      token,
      headers: { 'Idempotency-Key': idempotencyKey },
      json: createPatReq,
      expectStatus: 201,
    }, { sleepMs: 200 });
    results.push(r1);

    // Idempotency replay
    const r2 = await runStep({
      name: 'create PAT (idempotent replay)',
      method: 'POST',
      url: `${API}/auth/tokens`,
      token,
      headers: { 'Idempotency-Key': idempotencyKey },
      json: createPatReq,
      expectStatus: 201,
    }, { sleepMs: 200 });
    results.push(r2);

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
    const r = await runStep({ name: 'list PATs', method: 'GET', url: `${API}/auth/tokens?page[limit]=10`, token });
    results.push(r);
  }

  // Use the newly created PAT for a quick auth check, then revoke it
  if (createdPat && createdPat.token && createdPat.token_id) {
    const patToken = createdPat.token;

    results.push(await runStep({ name: 'PAT works (me)', method: 'GET', url: `${API}/me`, token: patToken, expectStatus: 200 }, { sleepMs: 150 }));
    results.push(await runStep({ name: 'revoke PAT', method: 'DELETE', url: `${API}/auth/tokens/${encodeURIComponent(createdPat.token_id)}`, token, expectStatus: 200 }, { sleepMs: 150 }));
    results.push(await runStep({ name: 'revoked PAT rejected', method: 'GET', url: `${API}/me`, token: patToken, expectStatus: 401 }, { allowAuthSkip: false, sleepMs: 150 }));
  }

  // --- Full Server API sweep (from API.md) ---
  // For endpoints that require IDs we don't have, we still hit them with a dummy id to ensure routing/404 behavior.
  const dummy = {
    projectId: projectId || '00000000-0000-0000-0000-000000000000',
    environmentId: '00000000-0000-0000-0000-000000000000',
    applicationId: (CONFIG.applicationId || '').trim() || '00000000-0000-0000-0000-000000000000',
    deploymentId: `dep_${Date.now()}`,
    databaseId: '00000000-0000-0000-0000-000000000000',
    serviceId: '00000000-0000-0000-0000-000000000000',
    templateSlug: 'minecraft-vanilla',
    gitSourceId: '00000000-0000-0000-0000-000000000000',
    webhookId: '00000000-0000-0000-0000-000000000000',
    userId: '00000000-0000-0000-0000-000000000000',
  };

  // Projects CRUD + members
  results.push(await runStep({ name: 'projects list', method: 'GET', url: `${API}/projects`, token }));
  results.push(await runStep({ name: 'projects create', method: 'POST', url: `${API}/projects`, token, json: { name: `p-${Date.now()}` } }));
  results.push(await runStep({ name: 'project show', method: 'GET', url: `${API}/projects/${encodeURIComponent(dummy.projectId)}`, token }));
  results.push(await runStep({ name: 'project patch', method: 'PATCH', url: `${API}/projects/${encodeURIComponent(dummy.projectId)}`, token, json: { name: `p-${Date.now()}-x` } }));
  results.push(await runStep({ name: 'project delete', method: 'DELETE', url: `${API}/projects/${encodeURIComponent(dummy.projectId)}`, token }));
  results.push(await runStep({ name: 'project members list', method: 'GET', url: `${API}/projects/${encodeURIComponent(dummy.projectId)}/members`, token }));
  results.push(await runStep({ name: 'project member role update', method: 'PATCH', url: `${API}/projects/${encodeURIComponent(dummy.projectId)}/members/${encodeURIComponent(dummy.userId)}`, token, json: { role: 'member' } }));

  // Environments
  results.push(await runStep({ name: 'environments list', method: 'GET', url: `${API}/projects/${encodeURIComponent(dummy.projectId)}/environments`, token }));
  results.push(await runStep({ name: 'environments create', method: 'POST', url: `${API}/projects/${encodeURIComponent(dummy.projectId)}/environments`, token, json: { name: `env-${Date.now()}` } }));
  results.push(await runStep({ name: 'environment show', method: 'GET', url: `${API}/environments/${encodeURIComponent(dummy.environmentId)}`, token }));
  results.push(await runStep({ name: 'environment patch', method: 'PATCH', url: `${API}/environments/${encodeURIComponent(dummy.environmentId)}`, token, json: { name: `env-${Date.now()}-x` } }));
  results.push(await runStep({ name: 'environment delete', method: 'DELETE', url: `${API}/environments/${encodeURIComponent(dummy.environmentId)}`, token }));

  // Applications
  results.push(await runStep({ name: 'applications list', method: 'GET', url: `${API}/environments/${encodeURIComponent(dummy.environmentId)}/applications`, token }));
  results.push(await runStep({ name: 'applications create', method: 'POST', url: `${API}/environments/${encodeURIComponent(dummy.environmentId)}/applications`, token, json: { name: `app-${Date.now()}` } }));
  results.push(await runStep({ name: 'application show', method: 'GET', url: `${API}/applications/${encodeURIComponent(dummy.applicationId)}`, token }));
  results.push(await runStep({ name: 'application patch', method: 'PATCH', url: `${API}/applications/${encodeURIComponent(dummy.applicationId)}`, token, json: { name: `app-${Date.now()}-x` } }));
  results.push(await runStep({ name: 'application delete', method: 'DELETE', url: `${API}/applications/${encodeURIComponent(dummy.applicationId)}`, token }));
  results.push(await runStep({ name: 'application stop', method: 'POST', url: `${API}/applications/${encodeURIComponent(dummy.applicationId)}:stop`, token, json: {} }));
  results.push(await runStep({ name: 'application restart', method: 'POST', url: `${API}/applications/${encodeURIComponent(dummy.applicationId)}:restart`, token, json: {} }));
  results.push(await runStep({ name: 'application reserve port', method: 'POST', url: `${API}/applications/${encodeURIComponent(dummy.applicationId)}:reserve-port`, token, json: {} }));
  results.push(await runStep({ name: 'application release port', method: 'POST', url: `${API}/applications/${encodeURIComponent(dummy.applicationId)}:release-port`, token, json: {} }));

  // Deployments
  results.push(await runStep({ name: 'deployment trigger', method: 'POST', url: `${API}/applications/${encodeURIComponent(dummy.applicationId)}/deployments`, token, json: { reason: 'e2e', git: { ref: 'main' } } }));
  results.push(await runStep({ name: 'deployments list', method: 'GET', url: `${API}/applications/${encodeURIComponent(dummy.applicationId)}/deployments`, token }));
  results.push(await runStep({ name: 'deployment show', method: 'GET', url: `${API}/deployments/${encodeURIComponent(dummy.deploymentId)}`, token }));
  results.push(await runStep({ name: 'deployment cancel', method: 'POST', url: `${API}/deployments/${encodeURIComponent(dummy.deploymentId)}:cancel`, token, json: {} }));
  results.push(await runStep({ name: 'deployment rollback', method: 'POST', url: `${API}/deployments/${encodeURIComponent(dummy.deploymentId)}:rollback`, token, json: {} }));
  results.push(await runStep({ name: 'deployment logs', method: 'GET', url: `${API}/deployments/${encodeURIComponent(dummy.deploymentId)}/logs`, token }));

  // Streams
  results.push(await runStep({ name: 'events SSE (connect)', method: 'GET', url: `${API}/streams/events`, token }));
  results.push(await runStep({ name: 'logs SSE (connect)', method: 'GET', url: `${API}/streams/logs`, token }));

  // Nodes
  results.push(await runStep({ name: 'nodes list (repeat)', method: 'GET', url: `${API}/nodes`, token }));
  results.push(await runStep({ name: 'node show', method: 'GET', url: `${API}/nodes/${encodeURIComponent(nodeFromList || '00000000-0000-0000-0000-000000000000')}`, token }));

  // Databases
  results.push(await runStep({ name: 'databases list', method: 'GET', url: `${API}/environments/${encodeURIComponent(dummy.environmentId)}/databases`, token }));
  results.push(await runStep({ name: 'databases create', method: 'POST', url: `${API}/environments/${encodeURIComponent(dummy.environmentId)}/databases`, token, json: { name: `db-${Date.now()}` } }));
  results.push(await runStep({ name: 'database show', method: 'GET', url: `${API}/databases/${encodeURIComponent(dummy.databaseId)}`, token }));
  results.push(await runStep({ name: 'database patch', method: 'PATCH', url: `${API}/databases/${encodeURIComponent(dummy.databaseId)}`, token, json: { name: `db-${Date.now()}-x` } }));
  results.push(await runStep({ name: 'database delete', method: 'DELETE', url: `${API}/databases/${encodeURIComponent(dummy.databaseId)}`, token }));
  results.push(await runStep({ name: 'database start', method: 'POST', url: `${API}/databases/${encodeURIComponent(dummy.databaseId)}:start`, token, json: {} }));
  results.push(await runStep({ name: 'database stop', method: 'POST', url: `${API}/databases/${encodeURIComponent(dummy.databaseId)}:stop`, token, json: {} }));

  // Services
  results.push(await runStep({ name: 'services list', method: 'GET', url: `${API}/environments/${encodeURIComponent(dummy.environmentId)}/services`, token }));
  results.push(await runStep({ name: 'services create', method: 'POST', url: `${API}/environments/${encodeURIComponent(dummy.environmentId)}/services`, token, json: { name: `svc-${Date.now()}` } }));
  results.push(await runStep({ name: 'service show', method: 'GET', url: `${API}/services/${encodeURIComponent(dummy.serviceId)}`, token }));
  results.push(await runStep({ name: 'service patch', method: 'PATCH', url: `${API}/services/${encodeURIComponent(dummy.serviceId)}`, token, json: { name: `svc-${Date.now()}-x` } }));
  results.push(await runStep({ name: 'service delete', method: 'DELETE', url: `${API}/services/${encodeURIComponent(dummy.serviceId)}`, token }));
  results.push(await runStep({ name: 'service start', method: 'POST', url: `${API}/services/${encodeURIComponent(dummy.serviceId)}:start`, token, json: {} }));
  results.push(await runStep({ name: 'service stop', method: 'POST', url: `${API}/services/${encodeURIComponent(dummy.serviceId)}:stop`, token, json: {} }));

  // Templates
  results.push(await runStep({ name: 'templates list', method: 'GET', url: `${API}/templates`, token }));
  results.push(await runStep({ name: 'template show', method: 'GET', url: `${API}/templates/${encodeURIComponent(dummy.templateSlug)}`, token }));

  // Git sources
  results.push(await runStep({ name: 'git sources list', method: 'GET', url: `${API}/git-sources`, token }));
  results.push(await runStep({ name: 'git sources create', method: 'POST', url: `${API}/git-sources`, token, json: { provider: 'github', name: `gs-${Date.now()}` } }));
  results.push(await runStep({ name: 'git source show', method: 'GET', url: `${API}/git-sources/${encodeURIComponent(dummy.gitSourceId)}`, token }));
  results.push(await runStep({ name: 'git source delete', method: 'DELETE', url: `${API}/git-sources/${encodeURIComponent(dummy.gitSourceId)}`, token }));
  results.push(await runStep({ name: 'git source test', method: 'POST', url: `${API}/git-sources/${encodeURIComponent(dummy.gitSourceId)}:test`, token, json: {} }));
  results.push(await runStep({ name: 'git source repositories', method: 'GET', url: `${API}/git-sources/${encodeURIComponent(dummy.gitSourceId)}/repositories`, token }));

  // Webhooks
  results.push(await runStep({ name: 'webhooks create', method: 'POST', url: `${API}/applications/${encodeURIComponent(dummy.applicationId)}/webhooks`, token, json: { events: ['push'] } }));
  results.push(await runStep({ name: 'webhook rotate secret', method: 'POST', url: `${API}/webhooks/${encodeURIComponent(dummy.webhookId)}:rotate-secret`, token, json: {} }));
  results.push(await runStep({ name: 'webhook delete', method: 'DELETE', url: `${API}/webhooks/${encodeURIComponent(dummy.webhookId)}`, token }));

  // Settings + activity
  results.push(await runStep({ name: 'settings get', method: 'GET', url: `${API}/settings`, token }));
  results.push(await runStep({ name: 'settings patch', method: 'PATCH', url: `${API}/settings`, token, json: { updated_at: new Date().toISOString() } }));
  results.push(await runStep({ name: 'activity', method: 'GET', url: `${API}/activity`, token }));

  // --- Node API sweep (from API.md) ---
  let appId = (CONFIG.applicationId || '').trim();
  const preferredNodeId = (CONFIG.nodeId || '').trim();
  const nodeUrl = String(nodeUrlFromList || '').replace(/\/$/, '');
  let nodeId = preferredNodeId || nodeFromList;

  // If caller didn't provide an application id, try to discover one via Server API.
  // This is required to mint a node session token (node tokens are application-scoped).
  if (!appId) {
    const teams = await runStep(
      { name: 'teams list (discover)', method: 'GET', url: `${API}/teams`, token },
      { allowAuthSkip: true }
    );
    results.push(teams);

    const teamUuid = pick(teams.json, 'data.0.uuid', '') || pick(teams.json, 'data.0.id', '');
    if (teamUuid) {
      const apps = await runStep(
        {
          name: 'applications list (discover)',
          method: 'GET',
          url: `${API}/applications?filter[team_id]=${encodeURIComponent(teamUuid)}&page[limit]=1`,
          token,
        },
        { allowAuthSkip: true }
      );
      results.push(apps);

      appId = pick(apps.json, 'data.0.uuid', '') || pick(apps.json, 'data.0.id', '');
      if (!preferredNodeId && !nodeId) {
        nodeId = pick(apps.json, 'data.0.node_id', '') || nodeId;
      }
    }
  }

  if (!nodeUrl) {
    console.log('[SKIP] Node API: missing node_url from /api/v2/nodes');
  } else {
    // Public node endpoints (no token)
    results.push(await runStep({ name: 'node health', method: 'GET', url: `${nodeUrl}/node/v2/health` }, { allowAuthSkip: true }));
    const nodeCaps = await runStep({ name: 'node capabilities', method: 'GET', url: `${nodeUrl}/node/v2/capabilities` }, { allowAuthSkip: true });
    results.push(nodeCaps);

    // Attempt to mint a node token (best-effort) if we have node id + app id
    let nodeToken = '';
    if (!nodeId) {
      console.log('[SKIP] Node API auth: missing node id');
    } else if (!appId) {
      console.log('[SKIP] Node API auth: missing CONFIG.applicationId');
    } else {
      const mint = await runStep({
        name: 'mint node session',
        method: 'POST',
        url: `${API}/nodes/${encodeURIComponent(nodeId)}/sessions`,
        token,
        json: {
          // Request scopes used by the node suite below.
          scopes: [
            'applications:read',
            'applications:deploy',
            'containers:read',
            'containers:write',
            'logs:read',
            'logs:stream',
            'files:read',
            'files:write',
            'files:delete',
            'volumes:read',
            'volumes:write',
            'metrics:read',
            'exec:run',
          ],
          constraints: { application_id: appId, paths: ['/app', '/data'], max_bytes: 1024 * 1024 },
          ttl_sec: 120,
        },
        expectStatus: 200,
      }, { sleepMs: 250 });
      results.push(mint);

      if (mint.tag === 'PASS') {
        nodeToken = pick(mint.json, 'node_access_token', '');
      } else {
        console.log('[SKIP] Node API auth: could not mint node token; authenticated node endpoints will be skipped.');
      }
    }

    if (nodeToken) {
      const deploymentId = `dep_${Date.now()}`;

      // Authenticated node calls
      results.push(await runStep({ name: 'node applications', method: 'GET', url: `${nodeUrl}/node/v2/applications`, token: nodeToken }));
      results.push(await runStep({ name: 'node app status', method: 'GET', url: `${nodeUrl}/node/v2/applications/${encodeURIComponent(appId)}/status`, token: nodeToken }));
      results.push(await runStep({
        name: 'node deployments start',
        method: 'POST',
        url: `${nodeUrl}/node/v2/deployments`,
        token: nodeToken,
        json: {
          deployment_id: deploymentId,
          application_id: appId,
          application: { id: appId, uuid: appId },
        },
        expectStatus: 202,
      }));
      results.push(await runStep({ name: 'node deployments show', method: 'GET', url: `${nodeUrl}/node/v2/deployments/${encodeURIComponent(deploymentId)}`, token: nodeToken }));
      results.push(await runStep({ name: 'node deployments cancel', method: 'POST', url: `${nodeUrl}/node/v2/deployments/${encodeURIComponent(deploymentId)}:cancel`, token: nodeToken, json: {} }));
      results.push(await runStep({ name: 'node containers list', method: 'GET', url: `${nodeUrl}/node/v2/containers?filter[application_id]=${encodeURIComponent(appId)}`, token: nodeToken }));

      const containersRes = results.find((x) => x.name === 'node containers list' && x.tag === 'PASS');
      const containerId = containersRes ? pick(containersRes.json, 'data.0.id', '') : '';
      const cid = containerId || '0000000000000000000000000000000000000000000000000000000000000000';
      results.push(await runStep({ name: 'node container inspect', method: 'GET', url: `${nodeUrl}/node/v2/containers/${encodeURIComponent(cid)}`, token: nodeToken }));
      results.push(await runStep({ name: 'node container restart', method: 'POST', url: `${nodeUrl}/node/v2/containers/${encodeURIComponent(cid)}:restart`, token: nodeToken, json: {} }));
      results.push(await runStep({ name: 'node container stop', method: 'POST', url: `${nodeUrl}/node/v2/containers/${encodeURIComponent(cid)}:stop`, token: nodeToken, json: {}, timeoutMs: 60000 }));
      results.push(await runStep({ name: 'node container logs (bounded)', method: 'GET', url: `${nodeUrl}/node/v2/containers/${encodeURIComponent(cid)}/logs?tail=10`, token: nodeToken }));
      results.push(await runStep({ name: 'node container logs stream (connect)', method: 'GET', url: `${nodeUrl}/node/v2/containers/${encodeURIComponent(cid)}/logs/stream?tail=2`, token: nodeToken }));

      // Exec (high-risk): only exercise if the agent reports it supports exec.
      const execSupported = !!pick(nodeCaps?.json, 'data.features.exec', false);
      if (execSupported) {
        results.push(await runStep({ name: 'node exec start', method: 'POST', url: `${nodeUrl}/node/v2/containers/${encodeURIComponent(cid)}/exec`, token: nodeToken, json: { cmd: ['/bin/sh'], tty: false } }));
      } else {
        console.log('[SKIP] Node exec: not supported by agent capabilities');
      }

      // Filesystem
      results.push(await runStep({ name: 'fs ls', method: 'GET', url: `${nodeUrl}/node/v2/fs/ls?path=/data&recursive=false&limit=200`, token: nodeToken }));
      results.push(await runStep({ name: 'fs stat', method: 'GET', url: `${nodeUrl}/node/v2/fs/stat?path=/data`, token: nodeToken }));
      results.push(await runStep({ name: 'fs mkdir', method: 'POST', url: `${nodeUrl}/node/v2/fs/mkdir`, token: nodeToken, json: { path: '/data/__chap_api_test' } }));
      results.push(await runStep({ name: 'fs write', method: 'PUT', url: `${nodeUrl}/node/v2/fs/write`, token: nodeToken, json: { path: '/data/__chap_api_test/hello.txt', mode: '0644', atomic: true, content_base64: Buffer.from('hello\n', 'utf8').toString('base64') } }));
      results.push(await runStep({ name: 'fs read', method: 'GET', url: `${nodeUrl}/node/v2/fs/read?path=${encodeURIComponent('/data/__chap_api_test/hello.txt')}&offset=0&length=4096`, token: nodeToken, headers: { Accept: 'application/json' } }));
      results.push(await runStep({ name: 'fs move', method: 'POST', url: `${nodeUrl}/node/v2/fs/move`, token: nodeToken, json: { from: '/data/__chap_api_test/hello.txt', to: '/data/__chap_api_test/hello2.txt' } }));
      results.push(await runStep({ name: 'fs copy', method: 'POST', url: `${nodeUrl}/node/v2/fs/copy`, token: nodeToken, json: { from: '/data/__chap_api_test/hello2.txt', to: '/data/__chap_api_test/hello3.txt' } }));
      results.push(await runStep({ name: 'fs chmod', method: 'POST', url: `${nodeUrl}/node/v2/fs/chmod`, token: nodeToken, json: { path: '/data/__chap_api_test/hello3.txt', mode: '0644' } }));
      results.push(await runStep({ name: 'fs delete file', method: 'DELETE', url: `${nodeUrl}/node/v2/fs/delete?path=${encodeURIComponent('/data/__chap_api_test/hello3.txt')}`, token: nodeToken }));
      results.push(await runStep({ name: 'fs delete dir', method: 'DELETE', url: `${nodeUrl}/node/v2/fs/delete?path=${encodeURIComponent('/data/__chap_api_test')}`, token: nodeToken }));

      // Multipart upload
      {
        const started = Date.now();
        const form = new FormData();
        form.append('path', '/data/__chap_api_test/upload.txt');
        form.append('file', new Blob([Buffer.from('upload-test\n', 'utf8')]), 'upload.txt');

        const res = await fetch(`${nodeUrl}/node/v2/fs/upload`, {
          method: 'POST',
          headers: { Authorization: `Bearer ${nodeToken}` },
          body: form,
        });
        const bodyText = await res.text();
        let parsed;
        try { parsed = bodyText ? JSON.parse(bodyText) : null; } catch { parsed = null; }

        const durationMs = Date.now() - started;
        const statusOk = res.status >= 200 && res.status < 300;
        const r = {
          ok: statusOk,
          name: 'fs upload (multipart)',
          method: 'POST',
          url: `${nodeUrl}/node/v2/fs/upload`,
          status: res.status,
          durationMs,
          bodyText,
          json: parsed,
        };
        r.tag = tagFor(r, { allow404Skip: !CONFIG.expectImplemented, allowAuthSkip: !CONFIG.expectAuthorized });
        r.ok = r.tag === 'PASS';
        printResult(r);
        results.push(r);
        await sleep(150);
      }

      results.push(await runStep({ name: 'fs download', method: 'GET', url: `${nodeUrl}/node/v2/fs/download?path=${encodeURIComponent('/data')}`, token: nodeToken }));

      // Volumes
      results.push(await runStep({ name: 'node volumes list', method: 'GET', url: `${nodeUrl}/node/v2/volumes`, token: nodeToken }));
      results.push(await runStep({ name: 'node volume attach', method: 'POST', url: `${nodeUrl}/node/v2/volumes/${encodeURIComponent('vol_1')}:attach`, token: nodeToken, json: {} }));
      results.push(await runStep({ name: 'node volume detach', method: 'POST', url: `${nodeUrl}/node/v2/volumes/${encodeURIComponent('vol_1')}:detach`, token: nodeToken, json: {} }));
      results.push(await runStep({ name: 'node volume snapshot', method: 'POST', url: `${nodeUrl}/node/v2/volumes/${encodeURIComponent('vol_1')}:snapshot`, token: nodeToken, json: {} }));

      // Metrics
      results.push(await runStep({ name: 'node metrics host', method: 'GET', url: `${nodeUrl}/node/v2/metrics/host`, token: nodeToken }));
      results.push(await runStep({ name: 'node metrics containers', method: 'GET', url: `${nodeUrl}/node/v2/metrics/containers`, token: nodeToken }));
      results.push(await runStep({ name: 'node metrics container', method: 'GET', url: `${nodeUrl}/node/v2/metrics/containers/${encodeURIComponent(cid)}`, token: nodeToken }));
    } else {
      // Still "tests" the existence of routes by reaching them unauthenticated (they should 401/403).
      const unauth = [
        { name: 'node applications (unauth)', method: 'GET', url: `${nodeUrl}/node/v2/applications` },
        { name: 'node containers (unauth)', method: 'GET', url: `${nodeUrl}/node/v2/containers` },
        { name: 'node metrics host (unauth)', method: 'GET', url: `${nodeUrl}/node/v2/metrics/host` },
        { name: 'fs ls (unauth)', method: 'GET', url: `${nodeUrl}/node/v2/fs/ls?path=/data&recursive=false&limit=5` },
      ];
      for (const ep of unauth) {
        results.push(await runStep(ep, { allowAuthSkip: true }));
      }
    }
  }

  // Summary
  const pass = results.filter((r) => r.tag === 'PASS').length;
  const fail = results.filter((r) => r.tag === 'FAIL').length;
  const skip = results.filter((r) => r.tag === 'SKIP').length;

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

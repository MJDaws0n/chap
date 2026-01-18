/**
 * Chap Node API v2 (HTTP)
 *
 * Mounted on the same port as the Browser WebSocket server.
 * Auth: Bearer Node Access Token (JWT HS256) minted by the server.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const url = require('url');
const os = require('os');
const { spawn, exec } = require('child_process');

function b64urlDecode(s) {
    const ss = String(s || '').replace(/-/g, '+').replace(/_/g, '/');
    const pad = ss.length % 4;
    const padded = ss + (pad ? '='.repeat(4 - pad) : '');
    return Buffer.from(padded, 'base64');
}

function b64urlEncode(buf) {
    return Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function verifyJwtHs256(jwt, secret) {
    const parts = String(jwt || '').split('.');
    if (parts.length !== 3) return null;
    const [h, p, s] = parts;

    let header;
    let payload;
    try {
        header = JSON.parse(b64urlDecode(h).toString('utf8'));
        payload = JSON.parse(b64urlDecode(p).toString('utf8'));
    } catch {
        return null;
    }

    if (!header || header.alg !== 'HS256') return null;

    const expected = crypto.createHmac('sha256', String(secret || '')).update(`${h}.${p}`).digest();
    const got = b64urlDecode(s);
    if (expected.length !== got.length || !crypto.timingSafeEqual(expected, got)) return null;

    return payload && typeof payload === 'object' ? payload : null;
}

function json(res, status, obj, extraHeaders = {}) {
    res.writeHead(status, {
        'Content-Type': 'application/json; charset=utf-8',
        ...extraHeaders,
    });
    res.end(JSON.stringify(obj));
}

function readBody(req) {
    return new Promise((resolve) => {
        let data = '';
        req.on('data', (c) => { data += c.toString('utf8'); });
        req.on('end', () => resolve(data));
        req.on('error', () => resolve(''));
    });
}

function readBodyBuffer(req, maxBytes = 15 * 1024 * 1024) {
    return new Promise((resolve) => {
        const chunks = [];
        let total = 0;
        req.on('data', (c) => {
            const buf = Buffer.isBuffer(c) ? c : Buffer.from(c);
            total += buf.length;
            if (total > maxBytes) {
                // Stop reading further; return what we have.
                try { req.destroy(); } catch {}
                return;
            }
            chunks.push(buf);
        });
        req.on('end', () => resolve(Buffer.concat(chunks)));
        req.on('error', () => resolve(Buffer.concat(chunks)));
    });
}

function isSafePathValue(p) {
    if (typeof p !== 'string') return false;
    if (!p.length) return false;
    if (p.length > 2048) return false;
    if (/\0|\r|\n/.test(p)) return false;
    if (p[0] !== '/') return false;
    if (p.includes('\\')) return false;
    if (/(^|\/)\.\.(\/|$)/.test(p)) return false;
    return true;
}

function scopeAllows(scopes, required) {
    const r = String(required || '').trim();
    if (!r) return true;

    const req = r.split(':');
    const list = Array.isArray(scopes) ? scopes.map((x) => String(x || '').trim()).filter(Boolean) : [];

    for (const s of list) {
        if (s === '*' || s === '*:*') return true;
        const sp = s.split(':');
        let ok = true;
        const n = Math.max(sp.length, req.length);
        for (let i = 0; i < n; i++) {
            const a = sp[i];
            const b = req[i];
            if (a === undefined) { ok = false; break; }
            if (a === '*') { ok = true; break; }
            if (b === undefined) { ok = true; break; }
            if (a !== b) { ok = false; break; }
        }
        if (ok) return true;
    }
    return false;
}

function ensureWithinRoot(rootDir, targetPath) {
    const rootReal = fs.realpathSync(rootDir);
    const targetReal = fs.realpathSync(targetPath);
    if (targetReal === rootReal) return true;
    const prefix = rootReal.endsWith(path.sep) ? rootReal : rootReal + path.sep;
    return targetReal.startsWith(prefix);
}

function resolveNodePath({ storage, applicationId, virtualPath }) {
    // virtualPath looks like /app/foo or /data/bar
    const v = String(virtualPath || '');
    if (!isSafePathValue(v)) return null;

    const roots = {
        '/app': storage.pathComposeDir(applicationId),
        '/data': storage.pathVolumeRoot(applicationId),
    };

    for (const [prefix, rootDir] of Object.entries(roots)) {
        try {
            if (rootDir && !fs.existsSync(rootDir)) {
                fs.mkdirSync(rootDir, { recursive: true });
            }
        } catch {
            // ignore
        }
        if (!v.startsWith(prefix)) continue;
        const rel = v.slice(prefix.length);
        const abs = path.resolve(rootDir, '.' + rel);
        return { prefix, rootDir, abs };
    }
    return null;
}
function allowedByPathsConstraint(virtualPath, paths) {
    const v = String(virtualPath || '');
    const list = Array.isArray(paths) ? paths.map((x) => String(x || '').trim()).filter(Boolean) : [];
    if (!list.length) return false;
    return list.some((p) => v === p || v.startsWith(p.endsWith('/') ? p : p + '/'));
}

function parseQuery(reqUrl) {
    const u = url.parse(reqUrl, true);
    return { pathname: u.pathname || '/', query: u.query || {} };
}

function parseLabels(labelsStr) {
    const out = {};
    const raw = String(labelsStr || '').trim();
    if (!raw) return out;
    for (const part of raw.split(',')) {
        const piece = String(part || '').trim();
        if (!piece) continue;
        const idx = piece.indexOf('=');
        if (idx <= 0) continue;
        const k = piece.slice(0, idx).trim();
        const v = piece.slice(idx + 1).trim();
        if (k) out[k] = v;
    }
    return out;
}

function extractAppIdFromLabels(labels) {
    const chapApp = String((labels && labels['chap.app']) || '').trim();
    if (chapApp) return chapApp;

    const project = String((labels && labels['com.docker.compose.project']) || '').trim();
    if (project.startsWith('chap-') && project.length > 5) {
        return project.slice(5);
    }
    return '';
}

function nowIso() {
    return new Date().toISOString();
}

function sseInit(res) {
    res.writeHead(200, {
        'Content-Type': 'text/event-stream; charset=utf-8',
        'Cache-Control': 'no-cache, no-transform',
        'Connection': 'keep-alive',
        'X-Accel-Buffering': 'no',
    });
    res.write(':ok\n\n');
}

function sseSend(res, event, data) {
    if (event) res.write(`event: ${event}\n`);
    const payload = typeof data === 'string' ? data : JSON.stringify(data);
    for (const line of String(payload).split('\n')) {
        res.write(`data: ${line}\n`);
    }
    res.write('\n');
}

function parseFilter(query, name) {
    const key = `filter[${name}]`;
    if (query && Object.prototype.hasOwnProperty.call(query, key)) {
        return String(query[key] ?? '').trim();
    }
    return '';
}

function makeNodeV2RequestHandler(deps) {
    const {
        config,
        storage,
        agentVersion,
        execCommand,
        deployComposeForNodeApi,
    } = deps;

    const getNodeId = () => String(config.nodeId || '').trim();
    const secret = String(process.env.CHAP_NODE_ACCESS_TOKEN_SECRET || '').trim();

    const jobs = new Map();

    function isSafeDockerRef(v) {
        const s = String(v || '').trim();
        if (!s) return false;
        if (s.length > 200) return false;
        // container ID (hex) or docker name-ish
        return /^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/.test(s);
    }

    function runDocker(args, options = {}) {
        const timeoutMs = Number.isFinite(options.timeout) ? Number(options.timeout) : 15000;
        return new Promise((resolve, reject) => {
            const child = spawn('docker', args.map((a) => String(a)), { stdio: ['ignore', 'pipe', 'pipe'] });
            let stdout = '';
            let stderr = '';

            const t = setTimeout(() => {
                try { child.kill('SIGTERM'); } catch {}
                setTimeout(() => { try { child.kill('SIGKILL'); } catch {} }, 1000);
                reject(new Error('Docker command timed out'));
            }, timeoutMs);

            child.stdout.on('data', (b) => { stdout += b.toString('utf8'); });
            child.stderr.on('data', (b) => { stderr += b.toString('utf8'); });
            child.on('error', (e) => {
                clearTimeout(t);
                reject(e);
            });
            child.on('close', (code) => {
                clearTimeout(t);
                if (code === 0) {
                    resolve(stdout);
                } else {
                    reject(new Error(String(stderr || stdout || `Docker exited ${code}` ).trim()));
                }
            });
        });
    }

    function run(cmd, options = {}) {
        if (typeof execCommand === 'function') {
            return execCommand(cmd, options);
        }
        return new Promise((resolve, reject) => {
            exec(cmd, options, (error, stdout, stderr) => {
                if (error) {
                    reject(new Error(String(stderr || error.message || error).trim()));
                } else {
                    resolve(stdout);
                }
            });
        });
    }

    async function listChapContainersRaw() {
        const raw = await runDocker([
            'ps', '-a',
            '--filter', 'label=chap.managed=true',
            '--format', '{{.ID}}|{{.Names}}|{{.Image}}|{{.Status}}|{{.Ports}}|{{.Labels}}',
        ], { timeout: 15000 });
        return String(raw || '')
            .trim()
            .split('\n')
            .filter(Boolean)
            .map((line) => {
                const [id, dockerName, image, status, ports, labelsRaw] = String(line).split('|');
                const labels = parseLabels(labelsRaw);
                const appId = extractAppIdFromLabels(labels);
                return {
                    id: String(id || '').trim(),
                    name: String(dockerName || '').trim(),
                    image: String(image || '').trim(),
                    status: String(status || '').trim(),
                    ports: String(ports || '').trim(),
                    labels,
                    application_id: appId || null,
                };
            })
            .filter((c) => c.id);
    }

    async function getContainerInspect(containerId) {
        const cid = String(containerId || '').trim();
        if (!cid || !isSafeDockerRef(cid)) return null;
        const out = await runDocker(['inspect', cid], { timeout: 15000 });
        const parsed = JSON.parse(out);
        return Array.isArray(parsed) && parsed[0] ? parsed[0] : null;
    }

    function tokenAppIdAllowed(tokenAppId, requestedAppId) {
        const a = String(tokenAppId || '').trim();
        const b = String(requestedAppId || '').trim();
        if (!a || !b) return false;
        return a === b;
    }

    return async function handler(req, res) {
        try {
            const { pathname, query } = parseQuery(req.url);

            const nodeId = getNodeId();

            // Only handle /node/v2/*; everything else is 404 (so WS server can still run normally).
            if (!pathname.startsWith('/node/v2/')) {
                res.statusCode = 404;
                res.end('Not Found');
                return;
            }

            if (pathname === '/node/v2/health' && req.method === 'GET') {
                json(res, 200, {
                    status: 'ok',
                    node_id: nodeId || null,
                    agent_version: agentVersion || null,
                    server_connected: !!deps.isServerConnected?.(),
                    server_time: nowIso(),
                });
                return;
            }

            if (pathname === '/node/v2/capabilities' && req.method === 'GET') {
                json(res, 200, {
                    data: {
                        api: { version: 'v2' },
                        features: {
                            filesystem: true,
                            container_logs: true,
                            exec: false,
                            volumes: true,
                        },
                    },
                });
                return;
            }

            // Auth required beyond this point.
            const auth = String(req.headers.authorization || '');
            if (!auth.startsWith('Bearer ')) {
                json(res, 401, { error: { code: 'unauthorized', message: 'Missing bearer token' } });
                return;
            }
            if (!secret) {
                json(res, 503, { error: { code: 'server_misconfigured', message: 'Missing CHAP_NODE_ACCESS_TOKEN_SECRET on node' } });
                return;
            }

            const jwt = auth.slice('Bearer '.length).trim();
            const payload = verifyJwtHs256(jwt, secret);
            if (!payload) {
                json(res, 401, { error: { code: 'unauthorized', message: 'Invalid token' } });
                return;
            }

            if (payload.aud && payload.aud !== 'chap-node') {
                json(res, 401, { error: { code: 'unauthorized', message: 'Invalid token audience' } });
                return;
            }
            if (payload.exp && Number(payload.exp) <= Math.floor(Date.now() / 1000)) {
                json(res, 401, { error: { code: 'unauthorized', message: 'Token expired' } });
                return;
            }
            if (nodeId && payload.node_id && String(payload.node_id) !== nodeId) {
                json(res, 403, { error: { code: 'forbidden', message: 'Token not valid for this node' } });
                return;
            }

            const scopes = Array.isArray(payload.scopes) ? payload.scopes : [];
            const constraints = payload.constraints && typeof payload.constraints === 'object' ? payload.constraints : {};
            const applicationId = constraints.application_id ? String(constraints.application_id) : '';
            const allowedPaths = Array.isArray(constraints.paths) ? constraints.paths : [];
            const maxBytes = Number.isFinite(constraints.max_bytes) ? Number(constraints.max_bytes) : null;

            // Applications
            if (pathname === '/node/v2/applications' && req.method === 'GET') {
                if (!scopeAllows(scopes, 'applications:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope applications:read' } });
                    return;
                }
                if (applicationId) {
                    json(res, 200, { data: [{ id: applicationId }] });
                    return;
                }
                const containers = await listChapContainersRaw();
                const ids = Array.from(new Set(containers.map((c) => c.application_id).filter(Boolean)));
                json(res, 200, { data: ids.map((id) => ({ id })) });
                return;
            }

            const appStatusMatch = pathname.match(/^\/node\/v2\/applications\/([^/]+)\/status$/);
            if (appStatusMatch && req.method === 'GET') {
                if (!scopeAllows(scopes, 'applications:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope applications:read' } });
                    return;
                }
                const reqAppId = decodeURIComponent(appStatusMatch[1]);
                if (applicationId && !tokenAppIdAllowed(applicationId, reqAppId)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token constraints forbid this application' } });
                    return;
                }
                const containers = (await listChapContainersRaw()).filter((c) => String(c.application_id || '') === String(reqAppId));
                const composeDir = storage && typeof storage.pathComposeDir === 'function' ? storage.pathComposeDir(reqAppId) : null;
                const hasComposeDir = !!(composeDir && fs.existsSync(composeDir));
                json(res, 200, {
                    data: {
                        application_id: reqAppId,
                        compose_dir: hasComposeDir ? composeDir : null,
                        containers: containers.map((c) => ({
                            id: c.id,
                            name: c.name,
                            image: c.image,
                            status: c.status,
                            ports: c.ports,
                        })),
                    },
                });
                return;
            }

            // Containers
            if (pathname === '/node/v2/containers' && req.method === 'GET') {
                if (!scopeAllows(scopes, 'containers:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope containers:read' } });
                    return;
                }
                const filterApp = parseFilter(query, 'application_id');
                const requestedAppId = filterApp || applicationId;
                if (applicationId && filterApp && !tokenAppIdAllowed(applicationId, filterApp)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token constraints forbid this application' } });
                    return;
                }
                const containers = await listChapContainersRaw();
                const filtered = requestedAppId
                    ? containers.filter((c) => String(c.application_id || '') === String(requestedAppId))
                    : containers;
                json(res, 200, {
                    data: filtered.map((c) => ({
                        id: c.id,
                        name: c.name,
                        image: c.image,
                        status: c.status,
                        ports: c.ports,
                        application_id: c.application_id,
                    })),
                });
                return;
            }

            const containerMatch = pathname.match(/^\/node\/v2\/containers\/([^/]+)$/);
            if (containerMatch && req.method === 'GET') {
                if (!scopeAllows(scopes, 'containers:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope containers:read' } });
                    return;
                }
                const cid = decodeURIComponent(containerMatch[1]);
                const inspected = await getContainerInspect(cid);
                if (!inspected) {
                    json(res, 404, { error: { code: 'not_found', message: 'Container not found' } });
                    return;
                }
                const labels = inspected && inspected.Config && inspected.Config.Labels ? inspected.Config.Labels : {};
                const appId = extractAppIdFromLabels(labels);
                if (applicationId && !tokenAppIdAllowed(applicationId, appId)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token constraints forbid this container' } });
                    return;
                }
                json(res, 200, {
                    data: {
                        id: inspected.Id,
                        name: (inspected.Name || '').replace(/^\//, ''),
                        image: inspected.Config ? inspected.Config.Image : null,
                        state: inspected.State || null,
                        labels,
                        application_id: appId || null,
                    },
                });
                return;
            }

            const containerActionMatch = pathname.match(/^\/node\/v2\/containers\/([^/]+):(restart|stop)$/);
            if (containerActionMatch && req.method === 'POST') {
                if (!scopeAllows(scopes, 'containers:write')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope containers:write' } });
                    return;
                }
                const cid = decodeURIComponent(containerActionMatch[1]);
                if (!isSafeDockerRef(cid)) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid container id' } });
                    return;
                }
                const action = containerActionMatch[2];
                const inspected = await getContainerInspect(cid);
                if (!inspected) {
                    json(res, 404, { error: { code: 'not_found', message: 'Container not found' } });
                    return;
                }
                const labels = inspected && inspected.Config && inspected.Config.Labels ? inspected.Config.Labels : {};
                const appId = extractAppIdFromLabels(labels);
                if (applicationId && !tokenAppIdAllowed(applicationId, appId)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token constraints forbid this container' } });
                    return;
                }
                if (action === 'restart') {
                    await runDocker(['restart', cid], { timeout: 45000 });
                } else {
                    await runDocker(['stop', '--time', '30', cid], { timeout: 45000 });
                }
                json(res, 200, { data: { ok: true } });
                return;
            }

            const containerLogsMatch = pathname.match(/^\/node\/v2\/containers\/([^/]+)\/logs$/);
            if (containerLogsMatch && req.method === 'GET') {
                if (!scopeAllows(scopes, 'logs:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope logs:read' } });
                    return;
                }
                const cid = decodeURIComponent(containerLogsMatch[1]);
                if (!isSafeDockerRef(cid)) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid container id' } });
                    return;
                }
                const inspected = await getContainerInspect(cid);
                if (!inspected) {
                    json(res, 404, { error: { code: 'not_found', message: 'Container not found' } });
                    return;
                }
                const labels = inspected && inspected.Config && inspected.Config.Labels ? inspected.Config.Labels : {};
                const appId = extractAppIdFromLabels(labels);
                if (applicationId && !tokenAppIdAllowed(applicationId, appId)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token constraints forbid this container' } });
                    return;
                }

                const since = String(query.since || '').trim();
                const tail = Math.max(1, Math.min(5000, parseInt(String(query.tail || '200'), 10) || 200));
                const args = ['logs', '--tail', String(tail)];
                if (since) args.push('--since', since);
                args.push(cid);
                const out = await runDocker(args, { timeout: 15000 });
                json(res, 200, { data: { text: String(out || '') } });
                return;
            }

            const containerLogsStreamMatch = pathname.match(/^\/node\/v2\/containers\/([^/]+)\/logs\/stream$/);
            if (containerLogsStreamMatch && req.method === 'GET') {
                if (!scopeAllows(scopes, 'logs:stream')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope logs:stream' } });
                    return;
                }
                const cid = decodeURIComponent(containerLogsStreamMatch[1]);
                if (!isSafeDockerRef(cid)) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid container id' } });
                    return;
                }
                const inspected = await getContainerInspect(cid);
                if (!inspected) {
                    json(res, 404, { error: { code: 'not_found', message: 'Container not found' } });
                    return;
                }
                const labels = inspected && inspected.Config && inspected.Config.Labels ? inspected.Config.Labels : {};
                const appId = extractAppIdFromLabels(labels);
                if (applicationId && !tokenAppIdAllowed(applicationId, appId)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token constraints forbid this container' } });
                    return;
                }

                const since = String(query.since || '').trim();
                const tail = Math.max(1, Math.min(5000, parseInt(String(query.tail || '200'), 10) || 200));

                sseInit(res);
                sseSend(res, 'meta', { container_id: cid, application_id: appId || null, started_at: nowIso() });

                const args = ['logs', '--tail', String(tail)];
                if (since) args.push('--since', since);
                args.push('-f', cid);
                const child = spawn('docker', args, { stdio: ['ignore', 'pipe', 'pipe'] });

                const kill = () => {
                    try { child.kill('SIGTERM'); } catch {}
                    setTimeout(() => { try { child.kill('SIGKILL'); } catch {} }, 1000);
                };
                req.on('close', kill);
                req.on('aborted', kill);

                child.stdout.on('data', (buf) => {
                    const text = buf.toString('utf8');
                    for (const line of text.split('\n')) {
                        if (line === '') continue;
                        sseSend(res, 'log', { line, ts: Date.now() });
                    }
                });
                child.stderr.on('data', (buf) => {
                    const text = buf.toString('utf8');
                    for (const line of text.split('\n')) {
                        if (line === '') continue;
                        sseSend(res, 'stderr', { line, ts: Date.now() });
                    }
                });
                child.on('close', (code) => {
                    sseSend(res, 'end', { code: code ?? null, ended_at: nowIso() });
                    try { res.end(); } catch {}
                });
                return;
            }

            // Metrics
            if (pathname === '/node/v2/metrics/host' && req.method === 'GET') {
                if (!scopeAllows(scopes, 'metrics:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope metrics:read' } });
                    return;
                }
                const diskUsage = storage && typeof storage.getDiskUsage === 'function' ? storage.getDiskUsage() : { total: 0, used: 0, free: 0 };
                json(res, 200, {
                    data: {
                        cpu: { cores: os.cpus().length, loadavg_1m: os.loadavg()[0] },
                        memory: { total: os.totalmem(), free: os.freemem(), used: os.totalmem() - os.freemem() },
                        uptime_sec: os.uptime(),
                        disk: diskUsage,
                        timestamp: Date.now(),
                    },
                });
                return;
            }

            if (pathname === '/node/v2/metrics/containers' && req.method === 'GET') {
                if (!scopeAllows(scopes, 'metrics:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope metrics:read' } });
                    return;
                }

                const out = await runDocker([
                    'stats', '--no-stream',
                    '--format', '{{.Container}}|{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}|{{.MemPerc}}|{{.NetIO}}|{{.BlockIO}}|{{.PIDs}}',
                ], { timeout: 15000 });
                const lines = String(out || '').trim().split('\n').filter(Boolean);

                const containers = await listChapContainersRaw();
                const byId = new Map(containers.map((c) => [c.id, c]));
                const rows = [];
                for (const line of lines) {
                    const [id, name, cpu, memUsage, memPerc, netIo, blockIo, pids] = String(line).split('|');
                    const meta = byId.get(String(id).trim());
                    if (!meta) continue;
                    if (applicationId && !tokenAppIdAllowed(applicationId, meta.application_id)) continue;
                    rows.push({
                        id: String(id).trim(),
                        name: String(name).trim(),
                        cpu_perc: String(cpu).trim(),
                        mem_usage: String(memUsage).trim(),
                        mem_perc: String(memPerc).trim(),
                        net_io: String(netIo).trim(),
                        block_io: String(blockIo).trim(),
                        pids: String(pids).trim(),
                        application_id: meta.application_id,
                    });
                }

                json(res, 200, { data: rows });
                return;
            }

            const metricContainerMatch = pathname.match(/^\/node\/v2\/metrics\/containers\/([^/]+)$/);
            if (metricContainerMatch && req.method === 'GET') {
                if (!scopeAllows(scopes, 'metrics:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope metrics:read' } });
                    return;
                }
                const cid = decodeURIComponent(metricContainerMatch[1]);
                if (!isSafeDockerRef(cid)) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid container id' } });
                    return;
                }

                // Best-effort: if docker inspect fails, treat as not found.
                const inspected = await getContainerInspect(cid);
                if (!inspected) {
                    json(res, 404, { error: { code: 'not_found', message: 'Container not found' } });
                    return;
                }

                const labels = inspected && inspected.Config && inspected.Config.Labels ? inspected.Config.Labels : {};
                const appId = extractAppIdFromLabels(labels);
                if (applicationId && !tokenAppIdAllowed(applicationId, appId)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token constraints forbid this container' } });
                    return;
                }

                // Use docker stats for a single container; keep response minimal.
                let out = '';
                try {
                    out = await runDocker([
                        'stats', '--no-stream',
                        '--format', '{{.Container}}|{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}|{{.MemPerc}}|{{.NetIO}}|{{.BlockIO}}|{{.PIDs}}',
                        cid,
                    ], { timeout: 15000 });
                } catch (e) {
                    json(res, 200, { data: { id: cid, application_id: appId || null, error: String(e && e.message ? e.message : e) } });
                    return;
                }

                const line = String(out || '').trim().split('\n').filter(Boolean)[0] || '';
                const [id, name, cpu, memUsage, memPerc, netIo, blockIo, pids] = String(line).split('|');
                json(res, 200, {
                    data: {
                        id: String(id || cid).trim(),
                        name: String(name || '').trim(),
                        cpu_perc: String(cpu || '').trim(),
                        mem_usage: String(memUsage || '').trim(),
                        mem_perc: String(memPerc || '').trim(),
                        net_io: String(netIo || '').trim(),
                        block_io: String(blockIo || '').trim(),
                        pids: String(pids || '').trim(),
                        application_id: appId || null,
                    },
                });
                return;
            }

            // Volumes (filesystem-backed)
            if (pathname === '/node/v2/volumes' && req.method === 'GET') {
                if (!scopeAllows(scopes, 'volumes:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope volumes:read' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }
                const root = storage && typeof storage.pathVolumeRoot === 'function' ? storage.pathVolumeRoot(applicationId) : null;
                if (!root || !fs.existsSync(root)) {
                    json(res, 200, { data: [] });
                    return;
                }
                const entries = fs.readdirSync(root, { withFileTypes: true })
                    .filter((d) => d.isDirectory())
                    .map((d) => ({ id: d.name, path: path.join(root, d.name) }));
                json(res, 200, { data: entries });
                return;
            }

            const volAttachMatch = pathname.match(/^\/node\/v2\/volumes\/([^/]+):attach$/);
            if (volAttachMatch && req.method === 'POST') {
                if (!scopeAllows(scopes, 'volumes:write')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope volumes:write' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }
                const volumeId = decodeURIComponent(volAttachMatch[1]);
                json(res, 200, { data: { ok: true, volume_id: volumeId, action: 'attach' } });
                return;
            }

            const volDetachMatch = pathname.match(/^\/node\/v2\/volumes\/([^/]+):detach$/);
            if (volDetachMatch && req.method === 'POST') {
                if (!scopeAllows(scopes, 'volumes:write')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope volumes:write' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }
                const volumeId = decodeURIComponent(volDetachMatch[1]);
                json(res, 200, { data: { ok: true, volume_id: volumeId, action: 'detach' } });
                return;
            }

            const volSnapshotMatch = pathname.match(/^\/node\/v2\/volumes\/([^/]+):snapshot$/);
            if (volSnapshotMatch && req.method === 'POST') {
                if (!scopeAllows(scopes, 'volumes:write')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope volumes:write' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }
                const volumeId = decodeURIComponent(volSnapshotMatch[1]);
                const snapshotId = 'snap_' + b64urlEncode(crypto.randomBytes(12));
                json(res, 201, { data: { ok: true, volume_id: volumeId, snapshot_id: snapshotId } });
                return;
            }

            // Deployments (advanced)
            if (pathname === '/node/v2/deployments' && req.method === 'POST') {
                if (!scopeAllows(scopes, 'applications:deploy')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope applications:deploy' } });
                    return;
                }
                if (typeof deployComposeForNodeApi !== 'function') {
                    json(res, 501, { error: { code: 'not_implemented', message: 'Node deployment API not available in this agent build' } });
                    return;
                }

                const bodyText = await readBody(req);
                let body;
                try { body = JSON.parse(bodyText || '{}'); } catch { body = {}; }

                const deployment_id = String(body.deployment_id || body.deploymentId || '').trim();
                const appConfig = body.application && typeof body.application === 'object' ? body.application : null;
                const reqAppId = String(body.application_id || body.applicationId || (appConfig ? (appConfig.uuid || appConfig.id || '') : '')).trim();
                if (!deployment_id || !reqAppId || !appConfig) {
                    json(res, 422, { error: { code: 'validation_error', message: 'Validation error', details: { field: 'deployment_id/application' } } });
                    return;
                }
                if (applicationId && !tokenAppIdAllowed(applicationId, reqAppId)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token constraints forbid this application' } });
                    return;
                }

                const job_id = 'job_' + b64urlEncode(crypto.randomBytes(12));
                jobs.set(deployment_id, { job_id, deployment_id, application_id: reqAppId, status: 'running', started_at: nowIso() });

                setImmediate(async () => {
                    try {
                        const result = await deployComposeForNodeApi({ deployment_id, application: appConfig });
                        if (result && result.ok === false) {
                            jobs.set(deployment_id, { job_id, deployment_id, application_id: reqAppId, status: 'failed', error: String(result.error || 'Unknown'), finished_at: nowIso() });
                        } else {
                            jobs.set(deployment_id, { job_id, deployment_id, application_id: reqAppId, status: 'finished', finished_at: nowIso() });
                        }
                    } catch (e) {
                        jobs.set(deployment_id, { job_id, deployment_id, application_id: reqAppId, status: 'failed', error: String(e && e.message ? e.message : e), finished_at: nowIso() });
                    }
                });

                json(res, 202, { data: { job_id, deployment_id, status: 'running' } });
                return;
            }

            const deploymentStatusMatch = pathname.match(/^\/node\/v2\/deployments\/([^/]+)$/);
            if (deploymentStatusMatch && req.method === 'GET') {
                if (!scopeAllows(scopes, 'applications:deploy')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope applications:deploy' } });
                    return;
                }
                const depId = decodeURIComponent(deploymentStatusMatch[1]);
                const st = jobs.get(depId);
                if (!st) {
                    json(res, 404, { error: { code: 'not_found', message: 'Deployment job not found' } });
                    return;
                }
                if (applicationId && !tokenAppIdAllowed(applicationId, st.application_id)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token constraints forbid this deployment' } });
                    return;
                }
                json(res, 200, { data: st });
                return;
            }

            const deploymentCancelMatch = pathname.match(/^\/node\/v2\/deployments\/([^/]+):cancel$/);
            if (deploymentCancelMatch && req.method === 'POST') {
                if (!scopeAllows(scopes, 'applications:deploy')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope applications:deploy' } });
                    return;
                }
                const depId = decodeURIComponent(deploymentCancelMatch[1]);
                const st = jobs.get(depId);
                if (!st) {
                    json(res, 404, { error: { code: 'not_found', message: 'Deployment job not found' } });
                    return;
                }
                if (applicationId && !tokenAppIdAllowed(applicationId, st.application_id)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token constraints forbid this deployment' } });
                    return;
                }
                jobs.set(depId, { ...st, status: 'canceled', finished_at: nowIso() });
                json(res, 200, { data: { canceled: true, deployment_id: depId } });
                return;
            }

            // FS endpoints
            if (pathname === '/node/v2/fs/ls' && req.method === 'GET') {
                if (!scopeAllows(scopes, 'files:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope files:read' } });
                    return;
                }
                const virtualPath = String(query.path || '');
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }
                if (!allowedByPathsConstraint(virtualPath, allowedPaths)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path not allowed by token constraints' } });
                    return;
                }
                const resolved = resolveNodePath({ storage, applicationId, virtualPath });
                if (!resolved) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid path' } });
                    return;
                }
                const { abs, rootDir } = resolved;
                if (!fs.existsSync(abs)) {
                    json(res, 404, { error: { code: 'not_found', message: 'Path not found' } });
                    return;
                }

                // Symlink escape protection.
                try {
                    if (!ensureWithinRoot(rootDir, abs)) {
                        json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                        return;
                    }
                } catch {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }

                const limit = Math.max(1, Math.min(2000, parseInt(String(query.limit || '2000'), 10) || 2000));
                const recursive = String(query.recursive || 'false') === 'true';

                const out = [];
                const walk = (dir, prefix) => {
                    let entries;
                    try {
                        entries = fs.readdirSync(dir, { withFileTypes: true });
                    } catch {
                        return;
                    }
                    for (const ent of entries) {
                        if (out.length >= limit) return;
                        const pAbs = path.join(dir, ent.name);
                        const pVirt = prefix + '/' + ent.name;
                        let stat;
                        try { stat = fs.lstatSync(pAbs); } catch { continue; }
                        out.push({
                            path: pVirt,
                            name: ent.name,
                            type: ent.isDirectory() ? 'dir' : ent.isFile() ? 'file' : ent.isSymbolicLink() ? 'symlink' : 'other',
                            size: stat.size,
                            mtime: stat.mtime.toISOString(),
                        });
                        if (recursive && ent.isDirectory()) {
                            walk(pAbs, pVirt);
                        }
                    }
                };

                walk(abs, virtualPath.replace(/\/$/, ''));
                json(res, 200, { data: out });
                return;
            }

            if (pathname === '/node/v2/fs/stat' && req.method === 'GET') {
                if (!scopeAllows(scopes, 'files:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope files:read' } });
                    return;
                }
                const virtualPath = String(query.path || '');
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }
                if (!allowedByPathsConstraint(virtualPath, allowedPaths)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path not allowed by token constraints' } });
                    return;
                }
                const resolved = resolveNodePath({ storage, applicationId, virtualPath });
                if (!resolved) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid path' } });
                    return;
                }
                const { abs, rootDir } = resolved;
                if (!fs.existsSync(abs)) {
                    json(res, 404, { error: { code: 'not_found', message: 'Path not found' } });
                    return;
                }
                try {
                    if (!ensureWithinRoot(rootDir, abs)) {
                        json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                        return;
                    }
                } catch {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }
                const stat = fs.lstatSync(abs);
                json(res, 200, {
                    data: {
                        path: virtualPath,
                        type: stat.isDirectory() ? 'dir' : stat.isFile() ? 'file' : stat.isSymbolicLink() ? 'symlink' : 'other',
                        size: stat.size,
                        mode: (stat.mode & 0o777).toString(8).padStart(4, '0'),
                        mtime: stat.mtime.toISOString(),
                    },
                });
                return;
            }

            if (pathname === '/node/v2/fs/read' && req.method === 'GET') {
                if (!scopeAllows(scopes, 'files:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope files:read' } });
                    return;
                }
                const virtualPath = String(query.path || '');
                const offset = Math.max(0, parseInt(String(query.offset || '0'), 10) || 0);
                const length = Math.max(0, parseInt(String(query.length || '0'), 10) || 0);
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }
                if (!allowedByPathsConstraint(virtualPath, allowedPaths)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path not allowed by token constraints' } });
                    return;
                }
                if (maxBytes !== null && length > maxBytes) {
                    json(res, 413, { error: { code: 'too_large', message: 'Read exceeds max_bytes constraint' } });
                    return;
                }
                const resolved = resolveNodePath({ storage, applicationId, virtualPath });
                if (!resolved) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid path' } });
                    return;
                }
                const { abs, rootDir } = resolved;
                if (!fs.existsSync(abs)) {
                    json(res, 404, { error: { code: 'not_found', message: 'Path not found' } });
                    return;
                }
                try {
                    if (!ensureWithinRoot(rootDir, abs)) {
                        json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                        return;
                    }
                } catch {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }
                const stat = fs.statSync(abs);
                const maxLen = stat.size - offset;
                const readLen = length > 0 ? Math.min(length, maxLen) : Math.min(maxLen, maxBytes ?? maxLen);

                const fd = fs.openSync(abs, 'r');
                try {
                    const buf = Buffer.alloc(Math.max(0, readLen));
                    fs.readSync(fd, buf, 0, buf.length, offset);

                    const accept = String(req.headers.accept || '');
                    if (accept.includes('application/json')) {
                        json(res, 200, { data: { content_base64: buf.toString('base64'), bytes: buf.length } });
                        return;
                    }

                    res.writeHead(200, {
                        'Content-Type': 'application/octet-stream',
                        'Content-Length': buf.length,
                    });
                    res.end(buf);
                    return;
                } finally {
                    try { fs.closeSync(fd); } catch {}
                }
            }

            if (pathname === '/node/v2/fs/write' && req.method === 'PUT') {
                if (!scopeAllows(scopes, 'files:write')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope files:write' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }

                const bodyText = await readBody(req);
                let body;
                try { body = JSON.parse(bodyText || '{}'); } catch { body = {}; }

                const virtualPath = String(body.path || '');
                const atomic = body.atomic !== false;
                const modeStr = body.mode ? String(body.mode) : null;
                const contentB64 = String(body.content_base64 || '');
                const buf = Buffer.from(contentB64, 'base64');

                if (!allowedByPathsConstraint(virtualPath, allowedPaths)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path not allowed by token constraints' } });
                    return;
                }
                if (maxBytes !== null && buf.length > maxBytes) {
                    json(res, 413, { error: { code: 'too_large', message: 'Write exceeds max_bytes constraint' } });
                    return;
                }

                const resolved = resolveNodePath({ storage, applicationId, virtualPath });
                if (!resolved) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid path' } });
                    return;
                }
                const { abs, rootDir } = resolved;
                const parent = path.dirname(abs);
                if (!fs.existsSync(parent)) {
                    json(res, 404, { error: { code: 'not_found', message: 'Parent directory not found' } });
                    return;
                }

                try {
                    if (!ensureWithinRoot(rootDir, parent)) {
                        json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                        return;
                    }
                } catch {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }

                if (atomic) {
                    const tmp = abs + '.tmp.' + crypto.randomBytes(8).toString('hex');
                    fs.writeFileSync(tmp, buf);
                    fs.renameSync(tmp, abs);
                } else {
                    fs.writeFileSync(abs, buf);
                }

                if (modeStr) {
                    const m = parseInt(modeStr, 8);
                    if (Number.isFinite(m)) {
                        try { fs.chmodSync(abs, m); } catch {}
                    }
                }

                json(res, 200, { data: { ok: true } });
                return;
            }

            if (pathname === '/node/v2/fs/mkdir' && req.method === 'POST') {
                if (!scopeAllows(scopes, 'files:write')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope files:write' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }
                const bodyText = await readBody(req);
                let body;
                try { body = JSON.parse(bodyText || '{}'); } catch { body = {}; }
                const virtualPath = String(body.path || '');
                if (!allowedByPathsConstraint(virtualPath, allowedPaths)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path not allowed by token constraints' } });
                    return;
                }
                const resolved = resolveNodePath({ storage, applicationId, virtualPath });
                if (!resolved) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid path' } });
                    return;
                }
                const { abs, rootDir } = resolved;
                const parent = path.dirname(abs);
                try {
                    if (!ensureWithinRoot(rootDir, parent)) {
                        json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                        return;
                    }
                } catch {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }
                fs.mkdirSync(abs, { recursive: true });
                json(res, 200, { data: { ok: true } });
                return;
            }

            if (pathname === '/node/v2/fs/move' && req.method === 'POST') {
                if (!scopeAllows(scopes, 'files:write')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope files:write' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }

                const bodyText = await readBody(req);
                let body;
                try { body = JSON.parse(bodyText || '{}'); } catch { body = {}; }

                const from = String(body.from || '');
                const to = String(body.to || '');
                const overwrite = body.overwrite === true;

                if (!allowedByPathsConstraint(from, allowedPaths) || !allowedByPathsConstraint(to, allowedPaths)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path not allowed by token constraints' } });
                    return;
                }
                const rFrom = resolveNodePath({ storage, applicationId, virtualPath: from });
                const rTo = resolveNodePath({ storage, applicationId, virtualPath: to });
                if (!rFrom || !rTo) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid path' } });
                    return;
                }

                if (!fs.existsSync(rFrom.abs)) {
                    json(res, 404, { error: { code: 'not_found', message: 'Source not found' } });
                    return;
                }
                if (fs.existsSync(rTo.abs) && !overwrite) {
                    json(res, 409, { error: { code: 'conflict', message: 'Destination exists' } });
                    return;
                }

                try {
                    if (!ensureWithinRoot(rFrom.rootDir, rFrom.abs) || !ensureWithinRoot(rTo.rootDir, path.dirname(rTo.abs))) {
                        json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                        return;
                    }
                } catch {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }

                fs.mkdirSync(path.dirname(rTo.abs), { recursive: true });
                if (overwrite && fs.existsSync(rTo.abs)) {
                    fs.rmSync(rTo.abs, { recursive: true, force: true });
                }
                fs.renameSync(rFrom.abs, rTo.abs);
                json(res, 200, { data: { ok: true } });
                return;
            }

            if (pathname === '/node/v2/fs/copy' && req.method === 'POST') {
                if (!scopeAllows(scopes, 'files:write')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope files:write' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }

                const bodyText = await readBody(req);
                let body;
                try { body = JSON.parse(bodyText || '{}'); } catch { body = {}; }

                const from = String(body.from || '');
                const to = String(body.to || '');
                const overwrite = body.overwrite === true;

                if (!allowedByPathsConstraint(from, allowedPaths) || !allowedByPathsConstraint(to, allowedPaths)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path not allowed by token constraints' } });
                    return;
                }
                const rFrom = resolveNodePath({ storage, applicationId, virtualPath: from });
                const rTo = resolveNodePath({ storage, applicationId, virtualPath: to });
                if (!rFrom || !rTo) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid path' } });
                    return;
                }
                if (!fs.existsSync(rFrom.abs)) {
                    json(res, 404, { error: { code: 'not_found', message: 'Source not found' } });
                    return;
                }
                if (fs.existsSync(rTo.abs) && !overwrite) {
                    json(res, 409, { error: { code: 'conflict', message: 'Destination exists' } });
                    return;
                }

                try {
                    if (!ensureWithinRoot(rFrom.rootDir, rFrom.abs) || !ensureWithinRoot(rTo.rootDir, path.dirname(rTo.abs))) {
                        json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                        return;
                    }
                } catch {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }

                fs.mkdirSync(path.dirname(rTo.abs), { recursive: true });
                if (overwrite && fs.existsSync(rTo.abs)) {
                    fs.rmSync(rTo.abs, { recursive: true, force: true });
                }

                const stat = fs.lstatSync(rFrom.abs);
                if (stat.isDirectory()) {
                    fs.cpSync(rFrom.abs, rTo.abs, { recursive: true });
                } else {
                    fs.copyFileSync(rFrom.abs, rTo.abs);
                }

                json(res, 200, { data: { ok: true } });
                return;
            }

            if (pathname === '/node/v2/fs/chmod' && req.method === 'POST') {
                if (!scopeAllows(scopes, 'files:write')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope files:write' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }

                const bodyText = await readBody(req);
                let body;
                try { body = JSON.parse(bodyText || '{}'); } catch { body = {}; }

                const virtualPath = String(body.path || '');
                const modeStr = String(body.mode || '').trim();
                const mode = parseInt(modeStr, 8);
                if (!modeStr || !Number.isFinite(mode)) {
                    json(res, 422, { error: { code: 'validation_error', message: 'Validation error', details: { field: 'mode' } } });
                    return;
                }
                if (!allowedByPathsConstraint(virtualPath, allowedPaths)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path not allowed by token constraints' } });
                    return;
                }
                const resolved = resolveNodePath({ storage, applicationId, virtualPath });
                if (!resolved) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid path' } });
                    return;
                }
                const { abs, rootDir } = resolved;
                if (!fs.existsSync(abs)) {
                    json(res, 404, { error: { code: 'not_found', message: 'Path not found' } });
                    return;
                }
                try {
                    if (!ensureWithinRoot(rootDir, abs)) {
                        json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                        return;
                    }
                } catch {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }

                fs.chmodSync(abs, mode);
                json(res, 200, { data: { ok: true } });
                return;
            }

            if (pathname === '/node/v2/fs/upload' && req.method === 'POST') {
                if (!scopeAllows(scopes, 'files:write')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope files:write' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }

                const ct = String(req.headers['content-type'] || '');
                const m = ct.match(/boundary=([^;]+)/i);
                const boundary = m ? String(m[1]).trim().replace(/^"|"$/g, '') : '';
                if (!boundary) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Missing multipart boundary' } });
                    return;
                }

                const buf = await readBodyBuffer(req, maxBytes !== null ? Math.max(1024, maxBytes) : 15 * 1024 * 1024);
                const raw = buf.toString('latin1');
                const marker = '--' + boundary;
                const parts = raw.split(marker).slice(1, -1);

                let virtualPath = '';
                let fileBytes = null;

                for (const part of parts) {
                    const p = part.replace(/^\r\n/, '');
                    const idx = p.indexOf('\r\n\r\n');
                    if (idx === -1) continue;
                    const headers = p.slice(0, idx);
                    let body = p.slice(idx + 4);
                    if (body.endsWith('\r\n')) body = body.slice(0, -2);

                    const disp = headers.match(/content-disposition:\s*form-data;\s*([^\r\n]+)/i);
                    const dispParams = disp ? disp[1] : '';
                    const nameMatch = dispParams.match(/name="([^"]+)"/i);
                    const field = nameMatch ? nameMatch[1] : '';

                    if (field === 'path') {
                        virtualPath = String(body || '').trim();
                    } else if (field === 'file') {
                        fileBytes = Buffer.from(body, 'latin1');
                    }
                }

                if (!virtualPath || !fileBytes) {
                    json(res, 422, { error: { code: 'validation_error', message: 'Validation error', details: { field: 'path/file' } } });
                    return;
                }
                if (!allowedByPathsConstraint(virtualPath, allowedPaths)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path not allowed by token constraints' } });
                    return;
                }
                if (maxBytes !== null && fileBytes.length > maxBytes) {
                    json(res, 413, { error: { code: 'too_large', message: 'Upload exceeds max_bytes constraint' } });
                    return;
                }

                const resolved = resolveNodePath({ storage, applicationId, virtualPath });
                if (!resolved) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid path' } });
                    return;
                }
                const { abs, rootDir } = resolved;
                const parent = path.dirname(abs);
                let rootReal = '';
                try { rootReal = fs.realpathSync(rootDir); } catch { rootReal = ''; }
                if (!rootReal) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }
                const parentResolved = path.resolve(parent);
                const prefix = rootReal.endsWith(path.sep) ? rootReal : rootReal + path.sep;
                if (parentResolved !== rootReal && !parentResolved.startsWith(prefix)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }

                fs.mkdirSync(parent, { recursive: true });
                fs.writeFileSync(abs, fileBytes);
                json(res, 200, { data: { ok: true, bytes: fileBytes.length } });
                return;
            }

            if (pathname === '/node/v2/fs/download' && req.method === 'GET') {
                if (!scopeAllows(scopes, 'files:read')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope files:read' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }
                const virtualPath = String(query.path || '');
                if (!allowedByPathsConstraint(virtualPath, allowedPaths)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path not allowed by token constraints' } });
                    return;
                }
                const resolved = resolveNodePath({ storage, applicationId, virtualPath });
                if (!resolved) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid path' } });
                    return;
                }
                const { abs, rootDir } = resolved;
                if (!fs.existsSync(abs)) {
                    json(res, 404, { error: { code: 'not_found', message: 'Path not found' } });
                    return;
                }
                try {
                    if (!ensureWithinRoot(rootDir, abs)) {
                        json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                        return;
                    }
                } catch {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }

                const stat = fs.lstatSync(abs);
                if (stat.isFile()) {
                    const fileBuf = fs.readFileSync(abs);
                    res.writeHead(200, {
                        'Content-Type': 'application/octet-stream',
                        'Content-Length': fileBuf.length,
                    });
                    res.end(fileBuf);
                    return;
                }

                // Directory: return a JSON listing (client can fetch individual files).
                let entries = [];
                try {
                    entries = fs.readdirSync(abs, { withFileTypes: true }).map((d) => ({
                        name: d.name,
                        type: d.isDirectory() ? 'dir' : d.isFile() ? 'file' : 'other',
                    }));
                } catch {
                    entries = [];
                }
                json(res, 200, { data: { path: virtualPath, entries } });
                return;
            }

            if (pathname === '/node/v2/fs/delete' && req.method === 'DELETE') {
                if (!scopeAllows(scopes, 'files:delete')) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Missing scope files:delete' } });
                    return;
                }
                if (!applicationId) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Token missing application_id constraint' } });
                    return;
                }
                const virtualPath = String(query.path || '');
                if (!allowedByPathsConstraint(virtualPath, allowedPaths)) {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path not allowed by token constraints' } });
                    return;
                }
                const resolved = resolveNodePath({ storage, applicationId, virtualPath });
                if (!resolved) {
                    json(res, 400, { error: { code: 'invalid_request', message: 'Invalid path' } });
                    return;
                }
                const { abs, rootDir } = resolved;
                if (!fs.existsSync(abs)) {
                    json(res, 404, { error: { code: 'not_found', message: 'Path not found' } });
                    return;
                }
                try {
                    if (!ensureWithinRoot(rootDir, abs)) {
                        json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                        return;
                    }
                } catch {
                    json(res, 403, { error: { code: 'forbidden', message: 'Path escapes sandbox' } });
                    return;
                }
                fs.rmSync(abs, { recursive: true, force: true });
                json(res, 200, { data: { ok: true } });
                return;
            }

            json(res, 404, { error: { code: 'not_found', message: 'Not found' } });
        } catch (e) {
            json(res, 500, { error: { code: 'internal', message: String(e && e.message ? e.message : e) } });
        }
    };
}

module.exports = {
    makeNodeV2RequestHandler,
};

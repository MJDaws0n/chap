/**
 * Chap Node Agent
 * Connects to the Chap server via WebSocket and manages Docker containers
 * version pre-rc-1.1
 */

const WebSocket = require('ws');
const { spawn } = require('child_process');
const http = require('http');
const os = require('os');
const fs = require('fs');
const path = require('path');
const yaml = require('yaml');
const StorageManager = require('./storage');
const security = require('./security');
const { createLiveLogsWs } = require('./liveLogsWs');

// Node Agent version (single source of truth)
const AGENT_VERSION = (() => {
    try {
        // node/src/index.js -> node/package.json
        return process.env.CHAP_AGENT_VERSION || require('../package.json').version;
    } catch (e) {
        return process.env.CHAP_AGENT_VERSION || '0.0.0';
    }
})();

// Configuration
const config = {
    serverUrl: process.env.CHAP_SERVER_URL || 'ws://localhost:6001',
    nodeId: process.env.NODE_ID || '',
    nodeToken: process.env.NODE_TOKEN || '',
    reconnectInterval: 5000,
    heartbeatInterval: 5000,
    dataDir: process.env.CHAP_DATA_DIR || '/data',

    // Browser WebSocket server config
    browserWsPort: parseInt(process.env.BROWSER_WS_PORT || '6002', 10),
    browserWsHost: process.env.BROWSER_WS_HOST || '0.0.0.0',

    // SSL config for browser WebSocket (WSS)
    browserWsSslCert: process.env.BROWSER_WS_SSL_CERT || null,
    browserWsSslKey: process.env.BROWSER_WS_SSL_KEY || null,

    // Cleanup sweeper (best-effort, conservative)
    sweeperEnabled: (process.env.CHAP_SWEEPER_ENABLED ?? '1') !== '0',
    sweeperIntervalSeconds: parseInt(process.env.CHAP_SWEEPER_INTERVAL_SECONDS || '3600', 10),
    sweeperMinAgeSeconds: parseInt(process.env.CHAP_SWEEPER_MIN_AGE_SECONDS || '86400', 10),
    sweeperBuildMinAgeSeconds: parseInt(process.env.CHAP_SWEEPER_BUILD_MIN_AGE_SECONDS || '21600', 10),
    sweeperDryRun: (process.env.CHAP_SWEEPER_DRY_RUN ?? '0') === '1',
};

// Images to use for port bind checks (first successful start wins)
const PORTCHECK_IMAGES = [
    process.env.CHAP_PORTCHECK_IMAGE,
    'ghcr.io/mjdaws0n/chap-node:latest',
    'node:20-alpine',
].filter(Boolean);

// Initialize storage manager
const storage = new StorageManager(config.dataDir);
storage.init();

// Best-effort: restore any previously configured enforcers after a node restart.
rehydrateAppEnforcersFromDisk();

// Utility: safely normalize IDs coming from external sources
function safeId(x) {
    return String(x || '').trim();
}

function redactSecrets(input, secrets = []) {
    let out = String(input ?? '');
    for (const secret of secrets || []) {
        if (!secret) continue;
        const s = String(secret);
        if (!s) continue;
        out = out.split(s).join('[REDACTED]');
    }
    return out;
}

function isLikelyGitAuthError(message) {
    const m = String(message || '').toLowerCase();
    return (
        m.includes('authentication failed') ||
        m.includes('could not read username') ||
        m.includes('repository not found') ||
        m.includes('permission denied') ||
        m.includes('access denied') ||
        m.includes('http basic: access denied') ||
        m.includes('fatal: authentication')
    );
}

function normalizeGitUrlForHttp(repoUrl) {
    const url = String(repoUrl || '').trim();
    if (!url) return url;

    // Convert GitHub SSH format to HTTPS so we can use HTTP auth headers.
    const sshMatch = url.match(/^git@github\.com:([^/\s]+)\/([^\s]+?)(?:\.git)?$/);
    if (sshMatch) {
        const owner = sshMatch[1];
        const repo = sshMatch[2];
        return `https://github.com/${owner}/${repo}.git`;
    }

    return url;
}

function gitAuthExtraHeaderFromToken(token) {
    // GitHub supports HTTPS git via Basic auth with username x-access-token.
    const raw = `x-access-token:${String(token || '').trim()}`;
    const b64 = Buffer.from(raw, 'utf8').toString('base64');
    return {
        header: `Authorization: Basic ${b64}`,
        redactions: [token, b64, raw],
    };
}

async function gitClone({ repoUrl, branch, destDir, authToken }) {
    const safeRepoUrl = authToken ? normalizeGitUrlForHttp(repoUrl) : String(repoUrl || '').trim();
    if (!safeRepoUrl) {
        throw new Error('Missing git repository URL');
    }

    if (authToken) {
        const { header, redactions } = gitAuthExtraHeaderFromToken(authToken);
        await execCommand([
            'git',
            '-c',
            `http.extraHeader=${header}`,
            'clone',
            '--branch',
            String(branch || ''),
            safeRepoUrl,
            destDir,
        ], { redact: redactions });
        return;
    }

    await execCommand(['git', 'clone', '--branch', String(branch || ''), safeRepoUrl, destDir]);
}

async function gitUpdate({ repoDir, repoUrl, branch, authToken }) {
    // We prefer updating via origin for normal cases; for authenticated attempts we
    // use explicit repoUrl so we don't need to rewrite remotes.
    if (authToken) {
        const safeRepoUrl = normalizeGitUrlForHttp(repoUrl);
        const { header, redactions } = gitAuthExtraHeaderFromToken(authToken);

        await execCommand(['git', '-c', `http.extraHeader=${header}`, 'fetch', safeRepoUrl, String(branch || '')], {
            cwd: repoDir,
            redact: redactions,
        });

        try {
            await execCommand(['git', 'checkout', String(branch || '')], { cwd: repoDir, redact: redactions });
        } catch {
            await execCommand(['git', 'checkout', '-B', String(branch || '')], { cwd: repoDir, redact: redactions });
        }

        await execCommand(['git', '-c', `http.extraHeader=${header}`, 'pull', safeRepoUrl, String(branch || '')], {
            cwd: repoDir,
            redact: redactions,
        });
        return;
    }

    await execCommand(['git', 'fetch', 'origin'], { cwd: repoDir });
    try {
        await execCommand(['git', 'checkout', String(branch || '')], { cwd: repoDir });
    } catch {
        await execCommand(['git', 'checkout', '-B', String(branch || '')], { cwd: repoDir });
    }
    await execCommand(['git', 'pull', 'origin', String(branch || '')], { cwd: repoDir });
}

async function gitCloneOrUpdateWithAuthAttempts({ deploymentId, repoDir, repoUrl, branch, attempts, sendLogFn }) {
    const gitAttempts = Array.isArray(attempts) ? attempts : [];
    const hasRepo = fs.existsSync(path.join(repoDir, '.git'));

    const tryUnauthed = async () => {
        if (hasRepo) {
            await gitUpdate({ repoDir, repoUrl, branch });
        } else {
            await gitClone({ repoUrl, branch, destDir: repoDir });
        }
    };

    try {
        await tryUnauthed();
        return { usedAuth: false };
    } catch (err) {
        const msg = String(err?.message || err);
        if (!gitAttempts.length || !isLikelyGitAuthError(msg)) {
            throw err;
        }
    }

    for (const attempt of gitAttempts) {
        const token = attempt?.token;
        const label = attempt?.label || attempt?.type || 'git auth';
        if (!token) continue;

        try {
            sendLogFn?.(deploymentId, `🔐 Trying git auth: ${label}`, 'info');

            // Always start from a clean slate for each attempt.
            try { fs.rmSync(repoDir, { recursive: true, force: true }); } catch {}
            await gitClone({ repoUrl, branch, destDir: repoDir, authToken: token });
            return { usedAuth: true, label };
        } catch {
            continue;
        }
    }

    throw new Error('Git authentication failed with all configured GitHub Apps');
}

// State
let ws = null;
let heartbeatTimer = null;
let sweeperTimer = null;
let reconnectTimer = null;
let isConnected = false;

// Live logs WebSocket server (browser -> node)
let liveLogsWs = null;

// Runtime enforcement (bandwidth/storage) keyed by application UUID
const appEnforcers = new Map();

function dockerApiJson(method, pathAndQuery, options = {}) {
    const timeout = Number.isFinite(options.timeout) ? options.timeout : 5000;
    const body = options.body;

    return new Promise((resolve, reject) => {
        const req = http.request(
            {
                socketPath: '/var/run/docker.sock',
                path: pathAndQuery,
                method,
                headers: {
                    'Content-Type': 'application/json',
                },
                timeout,
            },
            (res) => {
                let data = '';
                res.setEncoding('utf8');
                res.on('data', (chunk) => { data += chunk; });
                res.on('end', () => {
                    const code = res.statusCode || 0;
                    if (code < 200 || code >= 300) {
                        return reject(new Error(`Docker API error ${code}: ${data || res.statusMessage || 'unknown'}`));
                    }
                    if (!data) return resolve(null);
                    try {
                        resolve(JSON.parse(data));
                    } catch (e) {
                        reject(new Error(`Docker API JSON parse error: ${e.message}`));
                    }
                });
            }
        );

        req.on('error', (err) => reject(err));
        req.on('timeout', () => {
            try { req.destroy(new Error('Docker API request timeout')); } catch {}
        });

        if (body !== undefined) {
            try {
                req.write(typeof body === 'string' ? body : JSON.stringify(body));
            } catch (e) {
                try { req.destroy(); } catch {}
                return reject(e);
            }
        }
        req.end();
    });
}

function stopAppEnforcer(applicationId) {
    const key = safeId(applicationId);
    const enforcer = appEnforcers.get(key);
    if (!enforcer) return;
    try { clearInterval(enforcer.timer); } catch {}
    appEnforcers.delete(key);
}

function normalizeAppLimits(appConfig) {
    const cpuMilli = parseInt(appConfig.cpu_millicores_limit ?? appConfig.cpuMillicoresLimit, 10);
    const ramMb = parseInt(appConfig.ram_mb_limit ?? appConfig.ramMbLimit, 10);
    const storageMb = parseInt(appConfig.storage_mb_limit ?? appConfig.storageMbLimit, 10);
    const bandwidthMbps = parseInt(appConfig.bandwidth_mbps_limit ?? appConfig.bandwidthMbpsLimit, 10);
    const pids = parseInt(appConfig.pids_limit ?? appConfig.pidsLimit, 10);

    const cpuCores = Number.isFinite(cpuMilli) && cpuMilli > 0 ? (cpuMilli / 1000) : null;
    const memoryLimit = Number.isFinite(ramMb) && ramMb > 0 ? `${ramMb}m` : (appConfig.memory_limit ?? appConfig.memoryLimit ?? appConfig.ram_limit ?? appConfig.ramLimit);

    return {
        cpuCores,
        memoryLimit,
        storageMb: Number.isFinite(storageMb) && storageMb > 0 ? storageMb : null,
        bandwidthMbps: Number.isFinite(bandwidthMbps) && bandwidthMbps > 0 ? bandwidthMbps : null,
        pids: Number.isFinite(pids) && pids > 0 ? pids : null,
    };
}

function startAppEnforcer(applicationId, appConfig, composeDir) {
    const key = safeId(applicationId);
    stopAppEnforcer(key);

    const limits = normalizeAppLimits(appConfig);
    const hasRuntimeLimits = Number.isFinite(limits.storageMb) || Number.isFinite(limits.bandwidthMbps);
    if (!hasRuntimeLimits) return;

    const composeProject = `chap-${dockerSafeName(key)}`;
    const volumeRoot = storage.pathVolumeRoot(key);
    const bandwidthLimitBps = Number.isFinite(limits.bandwidthMbps)
        ? (limits.bandwidthMbps * 1000 * 1000) / 8
        : null;

    const state = {
        timer: null,
        paused: false,
        lastPausedAt: 0,
        lastBelowAt: 0,
        prev: { ts: 0, rx: 0, tx: 0 },
        lastStorageCheckAt: 0,
    };

    const pauseAll = async (containerIds, reason) => {
        if (!containerIds.length) return;
        if (state.paused) return;
        const now = Date.now();
        // Avoid rapid flapping.
        if (now - state.lastPausedAt < 3000) return;
        state.lastPausedAt = now;
        state.paused = true;
        state.lastBelowAt = 0;
        console.warn(`[Enforcer] Pausing app ${key}: ${reason}`);
        for (const id of containerIds) {
            try { await execCommand(['docker', 'pause', dockerSafeName(id)], { timeout: 10000 }); } catch {}
        }
    };

    const unpauseAll = async (containerIds, reason) => {
        if (!containerIds.length) return;
        if (!state.paused) return;
        console.log(`[Enforcer] Unpausing app ${key}: ${reason}`);
        for (const id of containerIds) {
            try { await execCommand(['docker', 'unpause', dockerSafeName(id)], { timeout: 10000 }); } catch {}
        }
        state.paused = false;
        state.lastBelowAt = 0;
    };

    const getProjectContainerIds = async () => {
        try {
            const out = await execCommand(['docker', 'ps', '-q', '--filter', `label=com.docker.compose.project=${composeProject}`], { timeout: 8000 });
            return String(out || '').split('\n').map(s => s.trim()).filter(Boolean);
        } catch {
            return [];
        }
    };

    const poll = async () => {
        const now = Date.now();
        const containerIds = await getProjectContainerIds();
        if (!containerIds.length) return;

        // Storage enforcement (best-effort) based on persistent volume directory size.
        if (Number.isFinite(limits.storageMb) && now - state.lastStorageCheckAt > 30000) {
            state.lastStorageCheckAt = now;
            try {
                // BusyBox du output: <KB> <path>
                const duOut = await execCommand(['du', '-sk', volumeRoot], { timeout: 15000 });
                const kb = parseInt(String(duOut || '').trim().split(/\s+/)[0], 10);
                if (Number.isFinite(kb) && kb >= 0) {
                    const usedMb = Math.ceil(kb / 1024);
                    if (usedMb > limits.storageMb) {
                        await pauseAll(containerIds, `storage limit exceeded (${usedMb}MB > ${limits.storageMb}MB)`);
                        return;
                    }
                }
            } catch {
                // ignore
            }
        }

        // Bandwidth enforcement (best-effort) based on Docker stats network totals.
        if (Number.isFinite(bandwidthLimitBps) && bandwidthLimitBps > 0) {
            let rx = 0;
            let tx = 0;
            for (const cid of containerIds) {
                try {
                    const stats = await dockerApiJson('GET', `/containers/${dockerSafeName(cid)}/stats?stream=false`, { timeout: 4000 });
                    const nets = stats && stats.networks && typeof stats.networks === 'object' ? stats.networks : null;
                    if (nets) {
                        for (const v of Object.values(nets)) {
                            if (!v) continue;
                            if (Number.isFinite(v.rx_bytes)) rx += v.rx_bytes;
                            if (Number.isFinite(v.tx_bytes)) tx += v.tx_bytes;
                        }
                    }
                } catch {
                    // ignore per-container failures
                }
            }

            const prev = state.prev;
            if (prev.ts && now > prev.ts) {
                const dt = (now - prev.ts) / 1000;
                if (dt > 0) {
                    const rxBps = Math.max(0, (rx - prev.rx) / dt);
                    const txBps = Math.max(0, (tx - prev.tx) / dt);
                    const totalBps = rxBps + txBps;

                    if (totalBps > bandwidthLimitBps) {
                        await pauseAll(containerIds, `bandwidth limit exceeded (${Math.round(totalBps / 1024)}B/s > ${Math.round(bandwidthLimitBps / 1024)}B/s)`);
                        state.prev = { ts: now, rx, tx };
                        return;
                    }

                    // Hysteresis: only unpause once sustained below 85% for 10s.
                    const below = totalBps < (bandwidthLimitBps * 0.85);
                    if (state.paused) {
                        if (below) {
                            if (!state.lastBelowAt) state.lastBelowAt = now;
                            if (now - state.lastBelowAt > 10000) {
                                await unpauseAll(containerIds, 'bandwidth back under limit');
                            }
                        } else {
                            state.lastBelowAt = 0;
                        }
                    }
                }
            }

            state.prev = { ts: now, rx, tx };
        }
    };

    // Persist on disk so a node restart can rehydrate later (best-effort).
    try {
        const meta = {
            application_id: key,
            storage_mb_limit: limits.storageMb ?? null,
            bandwidth_mbps_limit: limits.bandwidthMbps ?? null,
        };
        fs.writeFileSync(path.join(composeDir, 'chap.limits.json'), JSON.stringify(meta, null, 2), 'utf8');
    } catch {}

    state.timer = setInterval(() => {
        poll().catch(() => {});
    }, 5000);
    appEnforcers.set(key, state);
}

function rehydrateAppEnforcersFromDisk() {
    try {
        const root = storage.dirs.compose;
        if (!root || !fs.existsSync(root)) return;
        const entries = fs.readdirSync(root).map(n => path.join(root, n));
        for (const dir of entries) {
            try {
                const stat = fs.statSync(dir);
                if (!stat.isDirectory()) continue;
                const metaPath = path.join(dir, 'chap.limits.json');
                if (!fs.existsSync(metaPath)) continue;
                const meta = JSON.parse(fs.readFileSync(metaPath, 'utf8'));
                const appId = safeId(meta.application_id);
                if (!appId) continue;
                // Fake the appConfig shape expected by normalizeAppLimits.
                startAppEnforcer(appId, {
                    storage_mb_limit: meta.storage_mb_limit,
                    bandwidth_mbps_limit: meta.bandwidth_mbps_limit,
                }, dir);
            } catch {
                continue;
            }
        }
    } catch {
        // ignore
    }
}

/**
 * Connect to the Chap server
 */
function connect() {
    console.log(`[Agent] Connecting to ${config.serverUrl}...`);
    
    ws = new WebSocket(config.serverUrl);
    
    ws.on('open', () => {
        console.log('[Agent] Connected to server');
        isConnected = true;
        
        // Authenticate this node using the protocol expected by PHP server
        send('node:auth', {
            payload: {
                token: config.nodeToken,
                version: AGENT_VERSION
            }
        });
    });
    
    ws.on('message', (data) => {
        try {
            const message = JSON.parse(data.toString());
            handleMessage(message);
        } catch (err) {
            console.error('[Agent] Failed to parse message:', err);
        }
    });
    
    ws.on('close', () => {
        console.log('[Agent] Disconnected from server');
        isConnected = false;
        stopHeartbeat();
        scheduleReconnect();
    });
    
    ws.on('error', (err) => {
        console.error('[Agent] WebSocket error:', err.message);
    });
}

/**
 * Send message to server
 */
function send(type, data = {}) {
    if (!ws || ws.readyState !== WebSocket.OPEN) {
        console.error('[Agent] Cannot send message - not connected');
        return false;
    }
    
    const message = JSON.stringify({ type, ...data, timestamp: Date.now() });
    ws.send(message);
    return true;
}

/**
 * Handle incoming messages
 */
function handleMessage(message) {
    // Avoid log spam for high-frequency/noisy message types.
    const noisyTypes = new Set(['heartbeat:ack', 'pong', 'container:logs', 'port:check']);
    if (!noisyTypes.has(message.type)) {
        console.log(`[Agent] Received: ${message.type}`);
    }
    
    switch (message.type) {
        case 'server:auth:success':
            console.log('[Agent] Successfully authenticated with server');
            try {
                const nodeUuid = String((message.payload && message.payload.node_id) || '').trim();
                if (nodeUuid) {
                    config.nodeId = nodeUuid;
                    console.log(`[Agent] Node UUID from server: ${nodeUuid}`);
                }
            } catch {
                // ignore
            }
            // Send system info and start heartbeat
            sendSystemInfo();
            startHeartbeat();
            break;
        
        case 'server:auth:failed':
            console.error('[Agent] Authentication failed:', message.payload?.error);
            ws.close();
            break;
        
        case 'server:ack':
            // Acknowledgment from server, no action needed
            console.log('[Agent] Server acknowledged:', message.payload?.received || 'message');
            break;
        
        case 'server:error':
            console.error('[Agent] Server error:', message.payload?.error);
            break;

        case 'heartbeat:ack':
            // no-op
            break;
        
        case 'app:event': {
            // Keep this quiet by default; set DEBUG_APP_EVENTS=1 to see full payloads.
            const taskId = message.payload?.task_id || message.payload?.taskId;
            if (taskId) {
                send('task:ack', {
                    payload: {
                        task_id: taskId,
                        status: 'received',
                    }
                });
            }
            if (process.env.DEBUG_APP_EVENTS === '1') {
                console.log('[Agent] App event from server:', JSON.stringify(message.payload, null, 2));
            } else {
                const action = message.payload?.action || 'unknown';
                console.log(`[Agent] App event: ${action}`);
            }
            break;
        }
            
        case 'task:deploy':
            handleDeploy(message);
            break;

        case 'task:cancel':
            handleCancel(message);
            break;
            
        case 'container:stop':
            handleStop(message);
            break;
			
            
        case 'application:delete':
            handleApplicationDelete(message);
            break;

        case 'port:check':
            handlePortCheck(message);
            break;
            
        case 'container:logs':
            // Legacy HTTP logs polling task type (deprecated). ACK and ignore so the server won't retry.
            handleLegacyContainerLogs(message);
            break;
            
        case 'container:exec':
            handleExec(message);
            break;
            
            case 'ping':
                send('pong');
                break;
            case 'pong':
                // no-op (avoid pong loops)
                break;
        
        case 'session:validate:response':
            if (liveLogsWs) {
                liveLogsWs.handleServerMessage(message);
            }
            break;
            
        default:
            console.warn(`[Agent] Unknown message type: ${message.type}`);
    }
}

function handleCancel(message) {
    const payload = message.payload || {};
    const taskId = payload.task_id || payload.taskId;
    const deploymentId = payload.deployment_id || payload.deploymentId;

    // ACK so the server stops retrying.
    if (taskId) {
        send('task:ack', {
            payload: {
                task_id: taskId,
                status: 'received',
                deployment_id: deploymentId,
            }
        });
    }

    // Best-effort: deployments are not currently cancellable mid-flight in the agent.
    // Intentionally no-op.
}

function handleLegacyContainerLogs(message) {
    const payload = message.payload || {};
    const taskId = payload.task_id || payload.taskId;
    const applicationUuid = payload.application_uuid || payload.applicationId;

    // ACK so the server stops retrying.
    if (taskId) {
        send('task:ack', {
            payload: {
                task_id: taskId,
                status: 'received',
                application_uuid: applicationUuid,
            }
        });
    }

    // Logs are now served via the browser<->node websocket only.
    // Do not fetch/poll container logs over this channel.
}

async function handlePortCheck(message) {
    const payload = message.payload || {};
    const requestId = payload.request_id || payload.requestId;
    const taskId = payload.task_id || payload.taskId;
    const port = parseInt(payload.port, 10);

    if (!requestId || !Number.isInteger(port) || port < 1 || port > 65535) {
        send('port:check:response', {
            payload: { request_id: requestId || '', port: payload.port || null, free: false, error: 'Invalid request' }
        });
        return;
    }

    // ACK receipt so the server won't retry the request.
    if (taskId) {
        send('task:ack', {
            payload: {
                task_id: taskId,
                status: 'received',
                request_id: requestId,
            }
        });
    }

    let free = false;
    let lastError = null;

    for (const image of PORTCHECK_IMAGES) {
        try {
            // This tests OS-level port availability by asking Docker to bind the host port.
            // If the port is already in use (by any process), Docker will fail to start.
            await execCommand(['docker', 'run', '--rm', '-p', `${port}:1`, String(image), 'node', '-e', 'process.exit(0)'], { timeout: 15000 });
            free = true;
            lastError = null;
            break;
        } catch (e) {
            lastError = e;
            const msg = String(e && e.message ? e.message : e);
            // Try next image if this one isn't available.
            if (msg.includes('Unable to find image') || msg.includes('pull access denied') || msg.includes('not found')) {
                continue;
            }
            // Any other error likely means the port is not available.
            break;
        }
    }

    send('port:check:response', {
        payload: {
            request_id: requestId,
            task_id: taskId || undefined,
            port,
            free,
            error: free ? null : (lastError ? String(lastError.message || lastError) : null),
        }
    });
}

function enforceComposePortsAreAllocated(composeContent, allocatedPorts) {
    if (!composeContent || typeof composeContent !== 'string') {
        return;
    }

    let compose;
    try {
        compose = yaml.parse(composeContent);
    } catch (e) {
        throw new Error(`Invalid docker-compose.yml (cannot validate ports): ${e.message}`);
    }

    const services = compose && compose.services ? compose.services : null;
    if (!services || typeof services !== 'object') {
        return;
    }

    const allocatedSet = new Set((allocatedPorts || []).map(p => parseInt(p, 10)).filter(p => Number.isInteger(p)));
    const usedHostPorts = new Set();

    const parsePortString = (s) => {
        // Supported:
        //  - "8080:80"
        //  - "127.0.0.1:8080:80"
        //  - "8080:80/tcp"
        // Disallow:
        //  - "80" (random host port)
        // Some compose sources may accidentally include the YAML list dash as part of the scalar ("- 8080:80").
        // Normalize that so we don't treat published ports as negative.
        const raw = String(s).trim().replace(/^\-\s*/, '');
        const parts = raw.split(':');
        if (parts.length === 1) {
            return { published: null, target: parts[0] };
        }

        const right = parts[parts.length - 1];
        const host = parts[parts.length - 2];
        const hostPort = parseInt(String(host).split('/')[0].trim().replace(/^\-\s*/, ''), 10);
        const targetPort = parseInt(String(right).split('/')[0], 10);

        return {
            published: Number.isInteger(hostPort) && hostPort > 0 ? hostPort : null,
            target: Number.isInteger(targetPort) ? targetPort : null,
        };
    };

    for (const [serviceName, service] of Object.entries(services)) {
        if (!service || typeof service !== 'object') continue;

        const ports = service.ports;
        if (!ports) continue;

        if (!Array.isArray(ports)) {
            throw new Error(`Service "${serviceName}" has invalid ports format`);
        }

        for (const entry of ports) {
            let published = null;

            if (typeof entry === 'string') {
                const parsed = parsePortString(entry);
                published = parsed.published;
                if (!published) {
                    throw new Error(`Service "${serviceName}" uses a port mapping without an explicit host port (random host ports are not allowed)`);
                }
            } else if (entry && typeof entry === 'object') {
                // Compose v3 long syntax: { target, published, protocol, mode }
                if (entry.published !== undefined && entry.published !== null) {
                    published = parseInt(String(entry.published).trim().replace(/^\-\s*/, ''), 10);
                }

                if (!Number.isInteger(published) || published <= 0) {
                    throw new Error(`Service "${serviceName}" uses a port mapping without an explicit published host port`);
                }
            } else {
                throw new Error(`Service "${serviceName}" has invalid ports entry`);
            }

            if (allocatedSet.size === 0) {
                throw new Error(`Service "${serviceName}" publishes host port ${published}, but this application has no allocated ports`);
            }
            if (!allocatedSet.has(published)) {
                throw new Error(`Service "${serviceName}" publishes host port ${published}, which is not allocated to this application`);
            }
            if (usedHostPorts.has(published)) {
                throw new Error(`Host port ${published} is published more than once in docker-compose.yml`);
            }
            usedHostPorts.add(published);
        }
    }
}

/**
 * Handle deployment request
 */
async function handleDeploy(message) {
    // Extract from payload structure sent by server
    const payload = message.payload || {};
    const deploymentId = payload.deployment_id;
    const appConfig = payload.application || {};
    const applicationId = appConfig.uuid || appConfig.id;
    
    console.log('\n' + '='.repeat(60));
    console.log(`[Agent] 🚀 DEPLOYMENT STARTED`);
    console.log(`[Agent] Deployment ID: ${deploymentId}`);
    console.log(`[Agent] Application ID: ${applicationId}`);
    console.log(`[Agent] Application Name: ${appConfig.name || 'Unknown'}`);
    console.log('='.repeat(60) + '\n');
    
    // Acknowledge receipt
    send('task:ack', { 
        payload: { 
            task_id: payload.task_id,
            deployment_id: deploymentId,
            status: 'received' 
        }
    });
    
    try {
        // Send starting status
        sendLog(deploymentId, '🚀 Deployment started', 'info');
        console.log(`[Agent] ✓ Deployment acknowledged and queued`);

        const buildPack = String(appConfig.build_pack || 'docker-compose').trim();
        if (buildPack !== 'docker-compose' && buildPack !== 'compose') {
            throw new Error(`Unsupported build_pack "${buildPack}" (compose-only)`);
        }

        sendLog(deploymentId, 'Build type: docker-compose', 'info');
        await deployCompose(deploymentId, appConfig);
        return;
    } catch (err) {
        console.error('\n' + '='.repeat(60));
        console.error(`[Agent] ❌ DEPLOYMENT FAILED`);
        console.error(`[Agent] Deployment ID: ${deploymentId}`);
        console.error(`[Agent] Error: ${err.message}`);
        console.error('='.repeat(60) + '\n');
        console.error(err.stack);
        sendLog(deploymentId, `❌ Deployment failed: ${err.message}`, 'error');
        send('task:failed', { 
            payload: {
                deployment_id: deploymentId,
                error: err.message 
            }
        });
    }
}

// Node API v2: allow an authorized client to trigger a deploy directly on the node.
// This uses the same compose deploy pipeline as the server-driven task.
async function deployComposeForNodeApi({ deployment_id, application }) {
    const deploymentId = String(deployment_id || '').trim();
    const appConfig = application && typeof application === 'object' ? application : null;
    if (!deploymentId || !appConfig) {
        return { ok: false, error: 'Invalid request' };
    }

    try {
        const buildPack = String(appConfig.build_pack || 'docker-compose').trim();
        if (buildPack !== 'docker-compose' && buildPack !== 'compose') {
            throw new Error(`Unsupported build_pack "${buildPack}" (compose-only)`);
        }

        sendLog(deploymentId, '🚀 Node API deployment started', 'info');
        await deployCompose(deploymentId, appConfig);
        return { ok: true };
    } catch (err) {
        const msg = String(err && err.message ? err.message : err);
        sendLog(deploymentId, `❌ Node API deployment failed: ${msg}`, 'error');
        try {
            send('task:failed', {
                payload: {
                    deployment_id: deploymentId,
                    error: msg,
                    source: 'node_api',
                }
            });
        } catch {}
        return { ok: false, error: msg };
    }
}

/**
 * Deploy Docker Compose (with security hardening)
 */
async function deployCompose(deploymentId, appConfig) {
    const applicationId = appConfig.uuid || appConfig.applicationId;
    const composeDir = storage.getComposeDir(applicationId);
    
    console.log(`[Agent] 🐳 Deploying with Docker Compose (secured)`);
    sendLog(deploymentId, '🐳 Deploying with Docker Compose', 'info');
    security.auditLog('compose_deploy_start', { applicationId, deploymentId });
    
    // Security: Ensure isolated network exists
    await security.ensureAppNetwork(execCommand);
    
    // Get repository files first if this is a Git-based app
    if (appConfig.git_repository || appConfig.gitRepository) {
        const gitRepository = appConfig.git_repository || appConfig.gitRepository;
        const gitBranch = appConfig.git_branch || appConfig.gitBranch || 'main';
        const repoDir = storage.getRepoDir(applicationId);
        
        console.log(`[Agent] 📥 Fetching repository for compose: ${gitRepository}`);
        sendLog(deploymentId, `📥 Fetching repository: ${gitRepository}`, 'info');
        
        // Clone or update repository; if private, try each GitHub App before failing.
        const authAttempts = appConfig.git_auth_attempts || [];
        sendLog(deploymentId, `Git auth attempts available: ${Array.isArray(authAttempts) ? authAttempts.length : 0}`, 'info');
        try {
            const res = await gitCloneOrUpdateWithAuthAttempts({
                deploymentId,
                repoDir,
                repoUrl: gitRepository,
                branch: gitBranch,
                attempts: authAttempts,
                sendLogFn: sendLog,
            });
            if (res.usedAuth) {
                console.log(`[Agent] ✓ Repository cloned with auth (${res.label})`);
                sendLog(deploymentId, `✓ Repository cloned with auth (${res.label})`, 'info');
            } else {
                console.log(`[Agent] ✓ Repository ready`);
                sendLog(deploymentId, '✓ Repository ready', 'info');
            }
        } catch (err) {
            console.error(`[Agent] ❌ Git fetch/clone failed: ${err.message}`);
            sendLog(deploymentId, `❌ Git fetch/clone failed: ${err.message}`, 'error');
            throw err;
        }
        
        // Copy repository files to compose directory
        console.log(`[Agent] 📋 Copying files to compose directory`);
        sendLog(deploymentId, '📋 Preparing compose environment...', 'info');
        try {
            for (const name of fs.readdirSync(composeDir)) {
                const p = path.join(composeDir, name);
                try { fs.rmSync(p, { recursive: true, force: true }); } catch {}
            }
        } catch {}

        fs.cpSync(repoDir, composeDir, { recursive: true, force: true });
        console.log(`[Agent] ✓ Files copied to compose directory`);
    }
    
    let dockerCompose = appConfig.docker_compose || appConfig.dockerCompose;
    const envVars = appConfig.environment_variables || appConfig.environmentVariables || {};

    // Template-based deploys may include additional files (e.g. Dockerfile, entrypoint.sh)
    // that must be written into the compose directory.
    const writeExtraFiles = (filesMap) => {
        if (!filesMap || typeof filesMap !== 'object') return;

        const maxFiles = 500;
        const maxBytesPerFile = 1024 * 1024; // 1MB
        const maxBytesTotal = 20 * 1024 * 1024; // 20MB

        let count = 0;
        let total = 0;

        for (const [relRaw, contentRaw] of Object.entries(filesMap)) {
            if (count >= maxFiles) break;

            const rel = String(relRaw || '').replace(/\\/g, '/').replace(/^\/+/, '');
            if (!rel || rel.includes('..')) continue;

            const content = String(contentRaw ?? '');
            const bytes = Buffer.byteLength(content, 'utf8');
            if (bytes <= 0 || bytes > maxBytesPerFile) continue;
            if (total + bytes > maxBytesTotal) break;

            // Ensure resolved path stays within composeDir
            const abs = path.resolve(composeDir, rel);
            const root = path.resolve(composeDir);
            if (!abs.startsWith(root + path.sep) && abs !== root) continue;

            fs.mkdirSync(path.dirname(abs), { recursive: true });
            fs.writeFileSync(abs, content, 'utf8');

            // Best-effort: make shell scripts executable
            const lower = rel.toLowerCase();
            if (lower.endsWith('.sh') || lower.endsWith('.bash') || lower.includes('entrypoint')) {
                try { fs.chmodSync(abs, 0o755); } catch (_) {}
            }

            count++;
            total += bytes;
        }

        if (count > 0) {
            console.log(`[Agent] ✓ Wrote ${count} extra template file(s) to compose dir`);
            sendLog(deploymentId, `✓ Wrote ${count} extra template file(s)`, 'info');
        }
    };

    const extraFiles = appConfig.extra_files || appConfig.extraFiles || null;
    if (extraFiles) {
        console.log('[Agent] 📦 Writing extra template files');
        sendLog(deploymentId, '📦 Writing extra template files', 'info');
        writeExtraFiles(extraFiles);
    }
    
    // Security: Sanitize environment variables
    const safeEnvVars = security.sanitizeEnvVars(envVars);
    
    const injectChapLabels = (composeContent) => {
        const raw = String(composeContent || '');
        if (!raw.trim()) return raw;

        let doc;
        try {
            doc = yaml.parse(raw);
        } catch (err) {
            throw new Error(`Invalid docker-compose YAML: ${err.message}`);
        }

        if (!doc || typeof doc !== 'object') {
            return raw;
        }

        if (!doc.services || typeof doc.services !== 'object') {
            return raw;
        }

        for (const [serviceName, service] of Object.entries(doc.services)) {
            if (!service || typeof service !== 'object') continue;

            const requiredLabels = {
                'chap.managed': 'true',
                'chap.app': String(applicationId),
                'chap.deployment': String(deploymentId),
            };

            if (Array.isArray(service.labels)) {
                const existing = new Set(service.labels.map((x) => String(x)));
                for (const [k, v] of Object.entries(requiredLabels)) {
                    const kv = `${k}=${v}`;
                    if (!existing.has(kv)) service.labels.push(kv);
                }
            } else if (service.labels && typeof service.labels === 'object') {
                for (const [k, v] of Object.entries(requiredLabels)) {
                    service.labels[k] = v;
                }
            } else {
                service.labels = { ...requiredLabels };
            }

            doc.services[serviceName] = service;
        }

        return yaml.stringify(doc);
    };

    const parseDockerMemoryToBytes = (value) => {
        if (value === null || value === undefined) return null;
        const s = String(value).trim();
        if (s === '') return null;

        const m = s.match(/^([0-9]+(?:\.[0-9]+)?)\s*([kmgt])?b?$/i);
        if (!m) return null;

        const num = parseFloat(m[1]);
        if (!Number.isFinite(num) || num <= 0) return null;

        const unit = (m[2] || '').toLowerCase();
        const mul = unit === 't' ? 1024 ** 4
            : unit === 'g' ? 1024 ** 3
            : unit === 'm' ? 1024 ** 2
            : unit === 'k' ? 1024
            : 1;

        return Math.floor(num * mul);
    };

    // Enforce app-level resource caps by dividing them across all services.
    // This prevents users from exceeding an app limit by adding many containers.
    const applyAppResourceCapsToCompose = (composeContent) => {
        const raw = String(composeContent || '');
        if (!raw.trim()) return raw;

        // Prefer numeric hierarchy limits if available.
        const normalized = normalizeAppLimits(appConfig);

        const cpuRaw = appConfig.cpu_limit ?? appConfig.cpuLimit ?? appConfig.cpu_limit_cores ?? appConfig.cpuLimitCores;
        const memRawFallback = appConfig.memory_limit ?? appConfig.memoryLimit ?? appConfig.ram_limit ?? appConfig.ramLimit;
        const memRaw = normalized.memoryLimit ?? memRawFallback;

        const cpuRequested = normalized.cpuCores !== null
            ? normalized.cpuCores
            : (cpuRaw === null || cpuRaw === undefined ? null : parseFloat(String(cpuRaw).trim()));
        const cpuRequestedLimited = Number.isFinite(cpuRequested) && cpuRequested > 0;

        const maxCpu = Number.isFinite(security?.SECURITY_CONFIG?.maxCpus) ? security.SECURITY_CONFIG.maxCpus : null;
        const cpuTotal = cpuRequestedLimited && Number.isFinite(maxCpu) && maxCpu > 0 ? Math.min(cpuRequested, maxCpu) : cpuRequested;
        const cpuLimited = Number.isFinite(cpuTotal) && cpuTotal > 0;

        const memRequestedBytes = parseDockerMemoryToBytes(memRaw);
        const maxMemBytes = parseDockerMemoryToBytes(security?.SECURITY_CONFIG?.maxMemory);
        const memTotalBytes = Number.isFinite(memRequestedBytes) && memRequestedBytes > 0 && Number.isFinite(maxMemBytes) && maxMemBytes > 0
            ? Math.min(memRequestedBytes, maxMemBytes)
            : memRequestedBytes;
        const memLimited = Number.isFinite(memTotalBytes) && memTotalBytes > 0;

        const pidsRequested = normalized.pids;
        const maxPids = Number.isFinite(security?.SECURITY_CONFIG?.maxPids) ? security.SECURITY_CONFIG.maxPids : null;
        const pidsTotal = Number.isFinite(pidsRequested) && pidsRequested > 0 && Number.isFinite(maxPids) && maxPids > 0
            ? Math.min(pidsRequested, maxPids)
            : (Number.isFinite(pidsRequested) && pidsRequested > 0 ? pidsRequested : null);
        const pidsLimited = Number.isFinite(pidsTotal) && pidsTotal > 0;

        if (!cpuLimited && !memLimited && !pidsLimited) {
            return raw;
        }

        let doc;
        try {
            doc = yaml.parse(raw);
        } catch (err) {
            // If YAML isn't parseable, don't block deploy here (it will fail later anyway).
            return raw;
        }

        if (!doc || typeof doc !== 'object' || !doc.services || typeof doc.services !== 'object') {
            return raw;
        }

        const serviceEntries = Object.entries(doc.services)
            .filter(([, service]) => service && typeof service === 'object');

        if (serviceEntries.length === 0) {
            return raw;
        }

        // Weight by replicas if present (best-effort; compose may ignore deploy.replicas).
        const getServiceWeight = (service) => {
            const replicasRaw = service?.deploy?.replicas;
            const n = parseInt(replicasRaw, 10);
            return Number.isFinite(n) && n > 0 ? n : 1;
        };

        const weights = serviceEntries.map(([name, service]) => ({
            name,
            service,
            weight: getServiceWeight(service),
        }));

        const totalWeight = weights.reduce((sum, x) => sum + x.weight, 0);
        if (totalWeight <= 0) return raw;

        // CPU: distribute as milli-cores to avoid rounding making totals exceed the cap.
        let cpuRemainderMilli = 0;
        let perWeightCpuMilli = 0;
        if (cpuLimited) {
            const cpuTotalMilli = Math.floor(cpuTotal * 1000);
            perWeightCpuMilli = Math.floor(cpuTotalMilli / totalWeight);
            cpuRemainderMilli = cpuTotalMilli - (perWeightCpuMilli * totalWeight);
        }

        // Memory: distribute in bytes exactly.
        const mib = 1024 * 1024;
        let memRemainderMiB = 0;
        let perWeightMemMiB = 0;
        if (memLimited) {
            // Compose expects memory values like "512m"/"2g"; distribute in whole MiB and round DOWN
            // so the total applied never exceeds the application's cap.
            const totalMiB = Math.floor(memTotalBytes / mib);
            perWeightMemMiB = Math.floor(totalMiB / totalWeight);
            memRemainderMiB = totalMiB - (perWeightMemMiB * totalWeight);
        }

        // PIDs: distribute as ints.
        let pidsRemainder = 0;
        let perWeightPids = 0;
        if (pidsLimited) {
            const pidsTotalInt = Math.floor(pidsTotal);
            perWeightPids = Math.floor(pidsTotalInt / totalWeight);
            pidsRemainder = pidsTotalInt - (perWeightPids * totalWeight);
        }

        for (const entry of weights) {
            const { name, service, weight } = entry;

            if (cpuLimited) {
                let cpuMilli = perWeightCpuMilli * weight;
                if (cpuRemainderMilli > 0) {
                    const extra = Math.min(cpuRemainderMilli, weight);
                    cpuMilli += extra;
                    cpuRemainderMilli -= extra;
                }

                // Floor to 0.001 core increments to ensure we never exceed cpuTotal.
                const cpuCores = Math.max(0, cpuMilli) / 1000;
                service.deploy = service.deploy && typeof service.deploy === 'object' ? service.deploy : {};
                service.deploy.resources = service.deploy.resources && typeof service.deploy.resources === 'object' ? service.deploy.resources : {};
                service.deploy.resources.limits = service.deploy.resources.limits && typeof service.deploy.resources.limits === 'object' ? service.deploy.resources.limits : {};
                service.deploy.resources.limits.cpus = cpuCores.toFixed(3);

                // Also set non-swarm compose keys so limits apply without relying on deploy.* semantics.
                // docker compose supports `cpus` for service-level CPU quota.
                service.cpus = Number(cpuCores.toFixed(3));
            }

            if (memLimited) {
                let memMiB = perWeightMemMiB * weight;
                if (memRemainderMiB > 0) {
                    const extra = Math.min(memRemainderMiB, weight);
                    memMiB += extra;
                    memRemainderMiB -= extra;
                }

                // If the app memory cap is extremely small relative to service count, some services may
                // round down to 0 MiB. In that case, set a tiny 1 MiB cap to avoid "unlimited" services,
                // even though it's not a realistic production configuration.
                if (memMiB <= 0) memMiB = 1;

                const memStr = `${memMiB}m`;

                service.deploy = service.deploy && typeof service.deploy === 'object' ? service.deploy : {};
                service.deploy.resources = service.deploy.resources && typeof service.deploy.resources === 'object' ? service.deploy.resources : {};
                service.deploy.resources.limits = service.deploy.resources.limits && typeof service.deploy.resources.limits === 'object' ? service.deploy.resources.limits : {};

                // Compose expects a Docker-compatible memory string.
                service.deploy.resources.limits.memory = memStr;

                // Also set non-swarm compose keys.
                // `mem_limit`/`memswap_limit` are accepted by docker compose and translate to container HostConfig.*.
                service.mem_limit = memStr;
                service.memswap_limit = memStr;
            }

            if (pidsLimited) {
                let pidsForService = perWeightPids * weight;
                if (pidsRemainder > 0) {
                    const extra = Math.min(pidsRemainder, weight);
                    pidsForService += extra;
                    pidsRemainder -= extra;
                }

                if (pidsForService <= 0) pidsForService = 1;

                service.deploy = service.deploy && typeof service.deploy === 'object' ? service.deploy : {};
                service.deploy.resources = service.deploy.resources && typeof service.deploy.resources === 'object' ? service.deploy.resources : {};
                service.deploy.resources.limits = service.deploy.resources.limits && typeof service.deploy.resources.limits === 'object' ? service.deploy.resources.limits : {};
                service.deploy.resources.limits.pids = pidsForService;

                // Non-swarm compose key.
                service.pids_limit = pidsForService;
            }

            doc.services[name] = service;
        }

        // Log what we did (helpful for debugging)
        try {
            const cpuMsg = cpuLimited
                ? `${cpuTotal} CPU${(cpuRequestedLimited && cpuTotal !== cpuRequested) ? ` (clamped from ${cpuRequested})` : ''}`
                : 'CPU unlimited';
            const memMsg = memLimited
                ? `${memRaw}${(Number.isFinite(memRequestedBytes) && memRequestedBytes > 0 && Number.isFinite(memTotalBytes) && memTotalBytes !== memRequestedBytes) ? ` (clamped to ${security?.SECURITY_CONFIG?.maxMemory || 'node max'})` : ''}`
                : 'memory unlimited';
            const pidsMsg = pidsLimited
                ? `${pidsTotal} PIDs${(Number.isFinite(pidsRequested) && Number.isFinite(pidsTotal) && pidsTotal !== pidsRequested) ? ` (clamped from ${pidsRequested})` : ''}`
                : 'PIDs unlimited';
            sendLog(deploymentId, `🔧 App resource caps applied (${cpuMsg}, ${memMsg}, ${pidsMsg}) across ${serviceEntries.length} service(s)`, 'info');
        } catch (_) {}

        return yaml.stringify(doc);
    };

    // Write docker-compose.yml if provided via config (overrides the one from repo)
    if (dockerCompose) {
        console.log(`[Agent] 📝 Writing docker-compose.yml from config`);
        // Security: Sanitize compose file to remove dangerous options
        try {
            dockerCompose = security.sanitizeComposeFile(dockerCompose);

            // Ensure compose services are tagged as Chap-managed so metrics can find them.
            dockerCompose = injectChapLabels(dockerCompose);

            // Enforce app-level CPU/memory caps by dividing across services.
            dockerCompose = applyAppResourceCapsToCompose(dockerCompose);

            console.log(`[Agent] 🔒 Docker Compose file sanitized for security`);
            sendLog(deploymentId, '🔒 Compose file security validated', 'info');
        } catch (err) {
            console.error(`[Agent] ❌ Security validation failed:`, err.message);
            sendLog(deploymentId, `❌ Security validation failed: ${err.message}`, 'error');
            throw err;
        }
        fs.writeFileSync(path.join(composeDir, 'docker-compose.yml'), dockerCompose);
    } else {
        // Security: If compose file exists in repo, sanitize it
        const existingComposePath = path.join(composeDir, 'docker-compose.yml');
        if (fs.existsSync(existingComposePath)) {
            try {
                const existingCompose = fs.readFileSync(existingComposePath, 'utf8');
                let sanitizedCompose = security.sanitizeComposeFile(existingCompose);

                // Ensure compose services are tagged as Chap-managed so metrics can find them.
                sanitizedCompose = injectChapLabels(sanitizedCompose);

                // Enforce app-level CPU/memory caps by dividing across services.
                sanitizedCompose = applyAppResourceCapsToCompose(sanitizedCompose);

                fs.writeFileSync(existingComposePath, sanitizedCompose);
                console.log(`[Agent] 🔒 Existing compose file sanitized for security`);
                sendLog(deploymentId, '🔒 Compose file security validated', 'info');
            } catch (err) {
                console.error(`[Agent] ❌ Security validation failed:`, err.message);
                sendLog(deploymentId, `❌ Security validation failed: ${err.message}`, 'error');
                throw err;
            }
        }
    }

    // Write .env file with sanitized environment variables (used by docker compose interpolation)
    console.log(`[Agent] 🔐 Writing environment variables (${Object.keys(safeEnvVars).length} vars)`);
    const envContent = Object.entries(safeEnvVars)
        .map(([k, v]) => `${k}=${v}`)
        .join('\n');
    fs.writeFileSync(path.join(composeDir, '.env'), envContent);

    // Runtime enforcement: compose may only publish allocated host ports.
    // Important: resolve ${VARS} using the just-written .env so defaults like ${PORT:-8080}
    // don't cause false failures when PORT is set to an allocated port.
    const allocatedPorts = appConfig.allocated_ports || appConfig.allocatedPorts || [];
    const allocatedInts = Array.isArray(allocatedPorts) ? allocatedPorts.map(p => parseInt(p, 10)).filter(p => Number.isInteger(p)) : [];
    try {
        let resolvedCompose = '';
        try {
            resolvedCompose = await execCommand(['docker', 'compose', '--env-file', '.env', 'config'], { cwd: composeDir });
        } catch {
            // Fall back to raw file if compose config is unavailable.
            resolvedCompose = fs.readFileSync(path.join(composeDir, 'docker-compose.yml'), 'utf8');
        }

        enforceComposePortsAreAllocated(resolvedCompose, allocatedInts);
    } catch (err) {
        const msg = err && err.message ? err.message : String(err);
        console.error(`[Agent] ❌ Port enforcement failed: ${msg}`);
        sendLog(deploymentId, `❌ Port enforcement failed: ${msg}`, 'error');
        throw err;
    }
    
    // Log the .env contents (without sensitive values)
    console.log(`[Agent] Environment variables:`, Object.keys(safeEnvVars).join(', '));
    sendLog(deploymentId, `Environment: ${Object.keys(safeEnvVars).join(', ')}`, 'info');
    
    // Stop any existing compose project
    try {
        console.log(`[Agent] 🛑 Stopping existing services...`);
        const safeAppId = String(applicationId).trim();
        await execCommand(['docker', 'compose', '-p', `chap-${safeAppId}`, 'down'], { cwd: composeDir });
    } catch (err) {
        // Ignore if nothing to stop
    }
    
    console.log(`[Agent] 🚀 Starting services with Docker Compose...`);
    sendLog(deploymentId, '🚀 Starting services with Docker Compose...', 'info');
    
    // Start compose services with build (using isolated network)
    try {
        const safeAppId = String(applicationId).trim();
        // NOTE: Without --compatibility, docker compose ignores deploy.resources limits (non-swarm).
        // Chap uses deploy.resources.limits for CPU/memory; --compatibility translates them into container runtime flags.
        const composeOutput = await execCommand(['docker', 'compose', '--compatibility', '-p', `chap-${safeAppId}`, 'up', '-d', '--build'], { cwd: composeDir });
        console.log(`[Agent] ✓ Docker Compose services started`);
        sendLog(deploymentId, '✓ Docker Compose services started', 'info');
    } catch (err) {
        console.error(`[Agent] ❌ Docker Compose failed:`, err.message);
        sendLog(deploymentId, `❌ Docker Compose failed: ${err.message}`, 'error');
        throw err;
    }
    
    // Wait a moment for containers to start
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    // Get the list of started containers
    try {
        const psOutput = await execCommand(['docker', 'compose', '-p', `chap-${safeId(applicationId)}`, 'ps', '--format', 'json'], { cwd: composeDir });
        const containers = psOutput.trim().split('\n').filter(Boolean).map(line => {
            try {
                return JSON.parse(line);
            } catch {
                return null;
            }
        }).filter(Boolean);
        
        console.log(`[Agent] 📦 Services running: ${containers.length}`);
        sendLog(deploymentId, `📦 Started ${containers.length} container(s)`, 'info');
        
        containers.forEach(container => {
            console.log(`[Agent]   - ${container.Service || container.Name}: ${container.State}`);
            sendLog(deploymentId, `  ✓ ${container.Service || container.Name}: ${container.State}`, 'info');
        });
    } catch (err) {
        console.warn(`[Agent] ⚠ Could not list containers:`, err.message);
    }
    
    // Start/refresh runtime enforcement for this app (bandwidth/storage best-effort).
    try {
        startAppEnforcer(applicationId, appConfig, composeDir);
    } catch (e) {
        console.warn(`[Agent] ⚠️  Failed starting app enforcer: ${e.message}`);
    }

    console.log(`[Agent] ✅ Docker Compose deployment completed (secured)`);
    sendLog(deploymentId, '✅ Docker Compose deployment completed successfully!', 'info');
    security.auditLog('compose_deploy_complete', { applicationId, deploymentId });
    
    send('task:complete', {
        payload: {
            deployment_id: deploymentId
        }
    });
}

/**
 * Stop container
 */
function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function dockerSafeName(name) {
    // Container names/IDs should not contain whitespace or shell metacharacters.
    return String(name || '').trim().replace(/[^a-zA-Z0-9_.-]/g, '');
}

async function isContainerRunning(containerNameOrId) {
    const name = dockerSafeName(containerNameOrId);
    if (!name) return false;

    try {
        // Exact-name match for containers we manage.
        const out = await execCommand(['docker', 'ps', '--filter', `name=^/${name}$`, '--format', '{{.ID}}'], { timeout: 5000 });
        if (String(out || '').trim()) return true;
    } catch (err) {
        // ignore
    }

    // Fallback: treat as ID and inspect
    try {
        const running = await execCommand(['docker', 'inspect', '-f', '{{.State.Running}}', name], { timeout: 5000 });
        return String(running || '').trim() === 'true';
    } catch (err) {
        return false;
    }
}

/**
 * Handle application deletion - remove all containers and data
 */
async function handleApplicationDelete(message) {
    const payload = message.payload || {};
    const applicationUuid = payload.application_uuid || payload.applicationId;
    const taskId = payload.task_id || payload.taskId;
    const deploymentIds = Array.isArray(payload.deployment_ids)
        ? payload.deployment_ids.map(d => String(d).trim()).filter(Boolean)
        : [];
    
    console.log(`[Agent] 🗑️  Deleting application: ${applicationUuid}`);

    stopAppEnforcer(applicationUuid);

    // Acknowledge receipt so the server won't keep retrying this delete task.
    if (taskId) {
        send('task:ack', {
            payload: {
                task_id: taskId,
                status: 'received',
                application_uuid: applicationUuid,
            }
        });
    }
    
    try {
        const safeAppId = String(applicationUuid || '').trim();
        const composeProject = safeAppId ? `chap-${safeAppId}` : '';

        const safeRmTree = (targetPath, label) => {
            try {
                if (!targetPath) return;
                const resolved = path.resolve(targetPath);
                const allowedBases = [
                    path.resolve(storage.baseDir),
                    path.resolve(storage.dirs.apps),
                    path.resolve(storage.dirs.builds),
                    path.resolve(storage.dirs.volumes),
                    path.resolve(storage.dirs.compose),
                    path.resolve(storage.dirs.logs),
                ];

                const isInside = allowedBases.some(base => resolved === base || resolved.startsWith(base + path.sep));
                if (!isInside) {
                    console.warn(`[Agent] ⚠️  Refusing to delete outside storage: ${resolved} (${label})`);
                    return;
                }
                // Never delete the base directories themselves.
                if (allowedBases.includes(resolved)) {
                    console.warn(`[Agent] ⚠️  Refusing to delete storage root: ${resolved} (${label})`);
                    return;
                }

                if (fs.existsSync(resolved)) {
                    fs.rmSync(resolved, { recursive: true, force: true });
                    console.log(`[Agent] ✓ Removed ${label}: ${resolved}`);
                }
            } catch (err) {
                console.warn(`[Agent] ⚠️  Failed removing ${label}: ${err.message}`);
            }
        };

        // 1) Stop and remove Docker resources (best-effort).
        // Prefer compose down when compose file exists; otherwise fall back to label-based cleanup.
        const composeDirPath = storage.pathComposeDir(applicationUuid);
        const composeFilePath = path.join(composeDirPath, 'docker-compose.yml');
        if (composeProject) {
            if (fs.existsSync(composeFilePath)) {
                try {
                    await execCommand(['docker', 'compose', '-p', dockerSafeName(composeProject), 'down', '-v'], { cwd: composeDirPath, timeout: 60000 });
                    console.log(`[Agent] ✓ docker compose down -v (${composeProject})`);
                } catch (err) {
                    console.warn(`[Agent] ⚠️  docker compose down failed (${composeProject}): ${err.message}`);
                }
            }

            // Fallback cleanup by compose project label (works even if compose files are gone).
            // Remove containers
            try {
                const ids = (await execCommand(['docker', 'ps', '-aq', '--filter', `label=com.docker.compose.project=${dockerSafeName(composeProject)}`], { timeout: 15000 }))
                    .split('\n').map(s => s.trim()).filter(Boolean);
                for (const cid of ids) {
                    try { await execCommand(['docker', 'rm', '-f', dockerSafeName(cid)], { timeout: 15000 }); } catch {}
                }
                if (ids.length) console.log(`[Agent] ✓ Removed ${ids.length} container(s) for ${composeProject}`);
            } catch (err) {
                // ignore
            }

            // Remove volumes
            try {
                const vols = (await execCommand(['docker', 'volume', 'ls', '-q', '--filter', `label=com.docker.compose.project=${dockerSafeName(composeProject)}`], { timeout: 15000 }))
                    .split('\n').map(s => s.trim()).filter(Boolean);
                for (const v of vols) {
                    try { await execCommand(['docker', 'volume', 'rm', '-f', dockerSafeName(v)], { timeout: 15000 }); } catch {}
                }
                if (vols.length) console.log(`[Agent] ✓ Removed ${vols.length} volume(s) for ${composeProject}`);
            } catch (err) {
                // ignore
            }

            // Remove networks
            try {
                const nets = (await execCommand(['docker', 'network', 'ls', '-q', '--filter', `label=com.docker.compose.project=${dockerSafeName(composeProject)}`], { timeout: 15000 }))
                    .split('\n').map(s => s.trim()).filter(Boolean);
                for (const n of nets) {
                    try { await execCommand(['docker', 'network', 'rm', dockerSafeName(n)], { timeout: 15000 }); } catch {}
                }
                if (nets.length) console.log(`[Agent] ✓ Removed ${nets.length} network(s) for ${composeProject}`);
            } catch (err) {
                // ignore
            }
        }

        // 2) Remove on-disk artifacts.
        // Remove the whole app directory (includes repo/source and any other files).
        safeRmTree(storage.pathAppDir(applicationUuid), 'app directory');
        // Remove compose dir (even though it is keyed as service-<id>, we use app UUID as key).
        safeRmTree(composeDirPath, 'compose directory');
        // Remove entire volume root for this app (not just /data).
        safeRmTree(storage.pathVolumeRoot(applicationUuid), 'volume directory');

        // Remove deployment artifacts if provided.
        for (const depId of deploymentIds) {
            safeRmTree(storage.pathBuildDir(depId), `build directory (deployment ${depId})`);
            safeRmTree(storage.getLogFile(depId), `log file (deployment ${depId})`);
        }
        
        console.log(`[Agent] ✅ Application deleted: ${applicationUuid}`);
        send('application:deleted', { 
            payload: {
                application_uuid: applicationUuid,
                task_id: taskId,
            }
        });
    } catch (err) {
        console.error(`[Agent] ❌ Failed to delete application:`, err.message);
        send('application:delete:failed', { 
            payload: {
                application_uuid: applicationUuid,
                task_id: taskId,
                error: err.message
            }
        });
    }
}

/**
 * Handle stop request
 */
async function handleStop(message) {
    const payload = message.payload || {};
    const applicationUuid = payload.application_uuid || payload.applicationId;
    const taskId = payload.task_id || payload.taskId;
    
    console.log(`[Agent] 🛑 Stopping application: ${applicationUuid}`);

    stopAppEnforcer(applicationUuid);

    // Acknowledge receipt so the server won't keep retrying this stop task.
    if (taskId) {
        send('task:ack', {
            payload: {
                task_id: taskId,
                status: 'received',
                application_uuid: applicationUuid,
            }
        });
    }
    
    try {
        const composeDir = storage.getComposeDir(applicationUuid);
        if (!fs.existsSync(composeDir)) {
            throw new Error('Compose directory not found for application');
        }

        // Stop compose services
        console.log(`[Agent] Stopping compose services...`);
        const safeAppId = String(applicationUuid).trim();
        try {
            await execCommand(['docker', 'compose', '-p', `chap-${safeAppId}`, 'stop', '--timeout', '30'], { cwd: composeDir, timeout: 35000 });
        } catch (err) {
            // If stop hangs or fails, force kill.
            try { await execCommand(['docker', 'compose', '-p', `chap-${safeAppId}`, 'kill'], { cwd: composeDir, timeout: 15000 }); } catch (e) {}
        }
        console.log(`[Agent] ✓ Compose services stopped`);
        
        send('stopped', {
            payload: {
                application_uuid: applicationUuid,
                task_id: taskId || undefined,
            }
        });
    } catch (err) {
        console.error(`[Agent] ❌ Failed to stop:`, err.message);
        send('stopped', {
            payload: {
                application_uuid: applicationUuid,
                task_id: taskId || undefined,
                error: err.message
            }
        });
    }
}

/**
 * Handle restart request
 */
async function handleRestart(message) {
    const payload = message.payload || {};
    const applicationUuid = payload.application_uuid || payload.applicationId;
    const taskId = payload.task_id || payload.taskId;
    
    console.log(`[Agent] 🔄 Restarting application: ${applicationUuid}`);

    // Acknowledge receipt so the server won't keep retrying this restart task.
    if (taskId) {
        send('task:ack', {
            payload: {
                task_id: taskId,
                status: 'received',
                application_uuid: applicationUuid,
            }
        });
    }
    
    try {
        const composeDir = storage.getComposeDir(applicationUuid);
        if (!fs.existsSync(composeDir)) {
            throw new Error('Compose directory not found for application');
        }

        // Restart compose services
        console.log(`[Agent] Restarting compose services...`);
        const safeAppId = String(applicationUuid).trim();
        await execCommand(['docker', 'compose', '-p', `chap-${safeAppId}`, 'restart'], { cwd: composeDir });
        console.log(`[Agent] ✓ Compose services restarted`);
        
        send('restarted', { 
            payload: {
                application_uuid: applicationUuid,
                task_id: taskId || undefined,
                container_name: null
            }
        });
    } catch (err) {
        // console.error(`[Agent] ❌ Failed to restart container ${containerName}:`, err.message);
        send('restarted', { 
            payload: {
                application_uuid: applicationUuid,
                task_id: taskId || undefined,
                error: err.message
            }
        });
    }
}

/**
 * Handle exec request (with security validation)
 */
async function handleExec(message) {
    const { applicationId, containerId, command } = message;
    const containerName = containerId || `chap-${safeId(applicationId)}`;
    
    // Security: Validate command and tokenize it for argv execution.
    const validation = security.validateExecCommand(command);
    if (!validation.valid) {
        console.warn(`[Agent] 🔒 Exec blocked: ${validation.error}`);
        security.auditLog('exec_blocked', { applicationId, command, reason: validation.error });
        send('execResult', { applicationId, error: `Security: ${validation.error}` });
        return;
    }

    let argv;
    try {
        argv = security.tokenizeExecCommand(command);
    } catch (e) {
        const msg = String(e && e.message ? e.message : e);
        console.warn(`[Agent] 🔒 Exec blocked: ${msg}`);
        security.auditLog('exec_blocked', { applicationId, command, reason: msg });
        send('execResult', { applicationId, error: `Security: ${msg}` });
        return;
    }
    
    security.auditLog('exec_command', { applicationId, containerName, command });
    
    try {
        // Security: Run exec with timeout and no tty to prevent escape
        const output = await execCommand(['docker', 'exec', '--no-tty', dockerSafeName(containerName), ...argv], { timeout: 30000 });
        send('execResult', { applicationId, output });
    } catch (err) {
        send('execResult', { applicationId, error: err.message });
    }
}

/**
 * Send log to server
 */
function sendLog(deploymentId, message, logType = 'info') {
    console.log(`[Deploy ${deploymentId}] ${message}`);
    send('task:log', { 
        payload: {
            deployment_id: deploymentId, 
            message: message,
            log_type: logType,
            timestamp: Date.now() 
        }
    });
}

/**
 * Send system info after authentication
 */
function sendSystemInfo() {
    send('node:system_info', {
        payload: {
            hostname: os.hostname(),
            platform: os.platform(),
            arch: os.arch(),
            cpus: os.cpus().length,
            memory: os.totalmem(),
            agent_version: AGENT_VERSION
        }
    });
}

/**
 * Send system status to server
 */
async function sendStatus() {
    try {
        const parseLabels = (labelsStr) => {
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
        };

        const extractAppUuid = (labels) => {
            const chapApp = String(labels['chap.app'] || '').trim();
            if (chapApp) return chapApp;

            const project = String(labels['com.docker.compose.project'] || '').trim();
            if (project.startsWith('chap-') && project.length > 5) {
                return project.slice(5);
            }

            return '';
        };

        // Get container list (only Chap-managed containers)
        const containersRaw = await execCommand([
            'docker',
            'ps',
            '-a',
            '--filter',
            'label=chap.managed=true',
            '--format',
            '{{.ID}}|{{.Names}}|{{.Image}}|{{.Status}}|{{.Ports}}|{{.Labels}}',
        ]);
        const containers = containersRaw.trim().split('\n').filter(Boolean).map(line => {
            const [id, dockerName, image, status, ports, labelsRaw] = line.split('|');
            const labels = parseLabels(labelsRaw);
            const appUuid = extractAppUuid(labels);

            // Ensure server-side association works even for fixed container_name templates.
            let name = dockerName;
            if (appUuid && name && !name.includes(appUuid)) {
                name = `${name}-${appUuid}`;
            }

            return { id, name, image, status, ports, application_uuid: appUuid || undefined };
        });
        
        // Get disk usage for storage
        const diskUsage = storage.getDiskUsage();
        
        // Get system metrics
        const metrics = {
            cpuUsage: os.loadavg()[0] / os.cpus().length * 100,
            memoryUsage: (1 - os.freemem() / os.totalmem()) * 100,
            memoryTotal: os.totalmem(),
            memoryFree: os.freemem(),
            uptime: os.uptime(),
            diskUsage
        };
        
        send('node:metrics', {
            payload: {
                containers,
                metrics,
                timestamp: Date.now()
            }
        });
    } catch (err) {
        console.error('[Agent] Failed to send status:', err);
    }
}

/**
 * Execute a command without invoking a shell.
 *
 * IMPORTANT: Always pass argv arrays. This prevents command injection when
 * untrusted values (repo URLs, branches, paths, etc.) are involved.
 */
function execCommand(argv, options = {}) {
    if (!Array.isArray(argv) || argv.length === 0) {
        return Promise.reject(new Error('execCommand expects argv array'));
    }

    // Defense-in-depth: ensure the executable cannot be user-controlled.
    // This agent only needs a small set of host binaries.
    const allowedBinaries = new Set(['docker', 'git', 'du']);
    const cmdRaw = String(argv[0] ?? '').trim();
    const cmdBase = path.basename(cmdRaw);
    if (!allowedBinaries.has(cmdBase)) {
        return Promise.reject(new Error(`Refusing to execute disallowed binary: ${cmdBase || '(empty)'}`));
    }

    const { redact, timeout, cwd, env } = options || {};
    const timeoutMs = Number.isFinite(timeout) ? Number(timeout) : 0;

    return new Promise((resolve, reject) => {
        // Use the allowlisted base name as the executable to prevent callers from
        // influencing the path.
        const proc = spawn(cmdBase, argv.slice(1).map((x) => String(x)), {
            cwd: cwd || undefined,
            env: env || process.env,
            stdio: ['ignore', 'pipe', 'pipe'],
        });

        let stdout = '';
        let stderr = '';
        let killed = false;

        let t = null;
        if (timeoutMs > 0) {
            t = setTimeout(() => {
                killed = true;
                try { proc.kill('SIGTERM'); } catch {}
                setTimeout(() => { try { proc.kill('SIGKILL'); } catch {} }, 1000);
            }, timeoutMs);
        }

        proc.stdout.on('data', (b) => { stdout += b.toString('utf8'); });
        proc.stderr.on('data', (b) => { stderr += b.toString('utf8'); });
        proc.on('error', (err) => {
            if (t) clearTimeout(t);
            reject(err);
        });
        proc.on('close', (code) => {
            if (t) clearTimeout(t);
            if (code === 0 && !killed) return resolve(stdout);
            const msg = killed ? 'Command timed out' : (stderr || stdout || `Command exited ${code}`);
            reject(new Error(String(redactSecrets(msg, redact || [])).trim()));
        });
    });
}

/**
 * Start heartbeat
 */
function startHeartbeat() {
    heartbeatTimer = setInterval(() => {
        send('heartbeat', {
            cpuUsage: os.loadavg()[0] / os.cpus().length * 100,
            memoryUsage: (1 - os.freemem() / os.totalmem()) * 100
        });
        
        // Also send full status every 5th heartbeat
        if (Math.random() < 0.2) {
            sendStatus();
        }
    }, config.heartbeatInterval);
}

/**
 * Stop heartbeat
 */
function stopHeartbeat() {
    if (heartbeatTimer) {
        clearInterval(heartbeatTimer);
        heartbeatTimer = null;
    }
}

/**
 * Schedule reconnection
 */
function scheduleReconnect() {
    if (reconnectTimer) return;
    
    reconnectTimer = setTimeout(() => {
        reconnectTimer = null;
        connect();
    }, config.reconnectInterval);
    
    console.log(`[Agent] Reconnecting in ${config.reconnectInterval / 1000}s...`);
}

function statMtimeMsSafe(p) {
    try {
        return fs.statSync(p).mtimeMs;
    } catch {
        return 0;
    }
}

function listChildDirsSafe(parentDir) {
    try {
        return fs.readdirSync(parentDir, { withFileTypes: true })
            .filter(d => d.isDirectory())
            .map(d => d.name);
    } catch {
        return [];
    }
}

function safeStorageRemove(targetPath, label, dryRun = false) {
    try {
        if (!targetPath) return false;
        const resolved = path.resolve(targetPath);

        const allowedBases = [
            path.resolve(storage.baseDir),
            path.resolve(storage.dirs.apps),
            path.resolve(storage.dirs.builds),
            path.resolve(storage.dirs.volumes),
            path.resolve(storage.dirs.compose),
            path.resolve(storage.dirs.logs),
        ];

        const isInside = allowedBases.some(base => resolved === base || resolved.startsWith(base + path.sep));
        if (!isInside) {
            console.warn(`[Sweeper] Refusing to delete outside storage: ${resolved} (${label})`);
            return false;
        }
        if (allowedBases.includes(resolved)) {
            console.warn(`[Sweeper] Refusing to delete storage root: ${resolved} (${label})`);
            return false;
        }

        if (!fs.existsSync(resolved)) return false;
        if (dryRun) {
            console.log(`[Sweeper] DRY RUN delete ${label}: ${resolved}`);
            return true;
        }
        fs.rmSync(resolved, { recursive: true, force: true });
        console.log(`[Sweeper] Deleted ${label}: ${resolved}`);
        return true;
    } catch (err) {
        console.warn(`[Sweeper] Failed deleting ${label}: ${err.message}`);
        return false;
    }
}

async function composeProjectHasContainers(projectName) {
    const proj = dockerSafeName(projectName);
    if (!proj) return false;
    try {
        const out = await execCommand(
            ['docker', 'ps', '-aq', '--filter', `label=com.docker.compose.project=${proj}`],
            { timeout: 15000 }
        );
        return out.split('\n').map(s => s.trim()).filter(Boolean).length > 0;
    } catch {
        return false;
    }
}

async function cleanupDockerByComposeProject(projectName, dryRun = false) {
    const proj = dockerSafeName(projectName);
    if (!proj) return;

    const run = async (argv) => {
        if (dryRun) {
            console.log(`[Sweeper] DRY RUN ${Array.isArray(argv) ? argv.join(' ') : String(argv)}`);
            return '';
        }
        return execCommand(argv, { timeout: 20000 });
    };

    // Containers
    try {
        const ids = (await execCommand(['docker', 'ps', '-aq', '--filter', `label=com.docker.compose.project=${proj}`], { timeout: 15000 }))
            .split('\n').map(s => s.trim()).filter(Boolean);
        for (const cid of ids) {
            try { await run(['docker', 'rm', '-f', dockerSafeName(cid)]); } catch {}
        }
    } catch {}

    // Volumes
    try {
        const vols = (await execCommand(['docker', 'volume', 'ls', '-q', '--filter', `label=com.docker.compose.project=${proj}`], { timeout: 15000 }))
            .split('\n').map(s => s.trim()).filter(Boolean);
        for (const v of vols) {
            try { await run(['docker', 'volume', 'rm', '-f', dockerSafeName(v)]); } catch {}
        }
    } catch {}

    // Networks
    try {
        const nets = (await execCommand(['docker', 'network', 'ls', '-q', '--filter', `label=com.docker.compose.project=${proj}`], { timeout: 15000 }))
            .split('\n').map(s => s.trim()).filter(Boolean);
        for (const n of nets) {
            try { await run(['docker', 'network', 'rm', dockerSafeName(n)]); } catch {}
        }
    } catch {}
}

async function runSweeperOnce() {
    const enabled = !!config.sweeperEnabled;
    if (!enabled) return;

    const now = Date.now();
    const minAgeMs = Math.max(60, config.sweeperMinAgeSeconds || 86400) * 1000;
    const buildMinAgeMs = Math.max(60, config.sweeperBuildMinAgeSeconds || 21600) * 1000;
    const dryRun = !!config.sweeperDryRun;

    // 1) Old build directories: remove build-* older than threshold.
    for (const name of listChildDirsSafe(storage.dirs.builds)) {
        if (!name.startsWith('build-')) continue;
        const p = path.join(storage.dirs.builds, name);
        const ageMs = now - statMtimeMsSafe(p);
        if (ageMs < buildMinAgeMs) continue;
        safeStorageRemove(p, `build dir ${name}`, dryRun);
    }

    // 2) Orphan compose/app/volume directories keyed by UUID.
    const uuidRe = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    const composeDirs = listChildDirsSafe(storage.dirs.compose)
        .filter(n => n.startsWith('service-') && uuidRe.test(n.slice('service-'.length)));

    for (const name of composeDirs) {
        const uuid = name.slice('service-'.length).toLowerCase();
        const project = `chap-${uuid}`;
        const pCompose = path.join(storage.dirs.compose, name);
        const ageMs = now - statMtimeMsSafe(pCompose);
        if (ageMs < minAgeMs) continue;

        // Only sweep when there are no containers.
        const hasContainers = await composeProjectHasContainers(project);
        if (hasContainers) continue;

        // Clean docker resources that may remain.
        await cleanupDockerByComposeProject(project, dryRun);

        // Remove directories
        safeStorageRemove(pCompose, `compose dir ${name}`, dryRun);
        safeStorageRemove(path.join(storage.dirs.apps, `app-${uuid}`), `app dir app-${uuid}`, dryRun);
        safeStorageRemove(path.join(storage.dirs.volumes, `app-${uuid}`), `volume dir app-${uuid}`, dryRun);
    }
}

function startSweeper() {
    if (!config.sweeperEnabled) {
        console.log('[Sweeper] Disabled via CHAP_SWEEPER_ENABLED=0');
        return;
    }
    const intervalMs = Math.max(60, config.sweeperIntervalSeconds || 3600) * 1000;
    let running = false;

    const tick = async () => {
        if (running) return;
        running = true;
        try {
            await runSweeperOnce();
        } catch (err) {
            console.warn(`[Sweeper] Error: ${err.message}`);
        } finally {
            running = false;
        }
    };

    console.log(`[Sweeper] Enabled interval=${Math.round(intervalMs / 1000)}s minAge=${config.sweeperMinAgeSeconds}s dryRun=${config.sweeperDryRun ? 1 : 0}`);

    // Run once shortly after boot.
    setTimeout(() => tick().catch(() => {}), 10_000);
    sweeperTimer = setInterval(() => tick().catch(() => {}), intervalMs);
}

function stopSweeper() {
    if (sweeperTimer) {
        clearInterval(sweeperTimer);
        sweeperTimer = null;
    }
}

// ============================================
// Browser WebSocket Server for Direct Log Streaming
// ============================================

// Graceful shutdown
process.on('SIGTERM', () => {
    console.log('[Agent] Received SIGTERM, shutting down...');
    stopHeartbeat();
    stopSweeper();
    if (ws) ws.close();
    if (liveLogsWs) {
        liveLogsWs.close();
        liveLogsWs = null;
    }
    process.exit(0);
});

process.on('SIGINT', () => {
    console.log('[Agent] Received SIGINT, shutting down...');
    stopHeartbeat();
    stopSweeper();
    if (ws) ws.close();
    if (liveLogsWs) {
        liveLogsWs.close();
        liveLogsWs = null;
    }
    process.exit(0);
});

// Start
console.log('========================================');
console.log('  Chap Node Agent v1.0.0');
console.log('========================================');
console.log(`  Server: ${config.serverUrl}`);
console.log(`  Node ID: ${config.nodeId || '(auto)'}`);
console.log(`  Data Dir: ${config.dataDir}`);
const browserWsProtocol = (config.browserWsSslCert && config.browserWsSslKey) ? 'wss' : 'ws';
console.log(`  Browser WS: ${browserWsProtocol}://${config.browserWsHost}:${config.browserWsPort}`);
console.log('');

// Start browser WebSocket server (live logs)
if (!liveLogsWs) {
    liveLogsWs = createLiveLogsWs({
        config,
        sendToServer: (type, data) => send(type, data),
        isServerConnected: () => isConnected && ws && ws.readyState === WebSocket.OPEN,
        execCommand,
        safeId,
        storage,
        agentVersion: AGENT_VERSION,
        deployComposeForNodeApi,
    });
}
liveLogsWs.start();

// Start local sweeper (best-effort orphan cleanup)
startSweeper();

// Connect to Chap server
connect();

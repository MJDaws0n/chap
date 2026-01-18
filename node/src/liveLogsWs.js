/**
 * Live Logs Browser WebSocket server
 *
 * Browsers connect directly to the node on WS/WSS, authenticate via session validation
 * forwarded to the Chap PHP server, then receive container list + live docker logs.
 */

const WebSocket = require('ws');
const { WebSocketServer } = require('ws');
const { spawn } = require('child_process');
const net = require('net');
const http = require('http');
const fs = require('fs');
const path = require('path');
const security = require('./security');

const EXEC_LIMIT = {
    // per connection
    maxRequestsPerWindow: 3,
    windowMs: 10000,
    maxDurationMs: 30000,
    // output caps
    maxStdoutBytes: 64 * 1024,
    maxStderrBytes: 64 * 1024,
    maxTotalBytes: 128 * 1024,
};

const CONSOLE_LIMIT = {
    // per connection
    maxRequestsPerWindow: 10,
    windowMs: 10000,
    // output caps per message burst (attach can be noisy)
    maxChunkBytes: 64 * 1024,
};

const FILES_LIMIT = {
    maxRequestsPerWindow: 20,
    windowMs: 10000,
    maxPathLength: 2048,
    maxReadBytes: 1024 * 1024, // 1MB editor reads
    maxWriteBytes: 1024 * 1024, // 1MB editor writes
    maxUploadBytes: 25 * 1024 * 1024, // 25MB
    maxDownloadBytes: 50 * 1024 * 1024, // 50MB
    downloadChunkBytes: 48 * 1024,
};

const VOLUMES_LIMIT = {
    maxRequestsPerWindow: 10,
    windowMs: 10000,
    // Backups can be large; keep a sane cap.
    maxDownloadBytes: 512 * 1024 * 1024, // 512MB
    maxUploadBytes: 512 * 1024 * 1024, // 512MB
    chunkBytes: 48 * 1024,
    uploadChunkBytes: 1024 * 1024,
};

const VOLUMES_STREAM_LIMIT = {
    // Decoded bytes allowed per window per connection (upload streaming).
    windowMs: 10000,
    maxBytesPerWindow: 128 * 1024 * 1024, // 128MB / 10s
};

const VOLUME_HELPER_IMAGE = process.env.CHAP_VOLUME_HELPER_IMAGE || 'alpine:3.20';

function isSafeVolumeName(name) {
    if (typeof name !== 'string') return false;
    const v = name.trim();
    if (!v) return false;
    if (v.length > 255) return false;
    // Docker volume names allow [a-zA-Z0-9][a-zA-Z0-9_.-]
    if (!/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/.test(v)) return false;
    return true;
}

function checkVolumesRateLimit(browserWs) {
    const now = Date.now();
    if (!browserWs._volWindowStart) {
        browserWs._volWindowStart = now;
        browserWs._volWindowCount = 0;
    }
    if (now - browserWs._volWindowStart > VOLUMES_LIMIT.windowMs) {
        browserWs._volWindowStart = now;
        browserWs._volWindowCount = 0;
    }
    if (browserWs._volWindowCount >= VOLUMES_LIMIT.maxRequestsPerWindow) return false;
    browserWs._volWindowCount += 1;
    return true;
}

function checkVolumesStreamRateLimit(browserWs, bytes) {
    const b = Number.isFinite(bytes) ? bytes : 0;
    const now = Date.now();
    if (!browserWs._volStreamWindowStart) {
        browserWs._volStreamWindowStart = now;
        browserWs._volStreamBytes = 0;
    }
    if (now - browserWs._volStreamWindowStart > VOLUMES_STREAM_LIMIT.windowMs) {
        browserWs._volStreamWindowStart = now;
        browserWs._volStreamBytes = 0;
    }
    if (browserWs._volStreamBytes + b > VOLUMES_STREAM_LIMIT.maxBytesPerWindow) return false;
    browserWs._volStreamBytes += b;
    return true;
}

function isSafePathValue(p) {
    if (typeof p !== 'string') return false;
    if (!p.length) return false;
    if (p.length > FILES_LIMIT.maxPathLength) return false;
    if (/\0|\r|\n/.test(p)) return false;
    if (p[0] !== '/') return false;
    if (p.includes('\\')) return false;
    if (/(^|\/)\.\.(\/|$)/.test(p)) return false;
    return true;
}

function isSafeNameSegment(s) {
    if (typeof s !== 'string') return false;
    const v = s.trim();
    if (!v) return false;
    if (v.length > 255) return false;
    if (/\0|\r|\n|\//.test(v)) return false;
    if (v === '.' || v === '..') return false;
    if (v.startsWith('-')) return false;
    return true;
}

function splitBaseExt(filename) {
    const s = String(filename || '');
    const i = s.lastIndexOf('.');
    // Treat dotfiles like ".env" as having no extension.
    if (i > 0 && i < s.length - 1) {
        return { base: s.slice(0, i), ext: s.slice(i) };
    }
    return { base: s, ext: '' };
}

function checkFilesRateLimit(browserWs) {
    const now = Date.now();
    if (!browserWs._filesWindowStart) {
        browserWs._filesWindowStart = now;
        browserWs._filesWindowCount = 0;
    }
    if (now - browserWs._filesWindowStart > FILES_LIMIT.windowMs) {
        browserWs._filesWindowStart = now;
        browserWs._filesWindowCount = 0;
    }
    if (browserWs._filesWindowCount >= FILES_LIMIT.maxRequestsPerWindow) return false;
    browserWs._filesWindowCount += 1;
    return true;
}

function runDockerInspectMounts(containerId) {
    return new Promise((resolve) => {
        const proc = spawn('docker', ['inspect', '--format', '{{json .Mounts}}', containerId], {
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        let out = '';
        proc.stdout.on('data', (c) => { out += c.toString(); });
        proc.on('close', () => {
            try {
                const mounts = JSON.parse((out || '').trim() || '[]');
                const dests = Array.isArray(mounts)
                    ? mounts.map((m) => (m && m.Destination ? String(m.Destination) : '')).filter(Boolean)
                    : [];
                resolve(dests.filter((d) => d !== '/'));
            } catch {
                resolve([]);
            }
        });
        proc.on('error', () => resolve([]));
    });
}

function runDockerInspectMountDetails(containerId) {
    return new Promise((resolve) => {
        const proc = spawn('docker', ['inspect', '--format', '{{json .Mounts}}', containerId], {
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        let out = '';
        proc.stdout.on('data', (c) => { out += c.toString(); });
        proc.on('close', () => {
            try {
                const mounts = JSON.parse((out || '').trim() || '[]');
                resolve(Array.isArray(mounts) ? mounts : []);
            } catch {
                resolve([]);
            }
        });
        proc.on('error', () => resolve([]));
    });
}

function runDockerExec(containerId, argv, opts = {}) {
    return new Promise((resolve, reject) => {
        const args = ['exec'];
        if (opts.stdin) args.push('-i');
        args.push(containerId, ...argv);

        const proc = spawn('docker', args, {
            stdio: [opts.stdin ? 'pipe' : 'ignore', 'pipe', 'pipe'],
        });

        let stdout = Buffer.alloc(0);
        let stderr = Buffer.alloc(0);
        let stdoutTruncated = false;
        let stderrTruncated = false;
        const max = opts.maxBytes || (256 * 1024);
        const maxErr = opts.maxErrBytes || (128 * 1024);

        proc.stdout.on('data', (chunk) => {
            if (stdout.length < max) {
                const take = Math.min(chunk.length, max - stdout.length);
                stdout = Buffer.concat([stdout, chunk.subarray(0, take)]);
                if (take < chunk.length) stdoutTruncated = true;
            } else {
                stdoutTruncated = true;
            }
        });
        proc.stderr.on('data', (chunk) => {
            if (stderr.length < maxErr) {
                const take = Math.min(chunk.length, maxErr - stderr.length);
                stderr = Buffer.concat([stderr, chunk.subarray(0, take)]);
                if (take < chunk.length) stderrTruncated = true;
            } else {
                stderrTruncated = true;
            }
        });

        proc.on('error', reject);
        proc.on('close', (code) => {
            resolve({
                code: code ?? 0,
                stdout: stdout.toString('utf8'),
                stderr: stderr.toString('utf8'),
                stdoutBytes: stdout.length,
                stderrBytes: stderr.length,
                stdoutTruncated,
                stderrTruncated,
            });
        });

        if (opts.stdin) {
            try {
                proc.stdin.write(opts.stdin);
                proc.stdin.end();
            } catch {
                // ignore
            }
        }
    });
}

function getDockerSocketPath() {
    const raw = String(process.env.DOCKER_HOST || '').trim();
    if (raw.startsWith('unix://')) {
        const p = raw.slice('unix://'.length).trim();
        if (p) return p;
    }
    return '/var/run/docker.sock';
}

function dockerApiJson(method, pathAndQuery) {
    return new Promise((resolve, reject) => {
        const socketPath = getDockerSocketPath();
        const req = http.request(
            {
                socketPath,
                path: pathAndQuery,
                method,
                headers: {
                    Host: 'docker',
                    Accept: 'application/json',
                },
            },
            (res) => {
                let body = '';
                res.setEncoding('utf8');
                res.on('data', (c) => { body += c; });
                res.on('end', () => {
                    const code = res.statusCode || 0;
                    if (code < 200 || code >= 300) {
                        return reject(new Error(`Docker API ${method} ${pathAndQuery} failed (${code})`));
                    }
                    try {
                        resolve(JSON.parse(body || '{}'));
                    } catch (e) {
                        reject(new Error(`Docker API invalid JSON response for ${pathAndQuery}`));
                    }
                });
            }
        );
        req.on('error', reject);
        req.end();
    });
}

function parseCpusetCores(cpuset) {
    const s = String(cpuset || '').trim();
    if (!s) return null;
    const parts = s.split(',').map((p) => p.trim()).filter(Boolean);
    const set = new Set();
    for (const p of parts) {
        const m = p.match(/^(\d+)(?:-(\d+))?$/);
        if (!m) continue;
        const a = parseInt(m[1], 10);
        const b = m[2] ? parseInt(m[2], 10) : a;
        if (!Number.isFinite(a) || !Number.isFinite(b)) continue;
        const lo = Math.min(a, b);
        const hi = Math.max(a, b);
        for (let i = lo; i <= hi; i++) set.add(i);
    }
    return set.size ? set.size : null;
}

function computeCpuLimitCores(inspect, fallbackOnlineCpus) {
    const hostCfg = inspect && inspect.HostConfig ? inspect.HostConfig : null;
    if (!hostCfg) return Number.isFinite(fallbackOnlineCpus) && fallbackOnlineCpus > 0 ? fallbackOnlineCpus : null;

    const nano = typeof hostCfg.NanoCpus === 'number' ? hostCfg.NanoCpus : 0;
    if (Number.isFinite(nano) && nano > 0) return nano / 1e9;

    const quota = typeof hostCfg.CpuQuota === 'number' ? hostCfg.CpuQuota : 0;
    const period = typeof hostCfg.CpuPeriod === 'number' ? hostCfg.CpuPeriod : 0;
    if (Number.isFinite(quota) && Number.isFinite(period) && quota > 0 && period > 0) {
        return quota / period;
    }

    const cpuset = parseCpusetCores(hostCfg.CpusetCpus);
    if (cpuset) return cpuset;

    return Number.isFinite(fallbackOnlineCpus) && fallbackOnlineCpus > 0 ? fallbackOnlineCpus : null;
}

function openDockerHijackedConnection({ method, pathAndQuery }) {
    return new Promise((resolve, reject) => {
        const socketPath = getDockerSocketPath();
        const socket = net.createConnection({ path: socketPath });

        let headerBuf = Buffer.alloc(0);
        let done = false;

        const fail = (err) => {
            if (done) return;
            done = true;
            try { socket.destroy(); } catch {}
            reject(err);
        };

        socket.on('error', fail);

        socket.on('connect', () => {
            try {
                const req =
                    `${method} ${pathAndQuery} HTTP/1.1\r\n` +
                    `Host: docker\r\n` +
                    `Connection: Upgrade\r\n` +
                    `Upgrade: tcp\r\n` +
                    `\r\n`;
                socket.write(req);
            } catch (e) {
                fail(e);
            }
        });

        socket.on('data', (chunk) => {
            if (done) return;
            headerBuf = Buffer.concat([headerBuf, Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk))]);
            const marker = headerBuf.indexOf('\r\n\r\n');
            if (marker === -1) return;

            const headerText = headerBuf.slice(0, marker).toString('utf8');
            const statusLine = headerText.split('\r\n')[0] || '';
            const m = statusLine.match(/HTTP\/1\.[01]\s+(\d+)/i);
            const statusCode = m ? parseInt(m[1], 10) : 0;
            if (statusCode !== 101 && statusCode !== 200) {
                return fail(new Error(`Docker API attach failed (${statusCode}): ${statusLine}`));
            }

            done = true;
            // Ignore any leftover bytes (stream output) by default; we only need stdin.
            resolve({ socket });
        });
    });
}

function runDockerRun(argv, opts = {}) {
    return new Promise((resolve, reject) => {
        const proc = spawn('docker', argv, {
            stdio: [opts.stdin ? 'pipe' : 'ignore', 'pipe', 'pipe'],
        });

        let stdout = Buffer.alloc(0);
        let stderr = Buffer.alloc(0);
        let stdoutTruncated = false;
        let stderrTruncated = false;
        const max = opts.maxBytes || (256 * 1024);
        const maxErr = opts.maxErrBytes || (128 * 1024);

        proc.stdout.on('data', (chunk) => {
            if (stdout.length < max) {
                const take = Math.min(chunk.length, max - stdout.length);
                stdout = Buffer.concat([stdout, chunk.subarray(0, take)]);
                if (take < chunk.length) stdoutTruncated = true;
            } else {
                stdoutTruncated = true;
            }
        });
        proc.stderr.on('data', (chunk) => {
            if (stderr.length < maxErr) {
                const take = Math.min(chunk.length, maxErr - stderr.length);
                stderr = Buffer.concat([stderr, chunk.subarray(0, take)]);
                if (take < chunk.length) stderrTruncated = true;
            } else {
                stderrTruncated = true;
            }
        });

        proc.on('error', reject);
        proc.on('close', (code) => {
            resolve({
                code: code ?? 0,
                stdout: stdout.toString('utf8'),
                stderr: stderr.toString('utf8'),
                stdoutBytes: stdout.length,
                stderrBytes: stderr.length,
                stdoutTruncated,
                stderrTruncated,
            });
        });

        if (opts.stdin) {
            try {
                proc.stdin.write(opts.stdin);
                proc.stdin.end();
            } catch {
                // ignore
            }
        }
    });
}

function hasPerm(browserWs, key, action) {
    const perms = browserWs && browserWs.perms && typeof browserWs.perms === 'object' ? browserWs.perms : null;
    if (!perms) return false;
    const k = perms[key];
    if (!k || typeof k !== 'object') return false;
    return !!k[action];
}

function toVolumeFullPath(userPath) {
    const p = String(userPath || '/');
    if (p === '/') return '/volume';
    return '/volume' + p;
}

function isSafePrintableCommandChar(ch) {
    // Reject control chars and newlines; allow common printable ASCII.
    const code = ch.charCodeAt(0);
    if (code < 0x20 || code === 0x7f) return false;
    return true;
}

function tokenizeExecCommand(command) {
    const s = String(command ?? '').trim();
    if (!s) throw new Error('Empty command');
    if (s.length > 1000) throw new Error('Command too long');

    for (const ch of s) {
        if (!isSafePrintableCommandChar(ch)) {
            throw new Error('Command contains invalid characters');
        }
    }

    // Block obvious shell metacharacters. Even though we do not use a shell,
    // allowing these encourages sh -c workflows and increases risk.
    if (/[|&;<>`$]/.test(s)) {
        throw new Error('Command contains blocked characters');
    }

    const argv = [];
    let cur = '';
    let mode = 'none'; // none | single | double
    let escaped = false;

    const pushCur = () => {
        if (cur.length) argv.push(cur);
        cur = '';
    };

    for (let i = 0; i < s.length; i++) {
        const ch = s[i];

        if (escaped) {
            // Only allow escaping quotes, backslash, and space.
            if (ch === '\\' || ch === '"' || ch === "'" || ch === ' ') {
                cur += ch;
                escaped = false;
                continue;
            }
            throw new Error('Invalid escape sequence');
        }

        if (ch === '\\' && mode !== 'single') {
            escaped = true;
            continue;
        }

        if (mode === 'single') {
            if (ch === "'") {
                mode = 'none';
            } else {
                cur += ch;
            }
            continue;
        }

        if (mode === 'double') {
            if (ch === '"') {
                mode = 'none';
            } else {
                cur += ch;
            }
            continue;
        }

        if (ch === "'") {
            mode = 'single';
            continue;
        }
        if (ch === '"') {
            mode = 'double';
            continue;
        }

        if (/\s/.test(ch)) {
            pushCur();
            continue;
        }

        cur += ch;
    }

    if (escaped) throw new Error('Invalid escape sequence');
    if (mode !== 'none') throw new Error('Unterminated quote');
    pushCur();

    if (argv.length === 0) throw new Error('Empty command');
    if (argv.length > 32) throw new Error('Too many arguments');
    for (const a of argv) {
        if (a.length > 256) throw new Error('Argument too long');
    }

    const head = (argv[0] || '').toLowerCase();
    if (['sh', 'bash', 'zsh', 'dash', 'ksh'].includes(head) && argv.includes('-c')) {
        throw new Error('Shell execution is not allowed');
    }

    return argv;
}

function checkExecRateLimit(browserWs) {
    const now = Date.now();
    if (!browserWs._execWindowStart) {
        browserWs._execWindowStart = now;
        browserWs._execWindowCount = 0;
    }
    if (now - browserWs._execWindowStart > EXEC_LIMIT.windowMs) {
        browserWs._execWindowStart = now;
        browserWs._execWindowCount = 0;
    }
    if (browserWs._execWindowCount >= EXEC_LIMIT.maxRequestsPerWindow) {
        return false;
    }
    browserWs._execWindowCount += 1;
    return true;
}

function checkConsoleRateLimit(browserWs) {
    const now = Date.now();
    if (!browserWs._consoleWindowStart) {
        browserWs._consoleWindowStart = now;
        browserWs._consoleWindowCount = 0;
    }
    if (now - browserWs._consoleWindowStart > CONSOLE_LIMIT.windowMs) {
        browserWs._consoleWindowStart = now;
        browserWs._consoleWindowCount = 0;
    }
    if (browserWs._consoleWindowCount >= CONSOLE_LIMIT.maxRequestsPerWindow) {
        return false;
    }
    browserWs._consoleWindowCount += 1;
    return true;
}

function validateConsoleInput(command) {
    const s = String(command ?? '').trim();
    if (!s) return { valid: false, error: 'Empty command' };
    if (s.length > 512) return { valid: false, error: 'Command too long' };
    for (const ch of s) {
        const code = ch.charCodeAt(0);
        if (code < 0x20 || code === 0x7f) {
            return { valid: false, error: 'Command contains invalid characters' };
        }
    }
    // Hard-block newlines; we append our own \n when writing.
    if (/[\r\n]/.test(s)) return { valid: false, error: 'Command contains invalid characters' };
    return { valid: true, value: s };
}

function clampInt(value, min, max, fallback) {
    const n = parseInt(value, 10);
    if (!Number.isFinite(n)) return fallback;
    return Math.min(Math.max(n, min), max);
}

function normalizeStatus(statusRaw) {
    const s = String(statusRaw || '').trim();
    if (!s) return 'unknown';
    const lower = s.toLowerCase();
    // `docker ps` examples:
    // - "Up 3 minutes"
    // - "Restarting (1) 2 seconds ago"
    // - "Exited (1) 10 seconds ago"
    if (lower.startsWith('up')) return 'running';
    if (lower.startsWith('restarting') || lower.includes(' restarting')) return 'restarting';
    if (lower.startsWith('exited')) return 'stopped';
    if (lower.startsWith('created')) return 'created';
    return 'unknown';
}

function safeJsonSend(ws, payload) {
    if (!ws || ws.readyState !== WebSocket.OPEN) return;
    try {
        ws.send(JSON.stringify(payload));
    } catch {
        // ignore send errors
    }
}

function createLineEmitter(onLine) {
    let buf = '';
    return (chunk) => {
        const text = chunk.toString();
        buf += text;
        const parts = buf.split('\n');
        buf = parts.pop() ?? '';
        for (const part of parts) {
            const line = part.replace(/\r$/, '');
            onLine(line);
        }
    };
}

function parseDockerTimestampLine(line) {
    // docker logs --timestamps outputs: "<rfc3339nano> <message>"
    const s = String(line || '');
    const idx = s.indexOf(' ');
    if (idx <= 0) return { ts: null, msg: s };
    const ts = s.slice(0, idx);
    const msg = s.slice(idx + 1);
    // Basic sanity: must look like ISO-ish
    if (!/^\d{4}-\d{2}-\d{2}T/.test(ts)) return { ts: null, msg: s };
    return { ts, msg };
}

/**
 * @param {object} deps
 * @param {object} deps.config
 * @param {(type: string, data?: object) => boolean} deps.sendToServer
 * @param {() => boolean} deps.isServerConnected
 * @param {(cmd: string, opts?: object) => Promise<string>} deps.execCommand
 * @param {(x: any) => string} deps.safeId
 * @param {(applicationUuid: string) => string | null | undefined} [deps.getComposeDir]
 */
function createLiveLogsWs(deps) {
     const { config, sendToServer, isServerConnected, execCommand, safeId, getComposeDir } = deps;

    let browserWss = null;
    let heartbeatInterval = null;
    let pendingCleanupInterval = null;
    let volumeFsOrphanSweepInterval = null;

    // Map of requestId -> { browserWs, applicationUuid, sessionId, timestamp }
    const pendingValidations = new Map();

    // Upload transfer state: transferId -> { containerId, path, size, received, chunks: Buffer[] }
    const uploads = new Map();

    // Active file downloads: transferId -> { containerId, proc, cancelled }
    const fileDownloads = new Map();

    // Volume replace (streaming) transfer: transferId -> { volumeName, size, received, proc, startedAt }
    const volumeReplacements = new Map();

    // Active volume downloads: transferId -> { volumeName, proc, cancelled }
    const volumeDownloads = new Map();

    // Volume file upload (streaming) transfer: transferId -> { volumeName, size, received, proc, startedAt }
    const volumeFileUploads = new Map();

    // Active volume file downloads: transferId -> { volumeName, proc, cancelled }
    const volumeFileDownloads = new Map();

    function cleanupVolumeFileTransfersForWs(browserWs) {
        if (!browserWs) return;

        try {
            for (const [transferId, t] of volumeFileUploads) {
                if (!t || t.browserWs !== browserWs) continue;
                try { t.cancelled = true; } catch {}
                try { if (t.proc) t.proc.kill('SIGKILL'); } catch {}
                try { if (t.poolKey) unpinVolumeFsHelperKey(browserWs, t.poolKey); } catch {}
                volumeFileUploads.delete(transferId);
            }
        } catch {}

        try {
            for (const [transferId, t] of volumeFileDownloads) {
                if (!t || t.browserWs !== browserWs) continue;
                try { t.cancelled = true; } catch {}
                try { if (t.proc) t.proc.kill('SIGKILL'); } catch {}
                try { if (t.poolKey) unpinVolumeFsHelperKey(browserWs, t.poolKey); } catch {}
                volumeFileDownloads.delete(transferId);
            }
        } catch {}
    }

    // Pool helper containers per (app, volume) to avoid paying docker run startup on every page load.
    // key -> { id, name, inUse, lastUsed, removeTimer }
    const volumeFsHelperPool = new Map();
    const VOLUME_FS_HELPER_IDLE_MS = 90 * 1000;
    // If a user visits the volume-files UI once, their WebSocket may remain connected for logs/etc.
    // Release the pool reference after some inactivity so helpers don't hang around for hours.
    const VOLUME_FS_HELPER_CLIENT_HOLD_MS = 2 * 60 * 1000;
    const VOLUME_FS_HELPER_CLIENT_SWEEP_MS = 30 * 1000;
    // Safety: sweep orphan helpers that survive Node restarts (they run `tail -f /dev/null`).
    const VOLUME_FS_HELPER_ORPHAN_SWEEP_MS = 5 * 60 * 1000;
    const VOLUME_FS_HELPER_ORPHAN_GRACE_MS = 2 * 60 * 1000;

    function ensureVolFsTracking(browserWs) {
        if (!browserWs) return;
        if (!browserWs._volFsHelperKeys) browserWs._volFsHelperKeys = new Set();
        if (!browserWs._volFsHelperLastUsed) browserWs._volFsHelperLastUsed = new Map();
        if (!browserWs._volFsHelperPinnedKeys) browserWs._volFsHelperPinnedKeys = new Set();
        if (!browserWs._volFsHelperIdleTimer) {
            browserWs._volFsHelperIdleTimer = setInterval(() => {
                try {
                    if (!browserWs || browserWs.readyState !== WebSocket.OPEN) {
                        try { clearInterval(browserWs._volFsHelperIdleTimer); } catch {}
                        browserWs._volFsHelperIdleTimer = null;
                        return;
                    }
                    const now = Date.now();
                    const keys = browserWs._volFsHelperKeys;
                    if (!keys || !keys.size) return;
                    const pinned = browserWs._volFsHelperPinnedKeys;
                    const lastUsed = browserWs._volFsHelperLastUsed;

                    const toRelease = [];
                    for (const poolKey of keys) {
                        if (pinned && pinned.has(poolKey)) continue;
                        const t = lastUsed && lastUsed.has(poolKey) ? lastUsed.get(poolKey) : 0;
                        if (!t) continue;
                        if (now - t > VOLUME_FS_HELPER_CLIENT_HOLD_MS) toRelease.push(poolKey);
                    }

                    for (const poolKey of toRelease) {
                        releaseVolumeFsHelperKey(browserWs, poolKey, 'idle').catch(() => {});
                    }
                } catch {
                    // swallow
                }
            }, VOLUME_FS_HELPER_CLIENT_SWEEP_MS);
        }
    }

    function markVolumeFsHelperUsed(browserWs, poolKey) {
        if (!browserWs || !poolKey) return;
        ensureVolFsTracking(browserWs);
        try { browserWs._volFsHelperLastUsed.set(poolKey, Date.now()); } catch {}
    }

    async function releaseVolumeFsHelperKey(browserWs, poolKey, _reason) {
        if (!browserWs) return;
        ensureVolFsTracking(browserWs);
        const keys = browserWs._volFsHelperKeys;
        if (!keys || !keys.has(poolKey)) return;

        keys.delete(poolKey);
        try { browserWs._volFsHelperLastUsed.delete(poolKey); } catch {}

        const entry = volumeFsHelperPool.get(poolKey);
        if (!entry) return;
        entry.inUse = Math.max(0, (entry.inUse || 0) - 1);
        entry.lastUsed = Date.now();
        if (entry.inUse === 0 && !entry.removeTimer) {
            entry.removeTimer = setTimeout(async () => {
                const cur = volumeFsHelperPool.get(poolKey);
                if (!cur || cur.inUse !== 0) return;
                try { await execCommand(`docker rm -f ${cur.id}`); } catch {}
                volumeFsHelperPool.delete(poolKey);
            }, VOLUME_FS_HELPER_IDLE_MS);
        }
    }

    function pinVolumeFsHelperKey(browserWs, poolKey) {
        if (!browserWs || !poolKey) return;
        ensureVolFsTracking(browserWs);
        try { browserWs._volFsHelperPinnedKeys.add(poolKey); } catch {}
    }

    function unpinVolumeFsHelperKey(browserWs, poolKey) {
        if (!browserWs || !poolKey) return;
        ensureVolFsTracking(browserWs);
        try { browserWs._volFsHelperPinnedKeys.delete(poolKey); } catch {}
    }

    function scheduleVolumeFsOrphanSweep() {
        if (volumeFsOrphanSweepInterval) return;

        const sweep = async () => {
            // Build a set of helper IDs we still track.
            const trackedIds = new Set();
            try {
                for (const v of volumeFsHelperPool.values()) {
                    if (v && v.id) trackedIds.add(String(v.id).trim());
                }
            } catch {}

            // Include both new labeled helpers and older helpers (name prefix).
            let ids = [];
            try {
                const out1 = await execCommand('docker ps -aq --filter "label=chap.role=volfs-helper"');
                const out2 = await execCommand('docker ps -aq --filter "name=chap-volfs-"');
                ids = String(out1 || '').trim().split(/\s+/).filter(Boolean)
                    .concat(String(out2 || '').trim().split(/\s+/).filter(Boolean));
            } catch {
                return;
            }
            const uniq = Array.from(new Set(ids));
            if (!uniq.length) return;

            const now = Date.now();
            for (const id of uniq) {
                const cid = String(id || '').trim();
                if (!cid) continue;
                if (trackedIds.has(cid)) continue;

                let info;
                try {
                    info = await dockerApiJson('GET', `/containers/${cid}/json`);
                } catch {
                    continue;
                }
                const name = info && typeof info.Name === 'string' ? info.Name : '';
                const labels = info && info.Config && info.Config.Labels && typeof info.Config.Labels === 'object' ? info.Config.Labels : null;
                const role = labels && typeof labels['chap.role'] === 'string' ? labels['chap.role'] : null;
                const createdRaw = info && typeof info.Created === 'string' ? info.Created : null;
                const createdMs = createdRaw ? Date.parse(createdRaw) : NaN;

                // Only consider our helpers.
                const isOurs = role === 'volfs-helper' || String(name || '').startsWith('/chap-volfs-');
                if (!isOurs) continue;
                if (!Number.isFinite(createdMs)) continue;

                if (now - createdMs < VOLUME_FS_HELPER_ORPHAN_GRACE_MS) continue;

                try { await execCommand(`docker rm -f ${cid}`); } catch {}
            }
        };

        // Run once soon after startup.
        setTimeout(() => { sweep().catch(() => {}); }, 5000);
        volumeFsOrphanSweepInterval = setInterval(() => {
            sweep().catch(() => {});
        }, VOLUME_FS_HELPER_ORPHAN_SWEEP_MS);
    }

    async function ensureVolumeFsHelper(browserWs, volumeName) {
        if (!browserWs) throw new Error('Invalid connection');
        const vol = String(volumeName || '').trim();
        if (!vol) throw new Error('Invalid volume');
        const appUuid = String(browserWs.applicationUuid || '').trim();
        if (!appUuid) throw new Error('Invalid application');

        ensureVolFsTracking(browserWs);
        const poolKey = `${appUuid}:${vol}`;

        const existing = volumeFsHelperPool.get(poolKey);
        if (existing && existing.id) {
            try {
                // Cancel any pending removal when reused.
                if (existing.removeTimer) {
                    clearTimeout(existing.removeTimer);
                    existing.removeTimer = null;
                }

                const ping = await runDockerExec(existing.id, ['sh', '-c', 'true'], { maxBytes: 16, maxErrBytes: 64 });
                if ((ping.code ?? 0) === 0) {
                    existing.lastUsed = Date.now();
                    if (!browserWs._volFsHelperKeys.has(poolKey)) {
                        browserWs._volFsHelperKeys.add(poolKey);
                        existing.inUse = (existing.inUse || 0) + 1;
                    }
                    markVolumeFsHelperUsed(browserWs, poolKey);
                    return existing.id;
                }
            } catch {
                // recreate below
            }

            try { await execCommand(`docker rm -f ${existing.id}`); } catch {}
            volumeFsHelperPool.delete(poolKey);
        }

        const appPart = appUuid.replace(/[^a-zA-Z0-9]/g, '').slice(0, 12) || 'app';
        const volPart = vol.replace(/[^a-zA-Z0-9_.-]/g, '').slice(0, 32) || 'vol';
        const suffix = Math.random().toString(36).slice(2, 10);
        const cname = `chap-volfs-${appPart}-${volPart}-${suffix}`;

        const res = await runDockerRun([
            'run', '-d', '--rm',
            '--name', cname,
            '--label', 'chap.role=volfs-helper',
            '--label', `chap.app_uuid=${appPart}`,
            '--label', `chap.volume=${volPart}`,
            '-v', `${vol}:/volume`,
            VOLUME_HELPER_IMAGE,
            'sh', '-c', 'tail -f /dev/null',
        ], { maxBytes: 1024, maxErrBytes: 64 * 1024 });

        if ((res.code ?? 0) !== 0) {
            throw new Error((res.stderr || '').trim() || 'Failed to start volume helper');
        }
        const id = String(res.stdout || '').trim().split('\n').pop().trim();
        if (!id) throw new Error('Failed to start volume helper');

        const entry = { id, name: cname, inUse: 0, lastUsed: Date.now(), removeTimer: null };
        volumeFsHelperPool.set(poolKey, entry);
        if (!browserWs._volFsHelperKeys.has(poolKey)) {
            browserWs._volFsHelperKeys.add(poolKey);
            entry.inUse += 1;
        }
        markVolumeFsHelperUsed(browserWs, poolKey);
        return id;
    }

    async function cleanupVolumeFsHelpers(browserWs) {
        ensureVolFsTracking(browserWs);
        const keys = browserWs && browserWs._volFsHelperKeys;
        if (!keys || !keys.size) return;

        const all = Array.from(keys);
        for (const poolKey of all) {
            await releaseVolumeFsHelperKey(browserWs, poolKey, 'close');
        }
    }

    function volumesReply(browserWs, requestId, ok, resultOrError) {
        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
        if (ok) {
            safeJsonSend(browserWs, { type: 'volumes:response', request_id: requestId, ok: true, result: resultOrError || {} });
        } else {
            safeJsonSend(browserWs, { type: 'volumes:response', request_id: requestId, ok: false, error: String(resultOrError || 'Request failed') });
        }
    }

    function dockerSafeIdToken(x) {
        // Docker IDs are hex; keep this strict so we don't accidentally inject args.
        return String(x || '').trim().replace(/[^a-f0-9]/gi, '');
    }

    function findContainerById(containers, requestedId) {
        const id = String(requestedId || '').trim().toLowerCase();
        if (!id) return null;
        for (const c of Array.isArray(containers) ? containers : []) {
            const cid = String(c && c.id ? c.id : '').trim().toLowerCase();
            if (!cid) continue;
            if (cid === id) return c;
            // Support short IDs (prefix matching) in either direction.
            if (cid.startsWith(id) || id.startsWith(cid)) return c;
        }
        return null;
    }

    function buildCollisionName(base, ext, n) {
        const b = String(base || 'file');
        const e = String(ext || '');
        // Keep within typical filesystem segment length limits.
        const max = 255;
        const suffix = ` (${n})`;
        const room = max - suffix.length - e.length;
        const trimmedBase = b.length > room ? b.slice(0, Math.max(1, room)) : b;
        return `${trimmedBase}${suffix}${e}`;
    }

    async function pickUniqueNameInDockerContainer(containerId, dir, desiredName) {
        let candidate = String(desiredName || '').trim();
        if (!candidate) candidate = 'upload';
        const { base, ext } = splitBaseExt(candidate);

        // Hard cap attempts to avoid infinite loops.
        for (let i = 0; i < 500; i++) {
            const existsRes = await runDockerExec(
                containerId,
                ['sh', '-c', 'if [ -e "$1/$2" ]; then echo 1; else echo 0; fi', 'sh', dir, candidate],
                { maxBytes: 8, maxErrBytes: 128 }
            );
            if (String(existsRes.stdout || '').trim() !== '1') return candidate;
            candidate = buildCollisionName(base, ext, i + 1);
        }

        return candidate;
    }

    async function pickUniqueNameInHelperContainer(helperId, dirFullPath, desiredName) {
        let candidate = String(desiredName || '').trim();
        if (!candidate) candidate = 'upload';
        const { base, ext } = splitBaseExt(candidate);

        for (let i = 0; i < 500; i++) {
            const existsRes = await runDockerExec(
                helperId,
                ['sh', '-c', 'if [ -e "$1/$2" ]; then echo 1; else echo 0; fi', 'sh', dirFullPath, candidate],
                { maxBytes: 8, maxErrBytes: 128 }
            );
            if (String(existsRes.stdout || '').trim() !== '1') return candidate;
            candidate = buildCollisionName(base, ext, i + 1);
        }

        return candidate;
    }

    async function dockerInspectStdinConfig(containerId) {
        try {
            const id = dockerSafeIdToken(containerId);
            if (!id) return null;
            const out = await execCommand(`docker inspect --format "{{.Config.OpenStdin}}|{{.Config.Tty}}" ${id}`);
            const [openStdinRaw, ttyRaw] = String(out || '').trim().split('|');
            const openStdin = String(openStdinRaw || '').trim().toLowerCase() === 'true';
            const tty = String(ttyRaw || '').trim().toLowerCase() === 'true';
            return { openStdin, tty };
        } catch {
            return null;
        }
    }

    async function dockerPsList({ all, filters }) {
        const base = all ? 'docker ps -a' : 'docker ps';
        const fmt = '{{.ID}}|{{.Names}}|{{.Status}}';
        const flt = (Array.isArray(filters) ? filters : []).filter(Boolean);
        const cmd = `${base} ${flt.map((f) => `--filter "${f}"`).join(' ')} --format "${fmt}"`;
        const out = await execCommand(cmd);
        const lines = String(out || '').trim().split('\n').filter(Boolean);
        const parsed = lines
            .map((line) => {
                const [id, name, statusRaw] = String(line).split('|');
                const idTrim = (id || '').trim();
                const nameTrim = (name || '').trim();
                const status = normalizeStatus(statusRaw);
                return { id: idTrim, name: nameTrim, status };
            })
            .filter((c) => c.id && c.name);
        parsed.sort((a, b) => String(a.name).localeCompare(String(b.name)));
        return parsed;
    }

    async function getComposeProjectContainers(applicationUuid, all) {
        if (typeof getComposeDir !== 'function') return [];
        const app = safeId(applicationUuid);
        const composeDir = getComposeDir(app);
        if (!composeDir) return [];
        const composePath = path.join(composeDir, 'docker-compose.yml');
        if (!fs.existsSync(composePath)) return [];

        try {
            const args = all ? 'ps -a -q' : 'ps -q';
            const idsOut = await execCommand(`docker compose -p chap-${app} ${args}`, { cwd: composeDir });
            const ids = String(idsOut || '')
                .trim()
                .split('\n')
                .map((x) => dockerSafeIdToken(x))
                .filter(Boolean);
            if (!ids.length) return [];

            // Use docker inspect to fetch stable name/status for each id.
            // (docker ps filtering by id would also work but is slower per-container)
            const inspectOut = await execCommand(
                `docker inspect --format "{{.Id}}|{{.Name}}|{{.State.Status}}|{{.State.Restarting}}" ${ids.join(' ')}`
            );
            const lines = String(inspectOut || '').trim().split('\n').filter(Boolean);
            const containers = lines
                .map((line) => {
                    const [id, nameRaw, state, restartingRaw] = String(line).split('|');
                    const idTrim = dockerSafeIdToken(id);
                    const name = String(nameRaw || '').replace(/^\//, '').trim();
                    const st = String(state || '').trim().toLowerCase();
                    const restarting = String(restartingRaw || '').trim().toLowerCase() === 'true';
                    const status = restarting
                        ? 'restarting'
                        : st === 'running'
                            ? 'running'
                            : st === 'exited'
                                ? 'stopped'
                                : (st || 'unknown');
                    return { id: idTrim, name, status };
                })
                .filter((c) => c.id && c.name);
            containers.sort((a, b) => String(a.name).localeCompare(String(b.name)));
            return containers;
        } catch {
            return [];
        }
    }

    async function getAppContainers(applicationUuid, all) {
        const app = safeId(applicationUuid);

        // Most robust when composeDir exists (handles fixed container_name reliably).
        const fromCompose = await getComposeProjectContainers(app, all);
        if (fromCompose.length) return fromCompose;

        // Preferred: Chap labels (injected into compose on deploy; also used by non-compose runs).
        try {
            const byChapLabel = await dockerPsList({
                all,
                filters: [`label=chap.managed=true`, `label=chap.app=${app}`],
            });
            if (byChapLabel.length) return byChapLabel;
        } catch {
            // ignore and fall through
        }

        // Fallback: docker compose project label.
        try {
            const byProject = await dockerPsList({
                all,
                filters: [`label=com.docker.compose.project=chap-${app}`],
            });
            if (byProject.length) return byProject;
        } catch {
            // ignore and fall through
        }

        // Legacy fallback: name prefix.
        try {
            const namePrefix = `chap-${app}`.replace(/\s+/g, '');
            return await dockerPsList({ all, filters: [`name=${namePrefix}`] });
        } catch {
            return [];
        }
    }

    async function getAllAppContainers(applicationUuid) {
        return await getAppContainers(applicationUuid, true);
    }

    async function listVolumesForApp(applicationUuid) {
        const containers = await getAllAppContainers(applicationUuid);
        const byName = new Map();

        for (const c of containers) {
            const mounts = await runDockerInspectMountDetails(c.id);
            for (const m of mounts) {
                if (!m || m.Type !== 'volume') continue;
                const volName = String(m.Name || '').trim();
                if (!volName) continue;
                const dest = String(m.Destination || '').trim();
                const key = volName;
                if (!byName.has(key)) {
                    byName.set(key, {
                        name: volName,
                        type: 'volume',
                        mounts: new Set(),
                        used_by: new Set(),
                    });
                }
                const entry = byName.get(key);
                if (dest) entry.mounts.add(dest);
                entry.used_by.add(c.name);
            }
        }

        const vols = Array.from(byName.values()).map((v) => ({
            name: v.name,
            type: v.type,
            mounts: Array.from(v.mounts).sort(),
            used_by: Array.from(v.used_by).sort(),
        }));
        vols.sort((a, b) => String(a.name).localeCompare(String(b.name)));
        return vols;
    }

    async function ensureVolumeBelongsToApp(browserWs, volumeName) {
        if (!isSafeVolumeName(volumeName)) return false;
        const vols = await listVolumesForApp(browserWs.applicationUuid);
        return vols.some((v) => v.name === volumeName);
    }

    async function handleVolumesRequest(browserWs, message) {
        const requestId = typeof message.request_id === 'string' ? message.request_id.trim() : '';
        const action = typeof message.action === 'string' ? message.action.trim() : '';
        const payload = message.payload && typeof message.payload === 'object' ? message.payload : {};

        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
        if (!browserWs.authenticated) return volumesReply(browserWs, requestId, false, 'Not authenticated');
        if (!requestId || !action) return volumesReply(browserWs, requestId, false, 'Missing request_id or action');

        // Chunk streaming should be bandwidth-limited, not request-count-limited.
        // Cancel actions should always be allowed.
        const isVolumesStreaming = action === 'replace:chunk' || action === 'fs:upload:chunk';
        const isVolumesCancel = action === 'download:cancel' || action === 'replace:cancel' || action === 'fs:upload:cancel' || action === 'fs:download:cancel';
        if (!isVolumesStreaming && !isVolumesCancel && !checkVolumesRateLimit(browserWs)) {
            return volumesReply(browserWs, requestId, false, 'Rate limit exceeded');
        }

        try {
            const requirePerm = (permKey, permAction) => {
                if (!hasPerm(browserWs, permKey, permAction)) {
                    volumesReply(browserWs, requestId, false, 'Not authorized');
                    return false;
                }
                return true;
            };

            if (action === 'list') {
                if (!requirePerm('volumes', 'read')) return;
                const vols = await listVolumesForApp(browserWs.applicationUuid);
                return volumesReply(browserWs, requestId, true, { volumes: vols });
            }

            if (action === 'delete') {
                if (!requirePerm('volumes', 'execute')) return;
                const name = String(payload.name || '').trim();
                if (!await ensureVolumeBelongsToApp(browserWs, name)) return volumesReply(browserWs, requestId, false, 'Volume not found for application');

                const proc = spawn('docker', ['volume', 'rm', '--force', name], { stdio: ['ignore', 'pipe', 'pipe'] });
                let stderr = '';
                proc.stderr.on('data', (c) => { stderr += c.toString(); });
                proc.on('close', (code) => {
                    if ((code ?? 0) !== 0) {
                        return volumesReply(browserWs, requestId, false, (stderr || '').trim() || 'Failed to delete volume');
                    }
                    return volumesReply(browserWs, requestId, true, {});
                });
                proc.on('error', () => volumesReply(browserWs, requestId, false, 'Failed to delete volume'));
                return;
            }

            if (action === 'download') {
                if (!requirePerm('volumes', 'read')) return;
                const name = String(payload.name || '').trim();
                if (!await ensureVolumeBelongsToApp(browserWs, name)) return volumesReply(browserWs, requestId, false, 'Volume not found for application');

                const transferId = `vdl_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
                const filename = `${name}.tar.gz`;

                // Best-effort approximate bytes (uncompressed) for a progress indicator.
                let approxTotalBytes = 0;
                try {
                    const sizeProc = spawn('docker', [
                        'run', '--rm',
                        '-v', `${name}:/volume:ro`,
                        VOLUME_HELPER_IMAGE,
                        'sh', '-c', 'k=$(du -sk /volume 2>/dev/null | cut -f1 || echo 0); echo $((k*1024))',
                    ], { stdio: ['ignore', 'pipe', 'ignore'] });
                    let out = '';
                    sizeProc.stdout.on('data', (c) => { out += c.toString(); });
                    await new Promise((resolve) => sizeProc.on('close', resolve));
                    const n = parseInt(String(out || '').trim(), 10);
                    approxTotalBytes = Number.isFinite(n) && n > 0 ? n : 0;
                } catch {
                    approxTotalBytes = 0;
                }

                safeJsonSend(browserWs, {
                    type: 'volumes:download:start',
                    transfer_id: transferId,
                    name: filename,
                    mime: 'application/gzip',
                    approx_total_bytes: approxTotalBytes || undefined,
                });

                const proc = spawn('docker', [
                    'run', '--rm',
                    '-v', `${name}:/volume:ro`,
                    VOLUME_HELPER_IMAGE,
                    'sh', '-c', 'tar -czf - -C /volume .',
                ], { stdio: ['ignore', 'pipe', 'pipe'] });

                volumeDownloads.set(transferId, { volumeName: name, proc, cancelled: false });

                let sent = 0;
                proc.stdout.on('data', (chunk) => {
                    if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
                    sent += chunk.length;
                    if (sent > VOLUMES_LIMIT.maxDownloadBytes) {
                        try { proc.kill(); } catch {}
                        safeJsonSend(browserWs, { type: 'error', error: 'Volume download too large' });
                        return;
                    }
                    for (let i = 0; i < chunk.length; i += VOLUMES_LIMIT.chunkBytes) {
                        const part = chunk.subarray(i, i + VOLUMES_LIMIT.chunkBytes);
                        safeJsonSend(browserWs, {
                            type: 'volumes:download:chunk',
                            transfer_id: transferId,
                            data_b64: part.toString('base64'),
                            sent_bytes: sent,
                        });
                    }
                });

                let err = '';
                proc.stderr.on('data', (c) => { err += c.toString(); });
                proc.on('close', (code) => {
                    const entry = volumeDownloads.get(transferId);
                    volumeDownloads.delete(transferId);
                    if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
                    if (entry && entry.cancelled) {
                        safeJsonSend(browserWs, { type: 'volumes:download:cancelled', transfer_id: transferId });
                        return;
                    }
                    if ((code ?? 0) !== 0) {
                        safeJsonSend(browserWs, { type: 'error', error: (err || '').trim() || 'Volume download failed' });
                        return;
                    }
                    safeJsonSend(browserWs, { type: 'volumes:download:done', transfer_id: transferId, name: filename });
                });
                proc.on('error', () => {
                    volumeDownloads.delete(transferId);
                    if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
                    safeJsonSend(browserWs, { type: 'error', error: 'Volume download failed to start' });
                });

                return volumesReply(browserWs, requestId, true, { transfer_id: transferId });
            }

            if (action === 'download:cancel') {
                if (!requirePerm('volumes', 'read')) return;
                const transferId = String(payload.transfer_id || '').trim();
                const t = volumeDownloads.get(transferId);
                if (!t || !t.proc) return volumesReply(browserWs, requestId, false, 'Invalid transfer');
                t.cancelled = true;
                try { t.proc.kill(); } catch {}
                volumeDownloads.set(transferId, t);
                return volumesReply(browserWs, requestId, true, {});
            }

            if (action === 'replace:init') {
                if (!requirePerm('volumes', 'execute')) return;
                const name = String(payload.name || '').trim();
                const size = parseInt(String(payload.size || '0'), 10);
                if (!await ensureVolumeBelongsToApp(browserWs, name)) return volumesReply(browserWs, requestId, false, 'Volume not found for application');
                if (!Number.isFinite(size) || size <= 0 || size > VOLUMES_LIMIT.maxUploadBytes) {
                    return volumesReply(browserWs, requestId, false, `Upload too large (max ${VOLUMES_LIMIT.maxUploadBytes} bytes)`);
                }

                // Stream tar.gz into a helper container that wipes then restores.
                const transferId = `vup_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
                const script = [
                    'set -e',
                    'find /volume -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true',
                    'tar -xzf - -C /volume',
                ].join(' && ');

                const proc = spawn('docker', [
                    'run', '--rm', '-i',
                    '-v', `${name}:/volume`,
                    VOLUME_HELPER_IMAGE,
                    'sh', '-c', script,
                ], { stdio: ['pipe', 'ignore', 'pipe'] });

                let stderr = '';
                proc.stderr.on('data', (c) => { stderr += c.toString(); });
                proc.on('close', (code) => {
                    const t = volumeReplacements.get(transferId);
                    if (t) t.exitCode = code ?? 0;
                    if (t && t.cancelled) {
                        if (browserWs && browserWs.readyState === WebSocket.OPEN) {
                            safeJsonSend(browserWs, { type: 'volumes:replace:cancelled', transfer_id: transferId });
                        }
                        volumeReplacements.delete(transferId);
                        return;
                    }
                    if ((code ?? 0) !== 0) {
                        // If browser is still connected, surface an error.
                        if (browserWs && browserWs.readyState === WebSocket.OPEN) {
                            safeJsonSend(browserWs, { type: 'error', error: (stderr || '').trim() || 'Volume replace failed' });
                        }
                    }
                    volumeReplacements.delete(transferId);
                });
                proc.on('error', () => {
                    volumeReplacements.delete(transferId);
                });

                volumeReplacements.set(transferId, { volumeName: name, size, received: 0, proc, startedAt: Date.now() });
                return volumesReply(browserWs, requestId, true, { transfer_id: transferId, chunk_size: VOLUMES_LIMIT.uploadChunkBytes });
            }

            if (action === 'replace:cancel') {
                if (!requirePerm('volumes', 'execute')) return;
                const transferId = String(payload.transfer_id || '').trim();
                const t = volumeReplacements.get(transferId);
                if (!t || !t.proc) return volumesReply(browserWs, requestId, false, 'Invalid transfer');
                t.cancelled = true;
                try { t.proc.kill('SIGKILL'); } catch {}
                volumeReplacements.set(transferId, t);
                return volumesReply(browserWs, requestId, true, {});
            }

            if (action === 'replace:chunk') {
                if (!requirePerm('volumes', 'execute')) return;
                const transferId = String(payload.transfer_id || '');
                const offset = parseInt(String(payload.offset || '0'), 10);
                const dataB64 = String(payload.data_b64 || '');
                const t = volumeReplacements.get(transferId);
                if (!t || !t.proc) return volumesReply(browserWs, requestId, false, 'Invalid transfer');
                if (!Number.isFinite(offset) || offset !== t.received) return volumesReply(browserWs, requestId, false, 'Invalid offset');

                let buf;
                try { buf = Buffer.from(dataB64, 'base64'); } catch { return volumesReply(browserWs, requestId, false, 'Invalid base64'); }

                if (!checkVolumesStreamRateLimit(browserWs, buf.length)) {
                    return volumesReply(browserWs, requestId, false, 'Rate limit exceeded');
                }

                t.received += buf.length;
                if (t.received > t.size || t.received > VOLUMES_LIMIT.maxUploadBytes) {
                    try { t.proc.kill('SIGKILL'); } catch {}
                    volumeReplacements.delete(transferId);
                    return volumesReply(browserWs, requestId, false, 'Upload too large');
                }

                try {
                    const ok = t.proc.stdin.write(buf);
                    if (!ok) {
                        // Backpressure: wait for drain.
                        await new Promise((resolve) => t.proc.stdin.once('drain', resolve));
                    }
                } catch {
                    try { t.proc.kill('SIGKILL'); } catch {}
                    volumeReplacements.delete(transferId);
                    return volumesReply(browserWs, requestId, false, 'Failed to stream upload');
                }

                return volumesReply(browserWs, requestId, true, {});
            }

            if (action === 'replace:commit') {
                if (!requirePerm('volumes', 'execute')) return;
                const transferId = String(payload.transfer_id || '');
                const t = volumeReplacements.get(transferId);
                if (!t || !t.proc) return volumesReply(browserWs, requestId, false, 'Invalid transfer');
                if (t.received !== t.size) return volumesReply(browserWs, requestId, false, 'Upload incomplete');

                // Finish stdin and await completion.
                const proc = t.proc;
                try { proc.stdin.end(); } catch {}

                const ok = await new Promise((resolve) => {
                    let done = false;
                    const finish = (success) => {
                        if (done) return;
                        done = true;
                        resolve(success);
                    };
                    proc.on('close', (code) => finish((code ?? 0) === 0));
                    proc.on('error', () => finish(false));
                });

                volumeReplacements.delete(transferId);
                if (!ok) return volumesReply(browserWs, requestId, false, 'Volume replace failed');
                return volumesReply(browserWs, requestId, true, {});
            }

            if (action.startsWith('fs:')) {
                // IMPORTANT: For fs:* actions, `payload.name` is frequently used as a *filename*.
                // Prefer `payload.volume` as the volume identifier; keep `payload.name` only as a legacy fallback.
                const name = String(payload.volume || payload.volume_name || payload.volumeName || payload.name || '').trim();
                if (!await ensureVolumeBelongsToApp(browserWs, name)) return volumesReply(browserWs, requestId, false, 'Volume not found for application');

                // Permission mapping
                const fsAction = action.slice(3);
                if (fsAction === 'list' || fsAction === 'read' || fsAction === 'download' || fsAction === 'download:cancel') {
                    if (!requirePerm('volume_files', 'read')) return;
                } else if (
                    fsAction === 'write' ||
                    fsAction === 'mkdir' ||
                    fsAction === 'touch' ||
                    fsAction === 'rename' ||
                    fsAction === 'move' ||
                    fsAction === 'copy' ||
                    fsAction === 'archive' ||
                    fsAction === 'unarchive' ||
                    fsAction === 'upload:init' ||
                    fsAction === 'upload:chunk' ||
                    fsAction === 'upload:commit' ||
                    fsAction === 'upload:cancel'
                ) {
                    if (!requirePerm('volume_files', 'write')) return;
                } else if (fsAction === 'delete') {
                    if (!requirePerm('volume_files', 'execute')) return;
                }

                if (fsAction === 'list') {
                    const dirUser = String(payload.path || '/');
                    if (!isSafePathValue(dirUser)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    const dir = toVolumeFullPath(dirUser);
                    const helperId = await ensureVolumeFsHelper(browserWs, name);

                    // Single exec for list+stat to avoid extra roundtrips.
                    const script = [
                        'set -e',
                        'dir="$1"',
                        'if [ ! -d "$dir" ]; then exit 2; fi',
                        'max=2000',
                        'count=0',
                        'list_paths() {',
                        '  if command -v find >/dev/null 2>&1; then',
                        '    find "$dir" -mindepth 1 -maxdepth 1 -print',
                        '  else',
                        '    ls -A1 "$dir" 2>/dev/null | while IFS= read -r n; do printf "%s\n" "$dir/$n"; done',
                        '  fi',
                        '}',
                        'list_paths | while IFS= read -r p; do',
                        '  [ -n "$p" ] || continue',
                        '  count=$((count+1))',
                        '  if [ "$count" -gt "$max" ]; then break; fi',
                        '  name=${p##*/}',
                        '  type=file',
                        '  if [ -d "$p" ]; then type=dir; fi',
                        '  size=""',
                        '  mtime=""',
                        '  if command -v stat >/dev/null 2>&1; then',
                        '    size=$(stat -c %s "$p" 2>/dev/null || true)',
                        '    mtime=$(stat -c %Y "$p" 2>/dev/null || true)',
                        '  fi',
                        '  printf "%s\t%s\t%s\t%s\n" "$name" "$type" "$size" "$mtime"',
                        'done',
                    ].join('\n');

                    const res = await runDockerExec(helperId, ['sh', '-c', script, 'sh', dir], {
                        maxBytes: 2 * 1024 * 1024,
                        maxErrBytes: 64 * 1024,
                    });

                    if ((res.code ?? 0) !== 0) {
                        return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to list directory');
                    }

                    const entries = [];
                    for (const line of res.stdout.split('\n')) {
                        if (!line.trim()) continue;
                        const parts = line.split('\t');
                        if (parts.length < 4) continue;
                        const [entryName, entryType, sizeRaw, mtimeRaw] = parts;
                        const mtimeNum = mtimeRaw ? parseInt(mtimeRaw, 10) : null;
                        const sizeNum = sizeRaw ? parseInt(sizeRaw, 10) : null;
                        entries.push({
                            name: entryName,
                            type: entryType === 'dir' ? 'dir' : 'file',
                            size: Number.isFinite(sizeNum) ? sizeNum : null,
                            mtime: Number.isFinite(mtimeNum) ? new Date(mtimeNum * 1000).toISOString() : null,
                        });
                    }

                    entries.sort((a, b) => {
                        if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
                        return String(a.name).localeCompare(String(b.name));
                    });

                    return volumesReply(browserWs, requestId, true, { entries });
                }

                if (fsAction === 'read') {
                    const pUser = String(payload.path || '');
                    if (!isSafePathValue(pUser)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    const p = toVolumeFullPath(pUser);
                    const helperId = await ensureVolumeFsHelper(browserWs, name);
                    const limit = FILES_LIMIT.maxReadBytes;
                    const readScript = [
                        'set -e',
                        'p="$1"',
                        '[ -f "$p" ] || exit 3',
                        '[ -r "$p" ] || exit 4',
                        `head -c ${limit + 1} "$p"`,
                    ].join('\n');

                    const res = await runDockerExec(helperId, ['sh', '-c', readScript, 'sh', p], { maxBytes: limit + 1, maxErrBytes: 64 * 1024 });

                    if (res.code === 3) return volumesReply(browserWs, requestId, false, 'Not a regular file');
                    if (res.code === 4) return volumesReply(browserWs, requestId, false, 'File is not readable');
                    if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to read file');
                    if ((res.stdoutBytes || 0) > limit) return volumesReply(browserWs, requestId, false, `File too large to edit (>${limit} bytes)`);
                    return volumesReply(browserWs, requestId, true, { content: res.stdout });
                }

                if (fsAction === 'write') {
                    const pUser = String(payload.path || '');
                    const content = typeof payload.content === 'string' ? payload.content : '';
                    if (!isSafePathValue(pUser)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    if (Buffer.byteLength(content, 'utf8') > FILES_LIMIT.maxWriteBytes) {
                        return volumesReply(browserWs, requestId, false, `Content too large (>${FILES_LIMIT.maxWriteBytes} bytes)`);
                    }
                    const p = toVolumeFullPath(pUser);
                    const helperId = await ensureVolumeFsHelper(browserWs, name);
                    const res = await runDockerExec(helperId, ['sh', '-c', 'cat > "$1"', 'sh', p], {
                        stdin: Buffer.from(content, 'utf8'),
                        maxBytes: 1024,
                        maxErrBytes: 64 * 1024,
                    });
                    if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to write file');
                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'mkdir') {
                    const dirUser = String(payload.dir || payload.path || '/');
                    const entry = String(payload.entry || payload.name || payload.folder || '').trim();
                    if (!isSafePathValue(dirUser) || !isSafeNameSegment(entry)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    const dir = toVolumeFullPath(dirUser);
                    const helperId = await ensureVolumeFsHelper(browserWs, name);
                    const res = await runDockerExec(helperId, ['sh', '-c', 'mkdir -p -- "$1/$2"', 'sh', dir, entry], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                    if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to create folder');
                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'touch') {
                    const dirUser = String(payload.dir || payload.path || '/');
                    const entry = String(payload.entry || payload.name || payload.filename || '').trim();
                    if (!isSafePathValue(dirUser) || !isSafeNameSegment(entry)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    const dir = toVolumeFullPath(dirUser);
                    const helperId = await ensureVolumeFsHelper(browserWs, name);
                    const res = await runDockerExec(helperId, ['sh', '-c', ': > "$1/$2"', 'sh', dir, entry], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                    if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to create file');
                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'rename') {
                    const pUser = String(payload.path || '');
                    const newName = String(payload.new_name || '').trim();
                    if (!isSafePathValue(pUser) || !isSafeNameSegment(newName)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    if (pUser === '/' || pUser === '.') return volumesReply(browserWs, requestId, false, 'Refusing to rename root');
                    const parts = pUser.split('/');
                    parts[parts.length - 1] = newName;
                    const destUser = parts.join('/');
                    if (!isSafePathValue(destUser)) return volumesReply(browserWs, requestId, false, 'Invalid destination');
                    const src = toVolumeFullPath(pUser);
                    const dest = toVolumeFullPath(destUser);
                    const helperId = await ensureVolumeFsHelper(browserWs, name);
                    const res = await runDockerExec(helperId, ['sh', '-c', 'mv -f -- "$1" "$2"', 'sh', src, dest], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                    if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to rename');
                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'move') {
                    const destDirUser = String(payload.dest_dir || '/');
                    if (!isSafePathValue(destDirUser)) return volumesReply(browserWs, requestId, false, 'Invalid destination');
                    const destDir = toVolumeFullPath(destDirUser);

                    const helperId = await ensureVolumeFsHelper(browserWs, name);

                    const pathsRaw = Array.isArray(payload.paths) ? payload.paths : null;
                    if (pathsRaw) {
                        const paths = pathsRaw.map((x) => String(x || '')).filter(Boolean);
                        if (!paths.length) return volumesReply(browserWs, requestId, false, 'No paths provided');
                        if (paths.length > 200) return volumesReply(browserWs, requestId, false, 'Too many paths');
                        for (const pUser of paths) {
                            if (!isSafePathValue(pUser)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                            if (pUser === '/' || pUser === '.') return volumesReply(browserWs, requestId, false, 'Refusing to move root');
                        }
                        const fullPaths = paths.map(toVolumeFullPath);
                        const script = [
                            'set -e',
                            'dest="$1"',
                            'shift',
                            'mkdir -p -- "$dest"',
                            'for p in "$@"; do',
                            '  base=$(basename "$p")',
                            '  mv -f -- "$p" "$dest/$base"',
                            'done',
                        ].join('\n');
                        const res = await runDockerExec(helperId, ['sh', '-c', script, 'sh', destDir, ...fullPaths], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                        if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to move');
                        return volumesReply(browserWs, requestId, true, {});
                    }

                    const pUser = String(payload.path || '');
                    if (!isSafePathValue(pUser)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    if (pUser === '/' || pUser === '.') return volumesReply(browserWs, requestId, false, 'Refusing to move root');
                    const src = toVolumeFullPath(pUser);
                    const res = await runDockerExec(helperId, ['sh', '-c', 'dest="$2"; mkdir -p -- "$dest"; base=$(basename "$1"); mv -f -- "$1" "$dest/$base"', 'sh', src, destDir], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                    if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to move');
                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'copy') {
                    const destDirUser = String(payload.dest_dir || '');
                    const destPathUser = String(payload.dest_path || '');

                    const helperId = await ensureVolumeFsHelper(browserWs, name);

                    const pathsRaw = Array.isArray(payload.paths) ? payload.paths : null;
                    if (pathsRaw) {
                        if (!isSafePathValue(destDirUser)) return volumesReply(browserWs, requestId, false, 'Invalid destination');
                        const destDir = toVolumeFullPath(destDirUser);
                        const paths = pathsRaw.map((x) => String(x || '')).filter(Boolean);
                        if (!paths.length) return volumesReply(browserWs, requestId, false, 'No paths provided');
                        if (paths.length > 200) return volumesReply(browserWs, requestId, false, 'Too many paths');
                        for (const pUser of paths) {
                            if (!isSafePathValue(pUser)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                            if (pUser === '/' || pUser === '.') return volumesReply(browserWs, requestId, false, 'Refusing to copy root');
                        }
                        const fullPaths = paths.map(toVolumeFullPath);
                        const script = [
                            'set -e',
                            'dest="$1"',
                            'shift',
                            'mkdir -p -- "$dest"',
                            'for p in "$@"; do',
                            '  base=$(basename "$p")',
                            '  cp -a -- "$p" "$dest/$base"',
                            'done',
                        ].join('\n');
                        const res = await runDockerExec(helperId, ['sh', '-c', script, 'sh', destDir, ...fullPaths], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                        if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to copy');
                        return volumesReply(browserWs, requestId, true, {});
                    }

                    const pUser = String(payload.path || '');
                    if (!isSafePathValue(pUser)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    if (pUser === '/' || pUser === '.') return volumesReply(browserWs, requestId, false, 'Refusing to copy root');
                    const src = toVolumeFullPath(pUser);

                    if (destPathUser) {
                        if (!isSafePathValue(destPathUser)) return volumesReply(browserWs, requestId, false, 'Invalid destination');
                        const dest = toVolumeFullPath(destPathUser);
                        const res = await runDockerExec(helperId, ['sh', '-c', 'cp -a -- "$1" "$2"', 'sh', src, dest], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                        if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to copy');
                        return volumesReply(browserWs, requestId, true, {});
                    }

                    if (!isSafePathValue(destDirUser)) return volumesReply(browserWs, requestId, false, 'Invalid destination');
                    const destDir = toVolumeFullPath(destDirUser);
                    const res = await runDockerExec(helperId, ['sh', '-c', 'dest="$2"; mkdir -p -- "$dest"; base=$(basename "$1"); cp -a -- "$1" "$dest/$base"', 'sh', src, destDir], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                    if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to copy');
                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'archive') {
                    const namesRaw = Array.isArray(payload.names) ? payload.names : null;
                    const dirUser = String(payload.dir || '');
                    const outDirUser = String(payload.out_dir || '/');
                    const outName = String(payload.out_name || 'archive.tar.gz');

                    if (!namesRaw) return volumesReply(browserWs, requestId, false, 'Missing names');
                    if (!isSafePathValue(dirUser) || !isSafePathValue(outDirUser) || !isSafeNameSegment(outName)) return volumesReply(browserWs, requestId, false, 'Invalid path');

                    const names = namesRaw.map((x) => String(x || '')).filter(Boolean);
                    if (!names.length) return volumesReply(browserWs, requestId, false, 'No items provided');
                    if (names.length > 200) return volumesReply(browserWs, requestId, false, 'Too many items');
                    for (const n of names) {
                        if (!isSafeNameSegment(n)) return volumesReply(browserWs, requestId, false, 'Invalid name');
                    }

                    const dir = toVolumeFullPath(dirUser);
                    const outDir = toVolumeFullPath(outDirUser);
                    const helperId = await ensureVolumeFsHelper(browserWs, name);
                    const script = [
                        'set -e',
                        'dir="$1"',
                        'out_dir="$2"',
                        'out_name="$3"',
                        'out="$out_dir/$out_name"',
                        'shift 3',
                        'mkdir -p -- "$out_dir"',
                        'tar -czf "$out" -C "$dir" -- "$@"',
                    ].join('\n');
                    const res = await runDockerExec(helperId, ['sh', '-c', script, 'sh', dir, outDir, outName, ...names], { maxBytes: 1024, maxErrBytes: 128 * 1024 });
                    if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to archive (tar required)');
                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'unarchive') {
                    const pUser = String(payload.path || '');
                    const destDirUser = String(payload.dest_dir || '/');
                    if (!isSafePathValue(pUser) || !isSafePathValue(destDirUser)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    const p = toVolumeFullPath(pUser);
                    const destDir = toVolumeFullPath(destDirUser);
                    const helperId = await ensureVolumeFsHelper(browserWs, name);
                    const res = await runDockerExec(helperId, ['sh', '-c', 'mkdir -p "$2" && tar -xzf "$1" -C "$2"', 'sh', p, destDir], { maxBytes: 1024, maxErrBytes: 128 * 1024 });
                    if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to unarchive (tar required)');
                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'upload:init') {
                    if (!requirePerm('volume_files', 'write')) return;
                    const dirUser = String(payload.dir || '/');
                    const entry = String(payload.name || '').trim();
                    const size = parseInt(String(payload.size || '0'), 10);
                    if (!isSafePathValue(dirUser) || !isSafeNameSegment(entry)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    if (!Number.isFinite(size) || size < 0 || size > VOLUMES_LIMIT.maxUploadBytes) {
                        return volumesReply(browserWs, requestId, false, `Upload too large (max ${VOLUMES_LIMIT.maxUploadBytes} bytes)`);
                    }

                    const transferId = `vfu_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
                    const helperId = await ensureVolumeFsHelper(browserWs, name);
                    const poolKey = `${String(browserWs.applicationUuid || '').trim()}:${String(name || '').trim()}`;
                    pinVolumeFsHelperKey(browserWs, poolKey);

                    const dirFull = toVolumeFullPath(dirUser);
                    const chosenName = await pickUniqueNameInHelperContainer(helperId, dirFull, entry);
                    if (!isSafeNameSegment(chosenName)) return volumesReply(browserWs, requestId, false, 'Invalid destination');

                    const destUser = dirUser === '/' ? `/${chosenName}` : `${dirUser.replace(/\/+$/, '')}/${chosenName}`;
                    if (!isSafePathValue(destUser)) return volumesReply(browserWs, requestId, false, 'Invalid destination');
                    const dest = toVolumeFullPath(destUser);
                    const proc = spawn('docker', [
                        'exec', '-i', helperId,
                        'sh', '-c', 'cat > "$1"', 'sh', dest,
                    ], { stdio: ['pipe', 'ignore', 'pipe'] });

                    let stderr = '';
                    proc.stderr.on('data', (c) => { stderr += c.toString(); });
                    proc.on('close', (code) => {
                        const t = volumeFileUploads.get(transferId);
                        if (t) t.exitCode = code ?? 0;
                        if (t && t.poolKey) unpinVolumeFsHelperKey(browserWs, t.poolKey);
                        if (t && t.cancelled) {
                            volumeFileUploads.delete(transferId);
                            return;
                        }
                        if ((code ?? 0) !== 0) {
                            if (browserWs && browserWs.readyState === WebSocket.OPEN) {
                                safeJsonSend(browserWs, { type: 'error', error: (stderr || '').trim() || 'Upload failed' });
                            }
                        }
                        volumeFileUploads.delete(transferId);
                    });
                    proc.on('error', () => {
                        const t = volumeFileUploads.get(transferId);
                        if (t && t.poolKey) unpinVolumeFsHelperKey(browserWs, t.poolKey);
                        volumeFileUploads.delete(transferId);
                    });

                    volumeFileUploads.set(transferId, { browserWs, volumeName: name, size, received: 0, proc, startedAt: Date.now(), helperId, poolKey, dest, destUser, name: chosenName, cancelled: false });
                    return volumesReply(browserWs, requestId, true, { transfer_id: transferId, chunk_size: VOLUMES_LIMIT.uploadChunkBytes, name: chosenName, path: destUser });
                }

                if (fsAction === 'upload:cancel') {
                    const transferId = String(payload.transfer_id || '');
                    const t = volumeFileUploads.get(transferId);
                    if (!t || !t.proc || t.volumeName !== name) return volumesReply(browserWs, requestId, false, 'Invalid transfer');
                    t.cancelled = true;
                    volumeFileUploads.set(transferId, t);
                    try { t.proc.kill('SIGKILL'); } catch {}
                    // Best-effort cleanup of partially written file.
                    try {
                        if (t.helperId && t.dest) {
                            await runDockerExec(t.helperId, ['sh', '-c', 'rm -f -- "$1"', 'sh', t.dest], { maxBytes: 64, maxErrBytes: 1024 });
                        }
                    } catch {}
                    if (t.poolKey) unpinVolumeFsHelperKey(browserWs, t.poolKey);
                    volumeFileUploads.delete(transferId);
                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'upload:chunk') {
                    const transferId = String(payload.transfer_id || '');
                    const offset = parseInt(String(payload.offset || '0'), 10);
                    const dataB64 = String(payload.data_b64 || '');
                    const t = volumeFileUploads.get(transferId);
                    if (!t || !t.proc || t.volumeName !== name) return volumesReply(browserWs, requestId, false, 'Invalid transfer');
                    if (!Number.isFinite(offset) || offset !== t.received) return volumesReply(browserWs, requestId, false, 'Invalid offset');

                    let buf;
                    try { buf = Buffer.from(dataB64, 'base64'); } catch { return volumesReply(browserWs, requestId, false, 'Invalid base64'); }

                    if (!checkVolumesStreamRateLimit(browserWs, buf.length)) {
                        return volumesReply(browserWs, requestId, false, 'Rate limit exceeded');
                    }

                    t.received += buf.length;
                    if (t.received > t.size || t.received > VOLUMES_LIMIT.maxUploadBytes) {
                        try { t.proc.kill('SIGKILL'); } catch {}
                        if (t.poolKey) unpinVolumeFsHelperKey(browserWs, t.poolKey);
                        volumeFileUploads.delete(transferId);
                        return volumesReply(browserWs, requestId, false, 'Upload too large');
                    }

                    try {
                        const ok = t.proc.stdin.write(buf);
                        if (!ok) {
                            await new Promise((resolve) => t.proc.stdin.once('drain', resolve));
                        }
                    } catch {
                        try { t.proc.kill('SIGKILL'); } catch {}
                        if (t.poolKey) unpinVolumeFsHelperKey(browserWs, t.poolKey);
                        volumeFileUploads.delete(transferId);
                        return volumesReply(browserWs, requestId, false, 'Failed to stream upload');
                    }

                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'upload:commit') {
                    const transferId = String(payload.transfer_id || '');
                    const t = volumeFileUploads.get(transferId);
                    if (!t || !t.proc || t.volumeName !== name) return volumesReply(browserWs, requestId, false, 'Invalid transfer');
                    if (t.received !== t.size) return volumesReply(browserWs, requestId, false, 'Upload incomplete');

                    const proc = t.proc;
                    try { proc.stdin.end(); } catch {}

                    const ok = await new Promise((resolve) => {
                        let done = false;
                        const finish = (success) => {
                            if (done) return;
                            done = true;
                            resolve(success);
                        };
                        proc.on('close', (code) => finish((code ?? 0) === 0));
                        proc.on('error', () => finish(false));
                    });

                    volumeFileUploads.delete(transferId);
                    if (!ok) return volumesReply(browserWs, requestId, false, 'Upload failed');
                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'download') {
                    const pUser = String(payload.path || '');
                    if (!isSafePathValue(pUser)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    const p = toVolumeFullPath(pUser);

                    const helperId = await ensureVolumeFsHelper(browserWs, name);
                    const poolKey = `${String(browserWs.applicationUuid || '').trim()}:${String(name || '').trim()}`;
                    pinVolumeFsHelperKey(browserWs, poolKey);

                    const sizeRes = await runDockerExec(helperId, ['sh', '-c', 'stat -c %s "$1" 2>/dev/null || echo -1', 'sh', p], { maxBytes: 1024, maxErrBytes: 1024 });
                    const size = parseInt((sizeRes.stdout || '').trim(), 10);
                    if (Number.isFinite(size) && size > VOLUMES_LIMIT.maxDownloadBytes) {
                        return volumesReply(browserWs, requestId, false, `File too large to download (>${VOLUMES_LIMIT.maxDownloadBytes} bytes)`);
                    }

                    const transferId = `vfdl_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
                    const filename = pUser.split('/').pop() || 'download';
                    safeJsonSend(browserWs, { type: 'volume_files:download:start', transfer_id: transferId, name: filename, mime: 'application/octet-stream', size: Number.isFinite(size) && size >= 0 ? size : undefined });

                    const proc = spawn('docker', ['exec', helperId, 'cat', '--', p], { stdio: ['ignore', 'pipe', 'pipe'] });

                    volumeFileDownloads.set(transferId, { browserWs, volumeName: name, proc, cancelled: false, poolKey });

                    let sent = 0;
                    proc.stdout.on('data', (chunk) => {
                        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
                        sent += chunk.length;
                        if (sent > VOLUMES_LIMIT.maxDownloadBytes) {
                            try { proc.kill(); } catch {}
                            return;
                        }
                        for (let i = 0; i < chunk.length; i += VOLUMES_LIMIT.chunkBytes) {
                            const part = chunk.subarray(i, i + VOLUMES_LIMIT.chunkBytes);
                            safeJsonSend(browserWs, { type: 'volume_files:download:chunk', transfer_id: transferId, data_b64: part.toString('base64') });
                        }
                    });

                    proc.on('close', (code) => {
                        const entry = volumeFileDownloads.get(transferId);
                        volumeFileDownloads.delete(transferId);
                        if (entry && entry.poolKey) unpinVolumeFsHelperKey(browserWs, entry.poolKey);
                        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
                        if (entry && entry.cancelled) {
                            safeJsonSend(browserWs, { type: 'volume_files:download:cancelled', transfer_id: transferId });
                            return;
                        }
                        if ((code ?? 0) !== 0) {
                            safeJsonSend(browserWs, { type: 'error', error: 'Download failed' });
                            return;
                        }
                        safeJsonSend(browserWs, { type: 'volume_files:download:done', transfer_id: transferId, name: filename });
                    });

                    proc.on('error', () => {
                        const entry = volumeFileDownloads.get(transferId);
                        volumeFileDownloads.delete(transferId);
                        if (entry && entry.poolKey) unpinVolumeFsHelperKey(browserWs, entry.poolKey);
                        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
                        safeJsonSend(browserWs, { type: 'error', error: 'Download failed to start' });
                    });

                    return volumesReply(browserWs, requestId, true, { transfer_id: transferId });
                }

                if (fsAction === 'download:cancel') {
                    const transferId = String(payload.transfer_id || '').trim();
                    const t = volumeFileDownloads.get(transferId);
                    if (!t || !t.proc || t.volumeName !== name) return volumesReply(browserWs, requestId, false, 'Invalid transfer');
                    t.cancelled = true;
                    try { t.proc.kill(); } catch {}
                    volumeFileDownloads.set(transferId, t);
                    return volumesReply(browserWs, requestId, true, {});
                }

                if (fsAction === 'delete') {
                    const pathsRaw = Array.isArray(payload.paths) ? payload.paths : null;
                    if (pathsRaw) {
                        const pathsUser = pathsRaw.map((x) => String(x || '')).filter(Boolean);
                        if (!pathsUser.length) return volumesReply(browserWs, requestId, false, 'No paths provided');
                        if (pathsUser.length > 200) return volumesReply(browserWs, requestId, false, 'Too many paths');
                        for (const pUser of pathsUser) {
                            if (!isSafePathValue(pUser)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                            if (pUser === '/' || pUser === '.') return volumesReply(browserWs, requestId, false, 'Refusing to delete root');
                        }
                        const paths = pathsUser.map(toVolumeFullPath);
                        const helperId = await ensureVolumeFsHelper(browserWs, name);
                        const res = await runDockerExec(helperId, ['sh', '-c', 'rm -rf -- "$@"', 'sh', ...paths], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                        if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to delete');
                        return volumesReply(browserWs, requestId, true, {});
                    }

                    const pUser = String(payload.path || '');
                    const type = String(payload.type || 'file');
                    if (!isSafePathValue(pUser)) return volumesReply(browserWs, requestId, false, 'Invalid path');
                    if (pUser === '/' || pUser === '.') return volumesReply(browserWs, requestId, false, 'Refusing to delete root');
                    const p = toVolumeFullPath(pUser);
                    const cmd = type === 'dir' ? 'rm -rf -- "$1"' : 'rm -f -- "$1"';
                    const helperId = await ensureVolumeFsHelper(browserWs, name);
                    const res = await runDockerExec(helperId, ['sh', '-c', cmd, 'sh', p], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                    if (res.code !== 0) return volumesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to delete');
                    return volumesReply(browserWs, requestId, true, {});
                }

                return volumesReply(browserWs, requestId, false, 'Unknown fs action');
            }

            return volumesReply(browserWs, requestId, false, 'Unknown action');
        } catch (e) {
            return volumesReply(browserWs, requestId, false, e && e.message ? e.message : 'Error');
        }
    }

    function filesReply(browserWs, requestId, ok, resultOrError) {
        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
        if (ok) {
            safeJsonSend(browserWs, { type: 'files:response', request_id: requestId, ok: true, result: resultOrError || {} });
        } else {
            safeJsonSend(browserWs, { type: 'files:response', request_id: requestId, ok: false, error: String(resultOrError || 'Request failed') });
        }
    }

    async function resolveContainerForFiles(browserWs, requestedContainerId) {
        const appUuid = browserWs.applicationUuid;
        const allContainers = await getAppContainers(appUuid, true);
        const running = allContainers.filter((c) => isRunningLike(c.status));
        if (!running.length) return { containers: allContainers, container: null, reason: 'No running containers found' };
        if (requestedContainerId) {
            const found = findContainerById(running, requestedContainerId);
            if (found) return { containers: allContainers, container: found };
            const foundAny = findContainerById(allContainers, requestedContainerId);
            if (foundAny) return { containers: allContainers, container: null, reason: 'Selected container is not running' };
        }
        return { containers: allContainers, container: running[0] };
    }

    async function handleFilesRequest(browserWs, message) {
        const requestId = typeof message.request_id === 'string' ? message.request_id.trim() : '';
        const action = typeof message.action === 'string' ? message.action.trim() : '';
        const payload = message.payload && typeof message.payload === 'object' ? message.payload : {};

        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
        if (!browserWs.authenticated) return filesReply(browserWs, requestId, false, 'Not authenticated');
        if (!requestId || !action) return filesReply(browserWs, requestId, false, 'Missing request_id or action');
        const isFilesStreaming = action === 'upload:chunk';
        const isFilesCancel = action === 'upload:cancel' || action === 'download:cancel';
        if (!isFilesStreaming && !isFilesCancel && !checkFilesRateLimit(browserWs)) {
            return filesReply(browserWs, requestId, false, 'Rate limit exceeded');
        }

        try {
            const requestedContainerId = typeof payload.container_id === 'string' ? payload.container_id.trim() : '';
            const { containers, container } = await resolveContainerForFiles(browserWs, requestedContainerId);

            // Allow meta to succeed even when there are no running containers.
            if (action === 'meta') {
                const root = '/';
                const persistentPrefixes = container ? await runDockerInspectMounts(container.id) : [];
                if (container) {
                    try {
                        console.log(`[BrowserWS] files:${action} app=${browserWs.applicationUuid} container=${container.id}`);
                    } catch {}
                    browserWs.selectedContainerId = container.id;
                }
                return filesReply(browserWs, requestId, true, {
                    root,
                    default_path: '/',
                    containers,
                    selected_container_id: container ? container.id : null,
                    persistent_prefixes: persistentPrefixes,
                    no_running_containers: !container,
                });
            }

            if (!container) return filesReply(browserWs, requestId, false, 'No running containers found');

            try {
                console.log(`[BrowserWS] files:${action} app=${browserWs.applicationUuid} container=${container.id}`);
            } catch {}

            browserWs.selectedContainerId = container.id;
            const persistentPrefixes = await runDockerInspectMounts(container.id);
            const root = '/';

            if (action === 'list') {
                const dir = String(payload.path || '/');
                if (!isSafePathValue(dir)) return filesReply(browserWs, requestId, false, 'Invalid path');

                const listScript = [
                    'set -e',
                    'dir="$1"',
                    'if [ ! -d "$dir" ]; then exit 2; fi',
                    'if command -v find >/dev/null 2>&1; then',
                    '  find "$dir" -mindepth 1 -maxdepth 1 -print',
                    'else',
                    '  ls -A1 "$dir" 2>/dev/null | while IFS= read -r n; do printf "%s\n" "$dir/$n"; done',
                    'fi',
                ].join('\n');

                const listRes = await runDockerExec(container.id, ['sh', '-c', listScript, 'sh', dir], {
                    maxBytes: 1024 * 1024,
                    maxErrBytes: 64 * 1024,
                });

                if (listRes.code !== 0) {
                    try {
                        console.warn(`[BrowserWS] files:list failed code=${listRes.code} stderr=${(listRes.stderr || '').trim()}`);
                    } catch {}
                    return filesReply(browserWs, requestId, false, (listRes.stderr || '').trim() || 'Failed to list directory');
                }

                const paths = listRes.stdout.split('\n').map((x) => x.trim()).filter(Boolean).slice(0, 2000);

                const statScript = [
                    'set -e',
                    'dir="$1"',
                    'shift',
                    'for p in "$@"; do',
                    '  name=${p##*/}',
                    '  type=file',
                    '  if [ -d "$p" ]; then type=dir; fi',
                    '  size=""',
                    '  mtime=""',
                    '  if command -v stat >/dev/null 2>&1; then',
                    '    size=$(stat -c %s "$p" 2>/dev/null || true)',
                    '    mtime=$(stat -c %Y "$p" 2>/dev/null || true)',
                    '  fi',
                    '  printf "%s\t%s\t%s\t%s\t%s\n" "$name" "$type" "$size" "$mtime" "$p"',
                    'done',
                ].join('\n');

                const statRes = await runDockerExec(container.id, ['sh', '-c', statScript, 'sh', dir, ...paths], {
                    maxBytes: 2 * 1024 * 1024,
                    maxErrBytes: 64 * 1024,
                });

                if (statRes.code !== 0) {
                    try {
                        console.warn(`[BrowserWS] files:list stat failed code=${statRes.code} stderr=${(statRes.stderr || '').trim()}`);
                    } catch {}
                    return filesReply(browserWs, requestId, false, (statRes.stderr || '').trim() || 'Failed to stat directory entries');
                }

                const entries = [];
                for (const line of statRes.stdout.split('\n')) {
                    if (!line.trim()) continue;
                    const parts = line.split('\t');
                    if (parts.length < 5) continue;
                    const [name, type, sizeRaw, mtimeRaw, fullPath] = parts;
                    const mtimeNum = mtimeRaw ? parseInt(mtimeRaw, 10) : null;
                    const sizeNum = sizeRaw ? parseInt(sizeRaw, 10) : null;
                    const persistent = persistentPrefixes.some((p) => fullPath === p || fullPath.startsWith(p + '/'));
                    entries.push({
                        name,
                        type: type === 'dir' ? 'dir' : 'file',
                        size: Number.isFinite(sizeNum) ? sizeNum : null,
                        mtime: Number.isFinite(mtimeNum) ? new Date(mtimeNum * 1000).toISOString() : null,
                        path: fullPath,
                        persistent,
                    });
                }

                entries.sort((a, b) => {
                    if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
                    return String(a.name).localeCompare(String(b.name));
                });

                return filesReply(browserWs, requestId, true, { root, entries, persistent_prefixes: persistentPrefixes });
            }

            if (action === 'read') {
                const p = String(payload.path || '');
                if (!isSafePathValue(p)) return filesReply(browserWs, requestId, false, 'Invalid path');

                // Avoid hanging on special files and avoid reading full large files.
                // Read only up to (limit + 1) bytes; if we get >limit we reject.
                const limit = FILES_LIMIT.maxReadBytes;
                const readScript = [
                    'set -e',
                    'p="$1"',
                    '[ -f "$p" ] || exit 3',
                    '[ -r "$p" ] || exit 4',
                    // busybox head supports -c
                    `head -c ${limit + 1} "$p"`,
                ].join('\n');

                const res = await runDockerExec(container.id, ['sh', '-c', readScript, 'sh', p], {
                    maxBytes: limit + 1,
                    maxErrBytes: 64 * 1024,
                });

                if (res.code === 3) return filesReply(browserWs, requestId, false, 'Not a regular file');
                if (res.code === 4) return filesReply(browserWs, requestId, false, 'File is not readable');
                if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to read file');
                if ((res.stdoutBytes || 0) > limit) {
                    return filesReply(browserWs, requestId, false, `File too large to edit (>${limit} bytes)`);
                }
                return filesReply(browserWs, requestId, true, { content: res.stdout });
            }

            if (action === 'write') {
                const p = String(payload.path || '');
                const content = typeof payload.content === 'string' ? payload.content : '';
                if (!isSafePathValue(p)) return filesReply(browserWs, requestId, false, 'Invalid path');
                if (Buffer.byteLength(content, 'utf8') > FILES_LIMIT.maxWriteBytes) {
                    return filesReply(browserWs, requestId, false, `Content too large (>${FILES_LIMIT.maxWriteBytes} bytes)`);
                }

                const res = await runDockerExec(container.id, ['sh', '-c', 'cat > "$1"', 'sh', p], {
                    stdin: Buffer.from(content, 'utf8'),
                    maxBytes: 1024,
                    maxErrBytes: 64 * 1024,
                });
                if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to write file');
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'delete') {
                const pathsRaw = Array.isArray(payload.paths) ? payload.paths : null;
                if (pathsRaw) {
                    const paths = pathsRaw.map((x) => String(x || '')).filter(Boolean);
                    if (!paths.length) return filesReply(browserWs, requestId, false, 'No paths provided');
                    if (paths.length > 200) return filesReply(browserWs, requestId, false, 'Too many paths');
                    for (const p of paths) {
                        if (!isSafePathValue(p)) return filesReply(browserWs, requestId, false, 'Invalid path');
                        if (p === '/' || p === '.') return filesReply(browserWs, requestId, false, 'Refusing to delete root');
                    }
                    const res = await runDockerExec(container.id, ['sh', '-c', 'rm -rf -- "$@"', 'sh', ...paths], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                    if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to delete');
                    return filesReply(browserWs, requestId, true, {});
                }

                const p = String(payload.path || '');
                const type = String(payload.type || 'file');
                if (!isSafePathValue(p)) return filesReply(browserWs, requestId, false, 'Invalid path');
                if (p === '/' || p === '.') return filesReply(browserWs, requestId, false, 'Refusing to delete root');
                const cmd = type === 'dir' ? 'rm -rf -- "$1"' : 'rm -f -- "$1"';
                const res = await runDockerExec(container.id, ['sh', '-c', cmd, 'sh', p], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to delete');
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'mkdir') {
                const dir = String(payload.dir || '/');
                const name = String(payload.name || '');
                if (!isSafePathValue(dir) || !isSafeNameSegment(name)) return filesReply(browserWs, requestId, false, 'Invalid path');
                const res = await runDockerExec(container.id, ['sh', '-c', 'mkdir -p -- "$1/$2"', 'sh', dir, name], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to create folder');
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'touch') {
                const dir = String(payload.dir || '/');
                const name = String(payload.name || '');
                if (!isSafePathValue(dir) || !isSafeNameSegment(name)) return filesReply(browserWs, requestId, false, 'Invalid path');
                const res = await runDockerExec(container.id, ['sh', '-c', ': > "$1/$2"', 'sh', dir, name], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to create file');
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'rename') {
                const p = String(payload.path || '');
                const newName = String(payload.new_name || '');
                if (!isSafePathValue(p) || !isSafeNameSegment(newName)) return filesReply(browserWs, requestId, false, 'Invalid path');
                const res = await runDockerExec(container.id, ['sh', '-c', 'd=$(dirname "$1"); mv -- "$1" "$d/$2"', 'sh', p, newName], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to rename');
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'move') {
                const destDir = String(payload.dest_dir || '');
                if (!isSafePathValue(destDir)) return filesReply(browserWs, requestId, false, 'Invalid path');

                const pathsRaw = Array.isArray(payload.paths) ? payload.paths : null;
                if (pathsRaw) {
                    const paths = pathsRaw.map((x) => String(x || '')).filter(Boolean);
                    if (!paths.length) return filesReply(browserWs, requestId, false, 'No paths provided');
                    if (paths.length > 200) return filesReply(browserWs, requestId, false, 'Too many paths');
                    for (const p of paths) {
                        if (!isSafePathValue(p)) return filesReply(browserWs, requestId, false, 'Invalid path');
                    }
                    const script = [
                        'set -e',
                        'dest="$1"',
                        'shift',
                        'for p in "$@"; do',
                        '  base=$(basename "$p")',
                        '  mv -- "$p" "$dest/$base"',
                        'done',
                    ].join('\n');
                    const res = await runDockerExec(container.id, ['sh', '-c', script, 'sh', destDir, ...paths], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                    if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to move');
                    return filesReply(browserWs, requestId, true, {});
                }

                const p = String(payload.path || '');
                if (!isSafePathValue(p)) return filesReply(browserWs, requestId, false, 'Invalid path');
                const res = await runDockerExec(container.id, ['sh', '-c', 'base=$(basename "$1"); mv -- "$1" "$2/$base"', 'sh', p, destDir], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to move');
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'copy') {
                const explicitDestPath = String(payload.dest_path || '');
                if (explicitDestPath) {
                    const p = String(payload.path || '');
                    if (!isSafePathValue(p) || !isSafePathValue(explicitDestPath)) return filesReply(browserWs, requestId, false, 'Invalid path');
                    const script = [
                        'set -e',
                        'src="$1"',
                        'dst="$2"',
                        'if [ -e "$dst" ]; then',
                        '  echo "Destination already exists" >&2',
                        '  exit 17',
                        'fi',
                        'cp -a -- "$src" "$dst"',
                    ].join('\n');
                    const res = await runDockerExec(container.id, ['sh', '-c', script, 'sh', p, explicitDestPath], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                    if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to copy');
                    return filesReply(browserWs, requestId, true, {});
                }

                const destDir = String(payload.dest_dir || '');
                if (!isSafePathValue(destDir)) return filesReply(browserWs, requestId, false, 'Invalid path');

                const pathsRaw = Array.isArray(payload.paths) ? payload.paths : null;
                if (pathsRaw) {
                    const paths = pathsRaw.map((x) => String(x || '')).filter(Boolean);
                    if (!paths.length) return filesReply(browserWs, requestId, false, 'No paths provided');
                    if (paths.length > 200) return filesReply(browserWs, requestId, false, 'Too many paths');
                    for (const p of paths) {
                        if (!isSafePathValue(p)) return filesReply(browserWs, requestId, false, 'Invalid path');
                    }
                    const script = [
                        'set -e',
                        'dest="$1"',
                        'shift',
                        'for p in "$@"; do',
                        '  base=$(basename "$p")',
                        '  cp -a -- "$p" "$dest/$base"',
                        'done',
                    ].join('\n');
                    const res = await runDockerExec(container.id, ['sh', '-c', script, 'sh', destDir, ...paths], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                    if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to copy');
                    return filesReply(browserWs, requestId, true, {});
                }

                const p = String(payload.path || '');
                if (!isSafePathValue(p)) return filesReply(browserWs, requestId, false, 'Invalid path');
                const res = await runDockerExec(container.id, ['sh', '-c', 'base=$(basename "$1"); cp -a -- "$1" "$2/$base"', 'sh', p, destDir], { maxBytes: 1024, maxErrBytes: 64 * 1024 });
                if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to copy');
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'archive') {
                const namesRaw = Array.isArray(payload.names) ? payload.names : null;
                const dir = String(payload.dir || '');
                const outDir = String(payload.out_dir || '/');
                const outName = String(payload.out_name || 'archive.tar.gz');

                if (namesRaw) {
                    if (!isSafePathValue(dir) || !isSafePathValue(outDir) || !isSafeNameSegment(outName)) return filesReply(browserWs, requestId, false, 'Invalid path');
                    const names = namesRaw.map((x) => String(x || '')).filter(Boolean);
                    if (!names.length) return filesReply(browserWs, requestId, false, 'No items provided');
                    if (names.length > 200) return filesReply(browserWs, requestId, false, 'Too many items');
                    for (const n of names) {
                        if (!isSafeNameSegment(n)) return filesReply(browserWs, requestId, false, 'Invalid name');
                    }
                    const script = [
                        'set -e',
                        'dir="$1"',
                        'out="$2/$3"',
                        'shift 3',
                        'tar -czf "$out" -C "$dir" -- "$@"',
                    ].join('\n');
                    const res = await runDockerExec(container.id, ['sh', '-c', script, 'sh', dir, outDir, outName, ...names], { maxBytes: 1024, maxErrBytes: 128 * 1024 });
                    if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to archive (tar required)');
                    return filesReply(browserWs, requestId, true, {});
                }

                const p = String(payload.path || '');
                if (!isSafePathValue(p) || !isSafePathValue(outDir) || !isSafeNameSegment(outName)) return filesReply(browserWs, requestId, false, 'Invalid path');
                const res = await runDockerExec(container.id, ['sh', '-c', 'src="$1"; out="$2/$3"; tar -czf "$out" -C "$(dirname "$src")" "$(basename "$src")"', 'sh', p, outDir, outName], { maxBytes: 1024, maxErrBytes: 128 * 1024 });
                if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to archive (tar required)');
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'unarchive') {
                const p = String(payload.path || '');
                const destDir = String(payload.dest_dir || '/');
                if (!isSafePathValue(p) || !isSafePathValue(destDir)) return filesReply(browserWs, requestId, false, 'Invalid path');
                const res = await runDockerExec(container.id, ['sh', '-c', 'mkdir -p "$2" && tar -xzf "$1" -C "$2"', 'sh', p, destDir], { maxBytes: 1024, maxErrBytes: 128 * 1024 });
                if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to unarchive (tar required)');
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'upload:init') {
                const dir = String(payload.dir || '/');
                const name = String(payload.name || '');
                const size = parseInt(String(payload.size || '0'), 10);
                if (!isSafePathValue(dir) || !isSafeNameSegment(name)) return filesReply(browserWs, requestId, false, 'Invalid path');
                if (!Number.isFinite(size) || size < 0 || size > FILES_LIMIT.maxUploadBytes) {
                    return filesReply(browserWs, requestId, false, `Upload too large (max ${FILES_LIMIT.maxUploadBytes} bytes)`);
                }
                const transferId = `up_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
                const chosenName = await pickUniqueNameInDockerContainer(container.id, dir, name);
                if (!isSafeNameSegment(chosenName)) return filesReply(browserWs, requestId, false, 'Invalid destination');
                const path = dir === '/' ? `/${chosenName}` : `${dir.replace(/\/+$/, '')}/${chosenName}`;
                uploads.set(transferId, { containerId: container.id, path, size, received: 0, chunks: [], name: chosenName });
                return filesReply(browserWs, requestId, true, { transfer_id: transferId, chunk_size: 128 * 1024, name: chosenName, path });
            }

            if (action === 'upload:cancel') {
                const transferId = String(payload.transfer_id || '').trim();
                const t = uploads.get(transferId);
                if (!t || t.containerId !== container.id) return filesReply(browserWs, requestId, false, 'Invalid transfer');
                uploads.delete(transferId);
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'upload:chunk') {
                const transferId = String(payload.transfer_id || '');
                const offset = parseInt(String(payload.offset || '0'), 10);
                const dataB64 = String(payload.data_b64 || '');
                const t = uploads.get(transferId);
                if (!t || t.containerId !== container.id) return filesReply(browserWs, requestId, false, 'Invalid transfer');
                if (!Number.isFinite(offset) || offset !== t.received) return filesReply(browserWs, requestId, false, 'Invalid offset');
                let buf;
                try {
                    buf = Buffer.from(dataB64, 'base64');
                } catch {
                    return filesReply(browserWs, requestId, false, 'Invalid base64');
                }
                t.received += buf.length;
                if (t.received > t.size || t.received > FILES_LIMIT.maxUploadBytes) {
                    uploads.delete(transferId);
                    return filesReply(browserWs, requestId, false, 'Upload too large');
                }
                t.chunks.push(buf);
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'upload:commit') {
                const transferId = String(payload.transfer_id || '');
                const t = uploads.get(transferId);
                if (!t || t.containerId !== container.id) return filesReply(browserWs, requestId, false, 'Invalid transfer');
                if (t.received !== t.size) {
                    uploads.delete(transferId);
                    return filesReply(browserWs, requestId, false, 'Upload incomplete');
                }
                const body = Buffer.concat(t.chunks);
                uploads.delete(transferId);
                const res = await runDockerExec(container.id, ['sh', '-c', 'cat > "$1"', 'sh', t.path], { stdin: body, maxBytes: 1024, maxErrBytes: 64 * 1024 });
                if (res.code !== 0) return filesReply(browserWs, requestId, false, (res.stderr || '').trim() || 'Failed to write upload');
                return filesReply(browserWs, requestId, true, {});
            }

            if (action === 'download') {
                const p = String(payload.path || '');
                if (!isSafePathValue(p)) return filesReply(browserWs, requestId, false, 'Invalid path');

                const sizeRes = await runDockerExec(container.id, ['sh', '-c', 'stat -c %s "$1" 2>/dev/null || echo -1', 'sh', p], { maxBytes: 1024, maxErrBytes: 1024 });
                const size = parseInt((sizeRes.stdout || '').trim(), 10);
                if (Number.isFinite(size) && size > FILES_LIMIT.maxDownloadBytes) {
                    return filesReply(browserWs, requestId, false, `File too large to download (>${FILES_LIMIT.maxDownloadBytes} bytes)`);
                }

                const transferId = `dl_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
                safeJsonSend(browserWs, { type: 'files:download:start', transfer_id: transferId, name: p.split('/').pop() || 'download', mime: 'application/octet-stream', size: Number.isFinite(size) && size >= 0 ? size : undefined });

                const proc = spawn('docker', ['exec', container.id, 'cat', '--', p], { stdio: ['ignore', 'pipe', 'pipe'] });
                fileDownloads.set(transferId, { containerId: container.id, proc, cancelled: false });
                let sent = 0;

                proc.stdout.on('data', (chunk) => {
                    if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
                    sent += chunk.length;
                    if (sent > FILES_LIMIT.maxDownloadBytes) {
                        try { proc.kill(); } catch {}
                        return;
                    }
                    for (let i = 0; i < chunk.length; i += FILES_LIMIT.downloadChunkBytes) {
                        const part = chunk.subarray(i, i + FILES_LIMIT.downloadChunkBytes);
                        safeJsonSend(browserWs, { type: 'files:download:chunk', transfer_id: transferId, data_b64: part.toString('base64') });
                    }
                });

                proc.on('close', (code) => {
                    const entry = fileDownloads.get(transferId);
                    fileDownloads.delete(transferId);
                    if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
                    if (entry && entry.cancelled) {
                        safeJsonSend(browserWs, { type: 'files:download:cancelled', transfer_id: transferId });
                        return;
                    }
                    if ((code ?? 0) !== 0) {
                        safeJsonSend(browserWs, { type: 'error', error: 'Download failed' });
                        return;
                    }
                    safeJsonSend(browserWs, { type: 'files:download:done', transfer_id: transferId, name: p.split('/').pop() || 'download' });
                });

                proc.on('error', () => {
                    fileDownloads.delete(transferId);
                });

                return filesReply(browserWs, requestId, true, { transfer_id: transferId });
            }

            if (action === 'download:cancel') {
                const transferId = String(payload.transfer_id || '').trim();
                const t = fileDownloads.get(transferId);
                if (!t || !t.proc || t.containerId !== container.id) return filesReply(browserWs, requestId, false, 'Invalid transfer');
                t.cancelled = true;
                try { t.proc.kill(); } catch {}
                fileDownloads.set(transferId, t);
                return filesReply(browserWs, requestId, true, {});
            }

            return filesReply(browserWs, requestId, false, 'Unknown action');
        } catch (e) {
            return filesReply(browserWs, requestId, false, e && e.message ? e.message : 'Error');
        }
    }

    function schedulePendingCleanup() {
        if (pendingCleanupInterval) return;
        pendingCleanupInterval = setInterval(() => {
            const now = Date.now();
            for (const [requestId, pending] of pendingValidations) {
                if (now - pending.timestamp > 30000) {
                    pendingValidations.delete(requestId);
                    if (pending.browserWs && pending.browserWs.readyState === WebSocket.OPEN) {
                        try { pending.browserWs.close(4003, 'Validation timeout'); } catch {}
                    }
                }
            }
        }, 10000);
    }

    async function getRunningContainers(applicationUuid) {
        const all = await getAppContainers(applicationUuid, false);
        // `docker ps` returns only running, but keep this guard if normalizeStatus ever changes.
        return all.filter((c) => c.status === 'running' || c.status === 'restarting');
    }

    function isRunningLike(status) {
        const s = String(status || '').toLowerCase();
        return s === 'running' || s === 'restarting';
    }

    function stopAllLogProcesses(browserWs) {
        if (browserWs.logProcesses && Array.isArray(browserWs.logProcesses)) {
            for (const p of browserWs.logProcesses) {
                try {
                    if (p && typeof p.kill === 'function') p.kill();
                } catch {}
            }
        }
        browserWs.logProcesses = [];
        if (browserWs._restartTimers && Array.isArray(browserWs._restartTimers)) {
            for (const t of browserWs._restartTimers) {
                try { clearTimeout(t); } catch {}
            }
        }
        browserWs._restartTimers = [];
    }

    function startLogStreams(browserWs, applicationUuid, containers) {
        stopAllLogProcesses(browserWs);

        if (!Array.isArray(containers) || containers.length === 0) {
            safeJsonSend(browserWs, {
                type: 'log',
                stream: 'system',
                data: 'No running containers found for this application\n',
            });
            return;
        }

        // NOTE: container list is published by discovery (includes stopped containers).

        const tail = clampInt(browserWs.tailLines, 0, 1000, 100);

        for (const c of containers) {
            const containerId = c.id;
            const containerName = c.name;

            const proc = spawn('docker', [
                'logs',
                '-f',
                '--tail',
                String(tail),
                '--timestamps',
                containerId,
            ]);

            browserWs.logProcesses.push(proc);

            const onLine = (stream) => (line) => {
                if (!line || !line.trim()) return;
                const { ts, msg } = parseDockerTimestampLine(line);
                safeJsonSend(browserWs, {
                    type: 'log',
                    stream,
                    container: containerName,
                    container_id: containerId,
                    // Send message without timestamp; frontend can use timestampOverride.
                    content: msg,
                    timestamp: ts || undefined,
                });
            };

            const emitStdout = createLineEmitter(onLine('stdout'));
            const emitStderr = createLineEmitter(onLine('stderr'));

            proc.stdout.on('data', emitStdout);
            proc.stderr.on('data', emitStderr);

            proc.on('close', () => {
                // If the socket is still open, try restarting this container stream once.
                if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;

                const t = setTimeout(async () => {
                    if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
                    // Discovery loop will rebuild streams if container IDs changed.
                    // This is just a best-effort nudge if docker logs exits unexpectedly.
                    try {
                        const all = await getAppContainers(applicationUuid, true);
                        const latest = all.filter((c) => isRunningLike(c.status));
                        const idsKey = latest.map(x => x.id).sort().join(',');
                        if (browserWs._containerIdsKey && browserWs._containerIdsKey !== idsKey) return;
                        startLogStreams(browserWs, applicationUuid, latest);
                    } catch {
                        // ignore
                    }
                }, 1000);
                browserWs._restartTimers = browserWs._restartTimers || [];
                browserWs._restartTimers.push(t);
            });

            proc.on('error', (err) => {
                safeJsonSend(browserWs, {
                    type: 'error',
                    error: `Log stream error for ${containerName}: ${err.message}`,
                });
            });
        }
    }

    function startContainerDiscovery(browserWs, applicationUuid) {
        if (browserWs._containerDiscoveryTimer) {
            clearInterval(browserWs._containerDiscoveryTimer);
            browserWs._containerDiscoveryTimer = null;
        }

        const tick = async () => {
            if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
            if (browserWs._discoveryInFlight) return;
            browserWs._discoveryInFlight = true;

            try {
                const containers = await getAppContainers(applicationUuid, true);
                const running = containers.filter((c) => isRunningLike(c.status));
                const idsKey = running.map((c) => c.id).sort().join(',');

                // Avoid spam: hash only stable fields (id/name/status)
                const hash = JSON.stringify(containers.map((c) => ({ id: c.id, name: c.name, status: c.status })));

                if (browserWs._containersHash !== hash) {
                    browserWs._containersHash = hash;
                    safeJsonSend(browserWs, {
                        type: 'containers',
                        containers,
                        timestamp: Date.now(),
                    });
                }

                if (browserWs._containerIdsKey !== idsKey) {
                    browserWs._containerIdsKey = idsKey;
                    startLogStreams(browserWs, applicationUuid, running);
                }
            } finally {
                browserWs._discoveryInFlight = false;
            }
        };

        tick();
        browserWs._containerDiscoveryTimer = setInterval(tick, 3000);
    }

    function handleBrowserAuth(browserWs, message) {
        const session_id = typeof message.session_id === 'string' ? message.session_id.trim() : message.session_id;
        const application_uuid = typeof message.application_uuid === 'string' ? message.application_uuid.trim() : message.application_uuid;

        // Clamp initial history; default to 100 lines per container.
        browserWs.tailLines = clampInt(message.tail, 0, 1000, 100);

        if (!session_id || !application_uuid) {
            safeJsonSend(browserWs, { type: 'auth:failed', error: 'Missing session_id or application_uuid' });
            try { browserWs.close(4001, 'Invalid auth request'); } catch {}
            return;
        }

        if (!isServerConnected()) {
            safeJsonSend(browserWs, { type: 'auth:failed', error: 'Node not connected to server' });
            try { browserWs.close(4002, 'Node not connected'); } catch {}
            return;
        }

        const requestId = `validate_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
        pendingValidations.set(requestId, {
            browserWs,
            applicationUuid: application_uuid,
            sessionId: session_id,
            timestamp: Date.now(),
        });
        schedulePendingCleanup();

        sendToServer('session:validate', {
            request_id: requestId,
            session_id,
            application_uuid,
        });
    }

    function handleBrowserMessage(browserWs, message) {
        switch (message.type) {
            case 'auth':
                handleBrowserAuth(browserWs, message);
                break;
            case 'ping':
                safeJsonSend(browserWs, { type: 'pong' });
                break;
            case 'stats:subscribe':
                handleBrowserStatsSubscribe(browserWs, message);
                break;
            case 'stats:unsubscribe':
                stopStatsPolling(browserWs);
                break;
            case 'files:request':
                handleFilesRequest(browserWs, message);
                break;
            case 'volumes:request':
                handleVolumesRequest(browserWs, message);
                break;
            case 'exec:request':
                handleBrowserExec(browserWs, message);
                break;
            case 'console:input':
                handleBrowserConsoleInput(browserWs, message);
                break;
            default:
                if (!browserWs.authenticated) {
                    safeJsonSend(browserWs, { type: 'error', error: 'Not authenticated' });
                }
        }
    }

    function stopStatsPolling(browserWs) {
        if (!browserWs) return;
        if (browserWs._statsTimer) {
            try { clearInterval(browserWs._statsTimer); } catch {}
            browserWs._statsTimer = null;
        }
        browserWs._statsContainerId = null;
        browserWs._statsInspect = null;
        browserWs._statsPrev = null;
        browserWs._statsLastErrorAt = 0;
    }

    async function handleBrowserStatsSubscribe(browserWs, message) {
        const containerIdRaw = typeof message.container_id === 'string' ? message.container_id.trim() : '';
        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
        if (!browserWs.authenticated) {
            return safeJsonSend(browserWs, { type: 'stats:rejected', error: 'Not authenticated', timestamp: Date.now() });
        }
        if (!hasPerm(browserWs, 'logs', 'read')) {
            return safeJsonSend(browserWs, { type: 'stats:rejected', error: 'Not authorized', timestamp: Date.now() });
        }
        if (!containerIdRaw) {
            return safeJsonSend(browserWs, { type: 'stats:rejected', error: 'Missing container_id', timestamp: Date.now() });
        }

        const appUuid = browserWs.applicationUuid;
        const all = await getAllAppContainers(appUuid);
        const target = findContainerById(all, containerIdRaw);
        if (!target) {
            return safeJsonSend(browserWs, { type: 'stats:rejected', error: 'Container not found for application', timestamp: Date.now() });
        }

        const canonicalContainerId = dockerSafeIdToken(target.id);
        if (!canonicalContainerId) {
            return safeJsonSend(browserWs, { type: 'stats:rejected', error: 'Invalid container id', timestamp: Date.now() });
        }

        stopStatsPolling(browserWs);
        browserWs._statsContainerId = canonicalContainerId;

        try {
            browserWs._statsInspect = await dockerApiJson('GET', `/containers/${canonicalContainerId}/json`);
        } catch {
            browserWs._statsInspect = null;
        }

        const tick = async () => {
            if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
            if (!browserWs._statsContainerId) return;
            const cid = browserWs._statsContainerId;

            let stats;
            try {
                // stream=false returns a single snapshot.
                stats = await dockerApiJson('GET', `/containers/${cid}/stats?stream=false`);
            } catch (e) {
                const now = Date.now();
                // Avoid spamming errors.
                if (!browserWs._statsLastErrorAt || now - browserWs._statsLastErrorAt > 5000) {
                    browserWs._statsLastErrorAt = now;
                    safeJsonSend(browserWs, {
                        type: 'stats',
                        ok: false,
                        container_id: cid,
                        error: e && e.message ? e.message : 'Failed to fetch stats',
                        timestamp: now,
                    });
                }
                return;
            }

            const now = Date.now();
            const cpuStats = stats && stats.cpu_stats ? stats.cpu_stats : null;
            const precpu = stats && stats.precpu_stats ? stats.precpu_stats : null;

            const onlineCpus =
                (cpuStats && Number.isFinite(cpuStats.online_cpus) && cpuStats.online_cpus > 0)
                    ? cpuStats.online_cpus
                    : (cpuStats && cpuStats.cpu_usage && Array.isArray(cpuStats.cpu_usage.percpu_usage) && cpuStats.cpu_usage.percpu_usage.length)
                        ? cpuStats.cpu_usage.percpu_usage.length
                        : null;

            // Compute CPU% using previous sample (preferred) or Docker's precpu fallback.
            const prev = browserWs._statsPrev;
            const curCpuTotal = cpuStats && cpuStats.cpu_usage ? cpuStats.cpu_usage.total_usage : null;
            const curSystem = cpuStats ? cpuStats.system_cpu_usage : null;

            const prevCpuTotal = prev && prev.cpuTotal !== null ? prev.cpuTotal : (precpu && precpu.cpu_usage ? precpu.cpu_usage.total_usage : null);
            const prevSystem = prev && prev.system !== null ? prev.system : (precpu ? precpu.system_cpu_usage : null);
            const cpusForPercent = Number.isFinite(onlineCpus) && onlineCpus > 0 ? onlineCpus : 1;

            let cpuPercent = 0;
            let cpuUsageCores = null;
            if (Number.isFinite(curCpuTotal) && Number.isFinite(curSystem) && Number.isFinite(prevCpuTotal) && Number.isFinite(prevSystem)) {
                const cpuDelta = curCpuTotal - prevCpuTotal;
                const sysDelta = curSystem - prevSystem;
                if (sysDelta > 0 && cpuDelta >= 0) {
                    cpuPercent = (cpuDelta / sysDelta) * cpusForPercent * 100;
                    cpuUsageCores = (cpuDelta / sysDelta) * cpusForPercent;
                }
            }

            const memStats = stats && stats.memory_stats ? stats.memory_stats : null;
            const memUsage = memStats && Number.isFinite(memStats.usage) ? memStats.usage : null;
            const memLimitFromStats = memStats && Number.isFinite(memStats.limit) ? memStats.limit : null;
            const memLimitFromInspect = browserWs._statsInspect && browserWs._statsInspect.HostConfig && Number.isFinite(browserWs._statsInspect.HostConfig.Memory)
                ? browserWs._statsInspect.HostConfig.Memory
                : null;
            const memLimit = memLimitFromInspect && memLimitFromInspect > 0 ? memLimitFromInspect : memLimitFromStats;

            // Network totals (bytes) -> derive per-second rate.
            let rxBytes = 0;
            let txBytes = 0;
            const nets = stats && stats.networks && typeof stats.networks === 'object' ? stats.networks : null;
            if (nets) {
                for (const v of Object.values(nets)) {
                    if (!v) continue;
                    if (Number.isFinite(v.rx_bytes)) rxBytes += v.rx_bytes;
                    if (Number.isFinite(v.tx_bytes)) txBytes += v.tx_bytes;
                }
            }

            let rxBps = null;
            let txBps = null;
            if (prev && Number.isFinite(prev.rxBytes) && Number.isFinite(prev.txBytes) && Number.isFinite(prev.ts)) {
                const dt = (now - prev.ts) / 1000;
                if (dt > 0) {
                    rxBps = Math.max(0, (rxBytes - prev.rxBytes) / dt);
                    txBps = Math.max(0, (txBytes - prev.txBytes) / dt);
                }
            }

            const cpuLimitCores = computeCpuLimitCores(browserWs._statsInspect, onlineCpus);

            browserWs._statsPrev = {
                ts: now,
                cpuTotal: Number.isFinite(curCpuTotal) ? curCpuTotal : null,
                system: Number.isFinite(curSystem) ? curSystem : null,
                rxBytes,
                txBytes,
            };

            safeJsonSend(browserWs, {
                type: 'stats',
                ok: true,
                container_id: cid,
                cpu_percent: Number.isFinite(cpuPercent) ? cpuPercent : 0,
                cpu_usage_cores: Number.isFinite(cpuUsageCores) ? cpuUsageCores : null,
                cpu_limit_cores: Number.isFinite(cpuLimitCores) ? cpuLimitCores : null,
                mem_usage_bytes: Number.isFinite(memUsage) ? memUsage : null,
                mem_limit_bytes: Number.isFinite(memLimit) ? memLimit : null,
                net_rx_bps: Number.isFinite(rxBps) ? rxBps : null,
                net_tx_bps: Number.isFinite(txBps) ? txBps : null,
                timestamp: now,
            });
        };

        // Immediate sample, then poll.
        tick().catch(() => {});
        browserWs._statsTimer = setInterval(() => {
            tick().catch(() => {});
        }, 1000);

        safeJsonSend(browserWs, {
            type: 'stats:subscribed',
            container_id: canonicalContainerId,
            container: target.name,
            timestamp: Date.now(),
        });
    }

    async function handleBrowserConsoleInput(browserWs, message) {
        const requestId = typeof message.request_id === 'string' ? message.request_id.trim() : '';
        const containerId = typeof message.container_id === 'string' ? message.container_id.trim() : '';
        const commandRaw = typeof message.command === 'string' ? message.command : '';

        const reject = (err) => {
            safeJsonSend(browserWs, {
                type: 'console:rejected',
                request_id: requestId || undefined,
                container_id: containerId || undefined,
                error: err,
                timestamp: Date.now(),
            });
        };

        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
        if (!browserWs.authenticated) return reject('Not authenticated');
        if (!requestId || !containerId) return reject('Missing request_id or container_id');
        if (!checkConsoleRateLimit(browserWs)) return reject('Rate limit exceeded');

        const validated = validateConsoleInput(commandRaw);
        if (!validated.valid) return reject(validated.error);
        const command = validated.value;

        // Scope: only allow console input to containers for this application.
        const appUuid = browserWs.applicationUuid;
        const allowed = await getRunningContainers(appUuid);
        const target = findContainerById(allowed, containerId);
        if (!target) return reject('Container not found for application');
        if (target.status !== 'running') return reject('Container is not running');

        const canonicalContainerId = String(target.id || '').trim();
        if (!canonicalContainerId) return reject('Invalid container id');

        // If stdin isn't open, docker attach can connect but input won't reach PID 1.
        // Give a clear actionable error.
        const stdinCfg = await dockerInspectStdinConfig(canonicalContainerId);
        if (stdinCfg && stdinCfg.openStdin === false) {
            return reject('Container stdin is not open; redeploy with compose `stdin_open: true` (node now sets this by default for new deploys).');
        }

        browserWs._consoleSessions = browserWs._consoleSessions || new Map();
        let session = browserWs._consoleSessions.get(canonicalContainerId);

        if (!session || !session.socket || session.socket.destroyed) {
            try {
                // Use Docker Engine API attach (hijacked HTTP) instead of `docker attach` CLI.
                // This avoids the common CLI error from a non-TTY Node process: "the input device is not a TTY".
                const { socket } = await openDockerHijackedConnection({
                    method: 'POST',
                    pathAndQuery: `/containers/${canonicalContainerId}/attach?stream=1&stdin=1&stdout=0&stderr=0&logs=0`,
                });

                session = { socket, containerName: target.name };
                browserWs._consoleSessions.set(canonicalContainerId, session);

                socket.on('close', () => {
                    const s = browserWs._consoleSessions && browserWs._consoleSessions.get(canonicalContainerId);
                    if (s && s.socket === socket) {
                        s.socket = null;
                    }
                    safeJsonSend(browserWs, {
                        type: 'console:status',
                        container: target.name,
                        container_id: canonicalContainerId,
                        status: 'disconnected',
                        timestamp: Date.now(),
                    });
                });

                socket.on('error', (err) => {
                    safeJsonSend(browserWs, {
                        type: 'console:error',
                        request_id: requestId,
                        container: target.name,
                        container_id: canonicalContainerId,
                        error: err.message,
                        timestamp: Date.now(),
                    });
                });

                safeJsonSend(browserWs, {
                    type: 'console:status',
                    container: target.name,
                    container_id: canonicalContainerId,
                    status: 'connected',
                    timestamp: Date.now(),
                });
            } catch (e) {
                return reject(e && e.message ? e.message : 'Failed to connect to container console');
            }
        }

        // For TTY containers, terminal line discipline expects Enter as CR.
        const lineEnd = stdinCfg && stdinCfg.tty ? '\r' : '\n';
        try {
            session.socket.write(command + lineEnd);
        } catch {
            return reject('Failed to write to container console (connection closed)');
        }

        safeJsonSend(browserWs, {
            type: 'console:ack',
            request_id: requestId,
            container: target.name,
            container_id: canonicalContainerId,
            timestamp: Date.now(),
        });
    }

    async function handleBrowserExec(browserWs, message) {
        const requestId = typeof message.request_id === 'string' ? message.request_id.trim() : '';
        const containerId = typeof message.container_id === 'string' ? message.container_id.trim() : '';
        const command = typeof message.command === 'string' ? message.command.trim() : '';

        const reject = (err) => {
            safeJsonSend(browserWs, {
                type: 'exec:rejected',
                request_id: requestId || undefined,
                container_id: containerId || undefined,
                error: err,
                timestamp: Date.now(),
            });
        };

        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
        if (!browserWs.authenticated) return reject('Not authenticated');
        if (!requestId || !containerId || !command) return reject('Missing request_id, container_id, or command');
        if (browserWs._execInFlight) return reject('Exec already running');
        if (!checkExecRateLimit(browserWs)) return reject('Rate limit exceeded');

        // Secondary validation: existing policy blocks obvious dangerous patterns.
        const validation = security.validateExecCommand(command);
        if (!validation.valid) return reject(`Security: ${validation.error}`);

        let argv;
        try {
            argv = tokenizeExecCommand(command);
        } catch (e) {
            return reject(e.message || 'Invalid command');
        }

        // Scope: only allow exec into containers for this application.
        const appUuid = browserWs.applicationUuid;
        const allowed = await getRunningContainers(appUuid);
        const target = findContainerById(allowed, containerId);
        if (!target) return reject('Container not found for application');
        if (target.status !== 'running') return reject('Container is not running');

        const canonicalContainerId = String(target.id || '').trim();
        if (!canonicalContainerId) return reject('Invalid container id');

        browserWs._execInFlight = true;
        const startedAt = Date.now();

        const proc = spawn('docker', ['exec', '-i', canonicalContainerId, ...argv], {
            stdio: ['ignore', 'pipe', 'pipe'],
        });

        browserWs._execProc = proc;
        browserWs._execRequestId = requestId;

        let stdout = '';
        let stderr = '';
        let stdoutBytes = 0;
        let stderrBytes = 0;
        let truncated = false;
        let timedOut = false;

        const append = (which, chunk) => {
            if (!chunk) return;
            const buf = Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk));
            const len = buf.length;

            const totalBytes = stdoutBytes + stderrBytes;
            if (totalBytes >= EXEC_LIMIT.maxTotalBytes) {
                truncated = true;
                return;
            }

            if (which === 'stdout') {
                if (stdoutBytes >= EXEC_LIMIT.maxStdoutBytes) {
                    truncated = true;
                    return;
                }
                const allowedLen = Math.min(len, EXEC_LIMIT.maxStdoutBytes - stdoutBytes, EXEC_LIMIT.maxTotalBytes - totalBytes);
                stdoutBytes += allowedLen;
                stdout += buf.subarray(0, allowedLen).toString();
                if (allowedLen < len) truncated = true;
            } else {
                if (stderrBytes >= EXEC_LIMIT.maxStderrBytes) {
                    truncated = true;
                    return;
                }
                const allowedLen = Math.min(len, EXEC_LIMIT.maxStderrBytes - stderrBytes, EXEC_LIMIT.maxTotalBytes - totalBytes);
                stderrBytes += allowedLen;
                stderr += buf.subarray(0, allowedLen).toString();
                if (allowedLen < len) truncated = true;
            }
        };

        const timer = setTimeout(() => {
            timedOut = true;
            try { proc.kill('SIGKILL'); } catch {}
        }, EXEC_LIMIT.maxDurationMs);

        proc.stdout.on('data', (chunk) => append('stdout', chunk));
        proc.stderr.on('data', (chunk) => append('stderr', chunk));

        proc.on('error', (err) => {
            clearTimeout(timer);
            browserWs._execInFlight = false;
            browserWs._execProc = null;
            safeJsonSend(browserWs, {
                type: 'exec:result',
                request_id: requestId,
                container_id: containerId,
                container: target.name,
                ok: false,
                exit_code: null,
                signal: null,
                duration_ms: Date.now() - startedAt,
                stdout: stdout,
                stderr: stderr,
                truncated,
                error: err.message,
                timestamp: Date.now(),
            });
        });

        proc.on('close', (code, signal) => {
            clearTimeout(timer);
            browserWs._execInFlight = false;
            browserWs._execProc = null;

            safeJsonSend(browserWs, {
                type: 'exec:result',
                request_id: requestId,
                container_id: containerId,
                container: target.name,
                ok: !timedOut && code === 0,
                exit_code: typeof code === 'number' ? code : null,
                signal: signal || null,
                duration_ms: Date.now() - startedAt,
                stdout: stdout,
                stderr: stderr,
                truncated,
                error: timedOut ? 'Timeout' : null,
                timestamp: Date.now(),
            });
        });
    }

    function handleSessionValidateResponse(message) {
        const requestId = message.request_id;
        const pending = pendingValidations.get(requestId);
        if (!pending) return;
        pendingValidations.delete(requestId);

        const { browserWs, applicationUuid } = pending;
        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;

        if (!message.authorized) {
            safeJsonSend(browserWs, { type: 'auth:failed', error: message.error || 'Authentication failed' });
            try { browserWs.close(4001, 'Authentication failed'); } catch {}
            return;
        }

        browserWs.authenticated = true;
        browserWs.applicationUuid = applicationUuid;
        browserWs.userId = message.user_id;
        browserWs.teamId = message.team_id;
        browserWs.perms = message.perms && typeof message.perms === 'object' ? message.perms : null;
        if (browserWs.authTimeout) {
            clearTimeout(browserWs.authTimeout);
            browserWs.authTimeout = null;
        }

        safeJsonSend(browserWs, { type: 'auth:success', message: 'Authenticated successfully' });
        startContainerDiscovery(browserWs, applicationUuid);
    }

    function start() {
        if (browserWss) return;

        const useSSL =
            config.browserWsSslCert &&
            config.browserWsSslKey &&
            fs.existsSync(config.browserWsSslCert) &&
            fs.existsSync(config.browserWsSslKey);

        if (useSSL) {
            const https = require('https');
            const httpsServer = https.createServer({
                cert: fs.readFileSync(config.browserWsSslCert),
                key: fs.readFileSync(config.browserWsSslKey),
            });
            browserWss = new WebSocketServer({ server: httpsServer });
            httpsServer.listen(config.browserWsPort, config.browserWsHost, () => {
                console.log(
                    `[BrowserWS] Secure WSS server started on wss://${config.browserWsHost}:${config.browserWsPort}`
                );
            });
        } else {
            browserWss = new WebSocketServer({
                port: config.browserWsPort,
                host: config.browserWsHost,
            });
            console.log(`[BrowserWS] Server started on ws://${config.browserWsHost}:${config.browserWsPort} (no SSL)`);
        }

        // Ping-frame heartbeat
        heartbeatInterval = setInterval(() => {
            if (!browserWss) return;
            for (const client of browserWss.clients) {
                if (client.isAlive === false) {
                    try { client.terminate(); } catch {}
                    continue;
                }
                client.isAlive = false;
                try { client.ping(); } catch {}
            }
        }, 30000);

        // Cleanup leaked volume-fs helpers from previous runs.
        scheduleVolumeFsOrphanSweep();

        browserWss.on('connection', (browserWs, req) => {
            const clientIp = req.socket.remoteAddress;
            browserWs.isAlive = true;
            browserWs.authenticated = false;
            browserWs.applicationUuid = null;
            browserWs.logProcesses = [];
            browserWs._restartTimers = [];

            // Exec control
            browserWs._execInFlight = false;
            browserWs._execProc = null;
            browserWs._execRequestId = null;
            browserWs._execWindowStart = 0;
            browserWs._execWindowCount = 0;

            // Console input (docker attach) sessions
            browserWs._consoleSessions = new Map();
            browserWs._consoleWindowStart = 0;
            browserWs._consoleWindowCount = 0;

            browserWs.on('pong', () => {
                browserWs.isAlive = true;
            });

            browserWs.authTimeout = setTimeout(() => {
                if (!browserWs.authenticated) {
                    try { browserWs.close(4000, 'Authentication timeout'); } catch {}
                }
            }, 10000);

            browserWs.on('message', (data) => {
                try {
                    const message = JSON.parse(data.toString());
                    handleBrowserMessage(browserWs, message);
                } catch {
                    safeJsonSend(browserWs, { type: 'error', error: 'Invalid message format' });
                }
            });

            browserWs.on('close', () => {
                if (browserWs.authTimeout) {
                    clearTimeout(browserWs.authTimeout);
                    browserWs.authTimeout = null;
                }
                if (browserWs._execProc) {
                    try { browserWs._execProc.kill('SIGKILL'); } catch {}
                    browserWs._execProc = null;
                }

                if (browserWs._consoleSessions && browserWs._consoleSessions.size) {
                    for (const [, s] of browserWs._consoleSessions) {
                        if (!s || !s.socket) continue;
                        try { s.socket.destroy(); } catch {}
                    }
                    try { browserWs._consoleSessions.clear(); } catch {}
                }

                // Stop any in-flight volume file transfers that might keep helper containers alive.
                cleanupVolumeFileTransfersForWs(browserWs);

                // Stop per-connection helper containers (volume filesystem).
                cleanupVolumeFsHelpers(browserWs).catch(() => {});

                // Stop stats polling.
                stopStatsPolling(browserWs);

                stopAllLogProcesses(browserWs);
                if (browserWs._containerDiscoveryTimer) {
                    clearInterval(browserWs._containerDiscoveryTimer);
                    browserWs._containerDiscoveryTimer = null;
                }
                console.log(`[BrowserWS] Connection closed for ${clientIp}`);
            });

            browserWs.on('error', (err) => {
                console.error(`[BrowserWS] Error for ${clientIp}:`, err.message);
            });
        });

        browserWss.on('error', (err) => {
            console.error('[BrowserWS] Server error:', err);
        });
    }

    function close() {
        if (pendingCleanupInterval) {
            clearInterval(pendingCleanupInterval);
            pendingCleanupInterval = null;
        }
        if (heartbeatInterval) {
            clearInterval(heartbeatInterval);
            heartbeatInterval = null;
        }
        if (volumeFsOrphanSweepInterval) {
            clearInterval(volumeFsOrphanSweepInterval);
            volumeFsOrphanSweepInterval = null;
        }
        if (browserWss) {
            try {
                for (const client of browserWss.clients) {
                    try { client.close(1000, 'Server shutdown'); } catch {}
                }
            } catch {}
            try { browserWss.close(); } catch {}
            browserWss = null;
        }

        // Best-effort cleanup of any remaining tracked helpers.
        try {
            for (const entry of volumeFsHelperPool.values()) {
                if (!entry || !entry.id) continue;
                execCommand(`docker rm -f ${entry.id}`).catch(() => {});
            }
        } catch {}
        try { volumeFsHelperPool.clear(); } catch {}
    }

    function handleServerMessage(message) {
        if (!message || !message.type) return;
        if (message.type === 'session:validate:response') {
            handleSessionValidateResponse(message);
        }
    }

    return { start, close, handleServerMessage };
}

module.exports = {
    createLiveLogsWs,
};

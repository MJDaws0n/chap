/**
 * Live Logs Browser WebSocket server
 *
 * Browsers connect directly to the node on WS/WSS, authenticate via session validation
 * forwarded to the Chap PHP server, then receive container list + live docker logs.
 */

const WebSocket = require('ws');
const { WebSocketServer } = require('ws');
const { spawn } = require('child_process');
const fs = require('fs');
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
    if (s.toLowerCase().startsWith('up')) return 'running';
    if (s.toLowerCase().startsWith('exited')) return 'stopped';
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
 */
function createLiveLogsWs(deps) {
    const { config, sendToServer, isServerConnected, execCommand, safeId } = deps;

    let browserWss = null;
    let heartbeatInterval = null;
    let pendingCleanupInterval = null;

    // Map of requestId -> { browserWs, applicationUuid, sessionId, timestamp }
    const pendingValidations = new Map();

    // Upload transfer state: transferId -> { containerId, path, size, received, chunks: Buffer[] }
    const uploads = new Map();

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
        const allowed = await getRunningContainers(appUuid);
        const running = allowed.filter((c) => c.status === 'running');
        if (!running.length) return { containers: allowed, container: null };
        if (requestedContainerId) {
            const found = running.find((c) => c.id === requestedContainerId);
            if (found) return { containers: allowed, container: found };
        }
        return { containers: allowed, container: running[0] };
    }

    async function handleFilesRequest(browserWs, message) {
        const requestId = typeof message.request_id === 'string' ? message.request_id.trim() : '';
        const action = typeof message.action === 'string' ? message.action.trim() : '';
        const payload = message.payload && typeof message.payload === 'object' ? message.payload : {};

        if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
        if (!browserWs.authenticated) return filesReply(browserWs, requestId, false, 'Not authenticated');
        if (!requestId || !action) return filesReply(browserWs, requestId, false, 'Missing request_id or action');
        if (!checkFilesRateLimit(browserWs)) return filesReply(browserWs, requestId, false, 'Rate limit exceeded');

        try {
            const requestedContainerId = typeof payload.container_id === 'string' ? payload.container_id.trim() : '';
            const { containers, container } = await resolveContainerForFiles(browserWs, requestedContainerId);
            if (!container) return filesReply(browserWs, requestId, false, 'No running containers found');

            try {
                console.log(`[BrowserWS] files:${action} app=${browserWs.applicationUuid} container=${container.id}`);
            } catch {}

            browserWs.selectedContainerId = container.id;
            const persistentPrefixes = await runDockerInspectMounts(container.id);
            const root = '/';

            if (action === 'meta') {
                return filesReply(browserWs, requestId, true, {
                    root,
                    default_path: '/',
                    containers,
                    selected_container_id: container.id,
                    persistent_prefixes: persistentPrefixes,
                });
            }

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
                uploads.set(transferId, { containerId: container.id, path: `${dir}/${name}`, size, received: 0, chunks: [] });
                return filesReply(browserWs, requestId, true, { transfer_id: transferId, chunk_size: 128 * 1024 });
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
                safeJsonSend(browserWs, { type: 'files:download:start', transfer_id: transferId, name: p.split('/').pop() || 'download', mime: 'application/octet-stream' });

                const proc = spawn('docker', ['exec', container.id, 'cat', '--', p], { stdio: ['ignore', 'pipe', 'pipe'] });
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
                    if (!browserWs || browserWs.readyState !== WebSocket.OPEN) return;
                    if ((code ?? 0) !== 0) {
                        safeJsonSend(browserWs, { type: 'error', error: 'Download failed' });
                        return;
                    }
                    safeJsonSend(browserWs, { type: 'files:download:done', transfer_id: transferId, name: p.split('/').pop() || 'download' });
                });

                return filesReply(browserWs, requestId, true, { transfer_id: transferId });
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
        const namePrefix = `chap-${safeId(applicationUuid)}`.replace(/\s+/g, '');
        try {
            const out = await execCommand(
                `docker ps --filter "name=${namePrefix}" --format "{{.ID}}|{{.Names}}|{{.Status}}"`
            );
            const lines = out.trim().split('\n').filter(Boolean);
            return lines
                .map((line) => {
                    const [id, name, statusRaw] = line.split('|');
                    const idTrim = (id || '').trim();
                    const nameTrim = (name || '').trim();
                    const status = normalizeStatus(statusRaw);
                    return {
                        id: idTrim,
                        name: nameTrim,
                        status,
                    };
                })
                .filter((c) => c.id && c.name);
        } catch (err) {
            return [];
        }
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

        // Publish container list once per change
        safeJsonSend(browserWs, {
            type: 'containers',
            containers,
            timestamp: Date.now(),
        });

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
                        const latest = await getRunningContainers(applicationUuid);
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
                const containers = await getRunningContainers(applicationUuid);
                const idsKey = containers.map((c) => c.id).sort().join(',');

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
                    startLogStreams(browserWs, applicationUuid, containers);
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
            case 'files:request':
                handleFilesRequest(browserWs, message);
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
        const target = allowed.find((c) => c.id === containerId);
        if (!target) return reject('Container not found for application');
        if (target.status !== 'running') return reject('Container is not running');

        browserWs._consoleSessions = browserWs._consoleSessions || new Map();
        let session = browserWs._consoleSessions.get(containerId);

        if (!session || !session.proc) {
            // Start an attach session so we can write to stdin.
            // This requires the container to be started with stdin open (docker run -i or compose stdin_open).
            const proc = spawn('docker', ['attach', '--sig-proxy=false', containerId], {
                stdio: ['pipe', 'pipe', 'pipe'],
            });

            const emit = (stream) => (line) => {
                if (!line) return;
                safeJsonSend(browserWs, {
                    type: 'console:output',
                    stream,
                    container: target.name,
                    container_id: containerId,
                    content: line,
                    timestamp: Date.now(),
                });
            };

            const emitStdout = createLineEmitter(emit('stdout'));
            const emitStderr = createLineEmitter(emit('stderr'));

            proc.stdout.on('data', (chunk) => {
                const buf = Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk));
                emitStdout(buf.subarray(0, CONSOLE_LIMIT.maxChunkBytes));
            });
            proc.stderr.on('data', (chunk) => {
                const buf = Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk));
                emitStderr(buf.subarray(0, CONSOLE_LIMIT.maxChunkBytes));
            });

            proc.on('error', (err) => {
                safeJsonSend(browserWs, {
                    type: 'console:error',
                    request_id: requestId,
                    container: target.name,
                    container_id: containerId,
                    error: err.message,
                    timestamp: Date.now(),
                });
            });

            proc.on('close', (code, signal) => {
                const s = browserWs._consoleSessions && browserWs._consoleSessions.get(containerId);
                if (s) {
                    s.proc = null;
                }
                safeJsonSend(browserWs, {
                    type: 'console:status',
                    container: target.name,
                    container_id: containerId,
                    status: 'disconnected',
                    exit_code: typeof code === 'number' ? code : null,
                    signal: signal || null,
                    timestamp: Date.now(),
                });
            });

            session = { proc, containerName: target.name };
            browserWs._consoleSessions.set(containerId, session);

            safeJsonSend(browserWs, {
                type: 'console:status',
                container: target.name,
                container_id: containerId,
                status: 'connected',
                timestamp: Date.now(),
            });
        }

        // Send the command to stdin of the attached process.
        try {
            session.proc.stdin.write(command + '\n');
        } catch (e) {
            return reject('Failed to write to container console (is stdin open?)');
        }

        safeJsonSend(browserWs, {
            type: 'console:ack',
            request_id: requestId,
            container: target.name,
            container_id: containerId,
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
        const target = allowed.find((c) => c.id === containerId);
        if (!target) return reject('Container not found for application');
        if (target.status !== 'running') return reject('Container is not running');

        browserWs._execInFlight = true;
        const startedAt = Date.now();

        const proc = spawn('docker', ['exec', '-i', containerId, ...argv], {
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
                        if (!s || !s.proc) continue;
                        try { s.proc.kill('SIGKILL'); } catch {}
                    }
                    try { browserWs._consoleSessions.clear(); } catch {}
                }
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
        if (browserWss) {
            try {
                for (const client of browserWss.clients) {
                    try { client.close(1000, 'Server shutdown'); } catch {}
                }
            } catch {}
            try { browserWss.close(); } catch {}
            browserWss = null;
        }
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

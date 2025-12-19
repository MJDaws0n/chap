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
            default:
                if (!browserWs.authenticated) {
                    safeJsonSend(browserWs, { type: 'error', error: 'Not authenticated' });
                }
        }
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

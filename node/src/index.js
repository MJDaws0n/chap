/**
 * Chap Node Agent
 * Connects to the Chap server via WebSocket and manages Docker containers
 * version pre-rc-1.1
 */

const WebSocket = require('ws');
const { spawn, exec, execSync } = require('child_process');
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
    browserWsSslKey: process.env.BROWSER_WS_SSL_KEY || null
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
        await execCommand(
            `git -c http.extraHeader=\"${header}\" clone --branch ${branch} ${safeRepoUrl} ${destDir}`,
            { redact: redactions }
        );
        return;
    }

    await execCommand(`git clone --branch ${branch} ${safeRepoUrl} ${destDir}`);
}

async function gitUpdate({ repoDir, repoUrl, branch, authToken }) {
    // We prefer updating via origin for normal cases; for authenticated attempts we
    // use explicit repoUrl so we don't need to rewrite remotes.
    if (authToken) {
        const safeRepoUrl = normalizeGitUrlForHttp(repoUrl);
        const { header, redactions } = gitAuthExtraHeaderFromToken(authToken);

        await execCommand(`git -c http.extraHeader=\"${header}\" fetch ${safeRepoUrl} ${branch}`, {
            cwd: repoDir,
            redact: redactions,
        });

        try {
            await execCommand(`git checkout ${branch}`, { cwd: repoDir, redact: redactions });
        } catch {
            await execCommand(`git checkout -B ${branch}`, { cwd: repoDir, redact: redactions });
        }

        await execCommand(`git -c http.extraHeader=\"${header}\" pull ${safeRepoUrl} ${branch}`, {
            cwd: repoDir,
            redact: redactions,
        });
        return;
    }

    await execCommand(`git fetch origin`, { cwd: repoDir });
    try {
        await execCommand(`git checkout ${branch}`, { cwd: repoDir });
    } catch {
        await execCommand(`git checkout -B ${branch}`, { cwd: repoDir });
    }
    await execCommand(`git pull origin ${branch}`, { cwd: repoDir });
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
            await execCommand(`rm -rf ${repoDir}`);
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
let reconnectTimer = null;
let isConnected = false;

// Live logs WebSocket server (browser -> node)
let liveLogsWs = null;

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
    console.log(`[Agent] Received: ${message.type}`);
    
    switch (message.type) {
        case 'server:auth:success':
            console.log('[Agent] Successfully authenticated with server');
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
            console.log('[Agent] Server heartbeat acknowledged');
            break;
        
        case 'app:event':
            // Log application-related events sent by the server for debugging
            console.log('[Agent] App event from server:', JSON.stringify(message.payload, null, 2));
            break;
            
        case 'task:deploy':
            handleDeploy(message);
            break;
            
        case 'container:stop':
            handleStop(message);
            break;
            
        case 'container:restart':
            handleRestart(message);
            break;
            
        case 'application:delete':
            handleApplicationDelete(message);
            break;

        case 'port:check':
            handlePortCheck(message);
            break;
            
        case 'container:logs':
            //handleLogs(message);
            break;
            
        case 'container:exec':
            handleExec(message);
            break;
            
        case 'image:pull':
            handlePull(message);
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

async function handlePortCheck(message) {
    const payload = message.payload || {};
    const requestId = payload.request_id || payload.requestId;
    const port = parseInt(payload.port, 10);

    if (!requestId || !Number.isInteger(port) || port < 1 || port > 65535) {
        send('port:check:response', {
            payload: { request_id: requestId || '', port: payload.port || null, free: false, error: 'Invalid request' }
        });
        return;
    }

    let free = false;
    let lastError = null;

    for (const image of PORTCHECK_IMAGES) {
        try {
            // This tests OS-level port availability by asking Docker to bind the host port.
            // If the port is already in use (by any process), Docker will fail to start.
            await execCommand(`docker run --rm -p ${port}:1 ${image} node -e "process.exit(0)"`, { timeout: 15000 });
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
        const raw = String(s).trim();
        const parts = raw.split(':');
        if (parts.length === 1) {
            return { published: null, target: parts[0] };
        }

        const right = parts[parts.length - 1];
        const host = parts[parts.length - 2];
        const hostPort = parseInt(String(host).split('/')[0], 10);
        const targetPort = parseInt(String(right).split('/')[0], 10);

        return {
            published: Number.isInteger(hostPort) ? hostPort : null,
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
                    published = parseInt(entry.published, 10);
                }

                if (!Number.isInteger(published)) {
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
        
        let imageName = '';
        
        // DEBUG: Log the entire appConfig to see what we're receiving
        console.log(`[Agent] 🔍 DEBUG appConfig:`, JSON.stringify(appConfig, null, 2));
        
        // Determine build type from application config
        const buildType = appConfig.build_pack || appConfig.type || 'docker';
        console.log(`[Agent] 📦 Build type: ${buildType}`);
        console.log(`[Agent] 🔍 DEBUG - build_pack value: "${appConfig.build_pack}"`);
        console.log(`[Agent] 🔍 DEBUG - type value: "${appConfig.type}"`);
        sendLog(deploymentId, `Build type: ${buildType}`, 'info');
        
        // Build or pull image based on type
        if (buildType === 'compose' || buildType === 'docker-compose') {
            await deployCompose(deploymentId, appConfig);
            return;
        } else if (buildType === 'git') {
            imageName = await buildFromGit(deploymentId, appConfig);
        } else if (buildType === 'dockerfile') {
            imageName = await buildFromDockerfile(deploymentId, appConfig);
        } else if (buildType === 'docker' || appConfig.docker_image) {
            imageName = appConfig.docker_image || appConfig.dockerImage;
            await pullImage(deploymentId, imageName);
        } else {
            throw new Error(`Unknown build type: ${buildType}`);
        }
        
        // Deploy container
        console.log(`[Agent] 🚀 Deploying container...`);
        sendLog(deploymentId, '🚀 Starting container...', 'info');
        
        // Stop existing container if any
        console.log(`[Agent] 🛑 Stopping existing container (if any)...`);
        await stopContainer(`chap-${safeId(applicationId)}`);
        
        // Run new container
        console.log(`[Agent] 🏃 Running new container...`);
        const containerId = await runContainer(deploymentId, applicationId, imageName, appConfig);
        console.log(`[Agent] ✓ Container started: ${containerId}`);
        
        // Send completion
        send('task:complete', {
            payload: {
                deployment_id: deploymentId,
                container_id: containerId
            }
        });
        
        console.log('\n' + '='.repeat(60));
        console.log(`[Agent] ✅ DEPLOYMENT COMPLETED SUCCESSFULLY`);
        console.log(`[Agent] Deployment ID: ${deploymentId}`);
        console.log(`[Agent] Container ID: ${containerId}`);
        console.log('='.repeat(60) + '\n');
        sendLog(deploymentId, '✅ Deployment completed successfully!', 'info');
        
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

/**
 * Build image from Git repository (with security validation)
 */
async function buildFromGit(deploymentId, appConfig) {
    const applicationId = appConfig.uuid || appConfig.applicationId;
    const repoDir = storage.getRepoDir(applicationId);
    const buildDir = storage.getBuildDir(applicationId, deploymentId);
    
    // Support both snake_case (from server) and camelCase field names
    const gitRepository = appConfig.git_repository || appConfig.gitRepository;
    const gitBranch = appConfig.git_branch || appConfig.gitBranch || 'main';
    const buildContext = appConfig.build_context || appConfig.baseDirectory || '';
    const dockerfilePath = appConfig.dockerfile_path || appConfig.dockerfilePath || 'Dockerfile';
    
    security.auditLog('build_from_git_start', { applicationId, deploymentId, gitRepository, gitBranch });
    
    // Clone or update repository
    console.log(`[Agent] 📥 Fetching repository: ${gitRepository}`);
    console.log(`[Agent] 🌿 Branch: ${gitBranch}`);
    sendLog(deploymentId, `📥 Fetching repository: ${gitRepository}`, 'info');
    sendLog(deploymentId, `Branch: ${gitBranch}`, 'info');
    
    // Clone or update repository; if private, try each GitHub App before failing.
    const authAttempts = appConfig.git_auth_attempts || [];
    sendLog(deploymentId, `Git auth attempts available: ${Array.isArray(authAttempts) ? authAttempts.length : 0}`, 'info');
    if (fs.existsSync(path.join(repoDir, '.git'))) {
        console.log(`[Agent] 🔄 Repository exists, updating...`);
        sendLog(deploymentId, '🔄 Repository exists, updating...', 'info');
    } else {
        console.log(`[Agent] 📥 Cloning repository...`);
    }

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
    
    // Copy to build directory
    sendLog(deploymentId, 'Preparing build context...', 'info');
    await execCommand(`cp -r ${repoDir}/. ${buildDir}/`);
    
    // Change to base directory if specified
    const contextDir = path.join(buildDir, buildContext);
    
    // Security: Validate Dockerfile if it exists
    const dockerfileFull = path.join(contextDir, dockerfilePath);
    if (fs.existsSync(dockerfileFull)) {
        const dockerfileContent = fs.readFileSync(dockerfileFull, 'utf8');
        const validation = security.validateDockerfile(dockerfileContent);
        if (!validation.valid) {
            console.error(`[Agent] ❌ Security: ${validation.error}`);
            sendLog(deploymentId, `❌ Security validation failed: ${validation.error}`, 'error');
            throw new Error(`Security: ${validation.error}`);
        }
        console.log(`[Agent] 🔒 Dockerfile security validated`);
        sendLog(deploymentId, '🔒 Dockerfile security validated', 'info');
    }
    
    // Run install command if specified (in sandboxed way - limited commands)
    if (appConfig.installCommand) {
        console.log(`[Agent] 📦 Running install command: ${appConfig.installCommand}`);
        sendLog(deploymentId, `📦 Running install: ${appConfig.installCommand}`, 'info');
        const installOutput = await execCommand(appConfig.installCommand, { cwd: contextDir });
        console.log(`[Agent] ✓ Install completed`);
        sendLog(deploymentId, '✓ Install completed', 'info');
    }
    
    // Run build command if specified
    if (appConfig.buildCommand) {
        console.log(`[Agent] 🔨 Running build command: ${appConfig.buildCommand}`);
        sendLog(deploymentId, `🔨 Running build: ${appConfig.buildCommand}`, 'info');
        const buildOutput = await execCommand(appConfig.buildCommand, { cwd: contextDir });
        console.log(`[Agent] ✓ Build completed`);
        sendLog(deploymentId, '✓ Build completed', 'info');
    }
    
    // Build Docker image with resource limits
    const imageName = `chap-app-${safeId(applicationId)}:${deploymentId}`;
    
    console.log(`[Agent] 🐳 Building Docker image: ${imageName}`);
    sendLog(deploymentId, `🐳 Building Docker image: ${imageName}`, 'info');
    sendLog(deploymentId, `Using Dockerfile: ${dockerfilePath}`, 'info');
    
    // Security: Build with resource limits and no cache for untrusted code
    const buildCmd = `docker build --memory=${security.SECURITY_CONFIG.maxMemory} --cpu-period=100000 --cpu-quota=200000 -t ${imageName} -f ${dockerfilePath} .`;
    await execCommand(buildCmd, { cwd: contextDir });
    console.log(`[Agent] ✓ Docker image built successfully`);
    sendLog(deploymentId, '✓ Docker image built successfully', 'info');
    
    // Clean old builds but keep recent ones
    storage.cleanOldBuilds(applicationId, 3);
    
    security.auditLog('build_from_git_complete', { applicationId, deploymentId, imageName });
    
    return imageName;
}

/**
 * Build image from Dockerfile (with security validation)
 */
async function buildFromDockerfile(deploymentId, appConfig) {
    const applicationId = appConfig.uuid || appConfig.applicationId;
    const buildDir = storage.getBuildDir(applicationId, deploymentId);
    const imageName = `chap-app-${safeId(applicationId)}:${deploymentId}`;
    
    security.auditLog('build_from_dockerfile_start', { applicationId, deploymentId });
    
    if (appConfig.dockerfile) {
        // Security: Validate Dockerfile content
        const validation = security.validateDockerfile(appConfig.dockerfile);
        if (!validation.valid) {
            console.error(`[Agent] ❌ Security: ${validation.error}`);
            sendLog(deploymentId, `❌ Security validation failed: ${validation.error}`, 'error');
            throw new Error(`Security: ${validation.error}`);
        }
        console.log(`[Agent] 🔒 Dockerfile security validated`);
        sendLog(deploymentId, '🔒 Dockerfile security validated', 'info');
        
        fs.writeFileSync(path.join(buildDir, 'Dockerfile'), appConfig.dockerfile);
    }
    
    sendLog(deploymentId, 'Building Docker image...', 'info');
    
    // Security: Build with resource limits
    const buildCmd = `docker build --memory=${security.SECURITY_CONFIG.maxMemory} --cpu-period=100000 --cpu-quota=200000 -t ${imageName} .`;
    await execCommand(buildCmd, { cwd: buildDir });
    
    // Clean old builds
    storage.cleanOldBuilds(applicationId, 3);
    
    security.auditLog('build_from_dockerfile_complete', { applicationId, deploymentId, imageName });
    
    return imageName;
}

/**
 * Pull Docker image (with security validation)
 */
async function pullImage(deploymentId, imageName) {
    // Security: Validate image name
    const validation = security.validateImageName(imageName);
    if (!validation.valid) {
        console.error(`[Agent] ❌ Security: ${validation.error}`);
        sendLog(deploymentId, `❌ Security validation failed: ${validation.error}`, 'error');
        throw new Error(`Security: ${validation.error}`);
    }
    
    console.log(`[Agent] 📥 Pulling Docker image: ${imageName}`);
    sendLog(deploymentId, `📥 Pulling image: ${imageName}`, 'info');
    security.auditLog('image_pull', { imageName, deploymentId });
    
    await execCommand(`docker pull ${imageName}`);
    console.log(`[Agent] ✓ Image pulled successfully`);
    sendLog(deploymentId, '✓ Image pulled successfully', 'info');
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
        await execCommand(`rm -rf ${composeDir}/*`);
        await execCommand(`cp -r ${repoDir}/. ${composeDir}/`);
        console.log(`[Agent] ✓ Files copied to compose directory`);
    }
    
    let dockerCompose = appConfig.docker_compose || appConfig.dockerCompose;
    const envVars = appConfig.environment_variables || appConfig.environmentVariables || {};
    
    // Security: Sanitize environment variables
    const safeEnvVars = security.sanitizeEnvVars(envVars);
    
    // Write docker-compose.yml if provided via config (overrides the one from repo)
    if (dockerCompose) {
        console.log(`[Agent] 📝 Writing docker-compose.yml from config`);
        // Security: Sanitize compose file to remove dangerous options
        try {
            dockerCompose = security.sanitizeComposeFile(dockerCompose);
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
                const sanitizedCompose = security.sanitizeComposeFile(existingCompose);
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

    // Runtime enforcement: compose may only publish allocated host ports.
    const allocatedPorts = appConfig.allocated_ports || appConfig.allocatedPorts || [];
    try {
        enforceComposePortsAreAllocated(
            fs.readFileSync(path.join(composeDir, 'docker-compose.yml'), 'utf8'),
            Array.isArray(allocatedPorts) ? allocatedPorts.map(p => parseInt(p, 10)).filter(p => Number.isInteger(p)) : []
        );
    } catch (err) {
        const msg = err && err.message ? err.message : String(err);
        console.error(`[Agent] ❌ Port enforcement failed: ${msg}`);
        sendLog(deploymentId, `❌ Port enforcement failed: ${msg}`, 'error');
        throw err;
    }
    
    // Write .env file with sanitized environment variables
    console.log(`[Agent] 🔐 Writing environment variables (${Object.keys(safeEnvVars).length} vars)`);
    const envContent = Object.entries(safeEnvVars)
        .map(([k, v]) => `${k}=${v}`)
        .join('\n');
    fs.writeFileSync(path.join(composeDir, '.env'), envContent);
    
    // Log the .env contents (without sensitive values)
    console.log(`[Agent] Environment variables:`, Object.keys(safeEnvVars).join(', '));
    sendLog(deploymentId, `Environment: ${Object.keys(safeEnvVars).join(', ')}`, 'info');
    
    // Stop any existing compose project
    try {
        console.log(`[Agent] 🛑 Stopping existing services...`);
        const safeAppId = String(applicationId).trim();
        await execCommand(`docker compose -p chap-${safeAppId} down`, { cwd: composeDir });
    } catch (err) {
        // Ignore if nothing to stop
    }
    
    console.log(`[Agent] 🚀 Starting services with Docker Compose...`);
    sendLog(deploymentId, '🚀 Starting services with Docker Compose...', 'info');
    
    // Start compose services with build (using isolated network)
    try {
        const safeAppId = String(applicationId).trim();
        const composeOutput = await execCommand(`docker compose -p chap-${safeAppId} up -d --build`, { cwd: composeDir });
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
        const psOutput = await execCommand(`docker compose -p chap-${safeId(applicationId)} ps --format json`, { cwd: composeDir });
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
 * Run a container (with security hardening)
 */
async function runContainer(deploymentId, applicationId, imageName, appConfig) {
    const containerName = `chap-${safeId(applicationId)}`;
    const volumeDir = storage.getVolumeDir(applicationId);
    
    // Security: Validate image name
    const imageValidation = security.validateImageName(imageName);
    if (!imageValidation.valid) {
        throw new Error(`Security: ${imageValidation.error}`);
    }
    
    // Security: Ensure isolated network exists
    await security.ensureAppNetwork(execCommand);
    
    // Security: Build secure docker run arguments
    const cpuLimit = appConfig.cpuLimit ?? appConfig.cpu_limit;
    const memoryLimit = appConfig.memoryLimit ?? appConfig.memory_limit;
    const secureArgs = security.buildSecureDockerArgs(applicationId, deploymentId, {
        cpuLimit,
        memoryLimit,
    });
    
    // Build docker run command with security args
    let cmd = `docker run ${secureArgs.join(' ')}`;
    
    // Security + runtime enforcement: Sanitize and add ports (host ports must be pre-allocated)
    const allocatedPorts = appConfig.allocated_ports || appConfig.allocatedPorts || [];
    const allowedHostPorts = Array.isArray(allocatedPorts)
        ? allocatedPorts.map(p => parseInt(p, 10)).filter(p => Number.isInteger(p))
        : [];

    const ports = appConfig.ports || (appConfig.port ? [{ containerPort: appConfig.port }] : []);
    const safePorts = security.sanitizePorts(ports);
    for (const port of safePorts) {
        if (!port.hostPort) {
            throw new Error('Port publishing requires an allocated host port (dynamic/random ports are not allowed)');
        }
        if (allowedHostPorts.length > 0 && !allowedHostPorts.includes(port.hostPort)) {
            throw new Error(`Host port ${port.hostPort} is not allocated to this application`);
        }
        if (port.hostPort) {
            cmd += ` -p ${port.hostPort}:${port.containerPort}`;
        } else {
            cmd += ` -p ${port.containerPort}`;
        }
    }
    
    // Security: Sanitize and add environment variables
    const envVars = appConfig.environmentVariables ?? appConfig.environment_variables;
    const safeEnvVars = security.sanitizeEnvVars(envVars);
    for (const [key, value] of Object.entries(safeEnvVars)) {
        // Escape double quotes in value
        const escapedValue = value.replace(/"/g, '\\"');
        cmd += ` -e "${key}=${escapedValue}"`;
    }
    
    // Security: Sanitize and add volumes
    const safeVolumes = security.sanitizeVolumes(appConfig.volumes, volumeDir);
    for (const vol of safeVolumes) {
        // Ensure the directory exists
        fs.mkdirSync(vol.source, { recursive: true });
        const ro = vol.readOnly ? ':ro' : '';
        cmd += ` -v "${vol.source}:${vol.target}${ro}"`;
    }
    
    // Add persistent data volume for the app (in safe location)
    if (appConfig.persistentStorage) {
        const dataVolume = path.join(volumeDir, 'data');
        fs.mkdirSync(dataVolume, { recursive: true });
        cmd += ` -v "${dataVolume}:${appConfig.persistentStorage}"`;
    }
    
    // Add image and start command
    cmd += ` ${imageName}`;
    if (appConfig.startCommand) {
        // Security: Basic command sanitization
        const safeCommand = appConfig.startCommand.replace(/[;&|`$]/g, '');
        cmd += ` ${safeCommand}`;
    }
    
    console.log(`[Agent] 🐳 Docker run command (secured):`);
    console.log(`[Agent]   ${cmd}`);
    security.auditLog('container_run', { applicationId, imageName, deploymentId });
    
    sendLog(deploymentId, `Starting container: ${containerName}`, 'info');
    const containerId = (await execCommand(cmd)).trim();
    console.log(`[Agent] ✓ Container ${containerName} started (secured)`);
    sendLog(deploymentId, `✓ Container started: ${containerName}`, 'info');
    
    return containerId;
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
        const out = await execCommand(`docker ps --filter "name=^/${name}$" --format "{{.ID}}"`, { timeout: 5000 });
        if (String(out || '').trim()) return true;
    } catch (err) {
        // ignore
    }

    // Fallback: treat as ID and inspect
    try {
        const running = await execCommand(`docker inspect -f "{{.State.Running}}" ${name}`, { timeout: 5000 });
        return String(running || '').trim() === 'true';
    } catch (err) {
        return false;
    }
}

async function stopContainerWithTimeout(containerNameOrId, { timeoutMs = 30000, remove = false } = {}) {
    const name = dockerSafeName(containerNameOrId);
    if (!name) return;

    const start = Date.now();
    let running = await isContainerRunning(name);

    while (running && (Date.now() - start) < timeoutMs) {
        try {
            // Short stop attempt; loop until overall timeout.
            await execCommand(`docker stop -t 10 ${name}`, { timeout: 12000 });
        } catch (err) {
            // Keep trying until we hit the overall timeout.
        }

        await sleep(1000);
        running = await isContainerRunning(name);
    }

    if (running) {
        // Still running after timeout: force kill.
        try { await execCommand(`docker kill ${name}`, { timeout: 5000 }); } catch (err) {}
    }

    if (remove) {
        try { await execCommand(`docker rm -f ${name}`, { timeout: 5000 }); } catch (err) {}
    }
}

async function stopContainer(containerName) {
    try {
        await stopContainerWithTimeout(containerName, { timeoutMs: 30000, remove: true });
    } catch (err) {
        // Container might not exist / docker might be unavailable
    }
}

/**
 * Handle application deletion - remove all containers and data
 */
async function handleApplicationDelete(message) {
    const payload = message.payload || {};
    const applicationUuid = payload.application_uuid || payload.applicationId;
    const taskId = payload.task_id || payload.taskId;
    
    console.log(`[Agent] 🗑️  Deleting application: ${applicationUuid}`);

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
        // Stop and remove regular container
        const containerName = `chap-${safeId(applicationUuid)}`;
        try {
            await stopContainerWithTimeout(containerName, { timeoutMs: 30000, remove: true });
            console.log(`[Agent] ✓ Removed container: ${containerName}`);
        } catch (err) {
            // Container might not exist
        }
        
        // Stop and remove docker compose services
        const composeDir = storage.getComposeDir(applicationUuid);
        if (fs.existsSync(composeDir)) {
            try {
                const safeAppId = String(applicationUuid).trim();
                await execCommand(`docker compose -p chap-${safeAppId} down -v`, { cwd: composeDir });
                console.log(`[Agent] ✓ Removed compose services for: ${safeAppId}`);
            } catch (err) {
                // Compose project might not exist
            }
        }
        
        // Remove all data directories
        const repoDir = storage.getRepoDir(applicationUuid);
        const buildDir = storage.getBuildDir(applicationUuid);
        const volumeDir = storage.getVolumeDir(applicationUuid);
        
        try {
            if (fs.existsSync(repoDir)) {
                await execCommand(`rm -rf ${repoDir}`);
                console.log(`[Agent] ✓ Removed repo directory`);
            }
            if (fs.existsSync(buildDir)) {
                await execCommand(`rm -rf ${buildDir}`);
                console.log(`[Agent] ✓ Removed build directory`);
            }
            if (fs.existsSync(composeDir)) {
                await execCommand(`rm -rf ${composeDir}`);
                console.log(`[Agent] ✓ Removed compose directory`);
            }
            if (fs.existsSync(volumeDir)) {
                await execCommand(`rm -rf ${volumeDir}`);
                console.log(`[Agent] ✓ Removed volume directory`);
            }
        } catch (err) {
            console.warn(`[Agent] ⚠️  Failed to clean some directories:`, err.message);
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
    const buildPack = payload.build_pack || 'docker';
    
    console.log(`[Agent] 🛑 Stopping application: ${applicationUuid}`);
    
    try {
        // Check if this is a compose app
        const composeDir = storage.getComposeDir(applicationUuid);
        if ((buildPack === 'compose' || buildPack === 'docker-compose') && fs.existsSync(composeDir)) {
            // Stop compose services
            console.log(`[Agent] Stopping compose services...`);
            const safeAppId = String(applicationUuid).trim();
            try {
                await execCommand(`docker compose -p chap-${safeAppId} stop --timeout 30`, { cwd: composeDir, timeout: 35000 });
            } catch (err) {
                // If stop hangs or fails, force kill.
                try { await execCommand(`docker compose -p chap-${safeAppId} kill`, { cwd: composeDir, timeout: 15000 }); } catch (e) {}
            }
            console.log(`[Agent] ✓ Compose services stopped`);
        } else {
            // Stop regular container
            const containerName = `chap-${safeId(applicationUuid)}`;
            await stopContainerWithTimeout(containerName, { timeoutMs: 30000, remove: false });
            console.log(`[Agent] ✓ Container ${containerName} stopped`);
        }
        
        send('stopped', { 
            payload: {
                application_uuid: applicationUuid
            }
        });
    } catch (err) {
        console.error(`[Agent] ❌ Failed to stop:`, err.message);
        send('stopped', { 
            payload: {
                application_uuid: applicationUuid,
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
    const buildPack = payload.build_pack || 'docker';
    
    console.log(`[Agent] 🔄 Restarting application: ${applicationUuid}`);
    
    try {
        // Check if this is a compose app
        const composeDir = storage.getComposeDir(applicationUuid);
        if ((buildPack === 'compose' || buildPack === 'docker-compose') && fs.existsSync(composeDir)) {
            // Restart compose services
            console.log(`[Agent] Restarting compose services...`);
            const safeAppId = String(applicationUuid).trim();
            await execCommand(`docker compose -p chap-${safeAppId} restart`, { cwd: composeDir });
            console.log(`[Agent] ✓ Compose services restarted`);
        } else {
            // Restart regular container
            const containerName = `chap-${safeId(applicationUuid)}`;
            await execCommand(`docker restart ${containerName}`);
            console.log(`[Agent] ✓ Container ${containerName} restarted`);
        }
        
        send('restarted', { 
            payload: {
                application_uuid: applicationUuid,
                container_name: containerName
            }
        });
    } catch (err) {
        // console.error(`[Agent] ❌ Failed to restart container ${containerName}:`, err.message);
        send('restarted', { 
            payload: {
                application_uuid: applicationUuid,
                error: err.message
            }
        });
    }
}

/**
 * Handle logs request (with security limits)
 */
async function handleLogs(message) {
    const payload = message.payload || {};
    const applicationUuid = payload.application_uuid || payload.applicationId;
    const requestedContainerId = payload.container_id ? safeId(payload.container_id) : null;
    const tail = Math.min(Math.max(parseInt(payload.tail, 10) || 100, 1), 1000);
    
    // Security: Limit tail to reasonable amount
    const safeTail = Math.min(Math.max(parseInt(tail, 10) || 100, 1), 1000);
    
    console.log(`[Agent] 📋 Fetching logs for application: ${applicationUuid}`);
    
    try {
        let containers = [];
        
        // Try to get containers from compose project first
        const composeDir = storage.getComposeDir(applicationUuid);
        try {
                // Use docker ps to get actual container names for the compose project
                const safeAppId = String(applicationUuid).trim();
                const psOutput = await execCommand(`docker ps -a --filter "label=com.docker.compose.project=chap-${safeAppId}" --format "{{.ID}}|{{.Names}}|{{.Image}}|{{.Status}}"`);
            containers = psOutput.trim().split('\n').filter(Boolean).map(line => {
                const [id, fullName, image, status] = line.split('|');
                // Extract service name from full container name (e.g., "chap-xxx-app-1" -> "app-1")
                const nameParts = fullName.split('-');
                const serviceName = nameParts.length > 1 ? nameParts.slice(-2).join('-') : fullName;
                return {
                    id: id,
                    name: serviceName,
                    fullName: fullName,
                    status: status.toLowerCase().includes('up') ? 'running' : 'exited',
                    image: image
                };
            });
            console.log(`[Agent] Found ${containers.length} compose containers`);
        } catch (err) {
            console.log(`[Agent] No compose containers, trying single container`);
        }
        
        // If no compose containers, try single container
        if (containers.length === 0) {
            try {
                const containerName = `chap-${safeId(applicationUuid)}`;
                const inspectOutput = await execCommand(`docker inspect ${containerName} --format '{{.Id}}|{{.Name}}|{{.State.Status}}|{{.Config.Image}}'`);
                const [id, name, status, image] = inspectOutput.trim().split('|');
                containers = [{
                    id: id.substring(0, 12),
                    name: name.replace(/^\//, ''),
                    fullName: name.replace(/^\//, ''),
                    status: status,
                    image: image
                }];
            } catch {
                // No containers found
            }
        }
        
        console.log(`[Agent] Total containers found: ${containers.length}`);
        containers.forEach(c => console.log(`[Agent]   - ${c.name} (${c.id}): ${c.status}`));
        
        // If a specific container was requested, get its logs
        let logs = [];
        if (requestedContainerId) {
            // Find the container - could be ID or name
            const container = containers.find(c => 
                c.id === requestedContainerId || 
                c.id.startsWith(requestedContainerId) ||
                c.name === requestedContainerId ||
                c.fullName === requestedContainerId
            );
            
            const targetContainer = container ? container.fullName : requestedContainerId;
            console.log(`[Agent] Fetching logs for container: ${targetContainer}`);
            
            try {
                const logOutput = await execCommand(`docker logs --tail ${safeTail} ${targetContainer} 2>&1`);
                logs = logOutput.split('\n').filter(Boolean).map(line => ({
                    timestamp: new Date().toISOString(),
                    message: line,
                    level: line.toLowerCase().includes('error') ? 'error' : 
                           line.toLowerCase().includes('warn') ? 'warning' : 'info'
                }));
                console.log(`[Agent] Got ${logs.length} log lines`);
            } catch (err) {
                console.error(`[Agent] Failed to get logs for ${targetContainer}:`, err.message);
            }
        }
        
        // Send containers list and logs back to server
        const requestId = payload.task_id || payload.request_id || null;
        const responsePayload = {
            application_uuid: applicationUuid,
            containers: containers.map(c => ({
                id: c.id,
                name: c.name,
                status: c.status,
                image: c.image
            })),
            logs,
            requested_container: requestedContainerId
        };
        if (requestId) responsePayload.task_id = requestId;

        send('container:logs:response', { 
            payload: responsePayload
        });
    } catch (err) {
        console.error(`[Agent] Failed to get logs:`, err.message);
        send('container:logs:response', { 
            payload: {
                application_uuid: applicationUuid,
                containers: [],
                logs: [],
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
    
    // Security: Validate command
    const validation = security.validateExecCommand(command);
    if (!validation.valid) {
        console.warn(`[Agent] 🔒 Exec blocked: ${validation.error}`);
        security.auditLog('exec_blocked', { applicationId, command, reason: validation.error });
        send('execResult', { applicationId, error: `Security: ${validation.error}` });
        return;
    }
    
    security.auditLog('exec_command', { applicationId, containerName, command });
    
    try {
        // Security: Run exec with timeout and no tty to prevent escape
        const output = await execCommand(`docker exec --no-tty ${containerName} ${command}`, { timeout: 30000 });
        send('execResult', { applicationId, output });
    } catch (err) {
        send('execResult', { applicationId, error: err.message });
    }
}

/**
 * Handle pull request (with security validation)
 */
async function handlePull(message) {
    const { imageName } = message;
    
    // Security: Validate image name
    const validation = security.validateImageName(imageName);
    if (!validation.valid) {
        console.warn(`[Agent] 🔒 Pull blocked: ${validation.error}`);
        security.auditLog('pull_blocked', { imageName, reason: validation.error });
        send('pullFailed', { imageName, error: `Security: ${validation.error}` });
        return;
    }
    
    security.auditLog('image_pull_request', { imageName });
    
    try {
        await execCommand(`docker pull ${imageName}`);
        send('pulled', { imageName });
    } catch (err) {
        send('pullFailed', { imageName, error: err.message });
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
        // Get container list (only Chap-managed containers)
        const containersRaw = await execCommand('docker ps -a --filter "label=chap.managed=true" --format "{{.ID}}|{{.Names}}|{{.Image}}|{{.Status}}|{{.Ports}}"');
        const containers = containersRaw.trim().split('\n').filter(Boolean).map(line => {
            const [id, name, image, status, ports] = line.split('|');
            return { id, name, image, status, ports };
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
 * Execute shell command
 */
function execCommand(command, options = {}) {
    return new Promise((resolve, reject) => {
        const { redact, ...execOptions } = options || {};
        exec(command, execOptions, (error, stdout, stderr) => {
            if (error) {
                const msg = redactSecrets(stderr || error.message, redact || []);
                reject(new Error(String(msg || 'Command failed').trim()));
            } else {
                resolve(stdout);
            }
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

// ============================================
// Browser WebSocket Server for Direct Log Streaming
// ============================================

// Graceful shutdown
process.on('SIGTERM', () => {
    console.log('[Agent] Received SIGTERM, shutting down...');
    stopHeartbeat();
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
    });
}
liveLogsWs.start();

// Connect to Chap server
connect();

/**
 * Chap Node Agent
 * Connects to the Chap server via WebSocket and manages Docker containers
 */

const WebSocket = require('ws');
const { WebSocketServer } = require('ws');
const { spawn, exec } = require('child_process');
const os = require('os');
const fs = require('fs');
const path = require('path');
const StorageManager = require('./storage');
const security = require('./security');

// Configuration
const config = {
    serverUrl: process.env.CHAP_SERVER_URL || 'ws://localhost:6001',
    nodeId: process.env.NODE_ID || '',
    nodeToken: process.env.NODE_TOKEN || '',
    reconnectInterval: 5000,
    heartbeatInterval: 5000, // Check for tasks every 5 seconds
    dataDir: process.env.CHAP_DATA_DIR || '/data',
    // Browser WebSocket server config
    browserWsPort: parseInt(process.env.BROWSER_WS_PORT || '6002', 10),
    browserWsHost: process.env.BROWSER_WS_HOST || '0.0.0.0',
    // SSL config for browser WebSocket (WSS)
    browserWsSslCert: process.env.BROWSER_WS_SSL_CERT || null,
    browserWsSslKey: process.env.BROWSER_WS_SSL_KEY || null
};

// Initialize storage manager
const storage = new StorageManager(config.dataDir);
storage.init();

// Utility: safely normalize IDs coming from external sources
function safeId(x) {
    return String(x || '').trim();
}

// State
let ws = null;
let heartbeatTimer = null;
let reconnectTimer = null;
let isConnected = false;

// Browser WebSocket server state
let browserWss = null;
// Map of applicationUuid -> Set of authenticated browser connections
const browserConnections = new Map();
// Map of pending session validation requests
const pendingValidations = new Map();

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
                version: '1.0.0'
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
            
        case 'container:logs':
            handleLogs(message);
            break;
            
        case 'container:exec':
            handleExec(message);
            break;
            
        case 'image:pull':
            handlePull(message);
            break;
            
        case 'ping':
        case 'pong':
            send('pong');
            break;
        
        case 'session:validate:response':
            handleSessionValidateResponse(message);
            break;
            
        default:
            console.warn(`[Agent] Unknown message type: ${message.type}`);
    }
}

/**
 * Handle session validation response from PHP server
 */
function handleSessionValidateResponse(message) {
    const requestId = message.request_id;
    // Debug log the incoming session:validate:response
    console.log(`[BrowserWS] Received session:validate:response for ${message.application_uuid}:`, JSON.stringify(message));

    const pending = pendingValidations.get(requestId);
    if (!pending) {
        console.warn(`[BrowserWS] No pending validation for request ${requestId}`);
        return;
    }

    pendingValidations.delete(requestId);
    const { browserWs, applicationUuid, sessionId } = pending;

    if (message.authorized) {
        console.log(`[BrowserWS] Session validated for app ${applicationUuid}, user ${message.user_id}`);

        // Store connection info
        browserWs.authenticated = true;
        browserWs.applicationUuid = applicationUuid;
        browserWs.userId = message.user_id;
        browserWs.teamId = message.team_id;

        // Add to connections map
        if (!browserConnections.has(applicationUuid)) {
            browserConnections.set(applicationUuid, new Set());
        }
        browserConnections.get(applicationUuid).add(browserWs);

        // Send success message to browser
        browserWs.send(JSON.stringify({
            type: 'auth:success',
            message: 'Authenticated successfully'
        }));

        // Start streaming logs for this application
        startLogStreamForBrowser(browserWs, applicationUuid);
    } else {
        console.log(`[BrowserWS] Session validation failed: ${message.error}`);
        browserWs.send(JSON.stringify({
            type: 'auth:failed',
            error: message.error || 'Authentication failed'
        }));
        browserWs.close(4001, 'Authentication failed');
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
    
    // Check if repo exists and try to update it
    if (fs.existsSync(path.join(repoDir, '.git'))) {
        try {
            console.log(`[Agent] 🔄 Repository exists, updating...`);
            sendLog(deploymentId, '🔄 Repository exists, updating...', 'info');
            await execCommand(`git fetch origin`, { cwd: repoDir });
            await execCommand(`git checkout ${gitBranch}`, { cwd: repoDir });
            await execCommand(`git pull origin ${gitBranch}`, { cwd: repoDir });
            console.log(`[Agent] ✓ Repository updated`);
            sendLog(deploymentId, '✓ Repository updated', 'info');
        } catch (err) {
            // If update fails, remove and re-clone
            console.log(`[Agent] ⚠ Update failed, re-cloning...`);
            sendLog(deploymentId, 'Update failed, re-cloning...', 'info');
            await execCommand(`rm -rf ${repoDir}`);
            await execCommand(`git clone --branch ${gitBranch} ${gitRepository} ${repoDir}`);
            console.log(`[Agent] ✓ Repository cloned`);
            sendLog(deploymentId, '✓ Repository cloned', 'info');
        }
    } else {
        console.log(`[Agent] 📥 Cloning repository...`);
        await execCommand(`git clone --branch ${gitBranch} ${gitRepository} ${repoDir}`);
        console.log(`[Agent] ✓ Repository cloned`);
        sendLog(deploymentId, '✓ Repository cloned', 'info');
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
        
        // Clone or update repository
        if (fs.existsSync(path.join(repoDir, '.git'))) {
            try {
                await execCommand(`git fetch origin`, { cwd: repoDir });
                await execCommand(`git checkout ${gitBranch}`, { cwd: repoDir });
                await execCommand(`git pull origin ${gitBranch}`, { cwd: repoDir });
                console.log(`[Agent] ✓ Repository updated`);
                sendLog(deploymentId, '✓ Repository updated', 'info');
            } catch (err) {
                await execCommand(`rm -rf ${repoDir}`);
                await execCommand(`git clone --branch ${gitBranch} ${gitRepository} ${repoDir}`);
                console.log(`[Agent] ✓ Repository cloned`);
                sendLog(deploymentId, '✓ Repository cloned', 'info');
            }
        } else {
            await execCommand(`git clone --branch ${gitBranch} ${gitRepository} ${repoDir}`);
            console.log(`[Agent] ✓ Repository cloned`);
            sendLog(deploymentId, '✓ Repository cloned', 'info');
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
    const secureArgs = security.buildSecureDockerArgs(applicationId, deploymentId, {
        cpuLimit: appConfig.cpuLimit,
        memoryLimit: appConfig.memoryLimit,
    });
    
    // Build docker run command with security args
    let cmd = `docker run ${secureArgs.join(' ')}`;
    
    // Security: Sanitize and add ports
    const safePorts = security.sanitizePorts(appConfig.ports);
    for (const port of safePorts) {
        if (port.hostPort) {
            cmd += ` -p ${port.hostPort}:${port.containerPort}`;
        } else {
            cmd += ` -p ${port.containerPort}`;
        }
    }
    
    // Security: Sanitize and add environment variables
    const safeEnvVars = security.sanitizeEnvVars(appConfig.environmentVariables);
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
async function stopContainer(containerName) {
    try {
        await execCommand(`docker stop ${containerName}`);
        await execCommand(`docker rm ${containerName}`);
    } catch (err) {
        // Container might not exist
    }
}

/**
 * Handle application deletion - remove all containers and data
 */
async function handleApplicationDelete(message) {
    const payload = message.payload || {};
    const applicationUuid = payload.application_uuid || payload.applicationId;
    
    console.log(`[Agent] 🗑️  Deleting application: ${applicationUuid}`);
    
    try {
        // Stop and remove regular container
        const containerName = `chap-${safeId(applicationUuid)}`;
        try {
            await execCommand(`docker stop ${containerName}`);
            await execCommand(`docker rm ${containerName}`);
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
                application_uuid: applicationUuid
            }
        });
    } catch (err) {
        console.error(`[Agent] ❌ Failed to delete application:`, err.message);
        send('application:delete:failed', { 
            payload: {
                application_uuid: applicationUuid,
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
            await execCommand(`docker compose -p chap-${safeAppId} stop`, { cwd: composeDir });
            console.log(`[Agent] ✓ Compose services stopped`);
        } else {
            // Stop regular container
            const containerName = `chap-${safeId(applicationUuid)}`;
            await execCommand(`docker stop ${containerName}`);
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
        console.error(`[Agent] ❌ Failed to restart container ${containerName}:`, err.message);
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
            agent_version: '1.0.0'
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
        exec(command, options, (error, stdout, stderr) => {
            if (error) {
                reject(new Error(stderr || error.message));
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

/**
 * Start the browser WebSocket server
 * Uses WSS (secure) if SSL certificates are configured, otherwise WS
 */
function startBrowserWsServer() {
    const useSSL = config.browserWsSslCert && config.browserWsSslKey && 
                   fs.existsSync(config.browserWsSslCert) && fs.existsSync(config.browserWsSslKey);
    
    if (useSSL) {
        // Create HTTPS server for WSS
        const https = require('https');
        const httpsServer = https.createServer({
            cert: fs.readFileSync(config.browserWsSslCert),
            key: fs.readFileSync(config.browserWsSslKey)
        });
        
        browserWss = new WebSocketServer({ server: httpsServer });
        
        httpsServer.listen(config.browserWsPort, config.browserWsHost, () => {
            console.log(`[BrowserWS] Secure WSS server started on ${config.browserWsHost}:${config.browserWsPort}`);
        });
    } else {
        // Plain WS (no SSL)
        browserWss = new WebSocketServer({ 
            port: config.browserWsPort,
            host: config.browserWsHost
        });
        
        console.log(`[BrowserWS] Server started on ${config.browserWsHost}:${config.browserWsPort} (no SSL)`);
    }
    
    browserWss.on('connection', (browserWs, req) => {
        const clientIp = req.socket.remoteAddress;
        console.log(`[BrowserWS] New connection from ${clientIp}`);
        
        browserWs.authenticated = false;
        browserWs.applicationUuid = null;
        browserWs.logProcess = null;
        
        // Set a timeout for authentication
        browserWs.authTimeout = setTimeout(() => {
            if (!browserWs.authenticated) {
                console.log(`[BrowserWS] Auth timeout for ${clientIp}`);
                browserWs.close(4000, 'Authentication timeout');
            }
        }, 10000); // 10 second timeout
        
        browserWs.on('message', (data) => {
            try {
                const message = JSON.parse(data.toString());
                handleBrowserMessage(browserWs, message);
            } catch (err) {
                console.error('[BrowserWS] Failed to parse message:', err);
                browserWs.send(JSON.stringify({ type: 'error', error: 'Invalid message format' }));
            }
        });
        
        browserWs.on('close', () => {
            console.log(`[BrowserWS] Connection closed for ${clientIp}`);
            clearTimeout(browserWs.authTimeout);
            cleanupBrowserConnection(browserWs);
        });
        
        browserWs.on('error', (err) => {
            console.error(`[BrowserWS] Error for ${clientIp}:`, err.message);
        });
    });
    
    browserWss.on('error', (err) => {
        console.error('[BrowserWS] Server error:', err);
    });
}

/**
 * Handle messages from browser connections
 */
function handleBrowserMessage(browserWs, message) {
    switch (message.type) {
        case 'auth':
            handleBrowserAuth(browserWs, message);
            break;
            
        case 'ping':
            browserWs.send(JSON.stringify({ type: 'pong' }));
            break;
            
        default:
            if (!browserWs.authenticated) {
                browserWs.send(JSON.stringify({ type: 'error', error: 'Not authenticated' }));
                return;
            }
            console.warn(`[BrowserWS] Unknown message type: ${message.type}`);
    }
}

/**
 * Handle browser authentication request
 */
function handleBrowserAuth(browserWs, message) {
    // Always forward the exact session_id and application_uuid from the browser
    const rawSession = message.session_id;
    const rawApp = message.application_uuid;

    // Trim incoming IDs to avoid stray whitespace/newlines breaking docker commands
    const session_id = (typeof rawSession === 'string') ? rawSession.trim() : rawSession;
    const application_uuid = (typeof rawApp === 'string') ? rawApp.trim() : rawApp;

    // Debug log the incoming browser auth request
    console.log(`[BrowserWS] Incoming auth request:`, JSON.stringify({ session_id, application_uuid }));

    if (!session_id || !application_uuid) {
        browserWs.send(JSON.stringify({ 
            type: 'auth:failed', 
            error: 'Missing session_id or application_uuid' 
        }));
        browserWs.close(4001, 'Invalid auth request');
        return;
    }

    if (!isConnected || !ws || ws.readyState !== WebSocket.OPEN) {
        browserWs.send(JSON.stringify({ 
            type: 'auth:failed', 
            error: 'Node not connected to server' 
        }));
        browserWs.close(4002, 'Node not connected');
        return;
    }

    const requestId = `validate_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;

    pendingValidations.set(requestId, {
        browserWs,
        applicationUuid: application_uuid,
        sessionId: session_id,
        timestamp: Date.now()
    });

    // Debug log the outgoing session:validate request
    console.log(`[BrowserWS] FORWARDING session:validate to PHP server:`, JSON.stringify({ request_id: requestId, session_id, application_uuid }));

    // Send validation request to PHP server with the exact values
    send('session:validate', {
        request_id: requestId,
        session_id: session_id,
        application_uuid: application_uuid
    });
}

/**
 * Start streaming logs for an authenticated browser connection
 */
function startLogStreamForBrowser(browserWs, applicationUuid) {
    console.log(`[BrowserWS] Starting log stream for app ${applicationUuid}`);
    
    // Find a suitable container for this application, prefer label-based (compose) then name matches
    const safeAppId = safeId(applicationUuid);
    
    // Resilient attach: keep trying to attach to container logs until browser disconnects.
    async function findContainer() {
        return new Promise((resolve) => {
            // 1) label-based
            exec(`docker ps -q --filter "label=chap.application=${safeAppId}"`, (err, stdout) => {
                if (!err && stdout && stdout.trim()) {
                    return resolve(stdout.trim().split('\n')[0].trim());
                }

                // 2) name contains chap-<id>
                exec(`docker ps -a --filter "name=chap-${safeAppId}" --format "{{.ID}}|{{.Names}}"`, (err2, stdout2) => {
                    if (!err2 && stdout2 && stdout2.trim()) {
                        const line = stdout2.trim().split('\n')[0];
                        const [id, names] = line.split('|');
                        return resolve(names.trim());
                    }

                    // 3) exact name
                    const exactName = `chap-${safeAppId}`;
                    exec(`docker ps -q --filter "name=^/${exactName}$"`, (err3, stdout3) => {
                        if (!err3 && stdout3 && stdout3.trim()) {
                            return resolve(stdout3.trim().split('\n')[0].trim());
                        }

                        // not found
                        return resolve(null);
                    });
                });
            });
        });
    }

    function attachLogs(containerIdentifier) {
        if (browserWs.readyState !== WebSocket.OPEN) return;

        const logProcess = spawn('docker', ['logs', '-f', '--tail', '100', '--timestamps', containerIdentifier]);
        browserWs.logProcess = logProcess;

        const skipRegex = /caught\s+SIGWINCH|shutting down gracefully|AH\d{5}:\s*caught\s+SIGWINCH/i;

        logProcess.stdout.on('data', (data) => {
            if (browserWs.readyState !== WebSocket.OPEN) return;
            browserWs.send(JSON.stringify({ type: 'log', stream: 'stdout', data: data.toString() }));
        });

        logProcess.stderr.on('data', (data) => {
            if (browserWs.readyState !== WebSocket.OPEN) return;
            const text = data.toString();
            if (skipRegex.test(text)) {
                // Fully suppress known benign messages (do not forward to clients).
                return;
            }
            browserWs.send(JSON.stringify({ type: 'log', stream: 'stderr', data: text }));
        });

        const onExit = (code, signal) => {
            if (browserWs.readyState !== WebSocket.OPEN) return;
            console.log(`[BrowserWS] Log process for ${containerIdentifier} exited (code=${code}, signal=${signal}) — will retry`);
            // clear current process
            browserWs.logProcess = null;
            // schedule reattach
            browserWs._reattachTimer = setTimeout(() => {
                if (browserWs.readyState !== WebSocket.OPEN) return;
                startAttachLoop();
            }, 2000);
        };

        logProcess.on('error', (err) => {
            console.error(`[BrowserWS] Log process error for ${containerIdentifier}:`, err.message);
        });
        logProcess.on('close', onExit);
    }

    async function startAttachLoop() {
        if (browserWs.readyState !== WebSocket.OPEN) return;

        const containerId = await findContainer();
        if (!containerId) {
            // no container yet — notify and poll
            if (browserWs.readyState === WebSocket.OPEN) {
                browserWs.send(JSON.stringify({ type: 'log', stream: 'system', data: 'Waiting for container to appear...\n' }));
            }
            browserWs._reattachTimer = setTimeout(() => {
                startAttachLoop();
            }, 2000);
            return;
        }

        // attach to found container
        attachLogs(containerId);
    }

    // Begin the attach loop
    startAttachLoop();
}

/**
 * Alternative log streaming by finding container with label
 */
function startLogStreamByLabel(browserWs, applicationUuid) {
    console.log(`[BrowserWS] Trying label-based log stream for ${applicationUuid}`);
    
    // Find container by label (trim application UUID before use)
    const safeAppLabel = String(applicationUuid).trim();
    exec(`docker ps -q --filter "label=chap.application=${safeAppLabel}"`, (err, stdout) => {
        if (err || !stdout.trim()) {
            console.log(`[BrowserWS] No running container found for ${safeAppLabel}`);
            if (browserWs.readyState === WebSocket.OPEN) {
                browserWs.send(JSON.stringify({
                    type: 'log',
                    stream: 'system',
                    data: 'No running container found for this application\n'
                }));
            }
            return;
        }

        const containerId = stdout.trim().split('\n')[0].trim();
        console.log(`[BrowserWS] Found container ${containerId} for ${safeAppLabel}`);
        // Use the same resilient attach logic as startLogStreamForBrowser: try to
        // attach and on close schedule a reattach loop.
        if (browserWs.readyState === WebSocket.OPEN) {
            if (browserWs._reattachTimer) {
                clearTimeout(browserWs._reattachTimer);
                browserWs._reattachTimer = null;
            }
            const containerIdentifier = containerId;
            const logProcess = spawn('docker', ['logs', '-f', '--tail', '100', '--timestamps', containerIdentifier]);
            browserWs.logProcess = logProcess;

            const skipRegex = /caught\s+SIGWINCH|shutting down gracefully|AH\d{5}:\s*caught\s+SIGWINCH/i;
            logProcess.stdout.on('data', (data) => {
                if (browserWs.readyState !== WebSocket.OPEN) return;
                browserWs.send(JSON.stringify({ type: 'log', stream: 'stdout', data: data.toString() }));
            });
            logProcess.stderr.on('data', (data) => {
                if (browserWs.readyState !== WebSocket.OPEN) return;
                const text = data.toString();
                if (skipRegex.test(text)) {
                    // Fully suppress known benign messages (do not forward to clients).
                    return;
                }
                browserWs.send(JSON.stringify({ type: 'log', stream: 'stderr', data: text }));
            });
            logProcess.on('error', (err) => console.error(`[BrowserWS] Log process error:`, err.message));
            logProcess.on('close', (code) => {
                if (browserWs.readyState !== WebSocket.OPEN) return;
                browserWs.logProcess = null;
                browserWs._reattachTimer = setTimeout(() => startLogStreamForBrowser(browserWs, applicationUuid), 2000);
            });
        }
    });
}

/**
 * Cleanup browser connection
 */
function cleanupBrowserConnection(browserWs) {
    // Kill log process if running
    if (browserWs.logProcess) {
        browserWs.logProcess.kill();
        browserWs.logProcess = null;
    }
    // Clear any scheduled reattach timer
    if (browserWs._reattachTimer) {
        clearTimeout(browserWs._reattachTimer);
        browserWs._reattachTimer = null;
    }
    
    // Remove from connections map
    if (browserWs.applicationUuid && browserConnections.has(browserWs.applicationUuid)) {
        browserConnections.get(browserWs.applicationUuid).delete(browserWs);
        if (browserConnections.get(browserWs.applicationUuid).size === 0) {
            browserConnections.delete(browserWs.applicationUuid);
        }
    }
}

/**
 * Cleanup stale pending validations (older than 30 seconds)
 */
function cleanupPendingValidations() {
    const now = Date.now();
    for (const [requestId, pending] of pendingValidations) {
        if (now - pending.timestamp > 30000) {
            console.log(`[BrowserWS] Cleaning up stale validation ${requestId}`);
            pendingValidations.delete(requestId);
            if (pending.browserWs.readyState === WebSocket.OPEN) {
                pending.browserWs.close(4003, 'Validation timeout');
            }
        }
    }
}

// Cleanup pending validations every 10 seconds
setInterval(cleanupPendingValidations, 10000);

// Graceful shutdown
process.on('SIGTERM', () => {
    console.log('[Agent] Received SIGTERM, shutting down...');
    stopHeartbeat();
    if (ws) ws.close();
    if (browserWss) {
        browserWss.clients.forEach(client => client.close());
        browserWss.close();
    }
    process.exit(0);
});

process.on('SIGINT', () => {
    console.log('[Agent] Received SIGINT, shutting down...');
    stopHeartbeat();
    if (ws) ws.close();
    if (browserWss) {
        browserWss.clients.forEach(client => client.close());
        browserWss.close();
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

// Start browser WebSocket server
startBrowserWsServer();

// Connect to Chap server
connect();

/**
 * Chap Node Agent
 * Connects to the Chap server via WebSocket and manages Docker containers
 */

const WebSocket = require('ws');
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
    dataDir: process.env.CHAP_DATA_DIR || '/data'
};

// Initialize storage manager
const storage = new StorageManager(config.dataDir);
storage.init();

// State
let ws = null;
let heartbeatTimer = null;
let reconnectTimer = null;
let isConnected = false;

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
            
        default:
            console.warn(`[Agent] Unknown message type: ${message.type}`);
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
        await stopContainer(`chap-${applicationId}`);
        
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
    const imageName = `chap-app-${applicationId}:${deploymentId}`;
    
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
    const imageName = `chap-app-${applicationId}:${deploymentId}`;
    
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
        await execCommand(`docker compose -p chap-${applicationId} down`, { cwd: composeDir });
    } catch (err) {
        // Ignore if nothing to stop
    }
    
    console.log(`[Agent] 🚀 Starting services with Docker Compose...`);
    sendLog(deploymentId, '🚀 Starting services with Docker Compose...', 'info');
    
    // Start compose services with build (using isolated network)
    try {
        const composeOutput = await execCommand(`docker compose -p chap-${applicationId} up -d --build`, { cwd: composeDir });
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
        const psOutput = await execCommand(`docker compose -p chap-${applicationId} ps --format json`, { cwd: composeDir });
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
    const containerName = `chap-${applicationId}`;
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
        const containerName = `chap-${applicationUuid}`;
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
                await execCommand(`docker compose -p chap-${applicationUuid} down -v`, { cwd: composeDir });
                console.log(`[Agent] ✓ Removed compose services for: ${applicationUuid}`);
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
            await execCommand(`docker compose -p chap-${applicationUuid} stop`, { cwd: composeDir });
            console.log(`[Agent] ✓ Compose services stopped`);
        } else {
            // Stop regular container
            const containerName = `chap-${applicationUuid}`;
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
            await execCommand(`docker compose -p chap-${applicationUuid} restart`, { cwd: composeDir });
            console.log(`[Agent] ✓ Compose services restarted`);
        } else {
            // Restart regular container
            const containerName = `chap-${applicationUuid}`;
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
    const { applicationId, containerId, tail = 100 } = message;
    const containerName = containerId || `chap-${applicationId}`;
    
    // Security: Limit tail to reasonable amount
    const safeTail = Math.min(Math.max(parseInt(tail, 10) || 100, 1), 1000);
    
    try {
        const logs = await execCommand(`docker logs --tail ${safeTail} ${containerName}`);
        send('containerLogs', { applicationId, logs });
    } catch (err) {
        console.error(`[Agent] Failed to get logs:`, err);
    }
}

/**
 * Handle exec request (with security validation)
 */
async function handleExec(message) {
    const { applicationId, containerId, command } = message;
    const containerName = containerId || `chap-${applicationId}`;
    
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

// Graceful shutdown
process.on('SIGTERM', () => {
    console.log('[Agent] Received SIGTERM, shutting down...');
    stopHeartbeat();
    if (ws) ws.close();
    process.exit(0);
});

process.on('SIGINT', () => {
    console.log('[Agent] Received SIGINT, shutting down...');
    stopHeartbeat();
    if (ws) ws.close();
    process.exit(0);
});

// Start
console.log('========================================');
console.log('  Chap Node Agent v1.0.0');
console.log('========================================');
console.log(`  Server: ${config.serverUrl}`);
console.log(`  Node ID: ${config.nodeId || '(auto)'}`);
console.log(`  Data Dir: ${config.dataDir}`);
console.log('');

connect();

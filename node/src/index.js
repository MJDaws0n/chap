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

// Configuration
const config = {
    serverUrl: process.env.CHAP_SERVER_URL || 'ws://localhost:6001',
    nodeId: process.env.NODE_ID || '',
    nodeToken: process.env.NODE_TOKEN || '',
    reconnectInterval: 5000,
    heartbeatInterval: 30000,
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
            
        case 'task:deploy':
            console.log('TESTING');
            handleDeploy(message);
            break;
            
        case 'container:stop':
            handleStop(message);
            break;
            
        case 'container:restart':
            handleRestart(message);
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
    
    console.log(`[Agent] Starting deployment ${deploymentId} for app ${applicationId}`);
    
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
        sendLog(deploymentId, 'Deployment started', 'info');
        
        let imageName = '';
        
        // Determine build type from application config
        const buildType = appConfig.build_pack || appConfig.type || 'docker';
        
        // Build or pull image based on type
        if (buildType === 'git' || appConfig.git_repository) {
            imageName = await buildFromGit(deploymentId, appConfig);
        } else if (buildType === 'dockerfile') {
            imageName = await buildFromDockerfile(deploymentId, appConfig);
        } else if (buildType === 'docker' || appConfig.docker_image) {
            imageName = appConfig.docker_image || appConfig.dockerImage;
            await pullImage(deploymentId, imageName);
        } else if (buildType === 'compose') {
            await deployCompose(deploymentId, appConfig);
            return;
        } else {
            throw new Error(`Unknown build type: ${buildType}`);
        }
        
        // Deploy container
        sendLog(deploymentId, 'Starting container...', 'info');
        
        // Stop existing container if any
        await stopContainer(`chap-${applicationId}`);
        
        // Run new container
        const containerId = await runContainer(deploymentId, applicationId, imageName, appConfig);
        
        // Send completion
        send('task:complete', {
            payload: {
                deployment_id: deploymentId,
                container_id: containerId
            }
        });
        
        console.log(`[Agent] Deployment ${deploymentId} completed`);
        
    } catch (err) {
        console.error(`[Agent] Deployment ${deploymentId} failed:`, err);
        send('task:failed', { 
            payload: {
                deployment_id: deploymentId,
                error: err.message 
            }
        });
    }
}

/**
 * Build image from Git repository
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
    
    // Clone or update repository
    sendLog(deploymentId, `Cloning ${gitRepository}...`, 'info');
    
    // Check if repo exists and try to update it
    if (fs.existsSync(path.join(repoDir, '.git'))) {
        try {
            await execCommand(`git fetch origin`, { cwd: repoDir });
            await execCommand(`git checkout ${gitBranch}`, { cwd: repoDir });
            await execCommand(`git pull origin ${gitBranch}`, { cwd: repoDir });
            sendLog(deploymentId, 'Repository updated', 'info');
        } catch (err) {
            // If update fails, remove and re-clone
            await execCommand(`rm -rf ${repoDir}`);
            await execCommand(`git clone --branch ${gitBranch} ${gitRepository} ${repoDir}`);
        }
    } else {
        await execCommand(`git clone --branch ${gitBranch} ${gitRepository} ${repoDir}`);
    }
    
    // Copy to build directory
    sendLog(deploymentId, 'Preparing build context...', 'info');
    await execCommand(`cp -r ${repoDir}/. ${buildDir}/`);
    
    // Change to base directory if specified
    const contextDir = path.join(buildDir, buildContext);
    
    // Run install command if specified
    if (appConfig.installCommand) {
        sendLog(deploymentId, `Running install command...`, 'info');
        await execCommand(appConfig.installCommand, { cwd: contextDir });
    }
    
    // Run build command if specified
    if (appConfig.buildCommand) {
        sendLog(deploymentId, `Running build command...`, 'info');
        await execCommand(appConfig.buildCommand, { cwd: contextDir });
    }
    
    // Build Docker image
    const imageName = `chap-app-${applicationId}:${deploymentId}`;
    
    sendLog(deploymentId, `Building Docker image...`, 'info');
    await execCommand(`docker build -t ${imageName} -f ${dockerfilePath} .`, { cwd: contextDir });
    
    // Clean old builds but keep recent ones
    storage.cleanOldBuilds(applicationId, 3);
    
    return imageName;
}

/**
 * Build image from Dockerfile
 */
async function buildFromDockerfile(deploymentId, appConfig) {
    const applicationId = appConfig.uuid || appConfig.applicationId;
    const buildDir = storage.getBuildDir(applicationId, deploymentId);
    const imageName = `chap-app-${applicationId}:${deploymentId}`;
    
    if (appConfig.dockerfile) {
        fs.writeFileSync(path.join(buildDir, 'Dockerfile'), appConfig.dockerfile);
    }
    
    sendLog(deploymentId, 'Building Docker image...', 'info');
    await execCommand(`docker build -t ${imageName} .`, { cwd: buildDir });
    
    // Clean old builds
    storage.cleanOldBuilds(applicationId, 3);
    
    return imageName;
}

/**
 * Pull Docker image
 */
async function pullImage(deploymentId, imageName) {
    sendLog(deploymentId, `Pulling image ${imageName}...`, 'info');
    await execCommand(`docker pull ${imageName}`);
}

/**
 * Deploy Docker Compose
 */
async function deployCompose(deploymentId, appConfig) {
    const applicationId = appConfig.uuid || appConfig.applicationId;
    const composeDir = storage.getComposeDir(applicationId);
    const dockerCompose = appConfig.docker_compose || appConfig.dockerCompose;
    const envVars = appConfig.environment_variables || appConfig.environmentVariables;
    
    fs.writeFileSync(path.join(composeDir, 'docker-compose.yml'), dockerCompose);
    
    // Write env file if provided
    if (envVars && typeof envVars === 'object') {
        const envContent = Object.entries(envVars)
            .map(([k, v]) => `${k}=${v}`)
            .join('\n');
        fs.writeFileSync(path.join(composeDir, '.env'), envContent);
    }
    
    sendLog(deploymentId, 'Starting services with Docker Compose...', 'info');
    await execCommand(`docker compose -p chap-${applicationId} up -d`, { cwd: composeDir });
    
    send('task:complete', {
        payload: {
            deployment_id: deploymentId
        }
    });
}

/**
 * Run a container
 */
async function runContainer(deploymentId, applicationId, imageName, appConfig) {
    const containerName = `chap-${applicationId}`;
    const volumeDir = storage.getVolumeDir(applicationId);
    
    // Build docker run command
    let cmd = `docker run -d --name ${containerName}`;
    
    // Add restart policy
    cmd += ' --restart unless-stopped';
    
    // Add ports
    if (appConfig.ports) {
        for (const port of appConfig.ports) {
            if (port.hostPort) {
                cmd += ` -p ${port.hostPort}:${port.containerPort}`;
            } else {
                cmd += ` -p ${port.containerPort}`;
            }
        }
    }
    
    // Add environment variables
    if (appConfig.environmentVariables) {
        for (const [key, value] of Object.entries(appConfig.environmentVariables)) {
            cmd += ` -e "${key}=${value}"`;
        }
    }
    
    // Add volumes - user-defined
    if (appConfig.volumes) {
        for (const vol of appConfig.volumes) {
            // Support both relative (to app volume dir) and absolute paths
            let sourcePath = vol.source;
            if (!path.isAbsolute(sourcePath)) {
                sourcePath = path.join(volumeDir, sourcePath);
                // Ensure the directory exists
                fs.mkdirSync(sourcePath, { recursive: true });
            }
            cmd += ` -v ${sourcePath}:${vol.target}`;
        }
    }
    
    // Add persistent data volume for the app
    if (appConfig.persistentStorage) {
        const dataVolume = path.join(volumeDir, 'data');
        fs.mkdirSync(dataVolume, { recursive: true });
        cmd += ` -v ${dataVolume}:${appConfig.persistentStorage}`;
    }
    
    // Add labels
    cmd += ` --label chap.app=${applicationId}`;
    cmd += ` --label chap.deployment=${deploymentId}`;
    cmd += ` --label chap.managed=true`;
    
    // Add resource limits
    if (appConfig.cpuLimit) {
        cmd += ` --cpus=${appConfig.cpuLimit}`;
    }
    if (appConfig.memoryLimit) {
        cmd += ` --memory=${appConfig.memoryLimit}`;
    }
    
    // Add image and start command
    cmd += ` ${imageName}`;
    if (appConfig.startCommand) {
        cmd += ` ${appConfig.startCommand}`;
    }
    
    sendLog(deploymentId, `Starting container ${containerName}...`);
    const containerId = (await execCommand(cmd)).trim();
    
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
 * Handle stop request
 */
async function handleStop(message) {
    const { applicationId, containerId } = message;
    const containerName = containerId || `chap-${applicationId}`;
    
    console.log(`[Agent] Stopping container ${containerName}`);
    
    try {
        await execCommand(`docker stop ${containerName}`);
        send('stopped', { applicationId, containerId: containerName });
    } catch (err) {
        console.error(`[Agent] Failed to stop container:`, err);
    }
}

/**
 * Handle restart request
 */
async function handleRestart(message) {
    const { applicationId, containerId } = message;
    const containerName = containerId || `chap-${applicationId}`;
    
    console.log(`[Agent] Restarting container ${containerName}`);
    
    try {
        await execCommand(`docker restart ${containerName}`);
        send('restarted', { applicationId, containerId: containerName });
    } catch (err) {
        console.error(`[Agent] Failed to restart container:`, err);
    }
}

/**
 * Handle logs request
 */
async function handleLogs(message) {
    const { applicationId, containerId, tail = 100 } = message;
    const containerName = containerId || `chap-${applicationId}`;
    
    try {
        const logs = await execCommand(`docker logs --tail ${tail} ${containerName}`);
        send('containerLogs', { applicationId, logs });
    } catch (err) {
        console.error(`[Agent] Failed to get logs:`, err);
    }
}

/**
 * Handle exec request
 */
async function handleExec(message) {
    const { applicationId, containerId, command } = message;
    const containerName = containerId || `chap-${applicationId}`;
    
    try {
        const output = await execCommand(`docker exec ${containerName} ${command}`);
        send('execResult', { applicationId, output });
    } catch (err) {
        send('execResult', { applicationId, error: err.message });
    }
}

/**
 * Handle pull request
 */
async function handlePull(message) {
    const { imageName } = message;
    
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

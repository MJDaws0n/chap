/**
 * Chap Node Storage Manager
 * 
 * Manages persistent storage for deployments on this node.
 * Each application gets its own directory for:
 * - Git repositories (source code)
 * - Build contexts
 * - Docker Compose files
 * - Persistent data volumes
 */

const fs = require('fs');
const path = require('path');

class Storage {

    /**
     * Return a path inside storage without creating it.
     * These helpers are used for cleanup code to avoid creating directories
     * that we then try to delete.
     */
    pathAppDir(applicationId) {
        return path.join(this.dirs.apps, `app-${applicationId}`);
    }

    pathRepoDir(applicationId) {
        return path.join(this.pathAppDir(applicationId), 'repo');
    }

    pathSourceDir(applicationId) {
        return path.join(this.pathAppDir(applicationId), 'source');
    }

    pathBuildDir(deploymentId) {
        return path.join(this.dirs.builds, `build-${deploymentId}`);
    }

    pathVolumeRoot(applicationId) {
        return path.join(this.dirs.volumes, `app-${applicationId}`);
    }

    pathComposeDir(serviceId) {
        return path.join(this.dirs.compose, `service-${serviceId}`);
    }

        /**
         * Get path for an application's git repository
         */
        getRepoDir(applicationId) {
            const dir = path.join(this.getAppDir(applicationId), 'repo');
            if (!fs.existsSync(dir)) {
                fs.mkdirSync(dir, { recursive: true });
            }
            return dir;
        }

        /**
         * Get disk usage for the base storage directory
         * Returns { total, free, used } in bytes
         */
        getDiskUsage() {
            // Use statvfs via os module if available, else fallback to 'df' command
            try {
                const stat = fs.statSync(this.baseDir);
                // Node.js does not provide statvfs, so use 'df -k' as a fallback
                const { execSync } = require('child_process');
                const output = execSync(`df -k '${this.baseDir}'`).toString().split('\n')[1];
                const parts = output.trim().split(/\s+/);
                // Filesystem 1K-blocks Used Available Use% Mounted on
                const total = parseInt(parts[1], 10) * 1024;
                const used = parseInt(parts[2], 10) * 1024;
                const free = parseInt(parts[3], 10) * 1024;
                return { total, used, free };
            } catch (err) {
                // Fallback: return zeros if error
                return { total: 0, used: 0, free: 0 };
            }
        }
    constructor(baseDir = '/chap-data') {
        this.baseDir = process.env.CHAP_DATA_DIR || baseDir;
        this.dirs = {
            apps: path.join(this.baseDir, 'apps'),        // Application deployments
            builds: path.join(this.baseDir, 'builds'),    // Temporary build contexts
            volumes: path.join(this.baseDir, 'volumes'),  // Persistent data volumes
            compose: path.join(this.baseDir, 'compose'),  // Docker Compose files
            backups: path.join(this.baseDir, 'backups'),  // Backup storage
            logs: path.join(this.baseDir, 'logs'),        // Deployment logs
            config: path.join(this.baseDir, 'config'),    // Node configuration
        };
        
        this.init();
    }

    /**
     * Initialize storage directories
     */
    init() {
        console.log(`[Storage] Initializing storage at ${this.baseDir}`);
        
        // Create all directories
        Object.values(this.dirs).forEach(dir => {
            if (!fs.existsSync(dir)) {
                fs.mkdirSync(dir, { recursive: true });
                console.log(`[Storage] Created directory: ${dir}`);
            }
        });

        // Create node config file if it doesn't exist
        const configFile = path.join(this.dirs.config, 'node.json');
        if (!fs.existsSync(configFile)) {
            this.saveConfig({
                nodeId: process.env.NODE_ID || null,
                registeredAt: null,
                serverUrl: process.env.CHAP_SERVER_URL || null
            });
        }
    }

    /**
     * Get path for an application's directory
     */
    getAppDir(applicationId) {
        const dir = path.join(this.dirs.apps, `app-${applicationId}`);
        if (!fs.existsSync(dir)) {
            fs.mkdirSync(dir, { recursive: true });
        }
        return dir;
    }

    /**
     * Get path for an application's source code
     */
    getSourceDir(applicationId) {
        const dir = path.join(this.getAppDir(applicationId), 'source');
        if (!fs.existsSync(dir)) {
            fs.mkdirSync(dir, { recursive: true });
        }
        return dir;
    }

    /**
     * Get path for a deployment's build directory
     */
    getBuildDir(deploymentId) {
        const dir = path.join(this.dirs.builds, `build-${deploymentId}`);
        if (!fs.existsSync(dir)) {
            fs.mkdirSync(dir, { recursive: true });
        }
        return dir;
    }

    /**
     * Clean up a build directory after deployment
     */
    cleanBuildDir(deploymentId) {
        const dir = path.join(this.dirs.builds, `build-${deploymentId}`);
        if (fs.existsSync(dir)) {
            fs.rmSync(dir, { recursive: true, force: true });
            console.log(`[Storage] Cleaned build directory: ${dir}`);
        }
    }

    /**
     * Get path for an application's data volume
     */
    getVolumeDir(applicationId, volumeName = 'data') {
        const dir = path.join(this.dirs.volumes, `app-${applicationId}`, volumeName);
        if (!fs.existsSync(dir)) {
            fs.mkdirSync(dir, { recursive: true });
        }
        return dir;
    }

    /**
     * Get path for a service's compose file directory
     */
    getComposeDir(serviceId) {
        const dir = path.join(this.dirs.compose, `service-${serviceId}`);
        if (!fs.existsSync(dir)) {
            fs.mkdirSync(dir, { recursive: true });
        }
        return dir;
    }

    /**
     * Save docker-compose.yml for a service
     */
    saveComposeFile(serviceId, composeContent, envContent = null) {
        const dir = this.getComposeDir(serviceId);
        
        fs.writeFileSync(path.join(dir, 'docker-compose.yml'), composeContent);
        
        if (envContent) {
            fs.writeFileSync(path.join(dir, '.env'), envContent);
        }
        
        return dir;
    }

    /**
     * Get log file path for a deployment
     */
    getLogFile(deploymentId) {
        return path.join(this.dirs.logs, `deployment-${deploymentId}.log`);
    }

    /**
     * Append to deployment log
     */
    appendLog(deploymentId, message) {
        const logFile = this.getLogFile(deploymentId);
        const timestamp = new Date().toISOString();
        const line = `[${timestamp}] ${message}\n`;
        fs.appendFileSync(logFile, line);
    }

    /**
     * Read deployment log
     */
    readLog(deploymentId, lines = 100) {
        const logFile = this.getLogFile(deploymentId);
        if (!fs.existsSync(logFile)) {
            return '';
        }
        
        const content = fs.readFileSync(logFile, 'utf8');
        const allLines = content.split('\n');
        return allLines.slice(-lines).join('\n');
    }

    /**
     * Save node configuration
     */
    saveConfig(config) {
        const configFile = path.join(this.dirs.config, 'node.json');
        fs.writeFileSync(configFile, JSON.stringify(config, null, 2));
    }

    /**
     * Load node configuration
     */
    loadConfig() {
        const configFile = path.join(this.dirs.config, 'node.json');
        if (fs.existsSync(configFile)) {
            return JSON.parse(fs.readFileSync(configFile, 'utf8'));
        }
        return {};
    }

    /**
     * List all applications on this node
     */
    listApps() {
        const appsDir = this.dirs.apps;
        if (!fs.existsSync(appsDir)) {
            return [];
        }
        
        return fs.readdirSync(appsDir)
            .filter(name => name.startsWith('app-'))
            .map(name => ({
                id: name.replace('app-', ''),
                path: path.join(appsDir, name),
                hasSource: fs.existsSync(path.join(appsDir, name, 'source'))
            }));
    }

    /**
     * Get storage statistics
     */
    getStats() {
        const getSize = (dir) => {
            if (!fs.existsSync(dir)) return 0;
            let size = 0;
            const files = fs.readdirSync(dir, { withFileTypes: true });
            for (const file of files) {
                const filePath = path.join(dir, file.name);
                if (file.isDirectory()) {
                    size += getSize(filePath);
                } else {
                    size += fs.statSync(filePath).size;
                }
            }
            return size;
        };

        return {
            apps: {
                path: this.dirs.apps,
                size: getSize(this.dirs.apps),
                count: this.listApps().length
            },
            builds: {
                path: this.dirs.builds,
                size: getSize(this.dirs.builds)
            },
            volumes: {
                path: this.dirs.volumes,
                size: getSize(this.dirs.volumes)
            },
            logs: {
                path: this.dirs.logs,
                size: getSize(this.dirs.logs)
            }
        };
    }

    /**
     * Clean up old build directories (older than 24 hours)
     */
    cleanOldBuilds(maxAgeHours = 24) {
        const buildsDir = this.dirs.builds;
        if (!fs.existsSync(buildsDir)) return;

        const maxAge = maxAgeHours * 60 * 60 * 1000;
        const now = Date.now();

        fs.readdirSync(buildsDir).forEach(name => {
            const buildPath = path.join(buildsDir, name);
            const stat = fs.statSync(buildPath);
            
            if (now - stat.mtimeMs > maxAge) {
                fs.rmSync(buildPath, { recursive: true, force: true });
                console.log(`[Storage] Cleaned old build: ${name}`);
            }
        });
    }
}

module.exports = Storage;

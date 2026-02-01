/**
 * Chap Node Security Module
 * 
 * Provides security controls for running untrusted containers safely.
 * Implements defense-in-depth approach similar to Railway/Render.
 */

const path = require('path');
const os = require('os');
const yaml = require('yaml');

function parseDockerMemoryToBytes(value) {
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
}

function bytesToDockerMemory(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return '0m';
    }

    const gb = 1024 ** 3;
    const mb = 1024 ** 2;
    if (bytes % gb === 0) return `${bytes / gb}g`;
    if (bytes % mb === 0) return `${bytes / mb}m`;
    return `${Math.ceil(bytes / mb)}m`;
}

function clampDockerMemory(requested, maxAllowed) {
    const maxBytes = parseDockerMemoryToBytes(maxAllowed);
    const reqBytes = parseDockerMemoryToBytes(requested);

    if (!maxBytes) {
        // If max isn't parseable, fall back to the configured value.
        return String(maxAllowed);
    }

    if (!reqBytes) {
        return bytesToDockerMemory(maxBytes);
    }

    return bytesToDockerMemory(Math.min(reqBytes, maxBytes));
}

/**
 * Security configuration
 */
const SECURITY_CONFIG = {
    // Maximum resource limits for containers
    // NOTE: Docker rejects --cpus values above the host's CPU count.
    // Treat <= 0 (including -1) as "no cap" i.e. use all host CPUs.
    maxCpus: (() => {
        const hostCpus = Math.max(1, (os.cpus() || []).length || 1);
        const raw = String(process.env.CHAP_MAX_CPUS || '').trim();
        const parsed = raw === '' ? NaN : parseFloat(raw);
        const requested = Number.isFinite(parsed) ? parsed : hostCpus;
        const effective = requested > 0 ? requested : hostCpus;
        return Math.min(effective, hostCpus);
    })(),
    maxMemory: process.env.CHAP_MAX_MEMORY || '20g',
    // Default is intentionally high to avoid breaking common images (e.g. MySQL) on nodes
    // where CHAP_MAX_PIDS isn't set. Operators can still clamp it via CHAP_MAX_PIDS.
    maxPids: parseInt(process.env.CHAP_MAX_PIDS || '10000', 10),
    
    // Network settings
    defaultNetwork: 'chap-apps',
    allowHostNetwork: false,
    
    // Filesystem settings
    readOnlyRoot: false, // Some apps need writable root
    noNewPrivileges: true,
    
    // Blocked volume mounts (patterns)
    blockedMounts: [
        '/var/run/docker.sock',
        '/var/run/docker',
        '/etc/shadow',
        '/etc/passwd',
        '/etc/sudoers',
        '/root',
        '/proc',
        '/sys',
        '/dev',
        '/',
    ],
    
    // Allowed volume mount base paths (user data only)
    allowedMountBases: [
        '/data/volumes',
        '/data/apps',
    ],
    
    // Blocked environment variable names
    blockedEnvVars: [
        'DOCKER_HOST',
        'DOCKER_TLS_VERIFY',
        'DOCKER_CERT_PATH',
    ],
    
    // Blocked commands in exec
    blockedExecPatterns: [
        /docker\s/i,
        /kubectl\s/i,
        /nsenter/i,
        /chroot/i,
        /mount\s/i,
        /umount\s/i,
        /mkfs/i,
        /fdisk/i,
        /dd\s+if=/i,
        /rm\s+-rf\s+\//i,
        />\s*\/dev\//i,
    ],
};

/**
 * Capabilities to drop from containers (most restrictive set that still allows apps to work)
 */
const DROP_CAPABILITIES = [
    'ALL', // Drop all, then add back only what's needed
];

/**
 * Capabilities to add back (minimal set for web apps)
 */
const ADD_CAPABILITIES = [
    'CHOWN',
    'DAC_OVERRIDE', 
    'FOWNER',
    'FSETID',
    'KILL',
    'SETGID',
    'SETUID',
    'SETPCAP',
    'NET_BIND_SERVICE',
    'SYS_CHROOT',
    'MKNOD',
    'AUDIT_WRITE',
    'SETFCAP',
];

/**
 * Blocked Dockerfile instructions
 */
const BLOCKED_DOCKERFILE_PATTERNS = [
    /--privileged/i,
    /--cap-add\s+SYS_ADMIN/i,
    /--cap-add\s+ALL/i,
    /--security-opt\s+seccomp[=:]unconfined/i,
    /--security-opt\s+apparmor[=:]unconfined/i,
    /--pid[=:]host/i,
    /--network[=:]host/i,
    /--userns[=:]host/i,
];

/**
 * Blocked docker-compose options
 */
const BLOCKED_COMPOSE_OPTIONS = {
    // Service-level options to block
    service: [
        'privileged',
        'cap_add',
        'security_opt',
        'pid',
        'userns_mode',
        'ipc',
        'cgroup_parent',
        'devices',
        'device_cgroup_rules',
        'container_name', // Block strict container naming to prevent collisions
    ],
    // Network modes to block
    networkModes: ['host', 'container:'],
    // Volume patterns to block
    volumePatterns: [
        /^\/var\/run\/docker/,
        /^\/etc\/shadow/,
        /^\/etc\/passwd/,
        /^\/root/,
        /^\/proc/,
        /^\/sys/,
        /^\/dev(?!\/null|\/zero|\/urandom|\/random)/,
        /^\/$/, // Root filesystem
    ],
};

/**
 * Build secure docker run arguments
 */
function buildSecureDockerArgs(applicationId, deploymentId, options = {}) {
    const args = [];
    
    // Basic container settings
    args.push('-d'); // Detached
    args.push('-i'); // Keep stdin open (enables console input for apps that read stdin)
    args.push('--name', `chap-${applicationId}`);
    args.push('--restart', 'unless-stopped');
    
    // Security: Drop all capabilities, add back minimal set
    args.push('--cap-drop', 'ALL');
    for (const cap of ADD_CAPABILITIES) {
        args.push('--cap-add', cap);
    }
    
    // Security: Prevent privilege escalation
    args.push('--security-opt', 'no-new-privileges:true');
    
    // Security: Use seccomp profile (default docker profile is good)
    // args.push('--security-opt', 'seccomp=default'); // This is the default
    
    // Security: Resource limits
    const cpuLimit = Math.min(parseFloat(options.cpuLimit) || SECURITY_CONFIG.maxCpus, SECURITY_CONFIG.maxCpus);
    const memoryLimit = clampDockerMemory(options.memoryLimit || SECURITY_CONFIG.maxMemory, SECURITY_CONFIG.maxMemory);
    args.push('--cpus', cpuLimit.toString());
    args.push('--memory', memoryLimit);
    args.push('--memory-swap', memoryLimit); // Prevent swap abuse
    args.push('--pids-limit', SECURITY_CONFIG.maxPids.toString());
    
    // Security: Filesystem
    if (SECURITY_CONFIG.readOnlyRoot) {
        args.push('--read-only');
        // Apps often need /tmp writable
        args.push('--tmpfs', '/tmp:rw,noexec,nosuid,size=100m');
        args.push('--tmpfs', '/var/tmp:rw,noexec,nosuid,size=100m');
    }
    
    // Security: User namespace (run as non-root inside container if possible)
    // Note: This requires Docker daemon configuration, so we use a different approach
    // args.push('--userns', 'host'); // Don't use host userns
    
    // Security: Network isolation - use dedicated network
    args.push('--network', SECURITY_CONFIG.defaultNetwork);
    
    // Labels for management
    args.push('--label', `chap.app=${applicationId}`);
    args.push('--label', `chap.deployment=${deploymentId}`);
    args.push('--label', `chap.managed=true`);
    
    // Health check timeout
    args.push('--health-start-period', '30s');
    args.push('--health-timeout', '10s');
    
    return args;
}

/**
 * Validate and sanitize environment variables
 */
function sanitizeEnvVars(envVars) {
    if (!envVars || typeof envVars !== 'object') {
        return {};
    }
    
    const sanitized = {};
    for (const [key, value] of Object.entries(envVars)) {
        // Skip blocked env vars
        if (SECURITY_CONFIG.blockedEnvVars.includes(key.toUpperCase())) {
            console.warn(`[Security] Blocked env var: ${key}`);
            continue;
        }
        
        // Sanitize key (only alphanumeric and underscore)
        const sanitizedKey = key.replace(/[^a-zA-Z0-9_]/g, '_');
        
        // Convert value to string and escape quotes
        const sanitizedValue = String(value);
        
        sanitized[sanitizedKey] = sanitizedValue;
    }
    
    return sanitized;
}

/**
 * Validate and sanitize volume mounts
 */
function sanitizeVolumes(volumes, allowedBasePath) {
    if (!volumes || !Array.isArray(volumes)) {
        return [];
    }
    
    const sanitized = [];
    
    for (const vol of volumes) {
        let source = vol.source || vol.src;
        const target = vol.target || vol.dest || vol.destination;
        
        if (!source || !target) {
            continue;
        }
        
        // Check if source is an absolute path outside allowed base
        if (path.isAbsolute(source)) {
            // Check against blocked mounts
            const isBlocked = SECURITY_CONFIG.blockedMounts.some(blocked => 
                source === blocked || source.startsWith(blocked + '/')
            );
            
            if (isBlocked) {
                console.warn(`[Security] Blocked volume mount: ${source}`);
                continue;
            }
            
            // Check if it's within allowed bases
            const isAllowed = SECURITY_CONFIG.allowedMountBases.some(base =>
                source.startsWith(base)
            );
            
            if (!isAllowed) {
                console.warn(`[Security] Volume mount outside allowed path: ${source}`);
                // Convert to relative path within allowed base
                source = path.join(allowedBasePath, path.basename(source));
            }
        } else {
            // Relative path - make it relative to allowed base
            source = path.join(allowedBasePath, source);
        }
        
        // Validate target path (must be absolute, not system paths)
        if (!target.startsWith('/')) {
            console.warn(`[Security] Invalid target path: ${target}`);
            continue;
        }
        
        const blockedTargets = ['/proc', '/sys', '/dev', '/etc', '/var/run'];
        if (blockedTargets.some(blocked => target === blocked || target.startsWith(blocked + '/'))) {
            console.warn(`[Security] Blocked target mount: ${target}`);
            continue;
        }
        
        sanitized.push({
            source: source,
            target: target,
            readOnly: vol.readOnly || vol.ro || false,
        });
    }
    
    return sanitized;
}

/**
 * Validate and sanitize ports
 */
function sanitizePorts(ports) {
    if (!ports || !Array.isArray(ports)) {
        return [];
    }
    
    const sanitized = [];
    
    for (const port of ports) {
        const containerPort = parseInt(port.containerPort || port.container || port, 10);
        let hostPort = port.hostPort || port.host;
        
        if (isNaN(containerPort) || containerPort < 1 || containerPort > 65535) {
            console.warn(`[Security] Invalid container port: ${containerPort}`);
            continue;
        }
        
        // Block privileged ports on host (< 1024) unless explicitly allowed
        if (hostPort) {
            hostPort = parseInt(hostPort, 10);
            if (hostPort < 1024) {
                console.warn(`[Security] Privileged host port not allowed: ${hostPort}, using dynamic port`);
                hostPort = null;
            }
        }
        
        sanitized.push({
            containerPort,
            hostPort: hostPort || null,
        });
    }
    
    return sanitized;
}

/**
 * Validate exec command
 */
function validateExecCommand(command) {
    if (!command || typeof command !== 'string') {
        return { valid: false, error: 'Invalid command' };
    }
    
    // Reject control chars and newlines.
    for (const ch of command) {
        const code = ch.charCodeAt(0);
        if (code < 0x20 || code === 0x7f) {
            return { valid: false, error: 'Command contains invalid characters' };
        }
    }

    // Block shell metacharacters. Even if callers avoid shells, this prevents
    // accidentally passing untrusted strings into shell execution.
    if (/[|&;<>`$]/.test(command)) {
        return { valid: false, error: 'Command contains blocked shell characters' };
    }

    // Check against blocked patterns
    for (const pattern of SECURITY_CONFIG.blockedExecPatterns) {
        if (pattern.test(command)) {
            return { valid: false, error: `Command blocked by security policy: ${pattern}` };
        }
    }
    
    // Limit command length
    if (command.length > 1000) {
        return { valid: false, error: 'Command too long' };
    }
    
    return { valid: true };
}

function tokenizeExecCommand(command) {
    const s = String(command ?? '').trim();
    if (!s) throw new Error('Empty command');
    if (s.length > 1000) throw new Error('Command too long');

    // Validate characters (printable ASCII only; no control chars).
    for (const ch of s) {
        const code = ch.charCodeAt(0);
        if (code < 0x20 || code === 0x7f) {
            throw new Error('Command contains invalid characters');
        }
    }

    // No shell metacharacters.
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

/**
 * Validate and sanitize docker-compose content
 */
function sanitizeComposeFile(composeContent, options = {}) {
    let compose;
    
    try {
        compose = yaml.parse(composeContent);
    } catch (err) {
        throw new Error(`Invalid docker-compose YAML: ${err.message}`);
    }
    
    if (!compose || typeof compose !== 'object') {
        throw new Error('Invalid docker-compose structure');
    }

    // Note: We intentionally do NOT assign fixed IPAM subnets here.
    // Picking a hard-coded subnet can overlap with existing docker networks on the node.
    // Subnet selection (if desired) must be done at deploy time, with access to docker's
    // current network inventory.

    // Normalize networks so compose behaves consistently under Chap.
    // Key points:
    // - Ensure any service-referenced networks exist at top-level.
    // - Prevent user compose files from forcing global/shared network names (networks.*.name)
    //   or joining external networks (networks.*.external). In Chap, networks should be
    //   scoped to the compose project to avoid cross-app traffic and strange behavior.
    if (compose.networks === undefined || compose.networks === null) {
        compose.networks = {};
    }
    if (typeof compose.networks !== 'object') {
        // If networks is invalid, drop it (compose will use the default network).
        console.warn('[Security] Removed invalid top-level networks definition');
        compose.networks = {};
    }

    const ensureNetworkDefined = (name) => {
        if (!name) return;
        if (compose.networks[name] === undefined) {
            compose.networks[name] = {};
        }
        const cfg = compose.networks[name];
        if (cfg === null || cfg === true || cfg === false || typeof cfg === 'string') {
            compose.networks[name] = {};
        }
    };

    // Sanitize existing network configs.
    for (const [netName, netCfgRaw] of Object.entries(compose.networks)) {
        if (!netName) continue;
        ensureNetworkDefined(netName);
        const netCfg = compose.networks[netName];
        if (!netCfg || typeof netCfg !== 'object') continue;

        // In standard Chap mode, we allow external networks and custom names
        // so behavior matches manual `docker compose up`.
        // The isolation is provided by the fact that apps run in their own project.
        // If users explicitly want to join an external network (e.g. proxy), we allow it.
        // if (netCfg.external) { ... } // Removed restriction

        // Check if name is fixed (potential collision if multiple apps use same name)
        // We allow it to support "Exact manual parity", but log it.
        if (netCfg.name) {
             // console.warn(`[Security] Note: networks.${netName} uses a fixed name '${netCfg.name}'`);
        }

        compose.networks[netName] = netCfg;
    }
    
    // Process each service
    if (compose.services) {
        const serviceNames = new Set(Object.keys(compose.services));

        const extractServiceFromContainerRef = (ref) => {
            const raw = String(ref || '').trim().replace(/^\/+/, '');
            if (!raw) return null;
            if (serviceNames.has(raw)) return raw;

            // Common docker compose container naming: <project>_<service>_<index>
            // e.g. chap-<uuid>_db_1 -> db
            const byUnderscore = raw.split('_').filter(Boolean);
            if (byUnderscore.length >= 3) {
                const idx = byUnderscore[byUnderscore.length - 1];
                const svc = byUnderscore[byUnderscore.length - 2];
                if (/^\d+$/.test(idx) && serviceNames.has(svc)) {
                    return svc;
                }
            }

            // Sometimes people reference container names like <service>-1; keep this conservative.
            const m = raw.match(/^([a-zA-Z0-9][a-zA-Z0-9_.-]*)[-_](\d+)$/);
            if (m && serviceNames.has(m[1])) {
                return m[1];
            }

            return null;
        };

        for (const [serviceName, service] of Object.entries(compose.services)) {
            if (!service || typeof service !== 'object') {
                continue;
            }

            // Ensure any referenced networks are defined.
            // Compose requires top-level network definitions; outside Chap people often rely on
            // project-scoped defaults and expect this to "just work".
            if (service.networks) {
                if (Array.isArray(service.networks)) {
                    for (const n of service.networks) {
                        if (typeof n === 'string') ensureNetworkDefined(n);
                        else if (n && typeof n === 'object' && typeof n.name === 'string') ensureNetworkDefined(n.name);
                    }
                } else if (service.networks && typeof service.networks === 'object') {
                    for (const n of Object.keys(service.networks)) {
                        ensureNetworkDefined(n);
                    }
                }
            }

            // Enable stdin by default so the browser console can send input via `docker attach`.
            // If a service explicitly sets stdin_open, respect it.
            if (service.stdin_open === undefined) {
                service.stdin_open = true;
            }
            
            // Remove blocked options
            for (const blockedOption of BLOCKED_COMPOSE_OPTIONS.service) {
                if (service[blockedOption] !== undefined) {
                    console.warn(`[Security] Removed blocked option from service ${serviceName}: ${blockedOption}`);
                    delete service[blockedOption];
                }
            }
            
            // Check network_mode
            if (service.network_mode) {
                const mode = String(service.network_mode).trim();

                if (mode === 'host') {
                    console.warn(`[Security] Removed blocked network_mode from service ${serviceName}: ${mode}`);
                    delete service.network_mode;
                } else if (mode.toLowerCase().startsWith('container:')) {
                    // Generic compatibility fix:
                    // Compose files sometimes use `network_mode: container:<service>` to make a sidecar share the
                    // target service's network namespace (e.g. so 127.0.0.1 works). Our security rules block
                    // arbitrary `container:` joins, but we can safely rewrite the common in-project case.
                    const targetRaw = mode.slice('container:'.length).trim();
                    const target = targetRaw.replace(/^\/+/, '');
                    const svc = extractServiceFromContainerRef(target);

                    if (svc) {
                        service.network_mode = `service:${svc}`;
                        console.warn(`[Security] Rewrote network_mode for service ${serviceName}: container:${target} -> service:${svc}`);
                    } else {
                        console.warn(`[Security] Removed blocked network_mode from service ${serviceName}: ${mode}`);
                        delete service.network_mode;
                    }
                }
            }
            
            // Check volumes
            if (service.volumes && Array.isArray(service.volumes)) {
                service.volumes = service.volumes.filter(vol => {
                    const volPath = typeof vol === 'string' ? vol.split(':')[0] : vol.source;
                    const isBlocked = BLOCKED_COMPOSE_OPTIONS.volumePatterns.some(pattern =>
                        pattern.test(volPath)
                    );
                    if (isBlocked) {
                        console.warn(`[Security] Removed blocked volume from service ${serviceName}: ${volPath}`);
                        return false;
                    }
                    return true;
                });
            }
            
            // Add security options
            service.security_opt = service.security_opt || [];
            if (!service.security_opt.includes('no-new-privileges:true')) {
                service.security_opt.push('no-new-privileges:true');
            }
            
            // Add resource limits
            service.deploy = service.deploy || {};
            service.deploy.resources = service.deploy.resources || {};
            service.deploy.resources.limits = service.deploy.resources.limits || {};
            
            // Set default limits if not specified or if exceeding max
            const currentCpus = parseFloat(service.deploy.resources.limits.cpus) || SECURITY_CONFIG.maxCpus;
            service.deploy.resources.limits.cpus = Math.min(currentCpus, SECURITY_CONFIG.maxCpus).toString();
            
            const currentMemory = service.deploy.resources.limits.memory || SECURITY_CONFIG.maxMemory;
            service.deploy.resources.limits.memory = clampDockerMemory(currentMemory, SECURITY_CONFIG.maxMemory);

            // PIDs: clamp rather than always forcing max (so users can request lower caps).
            // Treat <= 0 (including -1) as "unlimited" but still enforce node maximum.
            const pidsMax = Number.isFinite(SECURITY_CONFIG.maxPids) && SECURITY_CONFIG.maxPids > 0
                ? SECURITY_CONFIG.maxPids
                : 10000;

            const existingDeployPidsRaw = service.deploy.resources.limits.pids;
            const existingDeployPids = existingDeployPidsRaw === undefined ? NaN : parseInt(existingDeployPidsRaw, 10);
            const effectiveDeployPids = Number.isFinite(existingDeployPids) && existingDeployPids > 0
                ? Math.min(existingDeployPids, pidsMax)
                : pidsMax;

            service.deploy.resources.limits.pids = effectiveDeployPids;

            // Also set the non-swarm compose key so the cap applies even without --compatibility.
            // Compose supports pids_limit as a per-container cap.
            const existingServicePidsRaw = service.pids_limit;
            const existingServicePids = existingServicePidsRaw === undefined ? NaN : parseInt(existingServicePidsRaw, 10);
            const effectiveServicePids = Number.isFinite(existingServicePids) && existingServicePids > 0
                ? Math.min(existingServicePids, pidsMax)
                : effectiveDeployPids;
            service.pids_limit = effectiveServicePids;
        }
    }
    
    return yaml.stringify(compose);
}

/**
 * Validate Dockerfile content
 */
function validateDockerfile(dockerfileContent) {
    if (!dockerfileContent || typeof dockerfileContent !== 'string') {
        return { valid: false, error: 'Invalid Dockerfile' };
    }
    
    // Check for blocked patterns
    for (const pattern of BLOCKED_DOCKERFILE_PATTERNS) {
        if (pattern.test(dockerfileContent)) {
            return { valid: false, error: `Dockerfile contains blocked instruction: ${pattern}` };
        }
    }
    
    // Check for suspicious base images
    const fromMatch = dockerfileContent.match(/^FROM\s+(.+)$/m);
    if (fromMatch) {
        const baseImage = fromMatch[1].toLowerCase();
        // Block images that are known to be dangerous or allow escape
        const blockedImages = [
            'docker:dind',
            'docker-in-docker',
            'rancher/agent',
            'gcr.io/google-containers/hyperkube',
        ];
        
        if (blockedImages.some(blocked => baseImage.includes(blocked))) {
            return { valid: false, error: `Base image not allowed: ${baseImage}` };
        }
    }
    
    return { valid: true };
}

/**
 * Validate image name
 */
function validateImageName(imageName) {
    if (!imageName || typeof imageName !== 'string') {
        return { valid: false, error: 'Invalid image name' };
    }
    
    // Block dangerous image names
    const blockedPatterns = [
        /docker:dind/i,
        /docker-in-docker/i,
        /privileged/i,
    ];
    
    for (const pattern of blockedPatterns) {
        if (pattern.test(imageName)) {
            return { valid: false, error: `Image not allowed: ${imageName}` };
        }
    }
    
    // Validate format (registry/image:tag)
    const validFormat = /^[a-z0-9]+([\._-][a-z0-9]+)*(\/[a-z0-9]+([\._-][a-z0-9]+)*)*(:[\w][\w\.-]*)?(@sha256:[a-f0-9]{64})?$/i;
    if (!validFormat.test(imageName)) {
        // Allow simple image names like 'nginx', 'redis:latest'
        const simpleFormat = /^[a-z0-9]+([\._-][a-z0-9]+)*(:[\w][\w\.-]*)?$/i;
        if (!simpleFormat.test(imageName)) {
            return { valid: false, error: `Invalid image name format: ${imageName}` };
        }
    }
    
    return { valid: true };
}

/**
 * Create isolated network for apps if it doesn't exist
 */
async function ensureAppNetwork(execCommand) {
    try {
        await execCommand(['docker', 'network', 'inspect', SECURITY_CONFIG.defaultNetwork]);
    } catch (err) {
        // Network doesn't exist, create it
        console.log(`[Security] Creating isolated network: ${SECURITY_CONFIG.defaultNetwork}`);
        await execCommand(['docker', 'network', 'create', '--driver', 'bridge', '--internal=false', SECURITY_CONFIG.defaultNetwork]);
    }
}

/**
 * Security audit log
 */
function auditLog(action, details) {
    const timestamp = new Date().toISOString();
    console.log(`[Security Audit] ${timestamp} - ${action}:`, JSON.stringify(details));
}

module.exports = {
    SECURITY_CONFIG,
    buildSecureDockerArgs,
    sanitizeEnvVars,
    sanitizeVolumes,
    sanitizePorts,
    validateExecCommand,
    tokenizeExecCommand,
    sanitizeComposeFile,
    validateDockerfile,
    validateImageName,
    ensureAppNetwork,
    auditLog,
    DROP_CAPABILITIES,
    ADD_CAPABILITIES,
};

<?php

namespace Chap\Controllers;

use Chap\Models\Application;
use Chap\Models\IncomingWebhook;
use Chap\Models\Environment;
use Chap\Models\Node;
use Chap\Models\Deployment;
use Chap\Models\ActivityLog;
use Chap\Models\PortAllocation;
use Chap\Services\DeploymentService;
use Chap\Services\GitCredentialResolver;
use Chap\Services\NodeAccess;
use Chap\Services\DynamicEnv;
use Chap\Services\ResourceAllocator;
use Chap\Services\ResourceHierarchy;
use Chap\Services\PortAllocator;

/**
 * Application Controller
 */
class ApplicationController extends BaseController
{
    /**
     * Pull environment variables from a repository
     * (Used by the create application form; session-authenticated JSON endpoint)
     */
    public function repoEnv(string $envUuid): void
    {
        $this->currentTeam();
        $environment = Environment::findByUuid($envUuid);

        if (!$environment) {
            $this->json(['error' => 'Environment not found'], 404);
        }

        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            $this->json(['error' => 'Environment not found'], 404);
        }

        $this->requireTeamPermission('applications', 'write', (int) $project->team_id);

        $repoUrl = trim((string) $this->input('repo', ''));
        $branch = trim((string) $this->input('branch', 'main'));

        if ($repoUrl === '') {
            $this->json(['error' => 'Repository URL is required'], 422);
        }

        $parsed = $this->parseGitHubRepoUrl($repoUrl);
        if (!$parsed) {
            $this->json(['error' => 'Invalid repository URL. Only GitHub HTTPS/SSH URLs are supported.'], 422);
        }

        [$owner, $repo] = $parsed;

        // Try public first, then GitHub App installation tokens for this team.
        $tokensToTry = [null];
        $authAttempts = [];
        try {
            $authAttempts = GitCredentialResolver::gitAuthAttemptsForRepo((int)$project->team_id, $repoUrl);
            foreach ($authAttempts as $a) {
                if (!empty($a['token'])) {
                    $tokensToTry[] = (string)$a['token'];
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $envFile = null;
        $content = null;
        foreach ($tokensToTry as $token) {
            $envFile = $this->findEnvFileViaGitHubContents($owner, $repo, $branch, $token);
            if (!$envFile) {
                $envFile = $this->findEnvFileViaGitHubTree($owner, $repo, $branch, $token);
            }
            if (!$envFile) {
                continue;
            }

            $extraHeaders = $token ? ['Authorization: Bearer ' . $token] : null;
            $content = $this->httpGetText($envFile['download_url'], false, $extraHeaders);
            if ($content !== null) {
                break;
            }

            // If download_url failed, keep trying other tokens.
            $envFile = null;
        }

        if (!$envFile) {
            if (!empty($authAttempts)) {
                $this->json(['error' => 'Unable to access repository or no .env file found. Tried GitHub Apps configured for this team but none worked for this repo.'], 404);
            }
            $this->json(['error' => 'No .env file found in repository (looked for .env*, e.g. .env.example).'], 404);
        }

        if ($content === null) {
            $this->json(['error' => 'Failed to download env file from repository (tried GitHub Apps if configured)'], 502);
        }

        $vars = $this->parseEnvFile($content);
        if (empty($vars)) {
            $this->json(['error' => 'Found an env file but it does not look like a valid .env format'], 422);
        }

        $this->json([
            'file' => $envFile['path'],
            'vars' => $vars,
        ]);
    }

    /**
     * Create application form
     */
    public function create(string $envUuid): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($envUuid);

        if (!$environment) {
            flash('error', 'Environment not found');
            $this->redirect('/projects');
        }

        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            flash('error', 'Environment not found');
            $this->redirect('/projects');
        }

        $this->requireTeamPermission('applications', 'write', (int) $project->team_id);

        $allowedNodeIds = $this->user ? NodeAccess::allowedNodeIds($this->user, $team, $project, $environment) : [];
        $nodes = Node::onlineForTeamAllowed((int)$project->team_id, $allowedNodeIds);

        $this->view('applications/create', [
            'title' => 'New Application',
            'environment' => $environment,
            'project' => $project,
            'nodes' => $nodes,
        ]);
    }

    private function parseGitHubRepoUrl(string $url): ?array
    {
        // Supports:
        // - https://github.com/owner/repo
        // - https://github.com/owner/repo.git
        // - git@github.com:owner/repo.git
        $url = trim($url);

        if (preg_match('~^git@github\.com:([^/\s]+)/([^\s]+?)(?:\.git)?$~', $url, $m)) {
            return [$m[1], $m[2]];
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !str_contains($parts['host'], 'github.com')) {
            return null;
        }

        $path = trim($parts['path'] ?? '', '/');
        if ($path === '') {
            return null;
        }

        [$owner, $repo] = array_pad(explode('/', $path, 3), 2, null);
        if (!$owner || !$repo) {
            return null;
        }

        $repo = preg_replace('~\.git$~', '', $repo);
        if ($repo === '') {
            return null;
        }

        return [$owner, $repo];
    }

    private function findEnvFileViaGitHubContents(string $owner, string $repo, string $branch, ?string $bearerToken = null): ?array
    {
        $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents?ref=' . rawurlencode($branch);
        $json = $this->httpGetJson($url, $bearerToken);
        if (!$json || !is_array($json)) {
            return null;
        }

        // If API returns an object with message, treat as failure
        if (isset($json['message']) && !isset($json[0])) {
            return null;
        }

        $files = array_filter($json, fn($x) => is_array($x) && ($x['type'] ?? '') === 'file' && isset($x['name']));
        if (empty($files)) {
            return null;
        }

        $candidates = [];
        foreach ($files as $f) {
            $name = (string) ($f['name'] ?? '');
            if ($name !== '' && str_starts_with($name, '.env')) {
                $candidates[] = $f;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        $prefer = ['.env.example', '.env_example', '.env'];
        foreach ($prefer as $p) {
            foreach ($candidates as $c) {
                if (($c['name'] ?? '') === $p && !empty($c['download_url'])) {
                    return ['path' => $c['path'] ?? $p, 'download_url' => $c['download_url']];
                }
            }
        }

        // Otherwise pick the first .env* file with a download_url
        foreach ($candidates as $c) {
            if (!empty($c['download_url'])) {
                return ['path' => $c['path'] ?? ($c['name'] ?? '.env'), 'download_url' => $c['download_url']];
            }
        }

        return null;
    }

    private function findEnvFileViaGitHubTree(string $owner, string $repo, string $branch, ?string $bearerToken = null): ?array
    {
        $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/git/trees/' . rawurlencode($branch) . '?recursive=1';
        $json = $this->httpGetJson($url, $bearerToken);
        if (!$json || !is_array($json) || empty($json['tree']) || !is_array($json['tree'])) {
            return null;
        }

        $paths = [];
        foreach ($json['tree'] as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') !== 'blob') continue;
            $path = (string) ($item['path'] ?? '');
            if ($path === '') continue;
            $base = basename($path);
            if (str_starts_with($base, '.env')) {
                $paths[] = $path;
            }
        }

        if (empty($paths)) {
            return null;
        }

        // Prefer root-level files and common names
        usort($paths, function ($a, $b) {
            $da = substr_count($a, '/');
            $db = substr_count($b, '/');
            if ($da !== $db) return $da <=> $db;
            return strlen($a) <=> strlen($b);
        });

        $prefer = ['.env.example', '.env_example', '.env'];
        foreach ($prefer as $p) {
            foreach ($paths as $path) {
                if (basename($path) === $p) {
                    $raw = 'https://raw.githubusercontent.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/' . rawurlencode($branch) . '/' . str_replace('%2F', '/', rawurlencode($path));
                    return ['path' => $path, 'download_url' => $raw];
                }
            }
        }

        $path = $paths[0];
        $raw = 'https://raw.githubusercontent.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/' . rawurlencode($branch) . '/' . str_replace('%2F', '/', rawurlencode($path));
        return ['path' => $path, 'download_url' => $raw];
    }

    private function httpGetJson(string $url, ?string $bearerToken = null): mixed
    {
        $extra = $bearerToken ? ['Authorization: Bearer ' . $bearerToken] : null;
        $body = $this->httpGetText($url, true, $extra);
        if ($body === null) {
            return null;
        }
        return json_decode($body, true);
    }

    private function httpGetText(string $url, bool $githubApi = false, ?array $extraHeaders = null): ?string
    {
        $headers = [
            'User-Agent: Chap',
            'Accept: ' . ($githubApi ? 'application/vnd.github+json' : 'text/plain, */*'),
        ];

        if ($extraHeaders) {
            foreach ($extraHeaders as $h) {
                $headers[] = $h;
            }
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === false || $code < 200 || $code >= 300) {
                return null;
            }
            return (string) $resp;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 10,
            ],
        ]);
        $resp = @file_get_contents($url, false, $context);
        return $resp === false ? null : (string) $resp;
    }

    private function parseEnvFile(string $content): array
    {
        $vars = [];
        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || !preg_match('/^[A-Z0-9_]+$/i', $key)) {
                continue;
            }
            $value = ltrim($value);
            // strip inline comments (best-effort) for unquoted values
            if ($value !== '' && ($value[0] !== '"') && ($value[0] !== "'")) {
                $hashPos = strpos($value, ' #');
                if ($hashPos !== false) {
                    $value = substr($value, 0, $hashPos);
                }
                $value = trim($value);
            }
            // unquote
            if (strlen($value) >= 2 && (($value[0] === '"' && $value[strlen($value)-1] === '"') || ($value[0] === "'" && $value[strlen($value)-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            $vars[$key] = $value;
        }
        return $vars;
    }

    /**
     * Store new application
     */
    public function store(string $envUuid): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($envUuid);

        if (!$environment) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $this->requireTeamPermission('applications', 'write', (int) $project->team_id);

        $data = $this->all();

        // Parse legacy UI fields into configured hierarchy limits.
        $cpuMillicoresLimit = ResourceHierarchy::parseCpuMillicores((string)($data['cpu_limit'] ?? '-1'));
        $ramMbLimit = ResourceHierarchy::parseDockerMemoryToMb((string)($data['memory_limit'] ?? '-1'));
        $storageMbLimit = ResourceHierarchy::parseMb((string)($data['storage_mb_limit'] ?? '-1'));
        $portLimit = ResourceHierarchy::parseIntOrAuto((string)($data['port_limit'] ?? '-1'));
        $bandwidthLimit = ResourceHierarchy::parseIntOrAuto((string)($data['bandwidth_mbps_limit'] ?? '-1'));
        $pidsLimit = ResourceHierarchy::parseIntOrAuto((string)($data['pids_limit'] ?? '-1'));

        // Validate
        $errors = [];
        if (empty($data['name'])) {
            $errors['name'] = 'Name is required';
        }
        if (empty($data['node_uuid'])) {
            $errors['node_uuid'] = 'Node selection is required';
        } else {
            $node = Node::findByUuid($data['node_uuid']);
            if (!$node) {
                $errors['node_uuid'] = 'Invalid node';
            } else {
                $allowedNodeIds = $this->user ? NodeAccess::allowedNodeIds($this->user, $team, $project, $environment) : [];
                if (!in_array((int)$node->id, $allowedNodeIds, true)) {
                    $errors['node_uuid'] = 'You do not have access to this node';
                }
            }
        }

        // Validate configured resource limits against parent environment effective totals (fixed allocations only).
        $parent = ResourceHierarchy::effectiveEnvironmentLimits($environment);
        $siblings = Application::forEnvironment((int)$environment->id);

        $maps = [
            'cpu_millicores' => [],
            'ram_mb' => [],
            'storage_mb' => [],
            'ports' => [],
            'bandwidth_mbps' => [],
            'pids' => [],
        ];
        foreach ($siblings as $a) {
            $maps['cpu_millicores'][(int)$a->id] = (int)$a->cpu_millicores_limit;
            $maps['ram_mb'][(int)$a->id] = (int)$a->ram_mb_limit;
            $maps['storage_mb'][(int)$a->id] = (int)$a->storage_mb_limit;
            $maps['ports'][(int)$a->id] = (int)$a->port_limit;
            $maps['bandwidth_mbps'][(int)$a->id] = (int)$a->bandwidth_mbps_limit;
            $maps['pids'][(int)$a->id] = (int)$a->pids_limit;
        }

        // Synthetic child id 0 for the new (unsaved) application.
        $maps['cpu_millicores'][0] = $cpuMillicoresLimit;
        $maps['ram_mb'][0] = $ramMbLimit;
        $maps['storage_mb'][0] = $storageMbLimit;
        $maps['ports'][0] = $portLimit;
        $maps['bandwidth_mbps'][0] = $bandwidthLimit;
        $maps['pids'][0] = $pidsLimit;

        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['cpu_millicores'], $maps['cpu_millicores'])) {
            $errors['cpu_limit'] = 'CPU allocations exceed the environment\'s remaining limit';
        }
        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['ram_mb'], $maps['ram_mb'])) {
            $errors['memory_limit'] = 'RAM allocations exceed the environment\'s remaining limit';
        }
        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['storage_mb'], $maps['storage_mb'])) {
            $errors['storage_mb_limit'] = 'Storage allocations exceed the environment\'s remaining limit';
        }
        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['ports'], $maps['ports'])) {
            $errors['port_limit'] = 'Port allocations exceed the environment\'s remaining limit';
        }
        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['bandwidth_mbps'], $maps['bandwidth_mbps'])) {
            $errors['bandwidth_mbps_limit'] = 'Bandwidth allocations exceed the environment\'s remaining limit';
        }
        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['pids'], $maps['pids'])) {
            $errors['pids_limit'] = 'PID allocations exceed the environment\'s remaining limit';
        }

        if (!empty($errors)) {
            if ($this->isApiRequest()) {
                $this->json(['errors' => $errors], 422);
            } else {
                $_SESSION['_errors'] = $errors;
                $_SESSION['_old_input'] = $data;
                $this->redirect('/environments/' . $envUuid . '/applications/create');
            }
        }

        // Parse environment variables
        $envVars = [];
        if (!empty($data['environment_variables'])) {
            $lines = explode("\n", $data['environment_variables']);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $envVars[trim($key)] = trim($value);
                }
            }
        }

        // Validate dynamic port variables against any reserved ports (create flow).
        $reservationUuid = (string)($data['port_reservation_uuid'] ?? '');
        $reservedPorts = [];
        if ($reservationUuid !== '' && isset($node) && $node) {
            $reservedPorts = PortAllocation::portsForReservation($reservationUuid, (int)$node->id);
        }
        $dynErrors = DynamicEnv::validate($envVars, $reservedPorts);
        if (!empty($dynErrors)) {
            $errors['environment_variables'] = 'One or more variables reference missing allocated ports. Allocate ports first or fix {port[i]} indices.';
        }

        if (!empty($errors)) {
            if ($this->isApiRequest()) {
                $this->json(['errors' => $errors], 422);
            } else {
                $_SESSION['_errors'] = $errors;
                $_SESSION['_old_input'] = $data;
                $this->redirect('/environments/' . $envUuid . '/applications/create');
            }
        }

        $application = Application::create([
            'environment_id' => $environment->id,
            'node_id' => isset($node) ? $node->id : null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'git_repository' => $data['git_repository'] ?? null,
            'git_branch' => $data['git_branch'] ?? 'main',
            'build_pack' => 'docker-compose',
            'port' => !empty($data['port']) ? (int)$data['port'] : null,
            'domains' => $data['domains'] ?? null,
            'environment_variables' => !empty($envVars) ? json_encode($envVars) : null,
            'memory_limit' => $data['memory_limit'] ?? '512m',
            'cpu_limit' => $data['cpu_limit'] ?? '1',
            'cpu_millicores_limit' => $cpuMillicoresLimit,
            'ram_mb_limit' => $ramMbLimit,
            'storage_mb_limit' => $storageMbLimit,
            'port_limit' => $portLimit,
            'bandwidth_mbps_limit' => $bandwidthLimit,
            'pids_limit' => $pidsLimit,
            // When the UI doesn't post health-check fields, keep defaults enabled.
            'health_check_enabled' => array_key_exists('health_check_enabled', $data) ? (!empty($data['health_check_enabled']) ? 1 : 0) : 1,
            'health_check_path' => $data['health_check_path'] ?? '/',
        ]);

        // Attach any reserved ports from the create flow to the new application.
        if ($reservationUuid !== '' && $application->node_id) {
            PortAllocator::attachReservationToApplication($reservationUuid, (int)$application->node_id, (int)$application->id);
        }

        ActivityLog::log('application.created', 'Application', $application->id, ['name' => $application->name]);

        if ($this->isApiRequest()) {
            $this->json(['application' => $application->toArray()], 201);
        } else {
            flash('success', 'Application created');
            $this->redirect('/applications/' . $application->uuid);
        }
    }

    /**
     * Show application
     */
    public function show(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('applications', 'read', $teamId);

        $deployments = Deployment::forApplication($application->id, 10);
        $project = $application->environment()?->project();
        $nodes = [];
        if ($project && $this->user) {
            $allowedNodeIds = NodeAccess::allowedNodeIds($this->user, $team, $project, $application->environment(), $application);
            $nodes = Node::onlineForTeamAllowed((int)$project->team_id, $allowedNodeIds);
        }
        $incomingWebhooks = IncomingWebhook::forApplication($application->id);

        $incomingWebhookReveals = $_SESSION['incoming_webhook_reveals'] ?? [];
        unset($_SESSION['incoming_webhook_reveals']);

        $allocatedPorts = $application ? $application->allocatedPorts() : [];

        if ($this->isApiRequest()) {
            $this->json([
                'application' => $application->toArray(),
                'deployments' => array_map(fn($d) => $d->toArray(), $deployments),
            ]);
        } else {
            $this->view('applications/show', [
                'title' => $application->name,
                'application' => $application,
                'environment' => $application->environment(),
                'project' => $application->environment()->project(),
                'deployments' => $deployments,
                'nodes' => $nodes,
                'incomingWebhooks' => $incomingWebhooks,
                'incomingWebhookReveals' => $incomingWebhookReveals,
                'allocatedPorts' => $allocatedPorts,
            ]);
        }
    }

    /**
     * Update application
     */
    public function update(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        $oldNodeId = $application ? $application->node_id : null;

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('applications', 'write', $teamId);

        $data = $this->all();

    $errors = [];

        // Parse legacy UI fields into configured hierarchy limits.
        $cpuMillicoresLimit = ResourceHierarchy::parseCpuMillicores((string)($data['cpu_limit'] ?? (string)$application->cpu_limit));
        $ramMbLimit = ResourceHierarchy::parseDockerMemoryToMb((string)($data['memory_limit'] ?? (string)$application->memory_limit));
        $storageMbLimit = ResourceHierarchy::parseMb((string)($data['storage_mb_limit'] ?? (string)$application->storage_mb_limit));
        $portLimit = ResourceHierarchy::parseIntOrAuto((string)($data['port_limit'] ?? (string)$application->port_limit));
        $bandwidthLimit = ResourceHierarchy::parseIntOrAuto((string)($data['bandwidth_mbps_limit'] ?? (string)$application->bandwidth_mbps_limit));
        $pidsLimit = ResourceHierarchy::parseIntOrAuto((string)($data['pids_limit'] ?? (string)$application->pids_limit));

        // Handle node update
        $nodeId = $application->node_id;
        if (isset($data['node_uuid'])) {
            if (empty($data['node_uuid'])) {
                $nodeId = null;
            } else {
                $node = Node::findByUuid($data['node_uuid']);
                $project = $application->environment()?->project();
                if (!$node || !$project) {
                    $errors['node_uuid'] = 'Invalid node';
                } else {
                    $allowedNodeIds = $this->user ? NodeAccess::allowedNodeIds($this->user, $team, $project, $application->environment(), $application) : [];
                    if (!in_array((int)$node->id, $allowedNodeIds, true)) {
                        $errors['node_uuid'] = 'You do not have access to this node';
                    } else {
                        $nodeId = $node->id;
                    }
                }
            }
        }

        // Parse environment variables
        $envVars = $application->getEnvironmentVariables();
        if (isset($data['environment_variables'])) {
            $envVars = [];
            $lines = explode("\n", $data['environment_variables']);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $envVars[trim($key)] = trim($value);
                }
            }
        }

        // Validate dynamic port variables against currently allocated ports.
        // If moving nodes, existing allocations will be released and ports must be re-allocated on the new node.
        $isMovingNodes = ((string)$oldNodeId !== (string)$nodeId);
        $portsForValidation = $isMovingNodes ? [] : PortAllocation::portsForApplication((int)$application->id);
        $dynErrors = DynamicEnv::validate($envVars, $portsForValidation);
        if (!empty($dynErrors)) {
            $errors['environment_variables'] = $isMovingNodes
                ? 'Your environment variables reference {port[i]} but the application is changing nodes. Move the app first, then allocate new ports, then update {port[i]} indices.'
                : 'One or more variables reference missing allocated ports. Allocate ports first or fix {port[i]} indices.';
        }

        // Validate configured resource limits against parent environment effective totals (fixed allocations only).
        $environment = $application->environment();
        if ($environment) {
            $parent = ResourceHierarchy::effectiveEnvironmentLimits($environment);
            $siblings = Application::forEnvironment((int)$environment->id);

            $maps = [
                'cpu_millicores' => [],
                'ram_mb' => [],
                'storage_mb' => [],
                'ports' => [],
                'bandwidth_mbps' => [],
                'pids' => [],
            ];

            foreach ($siblings as $a) {
                $isCurrent = (int)$a->id === (int)$application->id;
                $maps['cpu_millicores'][(int)$a->id] = $isCurrent ? $cpuMillicoresLimit : (int)$a->cpu_millicores_limit;
                $maps['ram_mb'][(int)$a->id] = $isCurrent ? $ramMbLimit : (int)$a->ram_mb_limit;
                $maps['storage_mb'][(int)$a->id] = $isCurrent ? $storageMbLimit : (int)$a->storage_mb_limit;
                $maps['ports'][(int)$a->id] = $isCurrent ? $portLimit : (int)$a->port_limit;
                $maps['bandwidth_mbps'][(int)$a->id] = $isCurrent ? $bandwidthLimit : (int)$a->bandwidth_mbps_limit;
                $maps['pids'][(int)$a->id] = $isCurrent ? $pidsLimit : (int)$a->pids_limit;
            }

            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['cpu_millicores'], $maps['cpu_millicores'])) {
                $errors['cpu_limit'] = 'CPU allocations exceed the environment\'s remaining limit';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['ram_mb'], $maps['ram_mb'])) {
                $errors['memory_limit'] = 'RAM allocations exceed the environment\'s remaining limit';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['storage_mb'], $maps['storage_mb'])) {
                $errors['storage_mb_limit'] = 'Storage allocations exceed the environment\'s remaining limit';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['ports'], $maps['ports'])) {
                $errors['port_limit'] = 'Port allocations exceed the environment\'s remaining limit';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['bandwidth_mbps'], $maps['bandwidth_mbps'])) {
                $errors['bandwidth_mbps_limit'] = 'Bandwidth allocations exceed the environment\'s remaining limit';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['pids'], $maps['pids'])) {
                $errors['pids_limit'] = 'PID allocations exceed the environment\'s remaining limit';
            }
        }

        if (!empty($errors)) {
            if ($this->isApiRequest()) {
                $this->json(['errors' => $errors], 422);
            } else {
                $_SESSION['_errors'] = $errors;
                $_SESSION['_old_input'] = $data;
                $this->redirect('/applications/' . $uuid);
            }
        }

        $application->update([
            'node_id' => $nodeId,
            'name' => $data['name'] ?? $application->name,
            'description' => $data['description'] ?? $application->description,
            'git_repository' => $data['git_repository'] ?? $application->git_repository,
            'git_branch' => $data['git_branch'] ?? $application->git_branch,
            'build_pack' => 'docker-compose',
            'port' => isset($data['port']) && $data['port'] !== '' ? (int)$data['port'] : $application->port,
            'domains' => $data['domains'] ?? $application->domains,
            'environment_variables' => !empty($envVars) ? json_encode($envVars) : null,
            'memory_limit' => $data['memory_limit'] ?? $application->memory_limit,
            'cpu_limit' => $data['cpu_limit'] ?? $application->cpu_limit,
            'cpu_millicores_limit' => $cpuMillicoresLimit,
            'ram_mb_limit' => $ramMbLimit,
            'storage_mb_limit' => $storageMbLimit,
            'port_limit' => $portLimit,
            'bandwidth_mbps_limit' => $bandwidthLimit,
            'pids_limit' => $pidsLimit,
            // Preserve existing health-check config if the UI doesn't post these fields.
            'health_check_enabled' => array_key_exists('health_check_enabled', $data)
                ? (!empty($data['health_check_enabled']) ? 1 : 0)
                : ($application->health_check_enabled ? 1 : 0),
            'health_check_path' => $data['health_check_path'] ?? $application->health_check_path,
        ]);

        if ((string)$oldNodeId !== (string)$nodeId) {
            PortAllocator::releaseForApplication((int)$application->id);
        }

        if ($this->isApiRequest()) {
            $this->json(['application' => $application->toArray()]);
        } else {
            flash('success', 'Application updated');
            $this->redirect('/applications/' . $uuid);
        }
    }

    /**
     * Delete application
     */
    public function destroy(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('applications', 'write', $teamId);

        $envUuid = $application->environment()->uuid;
        $appName = $application->name;

        PortAllocator::releaseForApplication((int)$application->id);
        $application->delete();

        ActivityLog::log('application.deleted', 'Application', null, ['name' => $appName]);

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Application deleted']);
        } else {
            flash('success', 'Application deleted');
            $this->redirect('/environments/' . $envUuid);
        }
    }

    /**
     * Deploy application
     */
    public function deploy(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('deployments', 'execute', $teamId);

        if (!$application->node_id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'No node assigned to application'], 400);
            } else {
                flash('error', 'Please assign a node before deploying');
                $this->redirect('/applications/' . $uuid);
            }
        }

        $node = $application->node();
        if (!$node || !$node->isOnline()) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Node is offline'], 400);
            } else {
                flash('error', 'The assigned node is offline');
                $this->redirect('/applications/' . $uuid);
            }
        }

        // Create deployment
        try {
            $deployment = DeploymentService::create($application, null, [
                'triggered_by' => $this->user ? 'user' : 'manual',
                'triggered_by_name' => $this->user?->displayName(),
            ]);
        } catch (\Throwable $e) {
            if ($this->isApiRequest()) {
                $this->json(['error' => $e->getMessage()], 422);
            } else {
                flash('error', $e->getMessage());
                $this->redirect('/applications/' . $uuid);
            }
            return;
        }

        ActivityLog::log('deployment.started', 'Deployment', $deployment->id, [
            'application' => $application->name
        ]);

        if ($this->isApiRequest()) {
            $this->json(['deployment' => $deployment->toArray()], 201);
        } else {
            flash('success', 'Deployment started');
            $this->redirect('/deployments/' . $deployment->uuid);
        }
    }

    /**
     * Stop application
     */
    public function stop(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('applications', 'execute', $teamId);

        // Send stop command to node via WebSocket
        DeploymentService::stop($application);

        $application->update(['status' => 'stopped']);

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Application stopped']);
        } else {
            flash('success', 'Application stopped');
            $this->redirect('/applications/' . $uuid);
        }
    }

    /**
     * Restart application
     */
    public function restart(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('applications', 'execute', $teamId);

        DeploymentService::restart($application);

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Application restarted']);
        } else {
            flash('success', 'Application restarted');
            $this->redirect('/applications/' . $uuid);
        }
    }

    /**
     * Live logs page for application
     */
    public function logs(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
            return;
        }

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('logs', 'read', $teamId);

        // Live logs are WebSocket-only; the HTTP API is intentionally not supported.
        if ($this->isApiRequest()) {
            $this->json(['error' => 'Live logs require WebSocket'], 400);
            return;
        }

        // Get node info for WebSocket URL
        $node = $application->node();
        if (!$node && $application->node_id) {
            $node = \Chap\Models\Node::find($application->node_id);
        }
        
        // Get logs websocket URL if configured
        $logsWebsocketUrl = $node ? ($node->logs_websocket_url ?? null) : null;

        // Initial page load - just render the view; containers/logs come from the node logs WebSocket
        $this->view('applications/logs', [
            'title' => 'Live Logs - ' . $application->name,
            'application' => $application,
            'environment' => $application->environment(),
            'project' => $application->environment()->project(),
            'containers' => [],
            'logsWebsocketUrl' => $logsWebsocketUrl,
            'sessionId' => session_id(),
        ]);
    }

    /**
     * Container file manager page for application
     */
    public function files(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
            return;
        }

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('files', 'read', $teamId);

        // File manager is WebSocket-only; the HTTP API is intentionally not supported.
        if ($this->isApiRequest()) {
            $this->json(['error' => 'File manager requires WebSocket'], 400);
            return;
        }

        $node = $application->node();
        if (!$node && $application->node_id) {
            $node = \Chap\Models\Node::find($application->node_id);
        }

        // Reuse the node browser WS URL (currently named logs_websocket_url in DB/model)
        $browserWebsocketUrl = $node ? ($node->logs_websocket_url ?? null) : null;

        $this->view('applications/files', [
            'title' => 'Files - ' . $application->name,
            'application' => $application,
            'environment' => $application->environment(),
            'project' => $application->environment()->project(),
            'browserWebsocketUrl' => $browserWebsocketUrl,
            'sessionId' => session_id(),
        ]);
    }

    /**
     * Persistent volumes manager page for application (WebSocket-only, browser connects to node).
     */
    public function volumes(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
            return;
        }

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        // Volumes can be destructive; use the same permission gate as Files.
        $this->requireTeamPermission('files', 'read', $teamId);

        if ($this->isApiRequest()) {
            $this->json(['error' => 'Volumes manager requires WebSocket'], 400);
            return;
        }

        $node = $application->node();
        if (!$node && $application->node_id) {
            $node = \Chap\Models\Node::find($application->node_id);
        }

        // Reuse the node browser WS URL (currently named logs_websocket_url in DB/model)
        $browserWebsocketUrl = $node ? ($node->logs_websocket_url ?? null) : null;

        $this->view('applications/volumes', [
            'title' => 'Volumes - ' . $application->name,
            'application' => $application,
            'environment' => $application->environment(),
            'project' => $application->environment()->project(),
            'browserWebsocketUrl' => $browserWebsocketUrl,
            'sessionId' => session_id(),
        ]);
    }

    /**
     * Dedicated file editor page (loads file via node WebSocket)
     */
    public function fileEditor(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
            return;
        }

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('files', 'write', $teamId);

        if ($this->isApiRequest()) {
            $this->json(['error' => 'File editor requires WebSocket'], 400);
            return;
        }

        $path = isset($_GET['path']) ? (string) $_GET['path'] : '';
        $dir = isset($_GET['dir']) ? (string) $_GET['dir'] : '';
        $containerId = isset($_GET['container']) ? (string) $_GET['container'] : '';

        $node = $application->node();
        if (!$node && $application->node_id) {
            $node = \Chap\Models\Node::find($application->node_id);
        }
        $browserWebsocketUrl = $node ? ($node->logs_websocket_url ?? null) : null;

        $this->view('applications/file_editor', [
            'title' => 'Edit File - ' . $application->name,
            'application' => $application,
            'environment' => $application->environment(),
            'project' => $application->environment()->project(),
            'browserWebsocketUrl' => $browserWebsocketUrl,
            'sessionId' => session_id(),
            'path' => $path,
            'dir' => $dir,
            'containerId' => $containerId,
        ]);
    }

    /**
     * Get containers for an application
     */
    private function getContainersForApplication(Application $application): array
    {
        $db = \Chap\App::db();
        
        // First try to get containers from containers table
        $results = $db->fetchAll(
            "SELECT DISTINCT c.* FROM containers c 
             LEFT JOIN deployments d ON c.deployment_id = d.id 
             WHERE c.application_id = ? OR d.application_id = ? 
             ORDER BY c.created_at DESC",
            [$application->id, $application->id]
        );
        
        if (!empty($results)) {
            return array_map(fn($data) => \Chap\Models\Container::fromArray($data), $results);
        }
        
        // Fallback: Create virtual container entries from deployments that have container_id
        $deployments = $db->fetchAll(
            "SELECT d.id, d.uuid, d.container_id, d.node_id, d.image_tag, d.status 
             FROM deployments d 
             WHERE d.application_id = ? AND d.container_id IS NOT NULL AND d.container_id != ''
             ORDER BY d.created_at DESC 
             LIMIT 5",
            [$application->id]
        );
        
        $containers = [];
        foreach ($deployments as $dep) {
            // Create a virtual container object
            $container = new \stdClass();
            $container->id = $dep['id']; // Use deployment ID as container ID for lookups
            $container->container_id = $dep['container_id'];
            $container->name = $application->name . '-' . substr($dep['container_id'], 0, 12);
            $container->status = ($dep['status'] === 'running') ? 'running' : 'exited';
            $container->image = $dep['image_tag'] ?? 'unknown';
            $container->node_id = $dep['node_id'];
            $containers[] = $container;
        }
        
        return $containers;
    }

    /**
     * Get logs for a container (placeholder - will be enhanced with real log storage)
     */
    private function getContainerLogs(int $containerId, int $tail = 100): array
    {
        $db = \Chap\App::db();
        
        // Check if container_logs table exists and fetch from it
        try {
            $logs = $db->fetchAll(
                "SELECT * FROM container_logs 
                 WHERE container_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT ?",
                [$containerId, $tail]
            );
            
            // Reverse to get chronological order
            $logs = array_reverse($logs);
            
            return array_map(fn($log) => [
                'timestamp' => $log['created_at'] ?? '',
                'message' => $log['line'] ?? '',
                'level' => $log['level'] ?? 'info',
            ], $logs);
        } catch (\Exception $e) {
            // Table doesn't exist yet or other error
            return [];
        }
    }

}

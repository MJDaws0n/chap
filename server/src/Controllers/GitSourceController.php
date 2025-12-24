<?php

namespace Chap\Controllers;

use Chap\Models\GitSource;
use Chap\Models\ActivityLog;
use Chap\Services\GitHubAppManifestService;
use Chap\Services\GitHubAppTokenService;

/**
 * Git Source Controller
 * 
 * Manages connections to Git providers (GitHub, GitLab, Bitbucket)
 */
class GitSourceController extends BaseController
{
    /**
     * List git sources
     */
    public function index(): void
    {
        $team = $this->currentTeam();

        $tab = trim((string) $this->input('tab', 'github-apps'));
        if (!in_array($tab, ['github-apps', 'oauth', 'deploy-keys'], true)) {
            $tab = 'github-apps';
        }

        $gitSources = GitSource::forTeam((int)$team->id);
        $githubApps = array_values(array_filter($gitSources, fn($s) => $s instanceof GitSource && $s->inferredAuthMethod() === 'github_app'));
        $oauthSources = array_values(array_filter($gitSources, fn($s) => $s instanceof GitSource && $s->inferredAuthMethod() === 'oauth'));
        $deployKeys = array_values(array_filter($gitSources, fn($s) => $s instanceof GitSource && $s->inferredAuthMethod() === 'deploy_key'));

        $this->view('git-sources/index', [
            'title' => 'Git Sources',
            'tab' => $tab,
            'gitSources' => $gitSources,
            'githubApps' => $githubApps,
            'oauthSources' => $oauthSources,
            'deployKeys' => $deployKeys,
        ]);
    }

    /**
     * Show GitHub App create form
     */
    public function createGithubApp(): void
    {
        $this->view('git-sources/github-apps/create', [
            'title' => 'Add GitHub App',
        ]);
    }

    /**
     * Show manifest-based GitHub App auto-setup form.
     */
    public function createGithubAppManifest(): void
    {
        $baseUrl = request_base_url();
        $redirectUrl = rtrim($baseUrl, '/') . '/git-sources/github-apps/manifest/callback';

        $this->view('git-sources/github-apps/manifest/create', [
            'title' => 'Auto-create GitHub App',
            'defaultBaseUrl' => $baseUrl,
            'defaultRedirectUrl' => $redirectUrl,
        ]);
    }

    /**
     * Start the manifest flow by rendering an auto-submitting form to GitHub.
     */
    public function startGithubAppManifest(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/git-sources?tab=github-apps');
            return;
        }

        $team = $this->currentTeam();

        $name = trim((string)$this->input('name', ''));
        $org = trim((string)$this->input('organization', ''));
        $baseUrl = trim((string)$this->input('base_url', ''));
        if ($baseUrl === '') {
            $baseUrl = request_base_url();
        }

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required';
        }
        if ($org !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,38}$/', $org)) {
            $errors['organization'] = 'Organization must be a valid GitHub org login';
        }
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            $errors['base_url'] = 'Base URL must be a valid URL (e.g. https://chap.example.com)';
        }

        if (!empty($errors)) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old_input'] = $this->all();
            $this->redirect('/git-sources/github-apps/manifest/create');
            return;
        }

        $baseUrl = rtrim($baseUrl, '/');
        $redirectUrl = $baseUrl . '/git-sources/github-apps/manifest/callback';

        $state = generate_token(16);
        $_SESSION['github_app_manifest'] = $_SESSION['github_app_manifest'] ?? [];
        $_SESSION['github_app_manifest'][$state] = [
            'team_id' => (int)$team->id,
            'name' => $name,
            'organization' => $org,
            'base_url' => $baseUrl,
            'created_at' => time(),
        ];

        $manifest = GitHubAppManifestService::buildManifest($name, $baseUrl, $redirectUrl);

        $action = $org !== ''
            ? 'https://github.com/organizations/' . rawurlencode($org) . '/settings/apps/new'
            : 'https://github.com/settings/apps/new';

        $this->view('git-sources/github-apps/manifest/submit', [
            'title' => 'Redirecting to GitHub…',
            'actionUrl' => $action . '?state=' . rawurlencode($state),
            'manifestJson' => json_encode($manifest, JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Manifest flow callback from GitHub.
     */
    public function handleGithubAppManifestCallback(): void
    {
        $team = $this->currentTeam();

        $code = trim((string)$this->input('code', ''));
        $state = trim((string)$this->input('state', ''));

        $flows = $_SESSION['github_app_manifest'] ?? [];
        $flow = (is_array($flows) && isset($flows[$state]) && is_array($flows[$state])) ? $flows[$state] : null;

        if ($code === '' || $state === '' || !$flow) {
            flash('error', 'Invalid GitHub manifest callback. Please try again.');
            $this->redirect('/git-sources?tab=github-apps');
            return;
        }

        unset($_SESSION['github_app_manifest'][$state]);

        if ((int)($flow['team_id'] ?? 0) !== (int)$team->id) {
            flash('error', 'Manifest flow does not match your current team.');
            $this->redirect('/git-sources?tab=github-apps');
            return;
        }

        try {
            $converted = GitHubAppManifestService::convert($code);
        } catch (\Throwable $e) {
            flash('error', 'Failed to create GitHub App: ' . $e->getMessage());
            $this->redirect('/git-sources?tab=github-apps');
            return;
        }

        $appId = (int)($converted['id'] ?? 0);
        $pem = isset($converted['pem']) ? (string)$converted['pem'] : '';
        if ($appId <= 0 || $pem === '') {
            flash('error', 'GitHub did not return app credentials. Please try again.');
            $this->redirect('/git-sources?tab=github-apps');
            return;
        }

        $defaults = GitSource::getProviderDefaults('github');
        $source = GitSource::create([
            'team_id' => (int)$team->id,
            'name' => (string)($flow['name'] ?? 'GitHub App'),
            'type' => 'github',
            'base_url' => $defaults['base_url'] ?? null,
            'api_url' => $defaults['api_url'] ?? null,
            'auth_method' => 'github_app',
            'is_oauth' => 0,
            'oauth_token' => null,
            'github_app_id' => $appId,
            'github_app_installation_id' => null,
            'github_app_private_key' => $pem,
            'is_active' => 1,
        ]);

        ActivityLog::log('git_source.github_app.created', 'GitSource', $source->id, [
            'provider' => 'github',
            'auth_method' => 'github_app',
            'via' => 'manifest',
        ]);

        // Attempt to auto-detect installation ID (if already installed).
        try {
            $installations = GitHubAppTokenService::listInstallations($appId, $pem);
            if (count($installations) === 1 && !empty($installations[0]['id'])) {
                $source->update(['github_app_installation_id' => (int)$installations[0]['id']]);
                flash('success', 'GitHub App created and installation detected');
                $this->redirect('/git-sources?tab=github-apps');
                return;
            }
        } catch (\Throwable $e) {
            // Ignore: likely not installed yet.
        }

        flash('success', 'GitHub App created. Finish setup by installing it and selecting an installation.');
        $this->redirect('/git-sources/github-apps/' . ($source->uuid ?? (string)$source->id) . '/installations');
    }

    /**
     * List/refresh installations for a GitHub App and let the user select one.
     */
    public function githubAppInstallations(string $id): void
    {
        $team = $this->currentTeam();
        $source = GitSource::findByUuid($id) ?? GitSource::find((int)$id);
        if (!$source || (int)$source->team_id !== (int)$team->id || $source->inferredAuthMethod() !== 'github_app') {
            flash('error', 'GitHub App not found');
            $this->redirect('/git-sources?tab=github-apps');
            return;
        }

        $installations = [];
        $installUrl = null;
        $appInfo = [];

        try {
            if ($source->github_app_id && $source->github_app_private_key) {
                $appInfo = GitHubAppTokenService::getApp((int)$source->github_app_id, (string)$source->github_app_private_key);
                $slug = isset($appInfo['slug']) ? (string)$appInfo['slug'] : '';
                if ($slug !== '') {
                    $installUrl = 'https://github.com/apps/' . rawurlencode($slug) . '/installations/new';
                }

                $installations = GitHubAppTokenService::listInstallations((int)$source->github_app_id, (string)$source->github_app_private_key);
            }
        } catch (\Throwable $e) {
            // We'll just show an empty list and let the user install then refresh.
            $installations = [];
        }

        $this->view('git-sources/github-apps/installations', [
            'title' => 'Finish GitHub App Setup',
            'source' => $source,
            'installations' => $installations,
            'installUrl' => $installUrl,
            'appInfo' => $appInfo,
        ]);
    }

    /**
     * Save selected installation ID.
     */
    public function setGithubAppInstallation(string $id): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/git-sources?tab=github-apps');
            return;
        }

        $team = $this->currentTeam();
        $source = GitSource::findByUuid($id) ?? GitSource::find((int)$id);
        if (!$source || (int)$source->team_id !== (int)$team->id || $source->inferredAuthMethod() !== 'github_app') {
            flash('error', 'GitHub App not found');
            $this->redirect('/git-sources?tab=github-apps');
            return;
        }

        $installationId = trim((string)$this->input('installation_id', ''));
        if ($installationId === '' || !ctype_digit($installationId)) {
            flash('error', 'Installation ID must be a number');
            $this->redirect('/git-sources/github-apps/' . ($source->uuid ?? (string)$source->id) . '/installations');
            return;
        }

        $source->update(['github_app_installation_id' => (int)$installationId]);
        flash('success', 'GitHub App installation saved');
        $this->redirect('/git-sources?tab=github-apps');
    }

    /**
     * Store GitHub App connection (team-scoped)
     */
    public function storeGithubApp(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/git-sources?tab=github-apps');
            return;
        }

        $team = $this->currentTeam();

        $name = trim((string)$this->input('name', ''));
        $appId = trim((string)$this->input('github_app_id', ''));
        $installationId = trim((string)$this->input('github_app_installation_id', ''));
        $privateKey = trim((string)$this->input('github_app_private_key', ''));

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required';
        }
        if ($appId === '' || !ctype_digit($appId)) {
            $errors['github_app_id'] = 'GitHub App ID must be a number';
        }
        if ($installationId === '' || !ctype_digit($installationId)) {
            $errors['github_app_installation_id'] = 'Installation ID must be a number';
        }
        if ($privateKey === '' || !str_contains($privateKey, 'BEGIN') || !str_contains($privateKey, 'PRIVATE KEY')) {
            $errors['github_app_private_key'] = 'Private key PEM is required';
        }

        if (!empty($errors)) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old_input'] = $this->all();
            $this->redirect('/git-sources/github-apps/create');
            return;
        }

        $defaults = GitSource::getProviderDefaults('github');

        $source = GitSource::create([
            'team_id' => (int)$team->id,
            'name' => $name,
            'type' => 'github',
            'base_url' => $defaults['base_url'] ?? null,
            'api_url' => $defaults['api_url'] ?? null,
            'auth_method' => 'github_app',
            'is_oauth' => 0,
            'oauth_token' => null,
            'github_app_id' => (int)$appId,
            'github_app_installation_id' => (int)$installationId,
            'github_app_private_key' => $privateKey,
            'is_active' => 1,
        ]);

        ActivityLog::log('git_source.github_app.created', 'GitSource', $source->id, [
            'provider' => 'github',
            'auth_method' => 'github_app',
        ]);

        flash('success', 'GitHub App added');
        $this->redirect('/git-sources?tab=github-apps');
    }

    /**
     * Delete GitHub App connection
     */
    public function destroyGithubApp(string $id): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/git-sources?tab=github-apps');
            return;
        }

        $team = $this->currentTeam();
        $source = GitSource::findByUuid($id) ?? GitSource::find((int)$id);
        if (!$source || (int)$source->team_id !== (int)$team->id || $source->inferredAuthMethod() !== 'github_app') {
            flash('error', 'GitHub App not found');
            $this->redirect('/git-sources?tab=github-apps');
            return;
        }

        $source->delete();
        ActivityLog::log('git_source.github_app.deleted', 'GitSource', $source->id);
        flash('success', 'GitHub App removed');
        $this->redirect('/git-sources?tab=github-apps');
    }

    /**
     * Show create form
     */
    public function create(): void
    {
        $this->view('git-sources/create', [
            'title' => 'Connect Git Source',
            'providers' => [
                'github' => [
                    'name' => 'GitHub',
                    'icon' => 'github',
                    'description' => 'Connect your GitHub account or organization',
                ],
                'gitlab' => [
                    'name' => 'GitLab',
                    'icon' => 'gitlab',
                    'description' => 'Connect to GitLab.com or self-hosted GitLab',
                ],
                'bitbucket' => [
                    'name' => 'Bitbucket',
                    'icon' => 'bitbucket',
                    'description' => 'Connect your Bitbucket account',
                ],
            ],
        ]);
    }

    /**
     * Store new git source
     */
    public function store(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/git-sources/create');
            return;
        }

        $team = $this->currentTeam();
        $data = $this->all();

        $provider = $data['provider'] ?? '';
        
        if (!in_array($provider, ['github', 'gitlab', 'bitbucket'])) {
            flash('error', 'Invalid provider');
            $this->redirect('/git-sources/create');
            return;
        }

        // TODO: Handle OAuth flow for the provider
        // For now, store basic token-based connection
        
        // TODO: Create GitSource model
        // GitSource::create([
        //     'team_id' => $team->id,
        //     'provider' => $provider,
        //     'name' => $data['name'],
        //     'access_token' => $data['access_token'],
        // ]);

        ActivityLog::log('git_source.created', 'GitSource', null, ['provider' => $provider]);

        flash('success', 'Git source connected');
        $this->redirect('/git-sources');
    }

    /**
     * Show git source details
     */
    public function show(string $id): void
    {
        // TODO: Fetch git source by UUID
        // TODO: List available repositories
        
        $this->view('git-sources/show', [
            'title' => 'Git Source Details',
        ]);
    }

    /**
     * Delete git source
     */
    public function destroy(string $id): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/git-sources');
            return;
        }

        // TODO: Fetch and delete git source

        ActivityLog::log('git_source.deleted', 'GitSource', null);

        flash('success', 'Git source disconnected');
        $this->redirect('/git-sources');
    }
}

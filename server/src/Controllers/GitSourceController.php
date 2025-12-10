<?php

namespace Chap\Controllers;

use Chap\Models\ActivityLog;

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
        
        // TODO: Fetch GitSource models for team
        $gitSources = []; // GitSource::forTeam($team->id);

        $this->view('git-sources/index', [
            'title' => 'Git Sources',
            'gitSources' => $gitSources,
        ]);
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

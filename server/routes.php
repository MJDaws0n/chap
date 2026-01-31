<?php
/**
 * Chap - Application Routes
 */

use Chap\Router\Route;

// Public routes
Route::get('/', 'HomeController@index');
Route::get('/docs', 'DocsController@index');
Route::get('/docs/{file}', 'DocsController@file');
Route::get('/login', 'AuthController@showLogin');
Route::middleware(['throttle.auth'], function() {
    Route::post('/login', 'AuthController@login');
    Route::post('/mfa', 'TwoFactorController@verifyChallenge');
    Route::post('/register', 'AuthController@register');
    Route::post('/forgot-password', 'AuthController@forgotPassword');
    Route::post('/reset-password', 'AuthController@resetPassword');
    // API login endpoint (public)
    Route::post('/api/v1/auth/login', 'Api\AuthController@login');
});
Route::get('/mfa', 'TwoFactorController@showChallenge');
Route::get('/register', 'AuthController@showRegister');
Route::post('/logout', 'AuthController@logout');

// OAuth routes
Route::get('/auth/github', 'AuthController@redirectToGitHub');
Route::get('/auth/github/callback', 'AuthController@handleGitHubCallback');

// Password reset
Route::get('/forgot-password', 'AuthController@showForgotPassword');
Route::get('/reset-password/{token}', 'AuthController@showResetPassword');

// Team invitations (public landing)
Route::get('/team-invites/{token}', 'TeamInviteController@show');
Route::post('/team-invites/{token}/decline', 'TeamInviteController@decline');

// Protected routes (require authentication)
Route::middleware(['auth'], function() {
    // Dashboard
    Route::get('/dashboard', 'DashboardController@index');
    
    // Profile
    Route::get('/profile', 'ProfileController@index');
    Route::post('/profile', 'ProfileController@update');
    Route::post('/profile/password', 'ProfileController@updatePassword');
    Route::get('/profile/mfa', 'TwoFactorController@showProfile');
    Route::post('/profile/mfa/start', 'TwoFactorController@startSetup');
    Route::post('/profile/mfa/confirm', 'TwoFactorController@confirmSetup');
    Route::post('/profile/mfa/disable', 'TwoFactorController@disable');
    Route::delete('/profile', 'ProfileController@destroy');
    
    // Teams
    Route::get('/teams', 'TeamController@index');
    Route::get('/teams/create', 'TeamController@create');
    Route::post('/teams', 'TeamController@store');
    Route::get('/teams/{id}', 'TeamController@show');
    Route::get('/teams/{id}/edit', 'TeamController@edit');
    Route::put('/teams/{id}', 'TeamController@update');
    Route::delete('/teams/{id}', 'TeamController@destroy');
    Route::post('/teams/{id}/switch', 'TeamController@switch');
    Route::post('/teams/{id}/members', 'TeamController@addMember');
    Route::put('/teams/{id}/members/{userId}', 'TeamController@updateMember');
    Route::delete('/teams/{id}/members/{userId}', 'TeamController@removeMember');

    // Team invitations
    Route::post('/team-invites/{token}/accept', 'TeamInviteController@accept');
    Route::post('/teams/{id}/invites/{inviteId}/revoke', 'TeamInviteController@revoke');

    // Team roles
    Route::get('/teams/{id}/roles', 'TeamRoleController@index');
    Route::get('/teams/{id}/roles/create', 'TeamRoleController@create');
    Route::post('/teams/{id}/roles', 'TeamRoleController@store');
    Route::get('/teams/{id}/roles/{roleId}/edit', 'TeamRoleController@edit');
    Route::put('/teams/{id}/roles/{roleId}', 'TeamRoleController@update');
    Route::delete('/teams/{id}/roles/{roleId}', 'TeamRoleController@destroy');
    
    // Nodes (admin-only)
    Route::middleware(['admin'], function() {
        Route::get('/admin/nodes', 'NodeController@index');
        Route::get('/admin/nodes/create', 'NodeController@create');
        Route::post('/admin/nodes', 'NodeController@store');
        Route::get('/admin/nodes/{id}', 'NodeController@show');
        Route::get('/admin/nodes/{id}/containers', 'NodeController@containers');
        Route::get('/admin/nodes/{id}/edit', 'NodeController@edit');
        Route::put('/admin/nodes/{id}', 'NodeController@update');
        Route::delete('/admin/nodes/{id}', 'NodeController@destroy');
        Route::post('/admin/nodes/{id}/validate', 'NodeController@validate');
    });
    
    // Projects
    Route::get('/projects', 'ProjectController@index');
    Route::get('/projects/create', 'ProjectController@create');
    Route::post('/projects', 'ProjectController@store');
    Route::get('/projects/{id}', 'ProjectController@show');
    Route::get('/projects/{id}/edit', 'ProjectController@edit');
    Route::put('/projects/{id}', 'ProjectController@update');
    Route::delete('/projects/{id}', 'ProjectController@destroy');

    // Project members & per-user settings
    Route::post('/projects/{id}/members', 'ProjectMemberController@add');
    Route::put('/projects/{id}/members/{userId}', 'ProjectMemberController@update');
    Route::delete('/projects/{id}/members/{userId}', 'ProjectMemberController@remove');
    
    // Environments
    Route::get('/projects/{projectId}/environments', 'EnvironmentController@index');
    Route::get('/projects/{projectId}/environments/create', 'EnvironmentController@create');
    Route::post('/projects/{projectId}/environments', 'EnvironmentController@store');
    Route::get('/environments/{id}', 'EnvironmentController@show');
    Route::get('/environments/{id}/edit', 'EnvironmentController@edit');
    Route::put('/environments/{id}', 'EnvironmentController@update');
    Route::delete('/environments/{id}', 'EnvironmentController@destroy');
    
    // Applications
    Route::get('/environments/{envId}/applications/create', 'ApplicationController@create');
    Route::get('/environments/{envId}/applications/repo-env', 'ApplicationController@repoEnv');
    Route::post('/environments/{envId}/applications', 'ApplicationController@store');
    Route::post('/environments/{envId}/nodes/{nodeId}/ports/reserve', 'ApplicationPortController@allocateForReservation');
    Route::post('/environments/{envId}/nodes/{nodeId}/ports/release', 'ApplicationPortController@releaseReservation');
    Route::get('/applications/{id}', 'ApplicationController@show');
    Route::get('/applications/{id}/edit', 'ApplicationController@edit');
    Route::put('/applications/{id}', 'ApplicationController@update');
    Route::delete('/applications/{id}', 'ApplicationController@destroy');
    Route::post('/applications/{id}/ports', 'ApplicationPortController@allocate');
    Route::delete('/applications/{id}/ports/{port}', 'ApplicationPortController@unallocate');
    Route::get('/applications/{id}/logs', 'ApplicationController@logs');
    Route::get('/applications/{id}/usage', 'ApplicationController@usage');
    Route::get('/applications/{id}/files', 'ApplicationController@files');
    Route::get('/applications/{id}/volumes', 'ApplicationController@volumes');
    Route::get('/applications/{id}/volumes/{volume}/files', 'ApplicationController@volumeFiles');
    Route::get('/applications/{id}/volumes/{volume}/files/edit', 'ApplicationController@volumeFileEditor');
    Route::get('/applications/{id}/files/edit', 'ApplicationController@fileEditor');
    Route::get('/applications/{id}/environment', 'ApplicationController@environment');
    Route::post('/applications/{id}/environment', 'ApplicationController@updateEnvironment');
    Route::post('/applications/{id}/stop', 'ApplicationController@stop');
    Route::post('/applications/{id}/restart', 'ApplicationController@restart');
    
    // Deployments
    Route::post('/applications/{appId}/deploy', 'DeploymentController@deploy');
    Route::post('/deployments/{id}/cancel', 'DeploymentController@cancel');
    Route::post('/deployments/{id}/rollback', 'DeploymentController@rollback');
    Route::get('/deployments/{id}/logs', 'DeploymentController@logs');

    // Template scripts (ChapScribe)
    Route::post('/chap-scripts/{uuid}/respond', 'ChapScriptRunController@respond');
    
    // Templates
    Route::get('/templates', 'TemplateController@index');
    Route::get('/templates/{slug}', 'TemplateController@show');
    Route::post('/templates/{slug}/deploy', 'TemplateController@deploy');
    
    // Git Sources
    Route::get('/git-sources', 'GitSourceController@index');
    Route::get('/git-sources/github-apps/create', 'GitSourceController@createGithubApp');
    Route::get('/git-sources/github-apps/manifest/create', 'GitSourceController@createGithubAppManifest');
    Route::post('/git-sources/github-apps/manifest', 'GitSourceController@startGithubAppManifest');
    Route::get('/git-sources/github-apps/manifest/callback', 'GitSourceController@handleGithubAppManifestCallback');
    Route::post('/git-sources/github-apps', 'GitSourceController@storeGithubApp');
    Route::get('/git-sources/github-apps/{id}/installations', 'GitSourceController@githubAppInstallations');
    Route::post('/git-sources/github-apps/{id}/installations', 'GitSourceController@setGithubAppInstallation');
    Route::delete('/git-sources/github-apps/{id}', 'GitSourceController@destroyGithubApp');
    Route::get('/git-sources/create', 'GitSourceController@create');
    Route::post('/git-sources', 'GitSourceController@store');
    Route::get('/git-sources/{id}', 'GitSourceController@show');
    Route::delete('/git-sources/{id}', 'GitSourceController@destroy');
    
    // Settings
    Route::get('/settings', 'SettingsController@index');
    Route::post('/settings', 'SettingsController@update');
    Route::post('/settings/api-tokens', 'SettingsController@createApiToken');
    Route::post('/settings/api-tokens/{tokenId}/revoke', 'SettingsController@revokeApiToken');
    
    // Activity Logs
    Route::get('/activity', 'ActivityController@index');
});

// Admin routes
Route::middleware(['auth', 'admin'], function() {
    Route::get('/admin', 'Admin\\AdminController@index');

    // Admin view mode toggle (personal vs all)
    Route::post('/admin/view-mode', 'Admin\\ViewModeController@update');

    // User management
    Route::get('/admin/users', 'Admin\\UserController@index');
    Route::post('/admin/users/register-page/toggle', 'Admin\\UserController@toggleRegisterPage');
    Route::get('/admin/users/create', 'Admin\\UserController@create');
    Route::post('/admin/users', 'Admin\\UserController@store');
    Route::get('/admin/users/{id}/edit', 'Admin\\UserController@edit');
    Route::put('/admin/users/{id}', 'Admin\\UserController@update');
    Route::post('/admin/users/{id}/mfa/reset', 'Admin\\UserController@resetMfa');
    Route::delete('/admin/users/{id}', 'Admin\\UserController@destroy');

    // Admin settings (email)
    Route::get('/admin/settings/email', 'Admin\\SettingsController@email');
    Route::post('/admin/settings/email', 'Admin\\SettingsController@updateEmail');
    Route::post('/admin/settings/email/test', 'Admin\\SettingsController@sendTestEmail');

    // Admin activity logs
    Route::get('/admin/activity', 'Admin\\ActivityController@index');

    // Admin templates
    Route::get('/admin/templates', 'Admin\\TemplateController@index');
    Route::post('/admin/templates/upload', 'Admin\\TemplateController@upload');

    // Admin API (docs + token management)
    Route::get('/admin/api', 'Admin\\ApiController@index');
    Route::post('/admin/api-tokens', 'Admin\\ApiController@createToken');
    Route::post('/admin/api-tokens/{tokenId}/revoke', 'Admin\\ApiController@revokeToken');
});

// API routes
Route::prefix('/api/v1', function() {
    // Public API
    // (moved above into throttle.auth group)
    
    // Protected API
    Route::middleware(['api.auth'], function() {
        // User
        Route::get('/user', 'Api\UserController@show');
        
        // Teams
        Route::get('/teams', 'Api\TeamController@index');
        Route::post('/teams', 'Api\TeamController@store');
        Route::get('/teams/{id}', 'Api\TeamController@show');
        Route::put('/teams/{id}', 'Api\TeamController@update');
        Route::delete('/teams/{id}', 'Api\TeamController@destroy');
        
        // Nodes
        Route::get('/nodes', 'Api\NodeController@index');
        Route::get('/nodes/{id}', 'Api\NodeController@show');
        Route::middleware(['admin'], function() {
            Route::post('/nodes', 'Api\NodeController@store');
            Route::put('/nodes/{id}', 'Api\NodeController@update');
            Route::delete('/nodes/{id}', 'Api\NodeController@destroy');
        });
        
        // Projects
        Route::get('/projects', 'Api\ProjectController@index');
        Route::post('/projects', 'Api\ProjectController@store');
        Route::get('/projects/{id}', 'Api\ProjectController@show');
        Route::put('/projects/{id}', 'Api\ProjectController@update');
        Route::delete('/projects/{id}', 'Api\ProjectController@destroy');
        
        // Environments
        Route::get('/projects/{projectId}/environments', 'Api\EnvironmentController@index');
        Route::post('/projects/{projectId}/environments', 'Api\EnvironmentController@store');
        Route::get('/environments/{id}', 'Api\EnvironmentController@show');
        Route::put('/environments/{id}', 'Api\EnvironmentController@update');
        Route::delete('/environments/{id}', 'Api\EnvironmentController@destroy');
        
        // Applications
        Route::get('/environments/{envId}/applications', 'Api\ApplicationController@index');
        Route::post('/environments/{envId}/applications', 'Api\ApplicationController@store');
        Route::get('/applications/{id}', 'Api\ApplicationController@show');
        Route::put('/applications/{id}', 'Api\ApplicationController@update');
        Route::delete('/applications/{id}', 'Api\ApplicationController@destroy');
        Route::post('/applications/{id}/deploy', 'Api\ApplicationController@deploy');
        
        // Deployments
        Route::get('/applications/{appId}/deployments', 'Api\DeploymentController@index');
        Route::get('/deployments/{id}', 'Api\DeploymentController@show');
        Route::post('/deployments/{id}/cancel', 'Api\DeploymentController@cancel');
        
        // Templates
        Route::get('/templates', 'Api\TemplateController@index');
        Route::get('/templates/{slug}', 'Api\TemplateController@show');
    });
});

// Client API v2 (proposed in API.md)
Route::prefix('/api/v2', function() {
    // Public
    Route::get('/health', 'ApiV2\\HealthController@health');
    Route::get('/capabilities', 'ApiV2\\HealthController@capabilities');
    Route::post('/auth/session', 'ApiV2\\AuthController@session');

    // Protected (Bearer token)
    Route::middleware(['api.v2'], function() {
        Route::get('/me', 'ApiV2\\MeController@show');
        Route::get('/teams', 'ApiV2\\TeamsController@index');
        Route::post('/teams/{team_id}/select', 'ApiV2\\TeamsController@select');

        Route::get('/auth/tokens', 'ApiV2\\AuthController@listTokens');
        Route::post('/auth/tokens', 'ApiV2\\AuthController@createToken');
        Route::delete('/auth/tokens/{token_id}', 'ApiV2\\AuthController@revokeToken');

        Route::get('/projects', 'ApiV2\\ProjectsController@index');

        Route::get('/environments', 'ApiV2\\EnvironmentsController@index');
        Route::get('/environments/{environment_id}', 'ApiV2\\EnvironmentsController@show');

        Route::get('/applications', 'ApiV2\\ApplicationsController@index');
        Route::get('/applications/{application_id}', 'ApiV2\\ApplicationsController@show');
        Route::post('/environments/{environment_id}/applications', 'ApiV2\\ApplicationsController@store');
        Route::patch('/applications/{application_id}', 'ApiV2\\ApplicationsController@update');
        Route::get('/applications/{application_id}/deployments', 'ApiV2\\DeploymentsController@indexForApplication');
        Route::post('/applications/{application_id}/deployments', 'ApiV2\\DeploymentsController@createForApplication');

        Route::get('/deployments/{deployment_id}', 'ApiV2\\DeploymentsController@show');
        Route::get('/deployments/{deployment_id}/logs', 'ApiV2\\DeploymentsController@logs');
        Route::post('/deployments/{deployment_id}/cancel', 'ApiV2\\DeploymentsController@cancel');
        Route::post('/deployments/{deployment_id}/rollback', 'ApiV2\\DeploymentsController@rollback');

        Route::get('/templates', 'ApiV2\\TemplatesController@index');
        Route::get('/templates/{slug}', 'ApiV2\\TemplatesController@show');
        Route::post('/templates/{slug}/deploy', 'ApiV2\\TemplatesController@deploy');

        Route::get('/nodes', 'ApiV2\\NodesController@index');
        Route::get('/nodes/{node_id}', 'ApiV2\\NodesController@show');
        Route::post('/nodes/{node_id}/sessions', 'ApiV2\\NodesController@mintSession');
    });
});

// Platform API v2 (platform-wide keys; created by admin; not attached to a user)
Route::prefix('/api/v2/platform', function() {
    Route::middleware(['api.v2.platform'], function() {
        // Users
        Route::get('/users', 'ApiV2\\Platform\\UsersController@index');
        Route::post('/users', 'ApiV2\\Platform\\UsersController@store');
        Route::get('/users/{user_id}', 'ApiV2\\Platform\\UsersController@show');
        Route::patch('/users/{user_id}', 'ApiV2\\Platform\\UsersController@update');
        Route::delete('/users/{user_id}', 'ApiV2\\Platform\\UsersController@destroy');

        // Teams
        Route::get('/teams', 'ApiV2\\Platform\\TeamsController@index');
        Route::post('/teams', 'ApiV2\\Platform\\TeamsController@store');
        Route::get('/teams/{team_id}', 'ApiV2\\Platform\\TeamsController@show');
        Route::patch('/teams/{team_id}', 'ApiV2\\Platform\\TeamsController@update');
        Route::delete('/teams/{team_id}', 'ApiV2\\Platform\\TeamsController@destroy');

        // Projects
        Route::get('/projects', 'ApiV2\\Platform\\ProjectsController@index');
        Route::post('/projects', 'ApiV2\\Platform\\ProjectsController@store');
        Route::get('/projects/{project_id}', 'ApiV2\\Platform\\ProjectsController@show');
        Route::patch('/projects/{project_id}', 'ApiV2\\Platform\\ProjectsController@update');
        Route::delete('/projects/{project_id}', 'ApiV2\\Platform\\ProjectsController@destroy');

        // Environments
        Route::get('/environments', 'ApiV2\\Platform\\EnvironmentsController@index');
        Route::post('/environments', 'ApiV2\\Platform\\EnvironmentsController@store');
        Route::get('/environments/{environment_id}', 'ApiV2\\Platform\\EnvironmentsController@show');
        Route::patch('/environments/{environment_id}', 'ApiV2\\Platform\\EnvironmentsController@update');
        Route::delete('/environments/{environment_id}', 'ApiV2\\Platform\\EnvironmentsController@destroy');

        // Applications
        Route::get('/applications', 'ApiV2\\Platform\\ApplicationsController@index');
        Route::post('/applications', 'ApiV2\\Platform\\ApplicationsController@store');
        Route::get('/applications/{application_id}', 'ApiV2\\Platform\\ApplicationsController@show');
        Route::patch('/applications/{application_id}', 'ApiV2\\Platform\\ApplicationsController@update');

        // Deployments
        Route::get('/deployments', 'ApiV2\\Platform\\DeploymentsController@index');
        Route::post('/applications/{application_id}/deployments', 'ApiV2\\Platform\\DeploymentsController@createForApplication');
        Route::get('/deployments/{deployment_id}', 'ApiV2\\Platform\\DeploymentsController@show');
        Route::get('/deployments/{deployment_id}/logs', 'ApiV2\\Platform\\DeploymentsController@logs');
        Route::post('/deployments/{deployment_id}/cancel', 'ApiV2\\Platform\\DeploymentsController@cancel');
        Route::post('/deployments/{deployment_id}/rollback', 'ApiV2\\Platform\\DeploymentsController@rollback');

        // Templates
        Route::get('/templates', 'ApiV2\\Platform\\TemplatesController@index');
        Route::post('/templates/sync', 'ApiV2\\Platform\\TemplatesController@sync');
        Route::get('/templates/{slug}', 'ApiV2\\Platform\\TemplatesController@show');
        Route::post('/templates/{slug}/deploy', 'ApiV2\\Platform\\TemplatesController@deploy');

        // Nodes
        Route::get('/nodes', 'ApiV2\\Platform\\NodesController@index');
        Route::get('/nodes/{node_id}', 'ApiV2\\Platform\\NodesController@show');
    });
});

// Webhook endpoints (public but authenticated)
Route::middleware(['throttle.webhooks'], function() {
    Route::post('/webhooks/github/{webhookUuid}', 'IncomingWebhookController@github');
    Route::post('/webhooks/gitlab/{webhookUuid}', 'IncomingWebhookController@gitlab');
    Route::post('/webhooks/bitbucket/{webhookUuid}', 'IncomingWebhookController@bitbucket');
    Route::post('/webhooks/custom/{webhookUuid}', 'IncomingWebhookController@custom');
});

// Incoming Webhook management (authenticated)
Route::middleware(['auth'], function() {
    Route::post('/applications/{id}/incoming-webhooks', 'IncomingWebhookController@store');
    Route::post('/incoming-webhooks/{id}/rotate', 'IncomingWebhookController@rotate');
    Route::delete('/incoming-webhooks/{id}', 'IncomingWebhookController@destroy');
});

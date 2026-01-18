<?php
/**
 * Chap - Application Routes
 */

use Chap\Router\Route;

// Public routes
Route::get('/', 'HomeController@index');
Route::get('/login', 'AuthController@showLogin');
Route::post('/login', 'AuthController@login');
Route::get('/register', 'AuthController@showRegister');
Route::post('/register', 'AuthController@register');
Route::post('/logout', 'AuthController@logout');

// OAuth routes
Route::get('/auth/github', 'AuthController@redirectToGitHub');
Route::get('/auth/github/callback', 'AuthController@handleGitHubCallback');

// Password reset
Route::get('/forgot-password', 'AuthController@showForgotPassword');
Route::post('/forgot-password', 'AuthController@forgotPassword');
Route::get('/reset-password/{token}', 'AuthController@showResetPassword');
Route::post('/reset-password', 'AuthController@resetPassword');

// Protected routes (require authentication)
Route::middleware(['auth'], function() {
    // Dashboard
    Route::get('/dashboard', 'DashboardController@index');
    
    // Profile
    Route::get('/profile', 'ProfileController@index');
    Route::post('/profile', 'ProfileController@update');
    Route::post('/profile/password', 'ProfileController@updatePassword');
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
    
    // Databases
    Route::get('/environments/{envId}/databases/create', 'DatabaseController@create');
    Route::post('/environments/{envId}/databases', 'DatabaseController@store');
    Route::get('/databases/{id}', 'DatabaseController@show');
    Route::get('/databases/{id}/edit', 'DatabaseController@edit');
    Route::put('/databases/{id}', 'DatabaseController@update');
    Route::delete('/databases/{id}', 'DatabaseController@destroy');
    Route::post('/databases/{id}/start', 'DatabaseController@start');
    Route::post('/databases/{id}/stop', 'DatabaseController@stop');
    
    // Services (one-click apps)
    Route::get('/environments/{envId}/services/create', 'ServiceController@create');
    Route::post('/environments/{envId}/services', 'ServiceController@store');
    Route::get('/services/{id}', 'ServiceController@show');
    Route::get('/services/{id}/edit', 'ServiceController@edit');
    Route::put('/services/{id}', 'ServiceController@update');
    Route::delete('/services/{id}', 'ServiceController@destroy');
    Route::post('/services/{id}/start', 'ServiceController@start');
    Route::post('/services/{id}/stop', 'ServiceController@stop');
    
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
    Route::get('/admin/users/create', 'Admin\\UserController@create');
    Route::post('/admin/users', 'Admin\\UserController@store');
    Route::get('/admin/users/{id}/edit', 'Admin\\UserController@edit');
    Route::put('/admin/users/{id}', 'Admin\\UserController@update');
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
});

// API routes
Route::prefix('/api/v1', function() {
    // Public API
    Route::post('/auth/login', 'Api\AuthController@login');
    
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
        
        // Databases
        Route::get('/databases', 'Api\DatabaseController@index');
        Route::post('/databases', 'Api\DatabaseController@store');
        Route::get('/databases/{id}', 'Api\DatabaseController@show');
        Route::put('/databases/{id}', 'Api\DatabaseController@update');
        Route::delete('/databases/{id}', 'Api\DatabaseController@destroy');
        
        // Services
        Route::get('/services', 'Api\ServiceController@index');
        Route::post('/services', 'Api\ServiceController@store');
        Route::get('/services/{id}', 'Api\ServiceController@show');
        Route::put('/services/{id}', 'Api\ServiceController@update');
        Route::delete('/services/{id}', 'Api\ServiceController@destroy');
        
        // Templates
        Route::get('/templates', 'Api\TemplateController@index');
        Route::get('/templates/{slug}', 'Api\TemplateController@show');
    });
});

// Webhook endpoints (public but signed)
Route::post('/webhooks/github/{webhookUuid}', 'IncomingWebhookController@github');
Route::post('/webhooks/gitlab/{applicationId}', 'WebhookController@gitlab');
Route::post('/webhooks/bitbucket/{applicationId}', 'WebhookController@bitbucket');
Route::post('/webhooks/custom/{applicationId}', 'WebhookController@custom');

// Incoming Webhook management (authenticated)
Route::middleware(['auth'], function() {
    Route::post('/applications/{id}/incoming-webhooks', 'IncomingWebhookController@store');
    Route::post('/incoming-webhooks/{id}/rotate', 'IncomingWebhookController@rotate');
    Route::delete('/incoming-webhooks/{id}', 'IncomingWebhookController@destroy');
});

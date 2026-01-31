<?php
/**
 * Create Project View
 * Updated to use new design system
 */

$errors = $_SESSION['_errors'] ?? [];
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_errors'], $_SESSION['_old_input']);
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item"><a href="/projects">Projects</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">New Project</span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-teal icon-box-lg">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h10M7 12h10M7 17h10M5 7a2 2 0 012-2h10a2 2 0 012 2v10a2 2 0 01-2 2H7a2 2 0 01-2-2V7z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title truncate">Create Project</h1>
                        <p class="page-header-description truncate">Projects help you organize related applications</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <form action="/projects" method="POST" class="card">
                <div class="card-header">
                    <h2 class="card-title">Project Details</h2>
                </div>
                <div class="card-body">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                    <div class="form-group">
                        <label for="name" class="form-label">Project Name <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            required
                            class="input"
                            placeholder="My Awesome Project"
                            value="<?= e($old['name'] ?? '') ?>"
                        >
                        <?php if (!empty($errors['name'])): ?>
                            <p class="form-error"><?= e($errors['name']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="description" class="form-label">Description <span class="text-muted">(optional)</span></label>
                        <textarea
                            id="description"
                            name="description"
                            rows="4"
                            class="textarea"
                            placeholder="Brief description of your project"
                        ><?= e($old['description'] ?? '') ?></textarea>
                        <?php if (!empty($errors['description'])): ?>
                            <p class="form-error"><?= e($errors['description']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="flex flex-col sm:flex-row justify-end gap-3">
                        <a href="/projects" class="btn btn-ghost">Cancel</a>
                        <button type="submit" class="btn btn-primary">Create Project</button>
                    </div>
                </div>
            </form>
        </div>

        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">About Projects</h2>
                </div>
                <div class="card-body">
                    <div class="flex flex-col gap-4">
                        <div>
                            <h3 class="text-sm font-semibold text-primary mb-2">Organize Applications</h3>
                            <p class="text-xs text-secondary">Group related applications together for easier management and deployment orchestration.</p>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-primary mb-2">Team Collaboration</h3>
                            <p class="text-xs text-secondary">Add team members with role-based access control to manage projects collaboratively.</p>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-primary mb-2">Multiple Environments</h3>
                            <p class="text-xs text-secondary">Create separate production, staging, and development environments within each project.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

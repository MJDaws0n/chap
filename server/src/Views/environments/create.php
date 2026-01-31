<?php
/**
 * Create Environment View
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
                    <span class="breadcrumb-item"><a href="/projects/<?= e($project->uuid) ?>"><?= e($project->name) ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">New Environment</span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-primary icon-box-lg">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title truncate">Create Environment</h1>
                        <p class="page-header-description truncate">Add a new environment to <?= e($project->name) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <form action="/projects/<?= e($project->uuid) ?>/environments" method="POST" class="card" id="environment-form">
                <div class="card-header">
                    <h2 class="card-title">Environment Details</h2>
                </div>
                <div class="card-body">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                    <div class="form-group">
                        <label for="name" class="form-label">Environment Name <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            required
                            class="input"
                            placeholder="production"
                            value="<?= e($old['name'] ?? '') ?>"
                        >
                        <p class="form-hint">Examples: production, staging, development, testing</p>
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
                            placeholder="Production environment for live traffic"
                        ><?= e($old['description'] ?? '') ?></textarea>
                        <?php if (!empty($errors['description'])): ?>
                            <p class="form-error"><?= e($errors['description']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="flex justify-end gap-3">
                        <a href="/projects/<?= e($project->uuid) ?>" class="btn btn-ghost">Cancel</a>
                        <button type="submit" class="btn btn-primary">Create Environment</button>
                    </div>
                </div>
            </form>
        </div>

        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">About Environments</h2>
                </div>
                <div class="card-body">
                    <div class="flex flex-col gap-4">
                        <div>
                            <h3 class="text-sm font-semibold text-primary mb-2">Deployment Stages</h3>
                            <p class="text-xs text-secondary">Separate your applications across production, staging, and development environments.</p>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-primary mb-2">Resource Limits</h3>
                            <p class="text-xs text-secondary">Set CPU, RAM, storage, and port limits for each environment independently.</p>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-primary mb-2">Node Access</h3>
                            <p class="text-xs text-secondary">Optionally restrict which nodes can deploy applications to this environment.</p>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-primary mb-2">Team Settings</h3>
                            <p class="text-xs text-secondary">Configure environment-specific team member permissions and access levels.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const nameInput = document.getElementById('name');
    
    nameInput.addEventListener('input', function(e) {
        const caret = nameInput.selectionStart;
        let value = nameInput.value;
        // Replace spaces with dash as you type
        value = value.replace(/ /g, '-');
        // Only allow a-z, 0-9, and dash, force lowercase
        value = value.replace(/[^a-z0-9-]/gi, '').toLowerCase();
        if (nameInput.value !== value) {
            nameInput.value = value;
            nameInput.setSelectionRange(caret, caret);
        }
    });
    
    nameInput.addEventListener('paste', function(e) {
        e.preventDefault();
        let paste = (e.clipboardData || window.clipboardData).getData('text');
        paste = paste.replace(/ /g, '-').replace(/[^a-z0-9-]/gi, '').toLowerCase();
        nameInput.value = paste;
    });
    
    document.getElementById('environment-form').addEventListener('submit', function(e) {
        // Remove trailing dashes before submit
        nameInput.value = nameInput.value.replace(/-+$/, '');
    });
})();
</script>

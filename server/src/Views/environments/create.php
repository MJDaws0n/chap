<?php
/**
 * Create Environment View
 * Updated to use new design system
 */
?>

<div class="environment-create">
    <div class="page-header">
        <div>
            <nav class="breadcrumb">
                <span class="breadcrumb-item"><a href="/projects">Projects</a></span>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-item"><a href="/projects/<?= e($project->uuid) ?>"><?= e($project->name) ?></a></span>

                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current">New Environment</span>
            </nav>
            <h1 class="page-title">Create Environment</h1>
            <p class="page-header-description">Add a new environment to <?= e($project->name) ?></p>
        </div>
    </div>

    <div class="form-container">
        <form action="/projects/<?= e($project->uuid) ?>/environments" method="POST" class="card card-glass" id="environment-form">
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
                        value="<?= htmlspecialchars($old['name'] ?? '') ?>"
                    >
                    <p class="form-hint">Examples: production, staging, development, testing</p>
                    <?php if (!empty($errors['name'])): ?>
                        <p class="form-error"><?= htmlspecialchars($errors['name']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Description <span class="text-muted">(optional)</span></label>
                    <textarea 
                        id="description" 
                        name="description" 
                        class="textarea"
                        rows="3"
                        placeholder="Production environment for live traffic"
                    ><?= htmlspecialchars($old['description'] ?? '') ?></textarea>
                    <?php if (!empty($errors['description'])): ?>
                        <p class="form-error"><?= htmlspecialchars($errors['description']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="alert alert-info">
                    <div class="alert-icon">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="alert-content">
                        <h4 class="alert-title">What is an environment?</h4>
                        <p class="alert-description">
                            Environments help you organize your applications by deployment stage. 
                            Common setups include Production for live traffic, Staging for final testing, 
                            and Development for active work.
                        </p>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="/projects/<?= e($project->uuid) ?>" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">Create Environment</button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.environment-create {
    display: flex;
    flex-direction: column;
    gap: var(--space-lg);
}

.form-container {
    max-width: 640px;
}

.form-group {
    margin-bottom: var(--space-lg);
}

.form-hint {
    font-size: var(--text-xs);
    color: var(--text-tertiary);
    margin-top: var(--space-xs);
}

.form-error {
    font-size: var(--text-sm);
    color: var(--red-400);
    margin-top: var(--space-xs);
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--border-subtle);
}

.alert {
    display: flex;
    gap: var(--space-md);
    padding: var(--space-md);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-lg);
}

.alert-info {
    background: var(--blue-500-alpha);
    border: 1px solid var(--blue-700);
}

.alert-icon {
    flex-shrink: 0;
    color: var(--blue-400);
}

.alert-title {
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--blue-400);
    margin: 0 0 var(--space-xs) 0;
}

.alert-description {
    font-size: var(--text-sm);
    color: var(--text-secondary);
    margin: 0;
}
</style>

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

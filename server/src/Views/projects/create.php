<?php
/**
 * Create Project View
 * Updated to use new design system
 */
?>

<div class="project-create">
    <div class="page-header">
        <div>
            <nav class="breadcrumb">
                <span class="breadcrumb-item"><a href="/projects">Projects</a></span>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current">New Project</span>
            </nav>
            <h1 class="page-title">Create Project</h1>
            <p class="page-header-description">Projects help you organize related applications</p>
        </div>
    </div>

    <div class="form-container">
        <form action="/projects" method="POST" class="card card-glass">
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
                        value="<?= htmlspecialchars($old['name'] ?? '') ?>"
                    >
                    <?php if (!empty($errors['name'])): ?>
                        <p class="form-error"><?= htmlspecialchars($errors['name']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Description <span class="text-muted">(optional)</span></label>
                    <textarea 
                        id="description" 
                        name="description" 
                        rows="3"
                        class="textarea"
                        placeholder="Brief description of your project"
                    ><?= htmlspecialchars($old['description'] ?? '') ?></textarea>
                </div>

                <div class="form-actions">
                    <a href="/projects" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">Create Project</button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.project-create {
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

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--border-subtle);
}

.form-error {
    font-size: var(--text-sm);
    color: var(--red-400);
    margin-top: var(--space-xs);
}
</style>

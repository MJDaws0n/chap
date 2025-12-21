<?php
/**
 * Create Service View
 * Updated to use new design system
 */
?>

<div class="page-header">
    <div>
        <nav class="breadcrumb">
            <a href="/services" class="breadcrumb-link">Services</a>
            <span class="breadcrumb-separator">/</span>
            <span>Deploy</span>
        </nav>
        <h1 class="page-title">Deploy Service</h1>
        <p class="text-muted">Deploy a one-click service from a template</p>
    </div>
</div>

<div class="form-container">
    <form method="POST" action="/services" class="card card-glass">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
        
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label for="name" class="form-label">Service Name</label>
                    <input type="text" name="name" id="name" required
                           class="input" placeholder="my-service">
                </div>

                <div class="form-group">
                    <label for="template_id" class="form-label">Template</label>
                    <select name="template_id" id="template_id" required class="select">
                        <option value="">Select a template...</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?= $template->id ?>"><?= e($template->name) ?> - <?= e($template->description ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="node_id" class="form-label">Node</label>
                    <select name="node_id" id="node_id" required class="select">
                        <?php foreach ($nodes as $node): ?>
                            <option value="<?= $node->id ?>"><?= e($node->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="environment_id" class="form-label">Environment</label>
                    <select name="environment_id" id="environment_id" required class="select">
                        <?php foreach ($environments as $env): ?>
                            <option value="<?= $env->id ?>"><?= e($env->name) ?> (<?= e($env->project()->name ?? 'No Project') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="template-config" class="form-section hidden">
                <h3 class="form-section-title">Service Configuration</h3>
                <div id="config-fields" class="form-grid">
                    <!-- Dynamic configuration fields will be inserted here -->
                </div>
            </div>
        </div>

        <div class="card-footer">
            <a href="/services" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Deploy Service</button>
        </div>
    </form>
</div>

<style>
.form-container {
    max-width: 800px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-md);
}

.form-section {
    border-top: 1px solid var(--border-color);
    padding-top: var(--space-lg);
    margin-top: var(--space-lg);
}

.form-section.hidden {
    display: none;
}

.form-section-title {
    font-size: var(--font-lg);
    font-weight: 500;
    margin-bottom: var(--space-md);
}

.card-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
    padding: var(--space-md);
    border-top: 1px solid var(--border-color);
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.getElementById('template_id').addEventListener('change', function() {
    const templateConfig = document.getElementById('template-config');
    const configFields = document.getElementById('config-fields');
    
    if (this.value) {
        // In a real implementation, fetch template configuration and render fields
        templateConfig.classList.remove('hidden');
    } else {
        templateConfig.classList.add('hidden');
    }
});
</script>

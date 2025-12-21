<?php
/**
 * Edit Service View
 * Updated to use new design system
 */
?>

<div class="page-header">
    <div>
        <nav class="breadcrumb">
            <a href="/services" class="breadcrumb-link">Services</a>
            <span class="breadcrumb-separator">/</span>
            <a href="/services/<?= $service->uuid ?>" class="breadcrumb-link"><?= e($service->name) ?></a>
            <span class="breadcrumb-separator">/</span>
            <span>Edit</span>
        </nav>
        <h1 class="page-title">Edit Service</h1>
        <p class="text-muted"><?= e($service->name) ?></p>
    </div>
</div>

<div class="form-container">
    <form method="POST" action="/services/<?= $service->uuid ?>" class="card card-glass">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="_method" value="PUT">
        
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label for="name" class="form-label">Service Name</label>
                    <input type="text" name="name" id="name" required
                           value="<?= e($service->name) ?>"
                           class="input">
                </div>

                <div class="form-group">
                    <label for="fqdn" class="form-label">Domain(s)</label>
                    <input type="text" name="fqdn" id="fqdn"
                           value="<?= e($service->fqdn ?? '') ?>"
                           class="input" placeholder="https://app.example.com">
                    <p class="form-hint">Separate multiple domains with commas</p>
                </div>
            </div>

            <div class="form-section">
                <h3 class="form-section-title">Environment Variables</h3>
                <div id="env-vars" class="env-list">
                    <?php 
                    $envVars = json_decode($service->configuration ?? '{}', true) ?? [];
                    foreach ($envVars as $key => $value): 
                    ?>
                        <div class="env-row">
                            <input type="text" name="env_keys[]" value="<?= e($key) ?>"
                                   class="input" placeholder="KEY">
                            <input type="text" name="env_values[]" value="<?= e($value) ?>"
                                   class="input" placeholder="VALUE">
                            <button type="button" class="btn-icon btn-icon-danger remove-env">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="3,6 5,6 21,6"></polyline>
                                    <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path>
                                </svg>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn-link" id="add-env-btn">+ Add Environment Variable</button>
            </div>
        </div>

        <div class="card-footer">
            <a href="/services/<?= $service->uuid ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Update Service</button>
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

.form-section-title {
    font-size: var(--font-lg);
    font-weight: 500;
    margin-bottom: var(--space-md);
}

.form-hint {
    font-size: var(--font-xs);
    color: var(--text-muted);
    margin-top: var(--space-xs);
}

.env-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.env-row {
    display: flex;
    gap: var(--space-sm);
    align-items: center;
}

.env-row .input {
    flex: 1;
}

.btn-icon {
    background: transparent;
    border: none;
    padding: var(--space-xs);
    cursor: pointer;
    border-radius: var(--radius-sm);
    color: var(--text-muted);
    transition: color var(--transition-fast);
}

.btn-icon svg {
    width: 20px;
    height: 20px;
}

.btn-icon-danger:hover {
    color: var(--red-400);
}

.btn-link {
    background: none;
    border: none;
    color: var(--primary);
    cursor: pointer;
    font-size: var(--font-sm);
    margin-top: var(--space-sm);
    padding: 0;
}

.btn-link:hover {
    text-decoration: underline;
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
    
    .env-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .env-row .btn-icon {
        align-self: flex-end;
    }
}
</style>

<script>
(function() {
    const envContainer = document.getElementById('env-vars');
    const addBtn = document.getElementById('add-env-btn');
    
    function addEnvRow() {
        const row = document.createElement('div');
        row.className = 'env-row';
        row.innerHTML = `
            <input type="text" name="env_keys[]" class="input" placeholder="KEY">
            <input type="text" name="env_values[]" class="input" placeholder="VALUE">
            <button type="button" class="btn-icon btn-icon-danger remove-env">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3,6 5,6 21,6"></polyline>
                    <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path>
                </svg>
            </button>
        `;
        envContainer.appendChild(row);
        
        row.querySelector('.remove-env').addEventListener('click', function() {
            row.remove();
        });
    }
    
    addBtn.addEventListener('click', addEnvRow);
    
    // Attach event listeners to existing remove buttons
    document.querySelectorAll('.remove-env').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.env-row').remove();
        });
    });
})();
</script>

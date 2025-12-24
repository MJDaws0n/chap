<?php
/**
 * Edit Database View
 * Updated to use new design system
 */
?>

<div class="page-header">
    <div>
        <nav class="breadcrumb">
            <a href="/databases" class="breadcrumb-link">Databases</a>
            <span class="breadcrumb-separator">/</span>
            <a href="/databases/<?= $database->uuid ?>" class="breadcrumb-link"><?= e($database->name) ?></a>
            <span class="breadcrumb-separator">/</span>
            <span>Edit</span>
        </nav>
        <h1 class="page-title">Edit Database</h1>
        <p class="text-muted"><?= e($database->name) ?></p>
    </div>
</div>

<div class="form-container">
    <form method="POST" action="/databases/<?= $database->uuid ?>" class="card card-glass">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="_method" value="PUT">
        
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label for="name" class="form-label">Database Name</label>
                    <input type="text" name="name" id="name" required
                           value="<?= e($database->name) ?>"
                           class="input">
                </div>

                <div class="form-group">
                    <label for="type" class="form-label">Database Type</label>
                    <input type="text" disabled
                           value="<?= ucfirst($database->type) ?>"
                           class="input input-disabled">
                    <p class="form-hint">Database type cannot be changed after creation</p>
                </div>

                <div class="form-group">
                    <label for="version" class="form-label">Version</label>
                    <input type="text" name="version" id="version"
                           value="<?= e($database->version ?? '') ?>"
                           class="input" placeholder="latest">
                </div>

                <div class="form-group">
                    <label for="port" class="form-label">External Port</label>
                    <input type="number" name="port" id="port"
                           value="<?= e($database->external_port ?? '') ?>"
                           class="input" placeholder="Auto-assigned">
                </div>
            </div>

            <div class="form-section">
                <h3 class="form-section-title">Update Credentials</h3>
                <p class="text-muted text-sm mb-md">Leave blank to keep existing values</p>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="db_name" class="form-label">Database Name</label>
                        <input type="text" name="db_name" id="db_name"
                               value="<?= e($database->db_name ?? '') ?>"
                               class="input">
                    </div>

                    <div class="form-group">
                        <label for="db_user" class="form-label">Username</label>
                        <input type="text" name="db_user" id="db_user"
                               value="<?= e($database->db_user ?? '') ?>"
                               class="input">
                    </div>

                    <div class="form-group form-group-full">
                        <label for="db_password" class="form-label">New Password</label>
                        <input type="password" name="db_password" id="db_password"
                               class="input" placeholder="Leave empty to keep current password">
                    </div>
                </div>
            </div>
        </div>

        <div class="card-footer">
            <a href="/databases/<?= $database->uuid ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Update Database</button>
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

.form-group-full {
    grid-column: 1 / -1;
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

.input-disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.mb-md {
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

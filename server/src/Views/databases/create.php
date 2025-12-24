<?php
/**
 * Create Database View
 * Updated to use new design system
 */
?>

<div class="page-header">
    <div>
        <nav class="breadcrumb">
            <a href="/databases" class="breadcrumb-link">Databases</a>
            <span class="breadcrumb-separator">/</span>
            <span>Create</span>
        </nav>
        <h1 class="page-title">Create Database</h1>
        <p class="text-muted">Deploy a managed database on your node</p>
    </div>
</div>

<div class="form-container">
    <form method="POST" action="/databases" class="card card-glass">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
        
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label for="name" class="form-label">Database Name</label>
                    <input type="text" name="name" id="name" required
                           class="input" placeholder="my-database">
                </div>

                <div class="form-group">
                    <label for="type" class="form-label">Database Type</label>
                    <select name="type" id="type" required class="select">
                        <option value="mysql">MySQL 8.0</option>
                        <option value="postgresql">PostgreSQL 16</option>
                        <option value="mariadb">MariaDB 11</option>
                        <option value="redis">Redis 7</option>
                        <option value="mongodb">MongoDB 7</option>
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

                <div class="form-group">
                    <label for="version" class="form-label">Version</label>
                    <input type="text" name="version" id="version"
                           class="input" placeholder="latest">
                </div>

                <div class="form-group">
                    <label for="port" class="form-label">External Port (optional)</label>
                    <input type="number" name="port" id="port"
                           class="input" placeholder="Auto-assigned">
                </div>
            </div>

            <div class="form-section">
                <h3 class="form-section-title">Credentials</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="db_name" class="form-label">Database Name</label>
                        <input type="text" name="db_name" id="db_name"
                               class="input" placeholder="app">
                    </div>

                    <div class="form-group">
                        <label for="db_user" class="form-label">Username</label>
                        <input type="text" name="db_user" id="db_user"
                               class="input" placeholder="admin">
                    </div>

                    <div class="form-group form-group-full">
                        <label for="db_password" class="form-label">Password</label>
                        <input type="password" name="db_password" id="db_password"
                               class="input" placeholder="Leave empty to auto-generate">
                    </div>
                </div>
            </div>
        </div>

        <div class="card-footer">
            <a href="/databases" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create Database</button>
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

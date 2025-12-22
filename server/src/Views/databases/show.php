<?php
/**
 * Database Show View
 * Updated to use new design system
 */
$typeLabels = [
    'mysql' => 'MySQL',
    'postgresql' => 'PostgreSQL',
    'mariadb' => 'MariaDB',
    'redis' => 'Redis',
    'mongodb' => 'MongoDB'
];
$typeLabel = $typeLabels[$database->type] ?? ucfirst($database->type);
?>

<div class="page-header">
    <div>
        <nav class="breadcrumb">
            <a href="/databases" class="breadcrumb-link">Databases</a>
            <span class="breadcrumb-separator">/</span>
            <span><?= e($database->name) ?></span>
        </nav>
        <h1 class="page-title"><?= e($database->name) ?></h1>
        <p class="text-muted"><?= $typeLabel ?> Database</p>
    </div>
    <div class="page-actions">
        <a href="/databases/<?= $database->uuid ?>/edit" class="btn btn-secondary">Edit</a>
        <?php if ($database->status === 'running'): ?>
            <form method="POST" action="/databases/<?= $database->uuid ?>/stop" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="btn btn-warning">Stop</button>
            </form>
        <?php else: ?>
            <form method="POST" action="/databases/<?= $database->uuid ?>/start" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="btn btn-success">Start</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="database-layout">
    <!-- Status Card -->
    <div class="card card-glass">
        <div class="card-header">
            <h2 class="card-title">Status</h2>
        </div>
        <div class="card-body">
            <div class="info-list">
                <div class="info-row">
                    <span class="info-label">Status</span>
                    <span class="badge <?= $database->status === 'running' ? 'badge-success' : 'badge-secondary' ?>">
                        <?= ucfirst($database->status) ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Type</span>
                    <span class="info-value"><?= $typeLabel ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Version</span>
                    <span class="info-value"><?= e($database->version ?? 'latest') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Node</span>
                    <span class="info-value"><?= e($database->node()->name ?? 'Unknown') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Connection Info Card -->
    <div class="card card-glass connection-card">
        <div class="card-header">
            <h2 class="card-title">Connection Details</h2>
        </div>
        <div class="card-body">
            <div class="connection-info">
                <div class="connection-row">
                    <label class="connection-label">Host</label>
                    <div class="connection-value-row">
                        <code class="code-block"><?= e($database->node()->name ?? 'localhost') ?></code>
                        <button type="button" class="btn-icon" onclick="copyToClipboard('<?= e($database->node()->name ?? 'localhost') ?>')">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="connection-grid">
                    <div class="connection-row">
                        <label class="connection-label">Port</label>
                        <code class="code-block"><?= e($database->external_port ?? $database->internal_port ?? '3306') ?></code>
                    </div>
                    <div class="connection-row">
                        <label class="connection-label">Database</label>
                        <code class="code-block"><?= e($database->db_name ?? 'app') ?></code>
                    </div>
                </div>

                <div class="connection-grid">
                    <div class="connection-row">
                        <label class="connection-label">Username</label>
                        <code class="code-block"><?= e($database->db_user ?? 'admin') ?></code>
                    </div>
                    <div class="connection-row">
                        <label class="connection-label">Password</label>
                        <div class="connection-value-row">
                            <code class="code-block" id="db-password">••••••••</code>
                            <button type="button" class="btn-icon" onclick="togglePassword()">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Danger Zone -->
<div class="card card-glass card-danger">
    <div class="card-header">
        <h2 class="card-title text-danger">Danger Zone</h2>
    </div>
    <div class="card-body">
        <div class="danger-row">
            <div>
                <p class="danger-title">Delete Database</p>
                <p class="text-muted text-sm">This will permanently delete the database and all its data.</p>
            </div>
            <form method="POST" action="/databases/<?= $database->uuid ?>" id="delete-form">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="button" class="btn btn-danger" id="delete-btn">Delete Database</button>
            </form>
        </div>
    </div>
</div>

<style>
.database-layout {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: var(--space-lg);
    margin-bottom: var(--space-lg);
}

.info-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.info-label {
    color: var(--text-muted);
}

.info-value {
    font-weight: 500;
}

.connection-card {
    grid-column: span 1;
}

.connection-info {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.connection-row {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.connection-label {
    font-size: var(--font-sm);
    color: var(--text-muted);
}

.connection-value-row {
    display: flex;
    gap: var(--space-sm);
    align-items: center;
}

.connection-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
}

.code-block {
    flex: 1;
    background: var(--surface-alt);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
    font-family: var(--font-mono);
    font-size: var(--font-sm);
    overflow: hidden;
    text-overflow: ellipsis;
}

.btn-icon {
    background: transparent;
    border: none;
    padding: var(--space-xs);
    color: var(--text-muted);
    cursor: pointer;
    border-radius: var(--radius-sm);
    transition: color var(--transition-fast);
}

.btn-icon:hover {
    color: var(--text-primary);
}

.icon {
    width: 18px;
    height: 18px;
}

.inline-form {
    display: inline;
}

.card-danger {
    border: 1px solid var(--red-500-alpha);
}

.text-danger {
    color: var(--red-400);
}

.danger-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.danger-title {
    font-weight: 500;
    margin-bottom: var(--space-xs);
}

.btn-warning {
    background: var(--yellow-500);
    color: #000;
}

.btn-warning:hover {
    background: var(--yellow-400);
}

.btn-success {
    background: var(--green-500);
    color: #fff;
}

.btn-success:hover {
    background: var(--green-400);
}

@media (max-width: 1024px) {
    .database-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .connection-grid {
        grid-template-columns: 1fr;
    }
    
    .danger-row {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-md);
    }
}
</style>

<script>
let passwordVisible = false;
const realPassword = '<?= e($database->db_password ?? '') ?>';

function togglePassword() {
    const el = document.getElementById('db-password');
    passwordVisible = !passwordVisible;
    el.textContent = passwordVisible ? realPassword : '••••••••';
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text);
    if (window.Toast && typeof window.Toast.success === 'function') {
        window.Toast.success('Copied to clipboard');
    }
}

document.getElementById('delete-btn').addEventListener('click', function() {
    Modal.confirmDelete('This will permanently delete the database and all its data. This action cannot be undone.')
        .then(confirmed => {
            if (confirmed) {
                document.getElementById('delete-form').submit();
            }
        });
});
</script>

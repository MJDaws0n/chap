<?php
/**
 * Service Show View
 * Updated to use new design system
 */
?>

<div class="page-header">
    <div>
        <nav class="breadcrumb">
            <a href="/services" class="breadcrumb-link">Services</a>
            <span class="breadcrumb-separator">/</span>
            <span><?= e($service->name) ?></span>
        </nav>
        <h1 class="page-title"><?= e($service->name) ?></h1>
        <p class="text-muted"><?= e($service->template()->name ?? 'Custom Service') ?></p>
    </div>
    <div class="page-actions">
        <a href="/services/<?= $service->uuid ?>/edit" class="btn btn-secondary">Edit</a>
        <?php if ($service->status === 'running'): ?>
            <form method="POST" action="/services/<?= $service->uuid ?>/stop" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="btn btn-warning">Stop</button>
            </form>
            <form method="POST" action="/services/<?= $service->uuid ?>/restart" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="btn btn-primary">Restart</button>
            </form>
        <?php else: ?>
            <form method="POST" action="/services/<?= $service->uuid ?>/start" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="btn btn-success">Start</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="service-layout">
    <!-- Status Card -->
    <div class="card card-glass">
        <div class="card-header">
            <h2 class="card-title">Status</h2>
        </div>
        <div class="card-body">
            <div class="info-list">
                <div class="info-row">
                    <span class="info-label">Status</span>
                    <span class="badge <?= $service->status === 'running' ? 'badge-success' : 'badge-secondary' ?>">
                        <?= ucfirst($service->status) ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Template</span>
                    <span class="info-value"><?= e($service->template()->name ?? 'Custom') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Node</span>
                    <span class="info-value"><?= e($service->node()->name ?? 'Unknown') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Environment</span>
                    <span class="info-value"><?= e($service->environment()->name ?? 'Unknown') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- URLs Card -->
    <div class="card card-glass urls-card">
        <div class="card-header">
            <h2 class="card-title">Access URLs</h2>
        </div>
        <div class="card-body">
            <?php if (!empty($service->fqdn)): ?>
                <div class="url-list">
                    <?php foreach (explode(',', $service->fqdn) as $url): ?>
                        <div class="url-item">
                            <a href="<?= e(trim($url)) ?>" target="_blank" class="url-link">
                                <?= e(trim($url)) ?>
                            </a>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"></path>
                            </svg>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted">No URLs configured. Add domains in the service settings.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Configuration Card -->
<div class="card card-glass">
    <div class="card-header">
        <h2 class="card-title">Configuration</h2>
    </div>
    <div class="card-body">
        <?php if (!empty($service->configuration)): ?>
            <?php $config = json_decode($service->configuration, true) ?? []; ?>
            <div class="config-grid">
                <?php foreach ($config as $key => $value): ?>
                    <div class="config-item">
                        <div class="config-key"><?= e($key) ?></div>
                        <div class="config-value"><?= e(is_array($value) ? json_encode($value) : $value) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted">No configuration set.</p>
        <?php endif; ?>
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
                <p class="danger-title">Delete Service</p>
                <p class="text-muted text-sm">This will permanently delete the service and all its data.</p>
            </div>
            <form method="POST" action="/services/<?= $service->uuid ?>" id="delete-form">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="button" class="btn btn-danger" id="delete-btn">Delete Service</button>
            </form>
        </div>
    </div>
</div>

<style>
.service-layout {
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

.urls-card {
    grid-column: span 1;
}

.url-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.url-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--surface-alt);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
}

.url-link {
    color: var(--primary);
    text-decoration: none;
    overflow: hidden;
    text-overflow: ellipsis;
}

.url-link:hover {
    text-decoration: underline;
}

.icon {
    width: 18px;
    height: 18px;
    color: var(--text-muted);
    flex-shrink: 0;
}

.config-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: var(--space-md);
}

.config-item {
    background: var(--surface-alt);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
}

.config-key {
    font-size: var(--font-sm);
    color: var(--text-muted);
    margin-bottom: var(--space-xs);
}

.config-value {
    font-family: var(--font-mono);
    font-size: var(--font-sm);
    word-break: break-all;
}

.inline-form {
    display: inline;
}

.card-danger {
    border: 1px solid var(--red-500-alpha);
    margin-top: var(--space-lg);
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
    .service-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .danger-row {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-md);
    }
}
</style>

<script>
document.getElementById('delete-btn').addEventListener('click', function() {
    Modal.confirmDelete('This will permanently delete the service and all its data. This action cannot be undone.')
        .then(confirmed => {
            if (confirmed) {
                document.getElementById('delete-form').submit();
            }
        });
});
</script>

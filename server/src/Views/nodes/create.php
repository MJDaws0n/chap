<?php
/**
 * Create Node View
 * Updated to use new design system
 */
?>

<div class="node-create">
    <div class="page-header">
        <div>
            <nav class="breadcrumb">
                <span class="breadcrumb-item">
                    <a href="/admin/nodes">Nodes</a>
                </span>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current">Add Node</span>
            </nav>
            <h1 class="page-title">Add Node</h1>
            <p class="page-header-description">Connect a server to deploy your applications</p>
        </div>
    </div>

    <div class="form-container">
        <form action="/admin/nodes" method="POST" class="card card-glass" id="node-form">
            <div class="card-body">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                <?php
                $oldPortRanges = $old['port_ranges'] ?? [''];
                if (!is_array($oldPortRanges)) $oldPortRanges = [$oldPortRanges];
                if (empty($oldPortRanges)) $oldPortRanges = [''];
                ?>

                <div class="form-group">
                    <label for="name" class="form-label">Node Name <span class="text-danger">*</span></label>
                    <input 
                        type="text" 
                        id="name" 
                        name="name" 
                        required
                        class="input"
                        placeholder="production-server-1"
                        value="<?= htmlspecialchars($old['name'] ?? '') ?>"
                    >
                    <p class="form-hint">A unique identifier for this server (lowercase, no spaces)</p>
                    <?php if (!empty($errors['name'])): ?>
                        <p class="form-error"><?= htmlspecialchars($errors['name']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Description <span class="text-muted">(optional)</span></label>
                    <input 
                        type="text" 
                        id="description" 
                        name="description" 
                        class="input"
                        placeholder="Production server in AWS us-east-1"
                        value="<?= htmlspecialchars($old['description'] ?? '') ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="logs_websocket_url" class="form-label">Live Logs WebSocket URL <span class="text-muted">(optional)</span></label>
                    <input 
                        type="text" 
                        id="logs_websocket_url" 
                        name="logs_websocket_url" 
                        class="input"
                        placeholder="wss://node.example.com:6002 or ws://192.168.1.10:6002"
                        value="<?= htmlspecialchars($old['logs_websocket_url'] ?? '') ?>"
                    >
                    <p class="form-hint">Direct WebSocket URL for live logs (browsers connect here). Leave blank to use polling.</p>
                </div>

                <div class="form-group">
                    <label for="api_url" class="form-label">Node API URL <span class="text-muted">(optional)</span></label>
                    <input
                        type="text"
                        id="api_url"
                        name="api_url"
                        class="input"
                        placeholder="https://node.example.com:6002"
                        value="<?= htmlspecialchars($old['api_url'] ?? '') ?>"
                    >
                    <p class="form-hint">Client-facing base URL for the Node API (used for /node/v2). If blank, Chap will attempt to derive it from the WebSocket URL.</p>
                </div>

                <div class="form-group">
                    <label class="form-label">Port Ranges <span class="text-danger">*</span></label>
                    <div id="port-ranges" class="flex flex-col gap-2">
                        <?php foreach ($oldPortRanges as $i => $v): ?>
                            <input
                                type="text"
                                name="port_ranges[]"
                                class="input"
                                placeholder="3000-3999 or 25565"
                                value="<?= htmlspecialchars((string)$v) ?>"
                            >
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-2">
                        <button type="button" class="btn btn-ghost btn-sm" id="add-port-range">+ Add range</button>
                    </div>
                    <p class="form-hint">Only these ports can be auto-allocated on this node. Use a single port (e.g. 25565) or range (e.g. 3000–3999).</p>
                    <?php if (!empty($errors['port_ranges'])): ?>
                        <p class="form-error"><?= htmlspecialchars($errors['port_ranges']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <a href="/admin/nodes" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">Add Node</button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.node-create {
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

@media (max-width: 767px) {
    .form-container {
        max-width: 100%;
    }

    .form-actions {
        flex-wrap: wrap;
        justify-content: stretch;
    }

    .form-actions .btn {
        width: 100%;
    }
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
    const rangesEl = document.getElementById('port-ranges');
    const addRangeBtn = document.getElementById('add-port-range');
    
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
    
    document.getElementById('node-form').addEventListener('submit', function(e) {
        // Remove trailing dashes before submit
        nameInput.value = nameInput.value.replace(/-+$/, '');
    });

    addRangeBtn.addEventListener('click', function() {
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'port_ranges[]';
        input.className = 'input';
        input.placeholder = '3000-3999 or 25565';
        rangesEl.appendChild(input);
        input.focus();
    });
})();
</script>

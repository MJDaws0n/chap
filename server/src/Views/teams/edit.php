<?php
/**
 * Edit Team View
 */

$errors = $_SESSION['_errors'] ?? [];
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_errors'], $_SESSION['_old_input']);

$existingNodeIds = \Chap\Services\NodeAccess::decodeNodeIds($team->allowed_node_ids) ?? [];
$restrictDefault = !empty($existingNodeIds);
$restrictValue = !empty($old) ? !empty($old['restrict_nodes']) : $restrictDefault;
$selected = !empty($old['allowed_node_ids']) && is_array($old['allowed_node_ids'])
    ? array_map('intval', $old['allowed_node_ids'])
    : $existingNodeIds;
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item"><a href="/teams">Teams</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/teams/<?= (int)$team->id ?>"><?= e($team->name) ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">Edit</span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-lg icon-box-blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title truncate">Edit Team</h1>
                        <p class="page-header-description truncate">Update team settings, limits, and access</p>
                    </div>
                </div>
            </div>

            <div class="page-header-actions">
                <form action="/teams/<?= (int)$team->id ?>" method="POST" id="delete-team-form">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="btn btn-danger-ghost">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <form action="/teams/<?= (int)$team->id ?>" method="POST" data-confirm-resource-limits="1">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="_method" value="PUT">

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Team Details</h2>
                </div>

                <div class="card-body">
                    <div class="flex flex-col gap-4">
                        <div class="form-group">
                            <label for="name" class="form-label">Team Name <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                required
                                class="input"
                                value="<?= e($old['name'] ?? $team->name) ?>"
                            >
                            <?php if (!empty($errors['name'])): ?>
                                <p class="form-error"><?= e($errors['name']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="description" class="form-label">Description <span class="text-muted">(optional)</span></label>
                            <textarea
                                id="description"
                                name="description"
                                rows="3"
                                class="textarea"
                            ><?= e($old['description'] ?? ($team->description ?? '')) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Limits & Access</h2>
                </div>

                <div class="card-body">
                    <div>
                        <h3 class="text-sm font-semibold text-primary">Resource Limits</h3>
                        <p class="form-hint">Set a fixed value to reserve it from the team owner\'s user max. Use <strong>-1</strong> to auto-split remaining resources across sibling teams.</p>
                        <?php if (!empty($ownerMax)): ?>
                            <p class="form-hint">Owner max: CPU <?= e((string)$ownerMax['cpu_millicores']) ?>m · RAM <?= e((string)$ownerMax['ram_mb']) ?>MB · Storage <?= e((string)$ownerMax['storage_mb']) ?>MB · Ports <?= e((string)$ownerMax['ports']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div class="form-group">
                            <label for="cpu_limit_cores" class="form-label">CPU Limit (cores or -1)</label>
                            <?php
                            $cpuDefault = $team->cpu_millicores_limit === -1 ? '-1' : rtrim(rtrim(number_format($team->cpu_millicores_limit / 1000, 3, '.', ''), '0'), '.');
                            ?>
                            <input type="text" id="cpu_limit_cores" name="cpu_limit_cores" class="input" value="<?= e($old['cpu_limit_cores'] ?? $cpuDefault) ?>" placeholder="-1">
                            <?php if (!empty($errors['cpu_limit_cores'])): ?><p class="form-error"><?= e($errors['cpu_limit_cores']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="ram_mb_limit" class="form-label">RAM Limit (MB or -1)</label>
                            <input type="number" id="ram_mb_limit" name="ram_mb_limit" class="input" value="<?= e($old['ram_mb_limit'] ?? (string)$team->ram_mb_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['ram_mb_limit'])): ?><p class="form-error"><?= e($errors['ram_mb_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="storage_mb_limit" class="form-label">Storage Limit (MB or -1)</label>
                            <input type="number" id="storage_mb_limit" name="storage_mb_limit" class="input" value="<?= e($old['storage_mb_limit'] ?? (string)$team->storage_mb_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['storage_mb_limit'])): ?><p class="form-error"><?= e($errors['storage_mb_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="port_limit" class="form-label">Port Limit (count or -1)</label>
                            <input type="number" id="port_limit" name="port_limit" class="input" value="<?= e($old['port_limit'] ?? (string)$team->port_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['port_limit'])): ?><p class="form-error"><?= e($errors['port_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="bandwidth_mbps_limit" class="form-label">Bandwidth Limit (Mbps or -1)</label>
                            <input type="number" id="bandwidth_mbps_limit" name="bandwidth_mbps_limit" class="input" value="<?= e($old['bandwidth_mbps_limit'] ?? (string)$team->bandwidth_mbps_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['bandwidth_mbps_limit'])): ?><p class="form-error"><?= e($errors['bandwidth_mbps_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="pids_limit" class="form-label">PIDs Limit (or -1)</label>
                            <input type="number" id="pids_limit" name="pids_limit" class="input" value="<?= e($old['pids_limit'] ?? (string)$team->pids_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['pids_limit'])): ?><p class="form-error"><?= e($errors['pids_limit']) ?></p><?php endif; ?>
                        </div>
                    </div>

                    <div class="divider"></div>

                    <div>
                        <h3 class="text-sm font-semibold text-primary">Node Restriction</h3>
                        <p class="form-hint">Optionally restrict this team to a subset of nodes you can access.</p>
                    </div>

                    <div class="form-group">
                        <label class="checkbox">
                            <input type="checkbox" name="restrict_nodes" id="restrict_nodes" <?= $restrictValue ? 'checked' : '' ?>>
                            <span>Restrict node access for this team</span>
                        </label>
                        <?php if (!empty($errors['allowed_node_ids'])): ?><p class="form-error"><?= e($errors['allowed_node_ids']) ?></p><?php endif; ?>
                    </div>

                    <div class="form-group" id="allowed-nodes-wrap">
                        <label class="form-label" for="allowed_node_ids">Allowed Nodes</label>
                        <select
                            class="select"
                            id="allowed_node_ids"
                            name="allowed_node_ids[]"
                            multiple
                            data-search="true"
                            data-placeholder="Select nodes..."
                        >
                            <?php foreach (($availableNodes ?? []) as $n): ?>
                                <?php
                                $nodeId = is_object($n) ? (int)($n->id ?? 0) : (int)($n['id'] ?? 0);
                                $nodeName = is_object($n) ? (string)($n->name ?? '') : (string)($n['name'] ?? '');
                                ?>
                                <?php if ($nodeId > 0): ?>
                                    <option value="<?= $nodeId ?>" <?= in_array($nodeId, $selected, true) ? 'selected' : '' ?>><?= e($nodeName) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">If restriction is unchecked, all nodes you can access remain available.</p>
                    </div>
                </div>

                <div class="card-footer">
                    <div class="flex items-center justify-end gap-3">
                        <a href="/teams/<?= (int)$team->id ?>" class="btn btn-ghost">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update Team</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function() {
    const deleteForm = document.getElementById('delete-team-form');
    if (deleteForm) {
        deleteForm.addEventListener('submit', async (e) => {
            if (deleteForm.dataset.submitting === '1') return;
            e.preventDefault();

            const fallback = () => {
                if (confirm('Delete this team?')) {
                    deleteForm.dataset.submitting = '1';
                    deleteForm.submit();
                }
            };

            if (!window.Modal || typeof window.Modal.show !== 'function') {
                fallback();
                return;
            }

            const res = await window.Modal.show({
                type: 'danger',
                title: 'Delete team',
                message: 'Delete this team? This cannot be undone.',
                showCancel: true,
                confirmText: 'Delete',
                cancelText: 'Cancel',
                closeOnBackdrop: false
            });

            if (res && res.confirmed) {
                deleteForm.dataset.submitting = '1';
                deleteForm.submit();
            }
        });
    }

    const restrict = document.getElementById('restrict_nodes');
    const wrap = document.getElementById('allowed-nodes-wrap');
    const select = document.getElementById('allowed_node_ids');
    if (!restrict || !wrap || !select) return;

    function sync() {
        const enabled = !!restrict.checked;
        wrap.style.opacity = enabled ? '' : '0.55';
        wrap.style.pointerEvents = enabled ? '' : 'none';
        select.disabled = !enabled;

        if (!enabled && window.Chap && window.Chap.dropdown && typeof window.Chap.dropdown.closeAll === 'function') {
            window.Chap.dropdown.closeAll();
        }
    }

    restrict.addEventListener('change', sync);
    sync();
})();
</script>

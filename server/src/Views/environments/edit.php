<?php
/**
 * Edit Environment View
 * Updated to use new design system
 */

$errors = $_SESSION['_errors'] ?? [];
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_errors'], $_SESSION['_old_input']);
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item">
                        <a href="/projects/<?= e($project->uuid) ?>"><?= e($project->name) ?></a>
                    </span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item">
                        <a href="/environments/<?= e($environment->uuid) ?>"><?= e($environment->name) ?></a>
                    </span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">Edit</span>
                </nav>

                <h1 class="page-header-title">Edit Environment</h1>
                <p class="page-header-description">Update environment details</p>
            </div>
        </div>
    </div>

    <div class="w-full max-w-2xl">
        <form action="/environments/<?= e($environment->uuid) ?>" method="POST" class="card card-glass" data-confirm-resource-limits="1">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="_method" value="PUT">

            <div class="card-header">
                <h2 class="card-title">Environment Details</h2>
            </div>

            <div class="card-body">
                <div class="flex flex-col gap-4">
                    <div class="form-group">
                        <label for="name" class="form-label">Environment Name <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            required
                            class="input"
                            placeholder="production"
                            value="<?= e($old['name'] ?? $environment->name) ?>"
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
                            placeholder="Production environment for live traffic"
                        ><?= e($old['description'] ?? ($environment->description ?? '')) ?></textarea>
                        <?php if (!empty($errors['description'])): ?>
                            <p class="form-error"><?= e($errors['description']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="mt-2" style="border-top: 1px solid var(--border-default); padding-top: var(--space-4);"></div>

                    <div>
                        <h3 class="text-sm font-semibold text-primary">Resource Limits</h3>
                        <p class="form-hint">Set a fixed value to reserve it from the parent. Use <strong>-1</strong> to auto-split remaining resources across sibling environments.</p>
                        <?php if (!empty($parentEffective)): ?>
                            <p class="form-hint">Parent effective: CPU <?= e((string)$parentEffective['cpu_millicores']) ?>m · RAM <?= e((string)$parentEffective['ram_mb']) ?>MB · Storage <?= e((string)$parentEffective['storage_mb']) ?>MB · Ports <?= e((string)$parentEffective['ports']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label for="cpu_limit_cores" class="form-label">CPU Limit (cores or -1)</label>
                            <?php
                            $cpuDefault = $environment->cpu_millicores_limit === -1 ? '-1' : rtrim(rtrim(number_format($environment->cpu_millicores_limit / 1000, 3, '.', ''), '0'), '.');
                            ?>
                            <input type="text" id="cpu_limit_cores" name="cpu_limit_cores" class="input" value="<?= e($old['cpu_limit_cores'] ?? $cpuDefault) ?>" placeholder="-1">
                            <?php if (!empty($errors['cpu_limit_cores'])): ?><p class="form-error"><?= e($errors['cpu_limit_cores']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="ram_mb_limit" class="form-label">RAM Limit (MB or -1)</label>
                            <input type="number" id="ram_mb_limit" name="ram_mb_limit" class="input" value="<?= e($old['ram_mb_limit'] ?? (string)$environment->ram_mb_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['ram_mb_limit'])): ?><p class="form-error"><?= e($errors['ram_mb_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="storage_mb_limit" class="form-label">Storage Limit (MB or -1)</label>
                            <input type="number" id="storage_mb_limit" name="storage_mb_limit" class="input" value="<?= e($old['storage_mb_limit'] ?? (string)$environment->storage_mb_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['storage_mb_limit'])): ?><p class="form-error"><?= e($errors['storage_mb_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="port_limit" class="form-label">Port Limit (count or -1)</label>
                            <input type="number" id="port_limit" name="port_limit" class="input" value="<?= e($old['port_limit'] ?? (string)$environment->port_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['port_limit'])): ?><p class="form-error"><?= e($errors['port_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="bandwidth_mbps_limit" class="form-label">Bandwidth Limit (Mbps or -1)</label>
                            <input type="number" id="bandwidth_mbps_limit" name="bandwidth_mbps_limit" class="input" value="<?= e($old['bandwidth_mbps_limit'] ?? (string)$environment->bandwidth_mbps_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['bandwidth_mbps_limit'])): ?><p class="form-error"><?= e($errors['bandwidth_mbps_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="pids_limit" class="form-label">PIDs Limit (or -1)</label>
                            <input type="number" id="pids_limit" name="pids_limit" class="input" value="<?= e($old['pids_limit'] ?? (string)$environment->pids_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['pids_limit'])): ?><p class="form-error"><?= e($errors['pids_limit']) ?></p><?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-2" style="border-top: 1px solid var(--border-default); padding-top: var(--space-4);"></div>

                    <div>
                        <h3 class="text-sm font-semibold text-primary">Node Restriction</h3>
                        <p class="form-hint">Optionally restrict this environment to a subset of nodes you can access.</p>
                    </div>

                    <?php
                    $existingNodeIds = \Chap\Services\NodeAccess::decodeNodeIds($environment->allowed_node_ids) ?? [];
                    $restrictDefault = !empty($existingNodeIds);
                    $restrictValue = !empty($old) ? !empty($old['restrict_nodes']) : $restrictDefault;
                    $selected = !empty($old['allowed_node_ids']) && is_array($old['allowed_node_ids'])
                        ? array_map('intval', $old['allowed_node_ids'])
                        : $existingNodeIds;
                    ?>

                    <div class="form-group">
                        <label class="checkbox">
                            <input type="checkbox" name="restrict_nodes" <?= $restrictValue ? 'checked' : '' ?>>
                            <span>Restrict node access for this environment</span>
                        </label>
                        <?php if (!empty($errors['allowed_node_ids'])): ?><p class="form-error"><?= e($errors['allowed_node_ids']) ?></p><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="allowed_node_ids">Allowed Nodes</label>
                        <select class="select" id="allowed_node_ids" name="allowed_node_ids[]" multiple size="6">
                            <?php foreach (($availableNodes ?? []) as $n): ?>
                                <option value="<?= (int)$n->id ?>" <?= in_array((int)$n->id, $selected, true) ? 'selected' : '' ?>><?= e($n->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">If restriction is unchecked, all nodes you can access remain available.</p>
                    </div>
                </div>
            </div>

            <div class="card-footer">
                <div class="flex items-center justify-end gap-3">
                    <a href="/environments/<?= e($environment->uuid) ?>" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">Update Environment</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
/**
 * Edit Project View
 * Updated to use new design system
 */

$errors = $_SESSION['_errors'] ?? [];
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_errors'], $_SESSION['_old_input']);

$team = method_exists($project, 'team') ? $project->team() : null;
$projectMembers = method_exists($project, 'members') ? $project->members() : [];

$existingNodeIds = \Chap\Services\NodeAccess::decodeNodeIds($project->allowed_node_ids) ?? [];
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
                    <span class="breadcrumb-item"><a href="/projects">Projects</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/projects/<?= htmlspecialchars($project->uuid) ?>"><?= htmlspecialchars($project->name) ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">Edit</span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-teal icon-box-lg">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h10M7 12h10M7 17h10M5 7a2 2 0 012-2h10a2 2 0 012 2v10a2 2 0 01-2 2H7a2 2 0 01-2-2V7z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title truncate">Edit Project</h1>
                        <?php if (!empty($project->description)): ?>
                            <p class="page-header-description truncate"><?= e($project->description) ?></p>
                        <?php else: ?>
                            <p class="page-header-description">Update settings, limits, and access</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <form action="/projects/<?= htmlspecialchars($project->uuid) ?>" method="POST" data-confirm-resource-limits="1">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="_method" value="PUT">

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Project Info Card -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Project Information</h2>
                </div>

                <div class="card-body">
                    <dl class="flex flex-col gap-4">
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">UUID</dt>
                            <dd class="m-0"><code class="break-all"><?= e($project->uuid) ?></code></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Team</dt>
                            <dd class="m-0"><?= $team ? e($team->name) : '<span class="text-secondary">Unknown</span>' ?></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Environments</dt>
                            <dd class="m-0"><?= e((string)count(method_exists($project, 'environments') ? $project->environments() : [])) ?></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Applications</dt>
                            <dd class="m-0"><?= method_exists($project, 'applicationCount') ? e((string)$project->applicationCount()) : '0' ?></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Created</dt>
                            <dd class="m-0"><?= $project->created_at ? time_ago($project->created_at) : '-' ?></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Updated</dt>
                            <dd class="m-0"><?= $project->updated_at ? time_ago($project->updated_at) : '-' ?></dd>
                        </div>
                    </dl>
                </div>

                <div class="card-footer">
                    <div class="flex flex-col gap-4">
                        <div class="form-group">
                            <label for="name" class="form-label">Project Name <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                required
                                class="input"
                                placeholder="My Awesome Project"
                                value="<?= htmlspecialchars($old['name'] ?? $project->name) ?>"
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
                            ><?= htmlspecialchars($old['description'] ?? $project->description ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Limits & Access Card -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Limits & Access</h2>
                </div>

                <div class="card-body">
                    <div>
                        <h3 class="text-sm font-semibold text-primary">Resource Limits</h3>
                        <p class="form-hint">Set a fixed value to reserve it from the parent. Use <strong>-1</strong> to auto-split remaining resources across sibling projects.</p>
                        <?php if (!empty($parentEffective)): ?>
                            <p class="form-hint">Parent effective: CPU <?= e((string)$parentEffective['cpu_millicores']) ?>m · RAM <?= e((string)$parentEffective['ram_mb']) ?>MB · Storage <?= e((string)$parentEffective['storage_mb']) ?>MB · Ports <?= e((string)$parentEffective['ports']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div class="form-group">
                            <label for="cpu_limit_cores" class="form-label">CPU Limit (cores or -1)</label>
                            <?php
                            $cpuDefault = $project->cpu_millicores_limit === -1 ? '-1' : rtrim(rtrim(number_format($project->cpu_millicores_limit / 1000, 3, '.', ''), '0'), '.');
                            ?>
                            <input type="text" id="cpu_limit_cores" name="cpu_limit_cores" class="input" value="<?= e($old['cpu_limit_cores'] ?? $cpuDefault) ?>" placeholder="-1">
                            <?php if (!empty($errors['cpu_limit_cores'])): ?><p class="form-error"><?= e($errors['cpu_limit_cores']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="ram_mb_limit" class="form-label">RAM Limit (MB or -1)</label>
                            <input type="number" id="ram_mb_limit" name="ram_mb_limit" class="input" value="<?= e($old['ram_mb_limit'] ?? (string)$project->ram_mb_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['ram_mb_limit'])): ?><p class="form-error"><?= e($errors['ram_mb_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="storage_mb_limit" class="form-label">Storage Limit (MB or -1)</label>
                            <input type="number" id="storage_mb_limit" name="storage_mb_limit" class="input" value="<?= e($old['storage_mb_limit'] ?? (string)$project->storage_mb_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['storage_mb_limit'])): ?><p class="form-error"><?= e($errors['storage_mb_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="port_limit" class="form-label">Port Limit (count or -1)</label>
                            <input type="number" id="port_limit" name="port_limit" class="input" value="<?= e($old['port_limit'] ?? (string)$project->port_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['port_limit'])): ?><p class="form-error"><?= e($errors['port_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="bandwidth_mbps_limit" class="form-label">Bandwidth Limit (Mbps or -1)</label>
                            <input type="number" id="bandwidth_mbps_limit" name="bandwidth_mbps_limit" class="input" value="<?= e($old['bandwidth_mbps_limit'] ?? (string)$project->bandwidth_mbps_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['bandwidth_mbps_limit'])): ?><p class="form-error"><?= e($errors['bandwidth_mbps_limit']) ?></p><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="pids_limit" class="form-label">PIDs Limit (or -1)</label>
                            <input type="number" id="pids_limit" name="pids_limit" class="input" value="<?= e($old['pids_limit'] ?? (string)$project->pids_limit) ?>" placeholder="-1">
                            <?php if (!empty($errors['pids_limit'])): ?><p class="form-error"><?= e($errors['pids_limit']) ?></p><?php endif; ?>
                        </div>
                    </div>

                    <div class="divider"></div>

                    <div>
                        <h3 class="text-sm font-semibold text-primary">Node Restriction</h3>
                        <p class="form-hint">Optionally restrict this project to a subset of nodes you can access.</p>
                    </div>

                    <div class="form-group">
                        <label class="checkbox">
                            <input type="checkbox" name="restrict_nodes" id="restrict_nodes" <?= $restrictValue ? 'checked' : '' ?>>
                            <span>Restrict node access for this project</span>
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
                        <a href="/projects/<?= htmlspecialchars($project->uuid) ?>" class="btn btn-ghost">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update Project</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Project Members</h2>
        </div>

        <div class="card-body">
            <form action="/projects/<?= e($project->uuid) ?>/members" method="POST">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                    <div class="form-group md:col-span-5">
                        <label class="form-label" for="account">Account</label>
                        <input class="input" type="text" id="account" name="account" placeholder="email or username" required>
                        <p class="form-hint">User must already exist and be in this team.</p>
                    </div>

                    <div class="form-group md:col-span-4">
                        <label class="form-label" for="role">Role</label>
                        <select class="select" id="role" name="role">
                            <option value="member" selected>member</option>
                            <option value="viewer">viewer</option>
                            <option value="admin">admin</option>
                        </select>
                        <p class="form-hint"><strong>admin</strong>: manage project + members. <strong>member</strong>: deploy/manage project resources. <strong>viewer</strong>: read-only.</p>
                    </div>

                    <div class="form-group md:col-span-3 flex justify-end">
                        <button type="submit" class="btn btn-primary">Add Member</button>
                    </div>
                </div>
            </form>

            <div class="divider"></div>

            <?php if (empty($projectMembers)): ?>
                <div class="empty-state" style="padding: var(--space-6);">
                    <div class="empty-state-icon">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1m-4 6H2v-2a4 4 0 014-4h1m6-4a4 4 0 11-8 0 4 4 0 018 0zm6 4a4 4 0 10-8 0 4 4 0 008 0z" />
                        </svg>
                    </div>
                    <p class="empty-state-title">No project members</p>
                    <p class="empty-state-description">Add members to control per-user project access and settings.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Email</th>
                                <th style="width: 220px;">Role</th>
                                <th style="width: 220px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projectMembers as $member): ?>
                                <tr>
                                    <td class="min-w-0">
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-primary truncate"><?= e($member->displayName()) ?></div>
                                            <div class="text-xs text-tertiary">User ID: <?= e((string)$member->id) ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-sm text-secondary"><?= e($member->email ?? '') ?></span>
                                    </td>
                                    <td>
                                        <form action="/projects/<?= e($project->uuid) ?>/members/<?= (int)$member->id ?>" method="POST" class="flex items-center gap-2">
                                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="_method" value="PUT">
                                            <select class="select" name="role">
                                                <?php $r = $member->project_role ?? 'member'; ?>
                                                <option value="member" <?= $r === 'member' ? 'selected' : '' ?>>member</option>
                                                <option value="viewer" <?= $r === 'viewer' ? 'selected' : '' ?>>viewer</option>
                                                <option value="admin" <?= $r === 'admin' ? 'selected' : '' ?>>admin</option>
                                            </select>
                                            <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form action="/projects/<?= e($project->uuid) ?>/members/<?= (int)$member->id ?>" method="POST" class="flex items-center justify-end">
                                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="_method" value="DELETE">
                                            <button type="submit" class="btn btn-danger-ghost btn-sm">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    const restrict = document.getElementById('restrict_nodes');
    const wrap = document.getElementById('allowed-nodes-wrap');
    const select = document.getElementById('allowed_node_ids');
    if (!restrict || !wrap || !select) return;

    function sync() {
        const enabled = !!restrict.checked;
        wrap.style.opacity = enabled ? '' : '0.55';
        wrap.style.pointerEvents = enabled ? '' : 'none';
        select.disabled = !enabled;

        // If we disabled it, also close any open dropdown so it doesn't "float".
        if (!enabled && window.Chap && window.Chap.dropdown && typeof window.Chap.dropdown.closeAll === 'function') {
            window.Chap.dropdown.closeAll();
        }
    }

    restrict.addEventListener('change', sync);
    sync();
})();
</script>

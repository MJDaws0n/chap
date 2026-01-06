<?php
/**
 * Team Role Create/Edit View
 *
 * Expects:
 * - $team
 * - $role (array|null)
 * - $ui (TeamPermissions::UI)
 * - $permissions (normalized)
 * - $levelOptions (int => label)
 */

$teamId = (int)($team->id ?? 0);
$isEdit = !empty($role);
$roleId = $isEdit ? (int)($role['id'] ?? 0) : 0;

$action = $isEdit ? "/teams/{$teamId}/roles/{$roleId}" : "/teams/{$teamId}/roles";
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item"><a href="/teams">Teams</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/teams/<?= $teamId ?>"><?= e($team->name ?? '') ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/teams/<?= $teamId ?>/roles">Roles</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current"><?= $isEdit ? 'Edit' : 'Create' ?></span>
                </nav>

                <h1 class="page-header-title"><?= $isEdit ? 'Edit Role' : 'Create Role' ?></h1>
                <p class="page-header-description">Choose the minimal permissions this role should grant.</p>
            </div>
        </div>
    </div>

    <div class="w-full max-w-3xl">
        <form action="<?= e($action) ?>" method="POST" class="card card-glass">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="_method" value="PUT">
            <?php endif; ?>

            <div class="card-header">
                <h2 class="card-title">Role details</h2>
            </div>

            <div class="card-body">
                <div class="flex flex-col gap-4">
                    <div class="form-group">
                        <label class="form-label" for="name">Role name <span class="text-danger">*</span></label>
                        <input class="input" id="name" name="name" type="text" required value="<?= e($isEdit ? (string)($role['name'] ?? '') : (string)old('name', '')) ?>">
                        <p class="form-hint">Avoid using built-in names (Owner/Admin/Manager/Member/Read-only Member).</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="hierarchy_level">Role level</label>
                        <select class="select" id="hierarchy_level" name="hierarchy_level">
                            <?php
                            $selectedLevel = (int)($isEdit ? ($role['hierarchy_level'] ?? 40) : (int)old('hierarchy_level', 40));
                            ?>
                            <?php foreach (($levelOptions ?? []) as $lvl => $label): ?>
                                <option value="<?= (int)$lvl ?>" <?= (int)$lvl === (int)$selectedLevel ? 'selected' : '' ?>><?= e((string)$label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">Level controls who can assign/manage this role (higher can manage lower).</p>
                    </div>

                    <div class="mt-2" style="border-top: 1px solid var(--border-default); padding-top: var(--space-4);"></div>

                    <div>
                        <h3 class="text-sm font-semibold text-primary">Permissions</h3>
                        <p class="form-hint">Only relevant toggles are shown per section.</p>
                    </div>

                    <div class="flex flex-col gap-3">
                        <?php foreach (($ui ?? []) as $key => $meta): ?>
                            <?php
                            $label = (string)($meta['label'] ?? $key);
                            $actions = $meta['actions'] ?? [];
                            if (!is_array($actions)) {
                                $actions = [];
                            }
                            $p = $permissions[$key] ?? ['read' => false, 'write' => false, 'execute' => false];
                            ?>
                            <div class="p-3" style="border: 1px solid var(--border-muted); border-radius: var(--radius-md);">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-primary"><?= e($label) ?></p>
                                        <p class="text-xs text-tertiary"><code class="code-inline"><?= e($key) ?></code></p>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-3">
                                        <?php if (in_array('read', $actions, true)): ?>
                                            <label class="checkbox">
                                                <input type="checkbox" name="perms[<?= e($key) ?>][read]" value="1" <?= !empty($p['read']) ? 'checked' : '' ?>>
                                                <span>Read</span>
                                            </label>
                                        <?php endif; ?>

                                        <?php if (in_array('write', $actions, true)): ?>
                                            <label class="checkbox">
                                                <input type="checkbox" name="perms[<?= e($key) ?>][write]" value="1" <?= !empty($p['write']) ? 'checked' : '' ?>>
                                                <span>Write</span>
                                            </label>
                                        <?php endif; ?>

                                        <?php if (in_array('execute', $actions, true)): ?>
                                            <label class="checkbox">
                                                <input type="checkbox" name="perms[<?= e($key) ?>][execute]" value="1" <?= !empty($p['execute']) ? 'checked' : '' ?>>
                                                <span>Execute</span>
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="card-footer">
                <div class="flex items-center justify-end gap-3">
                    <a href="/teams/<?= $teamId ?>/roles" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Role' : 'Create Role' ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.code-inline {
    font-family: var(--font-mono);
    font-size: var(--text-sm);
    background-color: var(--bg-tertiary);
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-sm);
    word-break: break-all;
}
</style>

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

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-purple icon-box-lg">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622C17.176 19.29 21 14.591 21 9c0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title truncate"><?= $isEdit ? 'Edit Role' : 'Create Role' ?></h1>
                        <p class="page-header-description truncate">Choose the minimal permissions this role should grant.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="w-full max-w-3xl">
        <form action="<?= e($action) ?>" method="POST" class="card">
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

                    <div class="divider"></div>

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
                            <div class="border border-primary rounded-lg p-3 bg-tertiary">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-primary"><?= e($label) ?></p>
                                        <p class="text-xs text-tertiary"><code class="break-all"><?= e($key) ?></code></p>
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
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-end gap-3">
                    <a href="/teams/<?= $teamId ?>/roles" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Role' : 'Create Role' ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

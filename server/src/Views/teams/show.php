<?php
/**
 * Team Show View
 */

$canManage = !empty($isOwner) || !empty($isAdmin) || !empty($adminViewAll);
$canViewMembers = $canViewMembers ?? true;
$canManageMembers = $canManageMembers ?? $canManage;
$canViewRoles = $canViewRoles ?? false;
$canManageRoles = $canManageRoles ?? false;

$builtinBaseRoles = $builtinBaseRoles ?? [];
$customRoles = $customRoles ?? [];

$baseRoleOrder = ['admin' => 80, 'manager' => 60, 'member' => 40, 'read_only_member' => 20];

function team_member_base_role_slug($member, array $baseRoleOrder): string {
    $legacy = $member->role ?? 'member';
    if ($legacy === 'owner') {
        return 'owner';
    }
    $slugs = $member->team_role_slugs ?? [];
    if (!is_array($slugs)) {
        $slugs = [];
    }
    $best = 'member';
    $bestLvl = 0;
    foreach ($slugs as $s) {
        if (!isset($baseRoleOrder[$s])) {
            continue;
        }
        if ($baseRoleOrder[$s] > $bestLvl) {
            $bestLvl = $baseRoleOrder[$s];
            $best = $s;
        }
    }
    if ($legacy === 'admin') {
        return 'admin';
    }
    return $best;
}

function team_member_custom_role_ids($member, array $customRoles): array {
    $slugs = $member->team_role_slugs ?? [];
    if (!is_array($slugs)) {
        $slugs = [];
    }
    $ids = [];
    foreach ($customRoles as $r) {
        $slug = (string)($r['slug'] ?? '');
        if ($slug !== '' && in_array($slug, $slugs, true)) {
            $ids[] = (int)($r['id'] ?? 0);
        }
    }
    return $ids;
}
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item"><a href="/teams">Teams</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current"><?= e($team->name) ?></span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-lg icon-box-blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title"><?= e($team->name) ?></h1>
                        <?php if (!empty($team->description)): ?>
                            <p class="page-header-description line-clamp-2"><?= e($team->description) ?></p>
                        <?php else: ?>
                            <p class="page-header-description">No description</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="page-header-actions">
                <form action="/teams/<?= (int)$team->id ?>/switch" method="POST">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="btn btn-secondary">Set Current</button>
                </form>

                <?php if (!empty($canViewRoles)): ?>
                    <a href="/teams/<?= (int)$team->id ?>/roles" class="btn btn-ghost">Roles</a>
                <?php endif; ?>

                <?php if ($canManage): ?>
                    <a href="/teams/<?= (int)$team->id ?>/edit" class="btn btn-ghost">Edit</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Members</h2>
        </div>

        <div class="card-body">
            <?php if (empty($canViewMembers)): ?>
                <div class="empty-state" style="padding: var(--space-6);">
                    <p class="empty-state-title">Permission denied</p>
                    <p class="empty-state-description">You don't have permission to view team members.</p>
                </div>
            <?php else: ?>
            <?php if ($canManageMembers): ?>
                <form action="/teams/<?= (int)$team->id ?>/members" method="POST" class="card" style="background: transparent; border: 1px solid var(--border-default);">
                    <div class="card-body">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="form-group">
                                <label class="form-label" for="account">Account</label>
                                <input class="input" type="text" id="account" name="account" placeholder="email or username" required>
                                <p class="form-hint">User must already exist.</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="base_role">Base role</label>
                                <select class="select" id="base_role" name="base_role">
                                    <?php
                                    $baseLabels = [
                                        'admin' => 'Admin',
                                        'manager' => 'Manager',
                                        'member' => 'Member',
                                        'read_only_member' => 'Read-only Member',
                                    ];
                                    foreach (($builtinBaseRoles ?? []) as $r) {
                                        $slug = (string)($r['slug'] ?? '');
                                        if (!isset($baseLabels[$slug])) {
                                            continue;
                                        }
                                        $selected = ($slug === 'member') ? 'selected' : '';
                                        echo '<option value="' . e($slug) . '" ' . $selected . '>' . e($baseLabels[$slug]) . '</option>';
                                    }
                                    ?>
                                </select>
                                <p class="form-hint">Base role controls the member’s baseline access.</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Extra roles <span class="text-muted">(optional)</span></label>
                                <?php if (empty($customRoles)): ?>
                                    <p class="form-hint">No custom roles yet.</p>
                                <?php else: ?>
                                    <div class="flex flex-col gap-2">
                                        <?php foreach ($customRoles as $r): ?>
                                            <label class="checkbox">
                                                <input type="checkbox" name="custom_role_ids[]" value="<?= (int)$r['id'] ?>">
                                                <span><?= e((string)($r['name'] ?? '')) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="flex items-center justify-end">
                            <button type="submit" class="btn btn-primary">Add Member</button>
                        </div>
                    </div>
                </form>

                <div class="mt-4"></div>
            <?php endif; ?>

            <?php if (empty($members)): ?>
                <div class="empty-state" style="padding: var(--space-6);">
                    <p class="empty-state-title">No members</p>
                    <p class="empty-state-description">This team has no members yet.</p>
                </div>
            <?php else: ?>
                <div class="flex flex-col gap-3">
                    <?php foreach ($members as $member): ?>
                        <?php
                        $memberRole = $member->role ?? 'member';
                        $isTeamOwnerRow = ($memberRole === 'owner');
                        $baseSlug = team_member_base_role_slug($member, $baseRoleOrder);
                        $customSelectedIds = team_member_custom_role_ids($member, $customRoles);
                        $roleTags = [];
                        if ($isTeamOwnerRow) {
                            $roleTags = ['Owner'];
                        } else {
                            $labels = [
                                'admin' => 'Admin',
                                'manager' => 'Manager',
                                'member' => 'Member',
                                'read_only_member' => 'Read-only',
                            ];
                            if (isset($labels[$baseSlug])) {
                                $roleTags[] = $labels[$baseSlug];
                            }
                            foreach ($customRoles as $r) {
                                if (in_array((int)$r['id'], $customSelectedIds, true)) {
                                    $roleTags[] = (string)($r['name'] ?? '');
                                }
                            }
                        }
                        ?>
                        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 p-3" style="border: 1px solid var(--border-muted); border-radius: var(--radius-md);">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-primary truncate"><?= e($member->displayName()) ?></p>
                                <p class="text-xs text-tertiary"><?= e($member->email ?? '') ?> · roles:
                                    <?php foreach ($roleTags as $t): ?>
                                        <span class="badge badge-neutral" style="margin-right: var(--space-1);"><?= e($t) ?></span>
                                    <?php endforeach; ?>
                                </p>
                            </div>

                            <?php if ($canManageMembers && !$isTeamOwnerRow): ?>
                                <div class="flex flex-col gap-2 w-full md:w-auto">
                                    <form action="/teams/<?= (int)$team->id ?>/members/<?= (int)$member->id ?>" method="POST" class="flex flex-col md:flex-row gap-2">
                                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="_method" value="PUT">

                                        <select class="select" name="base_role">
                                            <option value="read_only_member" <?= $baseSlug === 'read_only_member' ? 'selected' : '' ?>>Read-only Member</option>
                                            <option value="member" <?= $baseSlug === 'member' ? 'selected' : '' ?>>Member</option>
                                            <option value="manager" <?= $baseSlug === 'manager' ? 'selected' : '' ?>>Manager</option>
                                            <option value="admin" <?= $baseSlug === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        </select>

                                        <?php if (!empty($customRoles)): ?>
                                            <details class="w-full md:w-auto" style="border: 1px solid var(--border-muted); border-radius: var(--radius-md); padding: var(--space-2);">
                                                <summary class="text-xs text-secondary" style="cursor: pointer;">Extra roles</summary>
                                                <div class="mt-2 flex flex-col gap-2">
                                                    <?php foreach ($customRoles as $r): ?>
                                                        <label class="checkbox">
                                                            <input type="checkbox" name="custom_role_ids[]" value="<?= (int)$r['id'] ?>" <?= in_array((int)$r['id'], $customSelectedIds, true) ? 'checked' : '' ?>>
                                                            <span><?= e((string)($r['name'] ?? '')) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>

                                        <button type="submit" class="btn btn-secondary">Save</button>
                                    </form>

                                    <form action="/teams/<?= (int)$team->id ?>/members/<?= (int)$member->id ?>" method="POST" class="flex justify-end">
                                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="btn btn-danger-ghost">Remove</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.code-inline {
    font-family: var(--font-mono);
    font-size: var(--text-sm);
    background-color: var(--bg-tertiary);
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-sm);
    word-break: break-all;
}
</style>

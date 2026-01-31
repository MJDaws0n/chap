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
$pendingInvites = $pendingInvites ?? [];

$customRolesForJs = array_map(
    fn($r) => ['id' => (int)($r['id'] ?? 0), 'name' => (string)($r['name'] ?? '')],
    is_array($customRoles) ? $customRoles : []
);
$customRolesB64 = base64_encode(json_encode(array_values(array_filter($customRolesForJs, fn($r) => !empty($r['id'])))));

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
                    <button type="submit" class="btn btn-primary">Set Current</button>
                </form>

                <?php if (!empty($canViewRoles)): ?>
                    <a href="/teams/<?= (int)$team->id ?>/roles" class="btn btn-secondary">Roles</a>
                <?php endif; ?>

                <?php if ($canManage): ?>
                    <a href="/teams/<?= (int)$team->id ?>/edit" class="btn btn-secondary">Edit</a>
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
                <div class="empty-state p-6">
                    <p class="empty-state-title">Permission denied</p>
                    <p class="empty-state-description">You don't have permission to view team members.</p>
                </div>
            <?php else: ?>
            <?php if ($canManageMembers): ?>
                <form action="/teams/<?= (int)$team->id ?>/members" method="POST" class="border border-primary rounded-lg p-4 bg-tertiary">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="form-group">
                                <label class="form-label" for="account">Account</label>
                                <input class="input" type="text" id="account" name="account" placeholder="Email address" required>
                                <p class="form-hint">Enter an email address to invite someone to join. If they already have an account, they can accept using that email.</p>
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
                            <button type="submit" class="btn btn-primary">Send Invite</button>
                        </div>
                </form>

                <?php if (!empty($pendingInvites)): ?>
                    <div class="mt-4"></div>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Pending invitations</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            $baseLabels = [
                                'admin' => 'Admin',
                                'manager' => 'Manager',
                                'member' => 'Member',
                                'read_only_member' => 'Read-only',
                            ];
                            ?>

                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Sent</th>
                                            <th>Expires</th>
                                            <th class="text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingInvites as $inv): ?>
                                            <tr>
                                                <td>
                                                    <div class="font-medium"><?= e((string)$inv->email) ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-neutral"><?= e($baseLabels[(string)$inv->base_role_slug] ?? (string)$inv->base_role_slug) ?></span>
                                                </td>
                                                <td class="text-xs text-secondary"><?= !empty($inv->created_at) ? e(time_ago((string)$inv->created_at)) : '—' ?></td>
                                                <td class="text-xs text-secondary">
                                                    <?php if (!empty($inv->expires_at)): ?>
                                                        <?= e(date('M j, Y', strtotime((string)$inv->expires_at) ?: time())) ?>
                                                    <?php else: ?>
                                                        —
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-right">
                                                    <form action="/teams/<?= (int)$team->id ?>/invites/<?= (int)$inv->id ?>/revoke" method="POST">
                                                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                                        <button type="submit" class="btn btn-danger-ghost btn-sm">Revoke</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <p class="text-xs text-tertiary mt-3">If someone doesn’t want to join, they can ignore the email.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mt-4"></div>
            <?php endif; ?>

            <?php if (empty($members)): ?>
                <div class="empty-state p-6">
                    <p class="empty-state-title">No members</p>
                    <p class="empty-state-description">This team has no members yet.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Roles</th>
                                <?php if ($canManageMembers): ?>
                                    <th class="text-right">Manage</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $member): ?>
                                <?php
                                $memberRole = $member->role ?? 'member';
                                $isTeamOwnerRow = ($memberRole === 'owner');
                                $baseSlug = team_member_base_role_slug($member, $baseRoleOrder);
                                $customSelectedIds = team_member_custom_role_ids($member, $customRoles);

                                $baseLabels = [
                                    'admin' => 'Admin',
                                    'manager' => 'Manager',
                                    'member' => 'Member',
                                    'read_only_member' => 'Read-only',
                                ];
                                $baseLabel = $isTeamOwnerRow ? 'Owner' : ($baseLabels[$baseSlug] ?? 'Member');

                                $selectedCustomNames = [];
                                foreach ($customRoles as $r) {
                                    if (in_array((int)$r['id'], $customSelectedIds, true)) {
                                        $selectedCustomNames[] = (string)($r['name'] ?? '');
                                    }
                                }
                                ?>

                                <tr data-member-row>
                                    <td>
                                        <div class="font-medium"><?= e($member->displayName()) ?></div>
                                        <div class="text-xs text-tertiary truncate"><?= e($member->email ?? '') ?></div>
                                    </td>

                                    <td>
                                        <div class="flex flex-wrap items-center gap-1">
                                            <span class="badge badge-neutral" data-member-base-badge><?= e($baseLabel) ?></span>
                                            <span data-member-custom-badges>
                                                <?php foreach ($selectedCustomNames as $n): ?>
                                                    <span class="badge badge-neutral"><?= e($n) ?></span>
                                                <?php endforeach; ?>
                                            </span>
                                        </div>
                                    </td>

                                    <?php if ($canManageMembers): ?>
                                        <td class="text-right">
                                            <?php if ($isTeamOwnerRow): ?>
                                                <span class="text-xs text-tertiary">Owner</span>
                                            <?php else: ?>
                                                <div
                                                    class="flex flex-col md:flex-row items-stretch md:items-center justify-end gap-2"
                                                    data-member-role-editor
                                                    data-custom-roles-b64="<?= e($customRolesB64) ?>"
                                                >
                                                    <form
                                                        action="/teams/<?= (int)$team->id ?>/members/<?= (int)$member->id ?>"
                                                        method="POST"
                                                        class="flex flex-col md:flex-row items-stretch md:items-center justify-end gap-2"
                                                        data-member-update-form
                                                    >
                                                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                                        <input type="hidden" name="_method" value="PUT">

                                                        <select class="select" name="base_role" data-member-base-role>
                                                            <option value="read_only_member" <?= $baseSlug === 'read_only_member' ? 'selected' : '' ?>>Read-only Member</option>
                                                            <option value="member" <?= $baseSlug === 'member' ? 'selected' : '' ?>>Member</option>
                                                            <option value="manager" <?= $baseSlug === 'manager' ? 'selected' : '' ?>>Manager</option>
                                                            <option value="admin" <?= $baseSlug === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                        </select>

                                                        <span data-custom-role-inputs>
                                                            <?php foreach ($customSelectedIds as $rid): ?>
                                                                <input type="hidden" name="custom_role_ids[]" value="<?= (int)$rid ?>">
                                                            <?php endforeach; ?>
                                                        </span>

                                                        <?php if (!empty($customRoles)): ?>
                                                            <button type="button" class="btn btn-secondary btn-sm" data-custom-role-edit>
                                                                Extra roles<?= count($customSelectedIds) ? ' (' . (int)count($customSelectedIds) . ')' : '' ?>
                                                            </button>
                                                        <?php endif; ?>

                                                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                                    </form>

                                                    <form
                                                        action="/teams/<?= (int)$team->id ?>/members/<?= (int)$member->id ?>"
                                                        method="POST"
                                                        data-member-remove-form
                                                        data-member-name="<?= e($member->displayName()) ?>"
                                                    >
                                                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                                        <input type="hidden" name="_method" value="DELETE">
                                                        <button type="submit" class="btn btn-danger-ghost btn-sm">Remove</button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
</style>

<script>
(function() {
    function decodeB64Json(b64, fallback) {
        try {
            return JSON.parse(atob(b64 || ''));
        } catch (e) {
            return fallback;
        }
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    const baseLabels = {
        read_only_member: 'Read-only',
        member: 'Member',
        manager: 'Manager',
        admin: 'Admin'
    };

    function renderCustomBadges(container, rolesById, ids) {
        if (!container) return;
        const list = Array.isArray(ids) ? ids : [];
        const html = list
            .map((id) => {
                const name = rolesById.get(Number(id));
                if (!name) return '';
                return `<span class="badge badge-neutral">${escapeHtml(name)}</span>`;
            })
            .join('');
        container.innerHTML = html;
    }

    document.querySelectorAll('[data-member-role-editor]').forEach((wrap) => {
        const customRoles = decodeB64Json(wrap.dataset.customRolesB64, []);
        const rolesById = new Map((customRoles || []).map((r) => [Number(r.id), String(r.name || '')]));

        const updateForm = wrap.querySelector('form[data-member-update-form]');
        const inputsEl = wrap.querySelector('[data-custom-role-inputs]');
        const editBtn = wrap.querySelector('[data-custom-role-edit]');

        const row = wrap.closest('tr[data-member-row]');
        const baseBadge = row ? row.querySelector('[data-member-base-badge]') : null;
        const customBadges = row ? row.querySelector('[data-member-custom-badges]') : null;
        const baseSelect = wrap.querySelector('[data-member-base-role]');

        if (baseSelect && baseBadge) {
            baseSelect.addEventListener('change', () => {
                baseBadge.textContent = baseLabels[String(baseSelect.value || '')] || 'Member';
            });
        }

        if (!editBtn || !inputsEl) return;

        editBtn.addEventListener('click', async () => {
            if (!window.Modal || typeof window.Modal.show !== 'function') return;

            const currentIds = Array.from(inputsEl.querySelectorAll('input[name="custom_role_ids[]"]'))
                .map((i) => Number(i.value))
                .filter((n) => Number.isFinite(n) && n > 0);

            let working = new Set(currentIds);

            const html = `
                <div class="flex flex-col gap-3" style="max-height: 70vh; min-height: 0;">
                    <div class="flex items-center gap-2">
                        <input class="input w-full" type="text" placeholder="Search roles…" data-role-search>
                        <button type="button" class="btn btn-secondary" data-role-select-all>Select all</button>
                        <button type="button" class="btn btn-ghost" data-role-clear-all>Clear</button>
                    </div>
                    <div style="flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding-right: 4px;">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            ${(customRoles || []).map((r) => `
                                <label class="flex items-center gap-2 text-sm border border-primary rounded-lg p-2 bg-tertiary" data-role-item data-role-name="${escapeHtml(r.name)}">
                                    <input type="checkbox" class="checkbox" data-role-checkbox value="${escapeHtml(r.id)}" ${working.has(Number(r.id)) ? 'checked' : ''}>
                                    <span class="min-w-0" style="word-break: break-word;">${escapeHtml(r.name)}</span>
                                </label>
                            `).join('')}
                        </div>
                        ${(customRoles || []).length ? '' : '<p class="text-sm text-secondary m-0">No custom roles available.</p>'}
                    </div>
                </div>
            `;

            const promise = window.Modal.show({
                type: 'info',
                title: 'Extra roles',
                html,
                showCancel: true,
                confirmText: 'Done',
                cancelText: 'Cancel',
                closeOnBackdrop: false
            });

            requestAnimationFrame(() => {
                const backdrops = document.querySelectorAll('.modal-backdrop');
                const backdrop = backdrops[backdrops.length - 1];
                if (!backdrop) return;

                const search = backdrop.querySelector('[data-role-search]');
                const selectAll = backdrop.querySelector('[data-role-select-all]');
                const clearAll = backdrop.querySelector('[data-role-clear-all]');
                const items = Array.from(backdrop.querySelectorAll('[data-role-item]'));
                const checkboxes = Array.from(backdrop.querySelectorAll('[data-role-checkbox]'));

                function syncCheckboxes() {
                    checkboxes.forEach((cb) => {
                        cb.checked = working.has(Number(cb.value));
                    });
                }

                syncCheckboxes();

                checkboxes.forEach((cb) => {
                    cb.addEventListener('change', () => {
                        const id = Number(cb.value);
                        if (!Number.isFinite(id) || id <= 0) return;
                        if (cb.checked) working.add(id);
                        else working.delete(id);
                    });
                });

                if (selectAll) {
                    selectAll.addEventListener('click', () => {
                        working = new Set((customRoles || []).map((r) => Number(r.id)).filter((n) => Number.isFinite(n) && n > 0));
                        syncCheckboxes();
                    });
                }

                if (clearAll) {
                    clearAll.addEventListener('click', () => {
                        working = new Set();
                        syncCheckboxes();
                    });
                }

                if (search) {
                    search.addEventListener('input', () => {
                        const q = String(search.value || '').trim().toLowerCase();
                        items.forEach((row) => {
                            const name = String(row.dataset.roleName || '').toLowerCase();
                            row.style.display = (q !== '' && !name.includes(q)) ? 'none' : '';
                        });
                    });
                }
            });

            const res = await promise;
            if (!res || !res.confirmed) return;

            const nextIds = Array.from(working).sort((a, b) => a - b);
            inputsEl.innerHTML = '';
            nextIds.forEach((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'custom_role_ids[]';
                input.value = String(id);
                inputsEl.appendChild(input);
            });

            renderCustomBadges(customBadges, rolesById, nextIds);

            if (editBtn) {
                editBtn.textContent = `Extra roles${nextIds.length ? ` (${nextIds.length})` : ''}`;
            }
        });
    });

    document.querySelectorAll('form[data-member-remove-form]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            if (form.dataset.submitting === '1') return;
            e.preventDefault();

            const name = form.dataset.memberName || 'this member';
            const fallback = () => {
                if (confirm('Remove this member from the team?')) {
                    form.dataset.submitting = '1';
                    form.submit();
                }
            };

            if (!window.Modal || typeof window.Modal.show !== 'function') {
                fallback();
                return;
            }

            const res = await window.Modal.show({
                type: 'danger',
                title: 'Remove member',
                message: `Remove ${name} from the team?`,
                showCancel: true,
                confirmText: 'Remove',
                cancelText: 'Cancel',
                closeOnBackdrop: false
            });

            if (res && res.confirmed) {
                form.dataset.submitting = '1';
                form.submit();
            }
        });
    });
})();
</script>

<?php
/**
 * Edit Project View
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
                    <span class="breadcrumb-item"><a href="/projects">Projects</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/projects/<?= htmlspecialchars($project->uuid) ?>"><?= htmlspecialchars($project->name) ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">Edit</span>
                </nav>
                <h1 class="page-header-title">Edit Project</h1>
                <p class="page-header-description">Update your project details</p>
            </div>
        </div>
    </div>

    <div class="w-full max-w-2xl">
        <form action="/projects/<?= htmlspecialchars($project->uuid) ?>" method="POST" class="card card-glass">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="_method" value="PUT">

            <div class="card-header">
                <h2 class="card-title">Project Details</h2>
            </div>

            <div class="card-body">
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

            <div class="card-footer">
                <div class="flex items-center justify-end gap-3">
                    <a href="/projects/<?= htmlspecialchars($project->uuid) ?>" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">Update Project</button>
                </div>
            </div>
        </form>

        <div class="card card-glass mt-6">
            <div class="card-header">
                <h2 class="card-title">Project Members</h2>
            </div>

            <div class="card-body">
                <?php
                // Pull members on demand to avoid widening controller changes
                $projectMembers = method_exists($project, 'members') ? $project->members() : [];
                ?>

                <div class="flex flex-col gap-4">
                    <form action="/projects/<?= e($project->uuid) ?>/members" method="POST" class="card" style="background: transparent; border: 1px solid var(--border-default);">
                        <div class="card-body">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="form-group md:col-span-1">
                                    <label class="form-label" for="account">Account</label>
                                    <input class="input" type="text" id="account" name="account" placeholder="email or username" required>
                                    <p class="form-hint">User must already exist and be in this team.</p>
                                </div>
                                <div class="form-group md:col-span-1">
                                    <label class="form-label" for="role">Role</label>
                                    <select class="select" id="role" name="role">
                                        <option value="member" selected>member</option>
                                        <option value="viewer">viewer</option>
                                        <option value="admin">admin</option>
                                    </select>
                                    <p class="form-hint">
                                        <strong>admin</strong>: manage project + members. <strong>member</strong>: deploy/manage project resources. <strong>viewer</strong>: read-only.
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center justify-end">
                                <button type="submit" class="btn btn-primary">Add Member</button>
                            </div>
                        </div>
                    </form>

                    <?php if (empty($projectMembers)): ?>
                        <div class="empty-state" style="padding: var(--space-6);">
                            <p class="empty-state-title">No project members</p>
                            <p class="empty-state-description">Add members to control per-user project access and settings.</p>
                        </div>
                    <?php else: ?>
                        <div class="card" style="background: transparent; border: 1px solid var(--border-default);">
                            <div class="card-body">
                                <div class="flex flex-col gap-3">
                                    <?php foreach ($projectMembers as $member): ?>
                                        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 p-3" style="border: 1px solid var(--border-muted); border-radius: var(--radius-md);">
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold text-primary truncate"><?= e($member->displayName()) ?></p>
                                                <p class="text-xs text-tertiary"><?= e($member->email ?? '') ?> · role: <code class="code-inline"><?= e($member->project_role ?? 'member') ?></code></p>
                                            </div>

                                            <div class="flex flex-col gap-2 w-full md:w-auto">
                                                <form action="/projects/<?= e($project->uuid) ?>/members/<?= (int)$member->id ?>" method="POST" class="flex flex-col md:flex-row gap-2">
                                                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="_method" value="PUT">

                                                    <select class="select" name="role">
                                                        <?php $r = $member->project_role ?? 'member'; ?>
                                                        <option value="member" <?= $r === 'member' ? 'selected' : '' ?>>member</option>
                                                        <option value="viewer" <?= $r === 'viewer' ? 'selected' : '' ?>>viewer</option>
                                                        <option value="admin" <?= $r === 'admin' ? 'selected' : '' ?>>admin</option>
                                                    </select>
                                                    <button type="submit" class="btn btn-secondary">Save</button>
                                                </form>

                                                <form action="/projects/<?= e($project->uuid) ?>/members/<?= (int)$member->id ?>" method="POST" class="flex justify-end">
                                                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="_method" value="DELETE">
                                                    <button type="submit" class="btn btn-danger-ghost">Remove</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
/**
 * Edit Team View
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
                    <span class="breadcrumb-item"><a href="/teams">Teams</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/teams/<?= (int)$team->id ?>"><?= e($team->name) ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">Edit</span>
                </nav>

                <h1 class="page-header-title">Edit Team</h1>
                <p class="page-header-description">Update team details</p>
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

    <div class="w-full max-w-2xl">
        <form action="/teams/<?= (int)$team->id ?>" method="POST" class="card card-glass">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="_method" value="PUT">

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

            <div class="card-footer">
                <div class="flex items-center justify-end gap-3">
                    <a href="/teams/<?= (int)$team->id ?>" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">Update Team</button>
                </div>
            </div>
        </form>
    </div>
</div>

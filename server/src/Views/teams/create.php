<?php
/**
 * Create Team View
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
                    <span class="breadcrumb-current">New</span>
                </nav>
                <h1 class="page-header-title">Create Team</h1>
                <p class="page-header-description">Create a new team and invite members</p>
            </div>
        </div>
    </div>

    <div class="w-full max-w-2xl">
        <form action="/teams" method="POST" class="card card-glass">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

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
                            placeholder="My Team"
                            value="<?= e($old['name'] ?? '') ?>"
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
                            placeholder="What is this team for?"
                        ><?= e($old['description'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card-footer">
                <div class="flex items-center justify-end gap-3">
                    <a href="/teams" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">Create Team</button>
                </div>
            </div>
        </form>
    </div>
</div>

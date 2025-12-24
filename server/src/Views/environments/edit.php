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
        <form action="/environments/<?= e($environment->uuid) ?>" method="POST" class="card card-glass">
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

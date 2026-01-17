<?php
/**
 * Admin Templates Index
 */

$templates = $templates ?? [];
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Templates</h1>
                <p class="page-header-description">Upload and manage application templates</p>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body flex flex-col gap-4">
            <div>
                <h2 class="text-lg font-medium">Upload template package</h2>
                <p class="text-sm text-secondary">Upload a .zip containing config.json, docker-compose.yml and optional files/.</p>
            </div>

            <form method="POST" action="/admin/templates/upload" enctype="multipart/form-data" class="flex flex-col md:flex-row gap-3 md:items-end">
                <?= csrf_field() ?>
                <div class="flex-1">
                    <label class="label">Template zip</label>
                    <input class="input w-full" type="file" name="template_zip" accept=".zip" required />
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Category</th>
                        <th>Version</th>
                        <th>Official</th>
                        <th>Active</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $t): ?>
                        <tr>
                            <td class="font-medium text-primary"><?= e($t->name) ?></td>
                            <td class="text-secondary"><?= e($t->slug) ?></td>
                            <td class="text-secondary"><?= e($t->category ?? 'Other') ?></td>
                            <td class="text-secondary"><?= e($t->version ?? '-') ?></td>
                            <td>
                                <?php if (!empty($t->is_official)): ?>
                                    <span class="badge badge-success">Yes</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($t->is_active)): ?>
                                    <span class="badge badge-success">Yes</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-secondary"><?= !empty($t->updated_at) ? e(time_ago($t->updated_at)) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (empty($templates)): ?>
            <div class="card-body">
                <p class="text-secondary">No templates found.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

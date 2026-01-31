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

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-lg icon-box-blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title truncate">Create Team</h1>
                        <p class="page-header-description truncate">Create a new team and invite members</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <form action="/teams" method="POST" class="card">
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
                                rows="4"
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

        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">What’s next?</h2>
                </div>
                <div class="card-body">
                    <div class="flex flex-col gap-4 text-sm">
                        <div>
                            <div class="font-semibold text-primary mb-1">Invite teammates</div>
                            <div class="text-secondary">Add members and assign roles to control access.</div>
                        </div>
                        <div>
                            <div class="font-semibold text-primary mb-1">Create projects</div>
                            <div class="text-secondary">Projects let you organize environments and apps for your team.</div>
                        </div>
                        <div>
                            <div class="font-semibold text-primary mb-1">Define roles</div>
                            <div class="text-secondary">Create custom roles for granular permissions when needed.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

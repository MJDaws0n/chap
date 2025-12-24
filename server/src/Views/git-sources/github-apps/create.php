<?php
/**
 * Add GitHub App View
 */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item"><a href="/git-sources?tab=github-apps">Git Sources</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">Add GitHub App</span>
                </nav>
                <h1 class="page-header-title">Add GitHub App</h1>
                <p class="page-header-description">Chap will try each configured app for private repos.</p>
            </div>
        </div>
    </div>

    <div class="w-full max-w-2xl">
        <form method="POST" action="/git-sources/github-apps" class="card card-glass">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

            <div class="card-header">
                <h2 class="card-title">GitHub App Credentials</h2>
            </div>

            <div class="card-body">
                <div class="flex flex-col gap-4">
                    <div class="form-group">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            class="input"
                            placeholder="My Org App"
                            value="<?= e(old('name')) ?>"
                            required
                        >
                        <?php if (!empty($_SESSION['_errors']['name'])): ?>
                            <p class="form-error"><?= e($_SESSION['_errors']['name']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="github_app_id" class="form-label">GitHub App ID <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            inputmode="numeric"
                            id="github_app_id"
                            name="github_app_id"
                            class="input"
                            placeholder="123456"
                            value="<?= e(old('github_app_id')) ?>"
                            required
                        >
                        <?php if (!empty($_SESSION['_errors']['github_app_id'])): ?>
                            <p class="form-error"><?= e($_SESSION['_errors']['github_app_id']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="github_app_installation_id" class="form-label">Installation ID <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            inputmode="numeric"
                            id="github_app_installation_id"
                            name="github_app_installation_id"
                            class="input"
                            placeholder="987654321"
                            value="<?= e(old('github_app_installation_id')) ?>"
                            required
                        >
                        <?php if (!empty($_SESSION['_errors']['github_app_installation_id'])): ?>
                            <p class="form-error"><?= e($_SESSION['_errors']['github_app_installation_id']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="github_app_private_key" class="form-label">Private Key (PEM) <span class="text-danger">*</span></label>
                        <textarea
                            id="github_app_private_key"
                            name="github_app_private_key"
                            class="textarea"
                            rows="10"
                            placeholder="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
                            required
                        ><?= e(old('github_app_private_key')) ?></textarea>
                        <?php if (!empty($_SESSION['_errors']['github_app_private_key'])): ?>
                            <p class="form-error"><?= e($_SESSION['_errors']['github_app_private_key']) ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-tertiary">Stored encrypted at rest only if your DB encryption supports it; treat as sensitive.</p>
                    </div>
                </div>
            </div>

            <div class="card-footer">
                <div class="flex items-center justify-end gap-3">
                    <a href="/git-sources?tab=github-apps" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php unset($_SESSION['_errors'], $_SESSION['_old_input']); ?>

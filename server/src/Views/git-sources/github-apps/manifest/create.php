<?php
/**
 * GitHub App manifest auto-setup
 */
$defaultBaseUrl = $defaultBaseUrl ?? '';
$defaultRedirectUrl = $defaultRedirectUrl ?? '';
$errors = $_SESSION['_errors'] ?? [];
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div>
            <nav class="breadcrumb">
                <span class="breadcrumb-item">
                    <a href="/git-sources?tab=github-apps">Git Sources</a>
                </span>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current">Auto-create GitHub App</span>
            </nav>
            <h1 class="page-title">Auto-create GitHub App</h1>
            <p class="page-header-description">Creates a GitHub App via manifest and stores its credentials for this team.</p>
        </div>
    </div>

    <div class="card card-glass">
        <div class="card-body">
            <form method="POST" action="/git-sources/github-apps/manifest" class="flex flex-col gap-4">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                <div class="form-group">
                    <label class="form-label" for="name">App name (shown on GitHub)</label>
                    <input class="input" id="name" name="name" type="text" required value="<?= e(old('name', 'Chap')) ?>">
                    <?php if (!empty($errors['name'])): ?><p class="text-danger text-sm mt-1"><?= e($errors['name']) ?></p><?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="organization">Organization (optional)</label>
                    <input class="input" id="organization" name="organization" type="text" placeholder="my-org" value="<?= e(old('organization', '')) ?>">
                    <p class="text-secondary text-sm mt-1">If set, the app will be created under that GitHub organization. Leave blank to create under your personal account.</p>
                    <?php if (!empty($errors['organization'])): ?><p class="text-danger text-sm mt-1"><?= e($errors['organization']) ?></p><?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="base_url">Chap URL (callback domain)</label>
                    <input class="input" id="base_url" name="base_url" type="url" required value="<?= e(old('base_url', $defaultBaseUrl)) ?>">
                    <p class="text-secondary text-sm mt-1">We’ll use this to set the GitHub manifest redirect URL automatically.</p>
                    <?php if (!empty($errors['base_url'])): ?><p class="text-danger text-sm mt-1"><?= e($errors['base_url']) ?></p><?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Redirect URL (auto)</label>
                    <input class="input" type="text" value="<?= e($defaultRedirectUrl) ?>" readonly>
                </div>

                <div class="flex items-center gap-3">
                    <button class="btn btn-primary" type="submit">Continue to GitHub</button>
                    <a class="btn btn-secondary" href="/git-sources?tab=github-apps">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
unset($_SESSION['_errors'], $_SESSION['_old_input']);
?>

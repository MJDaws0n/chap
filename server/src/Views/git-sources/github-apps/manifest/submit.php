<?php
/**
 * Auto-submitting manifest form to GitHub.
 */
$actionUrl = $actionUrl ?? 'https://github.com/settings/apps/new';
$manifestJson = $manifestJson ?? '{}';
?>

<div class="flex flex-col gap-6">
    <div class="card card-glass">
        <div class="card-body">
            <h1 class="page-title">Redirecting to GitHub…</h1>
            <p class="text-secondary">You’ll be asked to confirm creation of the GitHub App. After that, GitHub will redirect back to Chap automatically.</p>

            <form id="manifest-form" method="POST" action="<?= e($actionUrl) ?>" class="mt-4">
                <input type="hidden" name="manifest" value="<?= e($manifestJson) ?>">
                <noscript>
                    <p class="text-secondary">JavaScript is required to auto-redirect. Click Continue:</p>
                    <button class="btn btn-primary" type="submit">Continue</button>
                </noscript>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        var form = document.getElementById('manifest-form');
        if (form) form.submit();
    })();
</script>

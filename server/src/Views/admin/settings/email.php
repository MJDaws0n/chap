<?php
/**
 * Admin Email Settings
 */

$errors = $_SESSION['_errors'] ?? [];
unset($_SESSION['_errors']);
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_old_input']);

$current = $settings ?? [];
$val = function(string $key, string $default = '') use ($old, $current): string {
    if (array_key_exists($key, $old)) {
        return (string)$old[$key];
    }
    return (string)($current[$key] ?? $default);
};

$authChecked = ($old['auth'] ?? null) !== null
    ? (($old['auth'] ?? '') === 'on')
    : (((string)($current['mail.auth'] ?? '1')) === '1');
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Email Settings</h1>
                <p class="page-header-description">Configure SMTP settings used to send emails</p>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="/admin/settings/email" class="form">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label" for="from_name">From name</label>
                        <input class="input" id="from_name" name="from_name" type="text" value="<?= e($val('from_name', (string)($current['mail.from_name'] ?? 'Chap'))) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="from_address">From address</label>
                        <input class="input <?= !empty($errors['from_address']) ? 'input-error' : '' ?>" id="from_address" name="from_address" type="email" value="<?= e($val('from_address', (string)($current['mail.from_address'] ?? ''))) ?>">
                        <?php if (!empty($errors['from_address'])): ?>
                            <p class="form-error"><?= e($errors['from_address']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="host">SMTP host</label>
                        <input class="input" id="host" name="host" type="text" value="<?= e($val('host', (string)($current['mail.host'] ?? ''))) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="port">SMTP port</label>
                        <input class="input <?= !empty($errors['port']) ? 'input-error' : '' ?>" id="port" name="port" type="text" inputmode="numeric" value="<?= e($val('port', (string)($current['mail.port'] ?? '587'))) ?>">
                        <?php if (!empty($errors['port'])): ?>
                            <p class="form-error"><?= e($errors['port']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="username">SMTP username</label>
                        <input class="input" id="username" name="username" type="text" value="<?= e($val('username', (string)($current['mail.username'] ?? ''))) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">SMTP password</label>
                        <input class="input" id="password" name="password" type="password" placeholder="Leave blank to keep existing">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="encryption">Encryption</label>
                        <?php $enc = $val('encryption', (string)($current['mail.encryption'] ?? 'tls')); ?>
                        <select class="select <?= !empty($errors['encryption']) ? 'select-error' : '' ?>" id="encryption" name="encryption">
                            <option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                            <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>>SSL</option>
                            <option value="none" <?= $enc === 'none' ? 'selected' : '' ?>>None</option>
                        </select>
                        <?php if (!empty($errors['encryption'])): ?>
                            <p class="form-error"><?= e($errors['encryption']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="checkbox">
                            <input type="checkbox" name="auth" <?= $authChecked ? 'checked' : '' ?>>
                            <span>SMTP authentication</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center gap-3 mt-4">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="submit" class="btn btn-secondary" formaction="/admin/settings/email/test">Send test email</button>
                </div>
            </form>
        </div>
    </div>
</div>

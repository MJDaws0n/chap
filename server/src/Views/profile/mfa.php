<?php
/** @var array $user */
$enabled = (bool)($user['two_factor_enabled'] ?? false);
$hasSetup = !empty($setupSecret ?? '');
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Multi-Factor Authentication</h1>
                <p class="page-header-description">Protect your account with time-based one-time passwords (TOTP).</p>
            </div>
            <div class="page-header-actions">
                <a href="/profile" class="btn btn-secondary">Back</a>
            </div>
        </div>
    </div>

    <div class="w-full max-w-2xl flex flex-col gap-6">
        <div class="card card-glass">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Status</h2>
                    <p class="text-secondary text-sm">
                        <?= $enabled ? 'MFA is enabled on your account.' : 'MFA is currently disabled.' ?>
                    </p>
                </div>
            </div>

            <div class="card-body">
                <?php if (!$enabled): ?>
                    <?php if (!$hasSetup): ?>
                        <form method="POST" action="/profile/mfa/start">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="btn btn-primary">Set up MFA</button>
                        </form>
                    <?php else: ?>
                        <div class="flex flex-col gap-4">
                            <div>
                                <h3 class="text-sm font-semibold mb-2">Scan QR Code</h3>
                                <?php if (!empty($setupQr)): ?>
                                    <img src="<?= htmlspecialchars($setupQr, ENT_QUOTES) ?>" alt="MFA QR code" style="width:220px;height:220px;" />
                                <?php else: ?>
                                    <p class="text-secondary text-sm">QR code could not be generated. Use the manual key below.</p>
                                <?php endif; ?>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold mb-2">One-tap link</h3>
                                <?php if (!empty($setupUri)): ?>
                                    <a class="text-blue break-all" href="<?= htmlspecialchars($setupUri, ENT_QUOTES) ?>">Open authenticator app</a>
                                <?php endif; ?>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold mb-2">Manual key</h3>
                                <div class="input" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;">
                                    <?= e($setupSecret) ?>
                                </div>
                                <p class="text-secondary text-sm mt-2">If your app asks: Type = TOTP, Digits = 6, Period = 30s, Algorithm = SHA1.</p>
                            </div>

                            <form method="POST" action="/profile/mfa/confirm" class="flex flex-col gap-3">
                                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                <div class="form-group">
                                    <label class="form-label" for="code">Enter the 6-digit code to confirm</label>
                                    <input class="input" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" required placeholder="123456">
                                </div>
                                <button type="submit" class="btn btn-primary">Enable MFA</button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <form method="POST" action="/profile/mfa/disable" class="flex flex-col gap-4">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                        <div class="form-group">
                            <label class="form-label" for="current_password">Current password</label>
                            <input class="input" id="current_password" name="current_password" type="password" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="code">Authentication code</label>
                            <input class="input" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" required placeholder="123456">
                        </div>

                        <button type="submit" class="btn btn-danger">Disable MFA</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php

namespace Chap\Controllers\Admin;

use Chap\Controllers\BaseController;
use Chap\Models\Setting;
use Chap\Models\ActivityLog;

class SettingsController extends BaseController
{
    public function email(): void
    {
        $settings = Setting::getMany([
            'mail.from_name',
            'mail.from_address',
            'mail.host',
            'mail.port',
            'mail.username',
            'mail.encryption',
            'mail.auth',
        ]);

        $this->view('admin/settings/email', [
            'title' => 'Email Settings',
            'currentPage' => 'admin-settings',
            'settings' => $settings,
        ]);
    }

    public function updateEmail(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/admin/settings/email');
        }

        $fromName = trim((string)$this->input('from_name', ''));
        $fromAddress = trim((string)$this->input('from_address', ''));
        $host = trim((string)$this->input('host', ''));
        $port = trim((string)$this->input('port', ''));
        $username = trim((string)$this->input('username', ''));
        $password = (string)$this->input('password', '');
        $encryption = (string)$this->input('encryption', 'tls');
        $auth = $this->input('auth') === 'on';

        $errors = [];

        if ($fromAddress !== '' && !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $errors['from_address'] = 'Invalid from address';
        }

        if ($port !== '' && (!ctype_digit($port) || (int)$port <= 0 || (int)$port > 65535)) {
            $errors['port'] = 'Invalid port';
        }

        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $errors['encryption'] = 'Invalid encryption';
        }

        if (!empty($errors)) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old_input'] = [
                'from_name' => $fromName,
                'from_address' => $fromAddress,
                'host' => $host,
                'port' => $port,
                'username' => $username,
                'encryption' => $encryption,
                'auth' => $auth ? 'on' : 'off',
            ];
            $this->redirect('/admin/settings/email');
        }

        Setting::set('mail.from_name', $fromName);
        Setting::set('mail.from_address', $fromAddress);
        Setting::set('mail.host', $host);
        Setting::set('mail.port', $port === '' ? '' : (string)(int)$port);
        Setting::set('mail.username', $username);
        Setting::set('mail.encryption', $encryption);
        Setting::set('mail.auth', $auth ? '1' : '0');

        // Only update password if explicitly provided (so we don't wipe it).
        if ($password !== '') {
            Setting::set('mail.password', $password);
        }

        ActivityLog::log('admin.settings.email.updated');

        flash('success', 'Email settings updated');
        $this->redirect('/admin/settings/email');
    }
}

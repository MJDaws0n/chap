<?php

namespace Chap\Controllers\Admin;

use Chap\Controllers\BaseController;
use Chap\Models\Setting;
use Chap\Models\ActivityLog;
use Chap\Services\Mailer;

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

    public function sendTestEmail(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/admin/settings/email');
        }

        $to = (string)($this->user?->email ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Your account does not have a valid email address to send a test email to.');
            $this->redirect('/admin/settings/email');
        }

        if (!Mailer::isConfigured()) {
            flash('error', 'Email is not configured. Please save SMTP settings first.');
            $this->redirect('/admin/settings/email');
        }

        $fromName = (string)Setting::get('mail.from_name', 'Chap');
        $host = (string)Setting::get('mail.host', '');
        $port = (string)Setting::get('mail.port', '');
        $encryption = (string)Setting::get('mail.encryption', 'tls');

        $subject = 'Chap test email';
        $html = '<p>This is a test email from Chap.</p>'
            . '<p><strong>From:</strong> ' . htmlspecialchars($fromName, ENT_QUOTES) . '</p>'
            . '<p><strong>SMTP:</strong> ' . htmlspecialchars($host, ENT_QUOTES) . ':' . htmlspecialchars($port, ENT_QUOTES)
            . ' (' . htmlspecialchars($encryption, ENT_QUOTES) . ')</p>'
            . '<p><strong>Sent to:</strong> ' . htmlspecialchars($to, ENT_QUOTES) . '</p>'
            . '<p><em>If you received this, your SMTP settings are working.</em></p>';
        $text = "This is a test email from Chap.\n\nFrom: {$fromName}\nSMTP: {$host}:{$port} ({$encryption})\nSent to: {$to}\n\nIf you received this, your SMTP settings are working.";

        try {
            Mailer::send($to, $subject, $html, $text);
            ActivityLog::log('admin.settings.email.test_sent');
            flash('success', 'Test email sent to ' . $to);
        } catch (\Throwable $e) {
            error_log('Test email failed: ' . $e->getMessage());
            flash('error', 'Failed to send test email. Please check your SMTP settings and server logs.');
        }

        $this->redirect('/admin/settings/email');
    }
}

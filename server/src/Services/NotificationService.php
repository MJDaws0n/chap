<?php

namespace Chap\Services;

use Chap\App;
use Chap\Config;
use Chap\Models\Application;
use Chap\Models\Deployment;
use Chap\Models\Node;
use Chap\Models\Team;
use Chap\Models\User;
use Chap\Services\Mailer;

class NotificationService
{
    /**
     * Default notification settings for a user (per team).
     */
    public static function defaultUserNotifications(): array
    {
        return [
            'deployments' => [
                'enabled' => true,
                'mode' => 'all', // all | failed
            ],
            'general' => [
                'enabled' => true,
            ],
            'channels' => [
                'email' => true,
                'webhook' => [
                    'enabled' => false,
                    'url' => '',
                ],
            ],
        ];
    }

    /**
     * Default notification settings for an application.
     */
    public static function defaultApplicationNotifications(): array
    {
        return [
            'deployments' => [
                'enabled' => true,
                'mode' => 'all',
            ],
        ];
    }

    /**
     * Get normalized notification settings for a user in a team.
     */
    public static function getUserNotifications(int $teamId, int $userId): array
    {
        $db = App::db();
        $row = $db->fetch(
            "SELECT settings FROM team_user WHERE team_id = ? AND user_id = ? LIMIT 1",
            [$teamId, $userId]
        );

        $settings = [];
        if ($row && isset($row['settings']) && $row['settings']) {
            $decoded = json_decode((string)$row['settings'], true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $notifications = self::normalizeUserNotifications($settings['notifications'] ?? null);
        return $notifications;
    }

    /**
     * Save user notification settings to team_user.settings (merge-safe).
     */
    public static function saveUserNotifications(int $teamId, int $userId, array $notifications): void
    {
        $db = App::db();
        $row = $db->fetch(
            "SELECT settings FROM team_user WHERE team_id = ? AND user_id = ? LIMIT 1",
            [$teamId, $userId]
        );

        $settings = [];
        if ($row && isset($row['settings']) && $row['settings']) {
            $decoded = json_decode((string)$row['settings'], true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $settings['notifications'] = self::normalizeUserNotifications($notifications);
        $db->update('team_user', ['settings' => json_encode($settings)], 'team_id = ? AND user_id = ?', [$teamId, $userId]);
    }

    /**
     * Get normalized application notification settings.
     */
    public static function getApplicationNotifications(Application $application): array
    {
        $raw = $application->notification_settings ?? null;
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
        }
        if (!is_array($decoded)) {
            $decoded = [];
        }
        return self::normalizeApplicationNotifications($decoded);
    }

    /**
     * Save application notification settings.
     */
    public static function saveApplicationNotifications(Application $application, array $notifications): void
    {
        $normalized = self::normalizeApplicationNotifications($notifications);
        $application->update([
            'notification_settings' => json_encode($normalized),
        ]);
    }

    /**
     * Normalize user notification settings.
     */
    public static function normalizeUserNotifications(?array $notifications): array
    {
        $defaults = self::defaultUserNotifications();
        $notifications = is_array($notifications) ? $notifications : [];

        $deploy = $notifications['deployments'] ?? [];
        $general = $notifications['general'] ?? [];
        $channels = $notifications['channels'] ?? [];
        $webhook = $channels['webhook'] ?? [];

        $mode = (string)($deploy['mode'] ?? $defaults['deployments']['mode']);
        if (!in_array($mode, ['all', 'failed'], true)) {
            $mode = $defaults['deployments']['mode'];
        }

        $normalized = [
            'deployments' => [
                'enabled' => (bool)($deploy['enabled'] ?? $defaults['deployments']['enabled']),
                'mode' => $mode,
            ],
            'general' => [
                'enabled' => (bool)($general['enabled'] ?? $defaults['general']['enabled']),
            ],
            'channels' => [
                'email' => (bool)($channels['email'] ?? $defaults['channels']['email']),
                'webhook' => [
                    'enabled' => (bool)($webhook['enabled'] ?? $defaults['channels']['webhook']['enabled']),
                    'url' => trim((string)($webhook['url'] ?? $defaults['channels']['webhook']['url'])),
                ],
            ],
        ];

        return $normalized;
    }

    /**
     * Normalize application notification settings.
     */
    public static function normalizeApplicationNotifications(?array $notifications): array
    {
        $defaults = self::defaultApplicationNotifications();
        $notifications = is_array($notifications) ? $notifications : [];
        $deploy = $notifications['deployments'] ?? [];

        $mode = (string)($deploy['mode'] ?? $defaults['deployments']['mode']);
        if (!in_array($mode, ['all', 'failed'], true)) {
            $mode = $defaults['deployments']['mode'];
        }

        return [
            'deployments' => [
                'enabled' => (bool)($deploy['enabled'] ?? $defaults['deployments']['enabled']),
                'mode' => $mode,
            ],
        ];
    }

    /**
     * Notify team members about deployment completion/failure.
     */
    public static function notifyDeploymentStatus(Deployment $deployment, string $status): void
    {
        if (!in_array($status, ['running', 'failed'], true)) {
            return;
        }

        $application = $deployment->application();
        if (!$application) {
            return;
        }

        $environment = $application->environment();
        $project = $environment ? $environment->project() : null;
        $team = $project ? $project->team() : null;
        if (!$team) {
            return;
        }

        $appNotifications = self::getApplicationNotifications($application);
        if (!($appNotifications['deployments']['enabled'] ?? false)) {
            return;
        }

        $members = $team->members();
        $event = $status === 'failed' ? 'deployment.failed' : 'deployment.succeeded';

        $appUrl = rtrim((string)Config::get('app.url', ''), '/');
        $logsUrl = $appUrl !== '' ? $appUrl . '/applications/' . $application->uuid . '/logs' : null;

        foreach ($members as $member) {
            $userId = (int)($member->id ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $userNotifications = self::getUserNotifications((int)$team->id, $userId);
            if (!($userNotifications['deployments']['enabled'] ?? false)) {
                continue;
            }

            $mode = self::resolveDeploymentMode($userNotifications, $appNotifications);
            if ($status === 'running' && $mode !== 'all') {
                continue;
            }

            $title = $status === 'failed'
                ? 'Deployment failed'
                : 'Deployment succeeded';

            $details = [
                'Application' => $application->name,
                'Environment' => $environment?->name ?? '-',
                'Project' => $project?->name ?? '-',
                'Team' => $team->name,
                'Status' => ucfirst($status),
            ];

            if (!empty($deployment->commit_sha)) {
                $details['Commit'] = substr((string)$deployment->commit_sha, 0, 7);
            }
            if (!empty($deployment->commit_message)) {
                $details['Message'] = (string)$deployment->commit_message;
            }

            $intro = $status === 'failed'
                ? 'A deployment has failed.'
                : 'A deployment has completed successfully.';

            $email = self::buildEmail($title, $intro, $details, $logsUrl ? 'View logs' : null, $logsUrl);

            $payload = [
                'id' => uuid(),
                'event' => $event,
                'timestamp' => date('c'),
                'team' => [
                    'id' => $team->id,
                    'name' => $team->name,
                ],
                'project' => $project ? [
                    'id' => $project->id,
                    'name' => $project->name,
                ] : null,
                'environment' => $environment ? [
                    'id' => $environment->id,
                    'name' => $environment->name,
                ] : null,
                'application' => [
                    'id' => $application->id,
                    'uuid' => $application->uuid,
                    'name' => $application->name,
                ],
                'deployment' => [
                    'id' => $deployment->id,
                    'uuid' => $deployment->uuid ?? null,
                    'status' => $status,
                    'commit_sha' => $deployment->commit_sha ?? null,
                    'commit_message' => $deployment->commit_message ?? null,
                    'triggered_by' => $deployment->triggered_by ?? null,
                    'triggered_by_name' => $deployment->triggered_by_name ?? null,
                ],
                'url' => $logsUrl,
            ];

            self::deliverUserNotification($member, $userNotifications, $title, $email['html'], $email['text'], $event, $payload);
        }
    }

    /**
     * Notify a user when added to a team.
     */
    public static function sendTeamInvitationEmail(Team $team, string $inviteeEmail, ?User $actor, string $inviteUrl, string $baseRoleLabel = 'Member'): void
    {
        if (!Mailer::isConfigured()) {
            throw new \RuntimeException('Email is not configured');
        }

        $title = 'Team invitation';
        $intro = 'You have been invited to join a team on Chap.';
        $details = [
            'Team' => $team->name,
            'Role' => $baseRoleLabel,
        ];
        if ($actor) {
            $details['Invited by'] = $actor->displayName();
        }

        $email = self::buildEmail($title, $intro, $details, 'View invitation', $inviteUrl);

        $text = $email['text'] . "\n\nIf you don't want to join, you can ignore this email.";
        $html = $email['html'] . '<p style="margin-top:16px; color:#6b7280; font-size:12px;">If you don\'t want to join, you can ignore this email.</p>';

        Mailer::send($inviteeEmail, $title, $html, $text);
    }

    public static function notifyTeamMemberAdded(Team $team, User $member, ?User $actor = null): void
    {
        $settings = self::getUserNotifications((int)$team->id, (int)$member->id);
        if (!($settings['general']['enabled'] ?? false)) {
            return;
        }

        $title = 'Added to team';
        $intro = 'You were added to a team on Chap.';
        $details = [
            'Team' => $team->name,
        ];
        if ($actor) {
            $details['Added by'] = $actor->displayName();
        }

        $email = self::buildEmail($title, $intro, $details, null, null);
        $payload = [
            'id' => uuid(),
            'event' => 'team.member_added',
            'timestamp' => date('c'),
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'member' => [
                'id' => $member->id,
                'email' => $member->email,
                'name' => $member->displayName(),
            ],
            'actor' => $actor ? [
                'id' => $actor->id,
                'email' => $actor->email,
                'name' => $actor->displayName(),
            ] : null,
        ];

        self::deliverUserNotification($member, $settings, $title, $email['html'], $email['text'], 'team.member_added', $payload);
    }

    /**
     * Notify a user when removed from a team.
     */
    public static function notifyTeamMemberRemoved(Team $team, User $member, ?User $actor = null, ?array $settings = null): void
    {
        $settings = $settings ? self::normalizeUserNotifications($settings) : self::getUserNotifications((int)$team->id, (int)$member->id);
        if (!($settings['general']['enabled'] ?? false)) {
            return;
        }

        $title = 'Removed from team';
        $intro = 'You were removed from a team on Chap.';
        $details = [
            'Team' => $team->name,
        ];
        if ($actor) {
            $details['Removed by'] = $actor->displayName();
        }

        $email = self::buildEmail($title, $intro, $details, null, null);
        $payload = [
            'id' => uuid(),
            'event' => 'team.member_removed',
            'timestamp' => date('c'),
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'member' => [
                'id' => $member->id,
                'email' => $member->email,
                'name' => $member->displayName(),
            ],
            'actor' => $actor ? [
                'id' => $actor->id,
                'email' => $actor->email,
                'name' => $actor->displayName(),
            ] : null,
        ];

        self::deliverUserNotification($member, $settings, $title, $email['html'], $email['text'], 'team.member_removed', $payload);
    }

    /**
     * Notify a user when their resource limits change.
     */
    public static function notifyUserLimitsChanged(User $user, array $oldTotals, array $newTotals, ?User $actor = null): void
    {
        $personalTeam = $user->personalTeam();
        if (!$personalTeam) {
            return;
        }

        $settings = self::getUserNotifications((int)$personalTeam->id, (int)$user->id);
        if (!($settings['general']['enabled'] ?? false)) {
            return;
        }

        $title = 'Resource limits updated';
        $intro = 'Your account limits were updated.';

        $details = [
            'CPU (mC)' => self::fmtLimit($oldTotals['cpu_millicores'] ?? null) . ' → ' . self::fmtLimit($newTotals['cpu_millicores'] ?? null),
            'RAM (MB)' => self::fmtLimit($oldTotals['ram_mb'] ?? null) . ' → ' . self::fmtLimit($newTotals['ram_mb'] ?? null),
            'Storage (MB)' => self::fmtLimit($oldTotals['storage_mb'] ?? null) . ' → ' . self::fmtLimit($newTotals['storage_mb'] ?? null),
            'Ports' => self::fmtLimit($oldTotals['ports'] ?? null) . ' → ' . self::fmtLimit($newTotals['ports'] ?? null),
            'Bandwidth (Mbps)' => self::fmtLimit($oldTotals['bandwidth_mbps'] ?? null) . ' → ' . self::fmtLimit($newTotals['bandwidth_mbps'] ?? null),
            'PIDs' => self::fmtLimit($oldTotals['pids'] ?? null) . ' → ' . self::fmtLimit($newTotals['pids'] ?? null),
        ];
        if ($actor) {
            $details['Updated by'] = $actor->displayName();
        }

        $email = self::buildEmail($title, $intro, $details, null, null);
        $payload = [
            'id' => uuid(),
            'event' => 'user.resource_limits_changed',
            'timestamp' => date('c'),
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->displayName(),
            ],
            'actor' => $actor ? [
                'id' => $actor->id,
                'email' => $actor->email,
                'name' => $actor->displayName(),
            ] : null,
            'limits' => [
                'old' => $oldTotals,
                'new' => $newTotals,
            ],
        ];

        self::deliverUserNotification($user, $settings, $title, $email['html'], $email['text'], 'user.resource_limits_changed', $payload);
    }

    /**
     * Notify a team about a node being down.
     *
     * @param array<int, array<string, mixed>> $applications
     */
    public static function notifyNodeDown(Team $team, array $members, Node $node, array $applications, int $offlineSeconds): void
    {
        $event = 'node.down';
        $appUrl = rtrim((string)Config::get('app.url', ''), '/');
        $nodeUrl = $appUrl !== '' ? $appUrl . '/admin/nodes/' . $node->id : null;

        foreach ($members as $member) {
            $userId = (int)($member->id ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $settings = self::getUserNotifications((int)$team->id, $userId);
            if (!($settings['general']['enabled'] ?? false)) {
                continue;
            }

            $title = 'Node offline';
            $intro = 'A node hosting your applications has not checked in for over 5 minutes.';

            $details = [
                'Node' => $node->name,
                'Last seen' => $node->last_seen_at ? (string)$node->last_seen_at : 'Unknown',
                'Offline (seconds)' => (string)$offlineSeconds,
                'Team' => $team->name,
            ];

            $appNames = array_values(array_unique(array_map(fn($a) => (string)($a['name'] ?? ''), $applications)));
            if (!empty($appNames)) {
                $details['Applications'] = implode(', ', array_filter($appNames));
            }

            $email = self::buildEmail($title, $intro, $details, $nodeUrl ? 'View node' : null, $nodeUrl);

            $payload = [
                'id' => uuid(),
                'event' => $event,
                'timestamp' => date('c'),
                'team' => [
                    'id' => $team->id,
                    'name' => $team->name,
                ],
                'node' => [
                    'id' => $node->id,
                    'uuid' => $node->uuid ?? null,
                    'name' => $node->name,
                    'last_seen_at' => $node->last_seen_at,
                    'status' => $node->status,
                ],
                'offline_seconds' => $offlineSeconds,
                'applications' => $applications,
                'url' => $nodeUrl,
            ];

            self::deliverUserNotification($member, $settings, $title, $email['html'], $email['text'], $event, $payload);
        }
    }

    /**
     * Clear node down alert flag (so we can alert again after recovery).
     */
    public static function clearNodeDownAlert(Node $node): void
    {
        $settings = self::decodeSettings($node->settings);
        if (!isset($settings['notifications']['node_down'])) {
            return;
        }
        unset($settings['notifications']['node_down']);
        self::updateNodeSettings($node, $settings);
    }

    /**
     * Update node settings JSON.
     */
    public static function updateNodeSettings(Node $node, array $settings): void
    {
        $db = App::db();
        $db->update('nodes', ['settings' => json_encode($settings)], 'id = ?', [$node->id]);
        $node->settings = json_encode($settings);
    }

    /**
     * Deliver notification via user channels.
     */
    private static function deliverUserNotification(User $user, array $settings, string $subject, string $html, string $text, string $event, array $payload): void
    {
        $channels = $settings['channels'] ?? [];
        $emailEnabled = (bool)($channels['email'] ?? false);
        if ($emailEnabled && !empty($user->email)) {
            try {
                Mailer::send($user->email, $subject, $html, $text);
            } catch (\Throwable $e) {
                error_log('Notification email failed: ' . $e->getMessage());
            }
        }

        $webhook = $channels['webhook'] ?? [];
        $webhookEnabled = (bool)($webhook['enabled'] ?? false);
        $webhookUrl = trim((string)($webhook['url'] ?? ''));
        if ($webhookEnabled && $webhookUrl !== '' && self::isValidWebhookUrl($webhookUrl)) {
            self::sendWebhook($webhookUrl, $payload, $event);
        }
    }

    private static function resolveDeploymentMode(array $userNotifications, array $appNotifications): string
    {
        $userMode = (string)($userNotifications['deployments']['mode'] ?? 'all');
        $appMode = (string)($appNotifications['deployments']['mode'] ?? 'all');

        if ($userMode === 'failed' || $appMode === 'failed') {
            return 'failed';
        }
        return 'all';
    }

    private static function buildEmail(string $title, string $intro, array $details, ?string $ctaLabel, ?string $ctaUrl): array
    {
        $safeTitle = self::escape($title);
        $safeIntro = self::escape($intro);
        $appName = self::escape((string)Config::get('app.name', 'Chap'));

        $detailRows = '';
        $textRows = [];
        foreach ($details as $label => $value) {
            $safeLabel = self::escape((string)$label);
            $safeValue = self::escape((string)$value);
            $detailRows .= "<tr><td style=\"padding:6px 0;color:#64748b;font-size:13px;\">{$safeLabel}</td><td style=\"padding:6px 0;color:#0f172a;font-size:13px;font-weight:600;text-align:right;\">{$safeValue}</td></tr>";
            $textRows[] = $label . ': ' . $value;
        }

        $cta = '';
        $textCta = '';
        if ($ctaLabel && $ctaUrl) {
            $safeLabel = self::escape($ctaLabel);
            $safeUrl = self::escape($ctaUrl);
            $cta = "<a href=\"{$safeUrl}\" style=\"display:inline-block;margin-top:16px;background:#2563eb;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:600;font-size:14px;\">{$safeLabel}</a>";
            $textCta = "\n{$ctaLabel}: {$ctaUrl}";
        }

        $html = "<!doctype html><html><body style=\"margin:0;padding:24px;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;\">
            <div style=\"max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,0.08);overflow:hidden;border:1px solid #e2e8f0;\">
                <div style=\"background:#0f172a;color:#ffffff;padding:16px 24px;\">
                    <div style=\"font-size:16px;font-weight:700;\">{$appName}</div>
                </div>
                <div style=\"padding:24px;\">
                    <h1 style=\"margin:0 0 8px;font-size:20px;color:#0f172a;\">{$safeTitle}</h1>
                    <p style=\"margin:0 0 16px;color:#475569;font-size:14px;line-height:1.5;\">{$safeIntro}</p>
                    <table style=\"width:100%;border-collapse:collapse;\">{$detailRows}</table>
                    {$cta}
                </div>
                <div style=\"background:#f1f5f9;color:#94a3b8;padding:12px 24px;font-size:12px;text-align:center;\">
                    Sent by {$appName}
                </div>
            </div>
        </body></html>";

        $text = $title . "\n" . $intro . "\n\n" . implode("\n", $textRows) . $textCta;

        return ['html' => $html, 'text' => $text];
    }

    private static function sendWebhook(string $url, array $payload, string $event): void
    {
        if (!function_exists('curl_init')) {
            return;
        }

        $body = json_encode($payload);
        if ($body === false) {
            return;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: Chap-Webhook/1.0',
            'X-Chap-Event: ' . $event,
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            error_log('Webhook delivery failed: ' . $err);
        }
        curl_close($ch);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function fmtLimit(?int $value): string
    {
        if ($value === null) {
            return '-';
        }
        return $value === -1 ? 'Unlimited' : (string)$value;
    }

    private static function decodeSettings(?string $raw): array
    {
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function isValidWebhookUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return in_array($scheme, ['http', 'https'], true);
    }
}

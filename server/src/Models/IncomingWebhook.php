<?php

namespace Chap\Models;

use Chap\App;

/**
 * IncomingWebhook Model
 *
 * Represents an incoming webhook endpoint used to trigger deployments.
 */
class IncomingWebhook extends BaseModel
{
    protected static string $table = 'incoming_webhooks';

    protected static array $fillable = [
        'application_id',
        'provider',
        'name',
        'secret',
        'branch',
        'is_active',
        'last_received_at',
        'last_event',
        'last_delivery_id',
        'last_status',
        'last_error',
    ];

    protected static array $hidden = ['secret'];

    public int $application_id;
    public string $provider = 'github';
    public string $name = '';
    public string $secret = '';
    public ?string $branch = null;
    public bool $is_active = true;

    public ?string $last_received_at = null;
    public ?string $last_event = null;
    public ?string $last_delivery_id = null;
    public ?string $last_status = null;
    public ?string $last_error = null;

    public function application(): ?Application
    {
        return Application::find($this->application_id);
    }

    /**
     * Get incoming webhooks for an application
     */
    public static function forApplication(int $applicationId): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM incoming_webhooks WHERE application_id = ? ORDER BY created_at DESC",
            [$applicationId]
        );

        return array_map(fn($data) => self::fromArray($data), $results);
    }

    public function effectiveBranch(?Application $application = null): ?string
    {
        $branch = $this->branch;
        if (!empty($branch)) {
            return $branch;
        }
        $application = $application ?? $this->application();
        return $application?->git_branch;
    }
}

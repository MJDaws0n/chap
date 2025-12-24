<?php

namespace Chap\Models;

use Chap\App;

/**
 * IncomingWebhookDelivery Model
 */
class IncomingWebhookDelivery extends BaseModel
{
    protected static string $table = 'incoming_webhook_deliveries';

    protected static array $fillable = [
        'incoming_webhook_id',
        'provider',
        'delivery_id',
        'event',
        'ref',
        'commit_sha',
        'received_at',
    ];

    public int $incoming_webhook_id;
    public string $provider = 'github';
    public string $delivery_id = '';
    public ?string $event = null;
    public ?string $ref = null;
    public ?string $commit_sha = null;
    public ?string $received_at = null;

    /**
     * Attempt to record a delivery ID for dedupe.
     * Returns true if inserted, false if already exists.
     */
    public static function tryInsert(
        int $incomingWebhookId,
        string $provider,
        string $deliveryId,
        ?string $event = null,
        ?string $ref = null,
        ?string $commitSha = null
    ): bool {
        $db = App::db();

        try {
            $db->query(
                "INSERT INTO incoming_webhook_deliveries (incoming_webhook_id, provider, delivery_id, event, ref, commit_sha, received_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [$incomingWebhookId, $provider, $deliveryId, $event, $ref, $commitSha]
            );
            return true;
        } catch (\PDOException $e) {
            // Duplicate delivery (unique constraint)
            if (($e->getCode() ?? '') === '23000') {
                return false;
            }
            throw $e;
        }
    }
}

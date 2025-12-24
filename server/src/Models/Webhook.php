<?php

namespace Chap\Models;

use Chap\App;

/**
 * Webhook Model
 * 
 * Represents an outgoing webhook configuration
 */
class Webhook extends BaseModel
{
    protected static string $table = 'webhooks';
    protected static array $fillable = [
        'team_id', 'application_id', 'name', 'url', 'secret',
        'events', 'is_active', 'last_triggered_at', 'last_response_code'
    ];
    protected static array $hidden = ['secret'];

    public int $team_id;
    public ?int $application_id = null;
    public string $name = '';
    public string $url = '';
    public ?string $secret = null;
    public string $events = '[]';
    public bool $is_active = true;
    public ?string $last_triggered_at = null;
    public ?int $last_response_code = null;

    /**
     * Get team
     */
    public function team(): ?Team
    {
        return Team::find($this->team_id);
    }

    /**
     * Get application
     */
    public function application(): ?Application
    {
        return $this->application_id ? Application::find($this->application_id) : null;
    }

    /**
     * Get events as array
     */
    public function getEvents(): array
    {
        return json_decode($this->events, true) ?: [];
    }

    /**
     * Set events
     */
    public function setEvents(array $events): void
    {
        $this->events = json_encode($events);
    }

    /**
     * Check if webhook handles event
     */
    public function handlesEvent(string $event): bool
    {
        return in_array($event, $this->getEvents());
    }

    /**
     * Get webhooks for team
     */
    public static function forTeam(int $teamId): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM webhooks WHERE team_id = ? ORDER BY name",
            [$teamId]
        );
        
        return array_map(fn($data) => self::fromArray($data), $results);
    }

    /**
     * Get active webhooks for application
     */
    public static function activeForApplication(int $applicationId): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM webhooks WHERE application_id = ? AND is_active = 1",
            [$applicationId]
        );
        
        return array_map(fn($data) => self::fromArray($data), $results);
    }

    /**
     * Trigger the webhook
     */
    public function trigger(array $payload): bool
    {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: Chap/1.0',
        ];

        if ($this->secret) {
            $signature = hash_hmac('sha256', json_encode($payload), $this->secret);
            $headers[] = 'X-Chap-Signature: sha256=' . $signature;
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Update last triggered info
        $db = App::db();
        $db->update('webhooks', [
            'last_triggered_at' => date('Y-m-d H:i:s'),
            'last_response_code' => $httpCode,
        ], 'id = ?', [$this->id]);

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Available webhook events
     */
    public static function availableEvents(): array
    {
        return [
            'deployment.started' => 'Deployment Started',
            'deployment.succeeded' => 'Deployment Succeeded',
            'deployment.failed' => 'Deployment Failed',
            'application.started' => 'Application Started',
            'application.stopped' => 'Application Stopped',
            'application.error' => 'Application Error',
        ];
    }
}

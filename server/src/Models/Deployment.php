<?php

namespace Chap\Models;

use Chap\App;
use Chap\Services\NotificationService;

/**
 * Deployment Model
 */
class Deployment extends BaseModel
{
    protected static string $table = 'deployments';
    protected static array $fillable = [
        'application_id', 'node_id', 'user_id',
        'type', 'status',
        // DB column names (preferred)
        'git_commit_sha', 'git_commit_message', 'git_branch',
        'container_id', 'image_tag',
        'logs', 'started_at', 'finished_at', 'error_message',
        'rollback_to_deployment_id',
        'triggered_by', 'triggered_by_name',
        // Back-compat aliases (normalized in create/update)
        'commit_sha', 'commit_message', 'rollback_of_id',
    ];

    public int $application_id;
    public ?int $node_id = null;
    public ?int $user_id = null;
    public ?string $type = null;

    // DB-backed git snapshot fields
    public ?string $git_commit_sha = null;
    public ?string $git_commit_message = null;
    public ?string $git_branch = null;

    // Back-compat aliases used by older code paths
    public ?string $commit_sha = null;
    public ?string $commit_message = null;
    public string $status = 'queued';
    public ?string $started_at = null;
    public ?string $finished_at = null;
    public ?string $logs = null;
    public ?string $error_message = null;
    public ?int $rollback_to_deployment_id = null;

    // Back-compat alias
    public ?int $rollback_of_id = null;
    public ?string $triggered_by = null;
    public ?string $triggered_by_name = null;

    /**
     * Normalize legacy field names to the current DB schema.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalizeFields(array $data): array
    {
        if (array_key_exists('commit_sha', $data) && !array_key_exists('git_commit_sha', $data)) {
            $data['git_commit_sha'] = $data['commit_sha'];
            unset($data['commit_sha']);
        }
        if (array_key_exists('commit_message', $data) && !array_key_exists('git_commit_message', $data)) {
            $data['git_commit_message'] = $data['commit_message'];
            unset($data['commit_message']);
        }
        if (array_key_exists('rollback_of_id', $data) && !array_key_exists('rollback_to_deployment_id', $data)) {
            $data['rollback_to_deployment_id'] = $data['rollback_of_id'];
            unset($data['rollback_of_id']);
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): static
    {
        return parent::create(self::normalizeFields($data));
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(array $data): bool
    {
        return parent::update(self::normalizeFields($data));
    }

    /**
     * Ensure older properties remain populated when hydrating from DB.
     */
    public static function fromArray(array $data): static
    {
        /** @var static $model */
        $model = parent::fromArray($data);

        if ($model->commit_sha === null && $model->git_commit_sha !== null) {
            $model->commit_sha = $model->git_commit_sha;
        }
        if ($model->commit_message === null && $model->git_commit_message !== null) {
            $model->commit_message = $model->git_commit_message;
        }
        if ($model->rollback_of_id === null && $model->rollback_to_deployment_id !== null) {
            $model->rollback_of_id = $model->rollback_to_deployment_id;
        }

        return $model;
    }

    /**
     * Get application
     */
    public function application(): ?Application
    {
        return Application::find($this->application_id);
    }

    /**
     * Get node
     */
    public function node(): ?Node
    {
        return Node::find($this->node_id);
    }

    /**
     * Get rollback source deployment
     */
    public function rollbackOf(): ?self
    {
        return $this->rollback_of_id ? self::find($this->rollback_of_id) : null;
    }

    /**
     * Append log message
     */
    public function appendLog(string $message, string $type = 'info'): void
    {
        $db = App::db();
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$type}] {$message}\n";
        
        $db->query(
            "UPDATE deployments SET logs = CONCAT(COALESCE(logs, ''), ?) WHERE id = ?",
            [$logEntry, $this->id]
        );
        
        $this->logs = ($this->logs ?? '') . $logEntry;
    }

    /**
     * Update status
     */
    public function updateStatus(string $status, ?string $errorMessage = null): void
    {
        $db = App::db();

        $previousStatus = $this->status;
        
        $data = ['status' => $status];
        
        if ($status === 'building' || $status === 'deploying') {
            $data['started_at'] = date('Y-m-d H:i:s');
        }
        
        if (in_array($status, ['running', 'failed', 'cancelled', 'rolled_back'])) {
            $data['finished_at'] = date('Y-m-d H:i:s');
        }
        
        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }

        try {
            $db->update('deployments', $data, 'id = ?', [$this->id]);
        } catch (\PDOException $e) {
            // Backwards-compatibility: some schemas may not have an error_message column yet.
            if (array_key_exists('error_message', $data)) {
                unset($data['error_message']);
                $db->update('deployments', $data, 'id = ?', [$this->id]);
            } else {
                throw $e;
            }
        }
        
        $this->status = $status;
        if (isset($data['started_at'])) $this->started_at = $data['started_at'];
        if (isset($data['finished_at'])) $this->finished_at = $data['finished_at'];
        if ($errorMessage) $this->error_message = $errorMessage;

        if ($previousStatus !== $status && in_array($status, ['running', 'failed'], true)) {
            NotificationService::notifyDeploymentStatus($this, $status);
        }

        // Update application status
        if ($status === 'running') {
            $db->update('applications', ['status' => 'running'], 'id = ?', [$this->application_id]);
        } elseif ($status === 'failed') {
            $db->update('applications', ['status' => 'error'], 'id = ?', [$this->application_id]);
        }
    }

    /**
     * Mark as building
     */
    public function markBuilding(): void
    {
        $this->updateStatus('building');
        $this->appendLog('Build started', 'info');
    }

    /**
     * Mark as deploying
     */
    public function markDeploying(): void
    {
        $this->updateStatus('deploying');
        $this->appendLog('Deployment started', 'info');
    }

    /**
     * Mark as running
     */
    public function markRunning(): void
    {
        $this->updateStatus('running');
        $this->appendLog('Deployment completed successfully', 'success');
    }

    /**
     * Mark as failed
     */
    public function markFailed(string $error): void
    {
        $this->updateStatus('failed', $error);
        $this->appendLog('Deployment failed: ' . $error, 'error');
    }

    /**
     * Mark as cancelled
     */
    public function markCancelled(): void
    {
        $this->updateStatus('cancelled');
        $this->appendLog('Deployment cancelled', 'warning');
    }

    /**
     * Get duration in seconds
     */
    public function duration(): ?int
    {
        if (!$this->started_at) {
            return null;
        }
        
        $end = $this->finished_at ? strtotime($this->finished_at) : time();
        return $end - strtotime($this->started_at);
    }

    /**
     * Get formatted duration
     */
    public function formattedDuration(): string
    {
        $seconds = $this->duration();
        
        if ($seconds === null) {
            return '-';
        }
        
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;
        
        return "{$minutes}m {$seconds}s";
    }

    /**
     * Get logs as array
     */
    public function logsArray(): array
    {
        if (!$this->logs) {
            return [];
        }
        
        $lines = array_filter(explode("\n", trim($this->logs)));
        $structured = [];
        
        foreach ($lines as $line) {
            // Parse log format: [2025-12-11 11:36:57] [info] message
            if (preg_match('/^\[([^\]]+)\]\s*\[([^\]]+)\]\s*(.*)$/', $line, $matches)) {
                $structured[] = [
                    'timestamp' => $matches[1],
                    'level' => $matches[2],
                    'message' => $matches[3]
                ];
            } else {
                // Fallback for lines without proper format
                $structured[] = [
                    'timestamp' => '',
                    'level' => 'info',
                    'message' => $line
                ];
            }
        }
        
        return $structured;
    }

    /**
     * Get deployments for application
     */
    public static function forApplication(int $applicationId, int $limit = 10): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM deployments WHERE application_id = ? ORDER BY created_at DESC LIMIT ?",
            [$applicationId, $limit]
        );
        
        return array_map(fn($data) => self::fromArray($data), $results);
    }

    /**
     * Get status badge color
     */
    public function statusColor(): string
    {
        return match($this->status) {
            'running' => 'green',
            'building', 'deploying', 'queued' => 'yellow',
            'failed' => 'red',
            'cancelled', 'rolled_back' => 'gray',
            default => 'gray'
        };
    }

    /**
     * Check if deployment is in progress
     */
    public function isInProgress(): bool
    {
        return in_array($this->status, ['queued', 'building', 'deploying']);
    }

    /**
     * Check if deployment can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return $this->isInProgress();
    }
}

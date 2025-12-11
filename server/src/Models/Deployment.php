<?php

namespace Chap\Models;

use Chap\App;

/**
 * Deployment Model
 */
class Deployment extends BaseModel
{
    protected static string $table = 'deployments';
    protected static array $fillable = [
        'application_id', 'node_id', 'commit_sha', 'commit_message',
        'status', 'started_at', 'finished_at', 'logs', 'error_message',
        'rollback_of_id'
    ];

    public int $application_id;
    public ?int $node_id = null;
    public ?string $commit_sha = null;
    public ?string $commit_message = null;
    public string $status = 'queued';
    public ?string $started_at = null;
    public ?string $finished_at = null;
    public ?string $logs = null;
    public ?string $error_message = null;
    public ?int $rollback_of_id = null;

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
        
        $db->update('deployments', $data, 'id = ?', [$this->id]);
        
        $this->status = $status;
        if (isset($data['started_at'])) $this->started_at = $data['started_at'];
        if (isset($data['finished_at'])) $this->finished_at = $data['finished_at'];
        if ($errorMessage) $this->error_message = $errorMessage;

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

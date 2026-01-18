<?php

namespace Chap\Models;

/**
 * ChapScript run state for interactive template scripts.
 */
class ChapScriptRun extends BaseModel
{
    protected static string $table = 'chap_script_runs';
    protected static array $fillable = [
        'application_id',
        'template_slug',
        'status',
        'state_json',
        'prompt_json',
        'context_json',
        'user_id',
    ];

    public ?int $application_id = null;
    public ?string $template_slug = null;
    public string $status = 'running'; // running|waiting|completed|stopped|error
    public ?string $state_json = null;
    public ?string $prompt_json = null;
    public ?string $context_json = null;
    public ?int $user_id = null;

    /** @return array<string,mixed> */
    public function state(): array
    {
        if (!$this->state_json) return [];
        $decoded = json_decode($this->state_json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed>|null */
    public function prompt(): ?array
    {
        if (!$this->prompt_json) return null;
        $decoded = json_decode($this->prompt_json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        if (!$this->context_json) return [];
        $decoded = json_decode($this->context_json, true);
        return is_array($decoded) ? $decoded : [];
    }
}

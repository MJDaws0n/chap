<?php

namespace Chap\Services;

final class TemplatePackage
{
    public string $directory;
    /** @var array<string,mixed> */
    public array $config;
    public string $dockerCompose;
    /** @var array<string,string> */
    public array $extraFiles;
    public bool $defaultIsOfficial;

    /**
     * @param array<string,mixed> $config
     * @param array<string,string> $extraFiles
     */
    public function __construct(string $directory, array $config, string $dockerCompose, array $extraFiles = [], bool $defaultIsOfficial = false)
    {
        $this->directory = $directory;
        $this->config = $config;
        $this->dockerCompose = $dockerCompose;
        $this->extraFiles = $extraFiles;
        $this->defaultIsOfficial = $defaultIsOfficial;
    }

    public function slug(): string
    {
        $slug = trim((string)($this->config['slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        return basename($this->directory);
    }

    public function name(): string
    {
        $name = trim((string)($this->config['name'] ?? ''));
        return $name !== '' ? $name : $this->slug();
    }

    /**
     * @return array<string,mixed>
     */
    public function toTemplateAttributes(): array
    {
        $isOfficial = array_key_exists('is_official', $this->config)
            ? (bool)$this->config['is_official']
            : $this->defaultIsOfficial;

        $isActive = array_key_exists('is_active', $this->config)
            ? (bool)$this->config['is_active']
            : true;

        return [
            'name' => $this->name(),
            'slug' => $this->slug(),
            'description' => $this->nullableString($this->config['description'] ?? null),
            'category' => $this->nullableString($this->config['category'] ?? null),
            'icon' => $this->nullableString($this->config['icon'] ?? null),
            'docker_compose' => $this->dockerCompose,
            // Treat documentation as a URL (config key: documentation_url).
            // Back-compat: fall back to `documentation` if provided.
            'documentation' => $this->nullableString($this->config['documentation_url'] ?? ($this->config['documentation'] ?? null)),
            // Optional template source/repository URL.
            // Back-compat: accept a couple of common aliases.
            'source_url' => $this->nullableString($this->config['source_url'] ?? ($this->config['repo_url'] ?? ($this->config['repository_url'] ?? null))),
            'default_environment_variables' => $this->encodeJson($this->config['default_environment_variables'] ?? null),
            'required_environment_variables' => $this->encodeJson($this->config['required_environment_variables'] ?? null),
            'ports' => $this->encodeJson($this->config['ports'] ?? null),
            'volumes' => $this->encodeJson($this->config['volumes'] ?? null),
            'version' => $this->nullableString($this->config['version'] ?? null),
            'is_official' => $isOfficial ? 1 : 0,
            'is_active' => $isActive ? 1 : 0,
            'extra_files' => !empty($this->extraFiles) ? json_encode($this->extraFiles) : null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $s = trim((string)($value ?? ''));
        return $s === '' ? null : $s;
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $trim = trim($value);
            return $trim === '' ? null : $trim;
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return null;
    }
}

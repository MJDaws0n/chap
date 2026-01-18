<?php

namespace Chap\Services\ChapScript;

use Chap\Services\TemplateRegistry;

final class ChapScriptTemplateLoader
{
    /**
     * Load a template script for a given template slug.
     *
     * @return array{script:array<string,mixed>, source:string}|null
     */
    public static function loadForTemplateSlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $pkg = TemplateRegistry::loadPackageBySlug($slug);
        if (!$pkg) {
            return null;
        }

        $rel = (string)($pkg->config['chap_script'] ?? ($pkg->config['script'] ?? ''));
        $rel = trim($rel);
        if ($rel === '') {
            return null;
        }

        $rel = str_replace('\\', '/', $rel);
        $rel = ltrim($rel, '/');
        if ($rel === '' || str_contains($rel, '..')) {
            throw new \RuntimeException('Invalid chap_script path');
        }

        $base = rtrim($pkg->directory, '/');
        $abs = $base . '/' . $rel;
        $realAbs = realpath($abs);
        $realBase = realpath($base);

        if ($realAbs === false || $realBase === false) {
            throw new \RuntimeException('ChapScript file not found');
        }

        $realAbs = str_replace('\\', '/', $realAbs);
        $realBase = rtrim(str_replace('\\', '/', $realBase), '/');

        if (!str_starts_with($realAbs, $realBase . '/')) {
            throw new \RuntimeException('ChapScript path escapes template directory');
        }

        $size = @filesize($realAbs);
        if (!is_int($size) || $size <= 0 || $size > 128 * 1024) {
            throw new \RuntimeException('ChapScript file too large');
        }

        $raw = file_get_contents($realAbs);
        if ($raw === false) {
            throw new \RuntimeException('Failed to read ChapScript');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid ChapScript JSON');
        }

        ChapScriptRunner::validateScript($decoded);
        return ['script' => $decoded, 'source' => $realAbs];
    }
}

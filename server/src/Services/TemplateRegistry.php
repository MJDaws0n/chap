<?php

namespace Chap\Services;

use Chap\App;
use Chap\Models\Template;

final class TemplateRegistry
{
    /**
     * Scan known template directories and sync to the database.
     *
     * @return array{scanned:int, upserted:int, errors: array<int,string>}
     */
    public static function syncToDatabase(): array
    {
        $roots = [
            ['path' => '/var/www/html/templates', 'is_official' => true],
            ['path' => '/var/www/html/storage/templates', 'is_official' => false],
        ];

        $scanned = 0;
        $upserted = 0;
        $deactivated = 0;
        $errors = [];

        /** @var array<string,bool> */
        $seenSlugs = [];

        foreach ($roots as $root) {
            $base = (string)$root['path'];
            $defaultIsOfficial = (bool)$root['is_official'];

            if (!is_dir($base)) {
                continue;
            }

            $entries = @scandir($base);
            if (!$entries) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $dir = rtrim($base, '/') . '/' . $entry;
                if (!is_dir($dir)) continue;

                try {
                    $pkg = self::loadPackageFromDirectory($dir, $defaultIsOfficial);
                    if (!$pkg) {
                        continue;
                    }

                    $scanned++;
                    $attrs = $pkg->toTemplateAttributes();

                    $slug = (string)($attrs['slug'] ?? '');
                    if ($slug !== '') {
                        $seenSlugs[$slug] = true;
                    }

                    $existing = Template::findBySlug((string)$attrs['slug']);
                    if ($existing) {
                        $existing->update($attrs);
                    } else {
                        Template::create($attrs);
                    }
                    $upserted++;
                } catch (\Throwable $e) {
                    $errors[] = basename($dir) . ': ' . $e->getMessage();
                    continue;
                }
            }
        }

        // Deactivate templates that no longer exist on disk.
        // This removes previously-seeded "built-in" templates that aren't backed by a template directory.
        try {
            $db = App::db();
            $rows = $db->fetchAll("SELECT id, slug FROM templates WHERE is_active = 1");
            foreach ($rows as $row) {
                $slug = (string)($row['slug'] ?? '');
                if ($slug === '' || isset($seenSlugs[$slug])) {
                    continue;
                }
                $db->update('templates', ['is_active' => 0], 'id = ?', [(int)$row['id']]);
                $deactivated++;
            }
        } catch (\Throwable $e) {
            $errors[] = 'deactivation: ' . $e->getMessage();
        }

        return ['scanned' => $scanned, 'upserted' => $upserted, 'deactivated' => $deactivated, 'errors' => $errors];
    }

    private static function loadPackageFromDirectory(string $dir, bool $defaultIsOfficial): ?TemplatePackage
    {
        $configPath = rtrim($dir, '/') . '/config.json';
        $composePath = rtrim($dir, '/') . '/docker-compose.yml';

        if (!is_file($configPath) || !is_file($composePath)) {
            return null;
        }

        $configRaw = file_get_contents($configPath);
        $composeRaw = file_get_contents($composePath);

        if ($configRaw === false || $composeRaw === false) {
            throw new \RuntimeException('Failed to read config or docker-compose');
        }

        $config = json_decode($configRaw, true);
        if (!is_array($config)) {
            throw new \RuntimeException('Invalid config.json');
        }

        $extraFiles = self::readExtraFiles($dir);
        return new TemplatePackage($dir, $config, (string)$composeRaw, $extraFiles, $defaultIsOfficial);
    }

    /**
     * Reads optional files/ directory and returns a map of relative path => file contents.
     *
     * The returned paths are relative to the compose directory root.
     *
     * @return array<string,string>
     */
    public static function readExtraFiles(string $packageDir): array
    {
        $filesDir = rtrim($packageDir, '/') . '/files';
        if (!is_dir($filesDir)) {
            return [];
        }

        $out = [];
        $maxFiles = 200;
        $maxBytesPerFile = 1024 * 1024; // 1MB
        $maxBytesTotal = 10 * 1024 * 1024; // 10MB
        $total = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($filesDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo) continue;
            if (!$file->isFile()) continue;

            if (count($out) >= $maxFiles) {
                break;
            }

            $size = (int)$file->getSize();
            if ($size <= 0 || $size > $maxBytesPerFile) {
                continue;
            }
            if ($total + $size > $maxBytesTotal) {
                break;
            }

            $abs = $file->getPathname();
            $rel = substr($abs, strlen(rtrim($filesDir, '/')) + 1);
            $rel = str_replace('\\', '/', $rel);

            // Normalize (strip leading slashes)
            $rel = ltrim($rel, '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }

            $content = file_get_contents($abs);
            if ($content === false) {
                continue;
            }

            $out[$rel] = (string)$content;
            $total += $size;
        }

        return $out;
    }
}

<?php

namespace Chap\Services;

final class TemplateZipImporter
{
    /**
     * Import a template zip upload into /var/www/html/storage/templates/{slug}
     *
     * @param array<string,mixed> $file The $_FILES['...'] entry
     */
    public static function importUploadedZip(array $file): TemplatePackage
    {
        if (empty($file['tmp_name']) || empty($file['name'])) {
            throw new \RuntimeException('No upload provided');
        }
        if (!empty($file['error'])) {
            throw new \RuntimeException('Upload failed (error ' . (int)$file['error'] . ')');
        }

        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension is not available');
        }

        $zipPath = (string)$file['tmp_name'];
        $zip = new \ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            throw new \RuntimeException('Failed to open zip');
        }

        $tmpBase = sys_get_temp_dir() . '/chap_template_' . bin2hex(random_bytes(8));
        if (!@mkdir($tmpBase, 0755, true) && !is_dir($tmpBase)) {
            $zip->close();
            throw new \RuntimeException('Failed to create temp directory');
        }

        try {
            self::safeExtract($zip, $tmpBase);
        } finally {
            $zip->close();
        }

        try {
            $packageDir = self::resolvePackageRoot($tmpBase);
            $pkg = self::loadPackage($packageDir);
            $slug = $pkg->slug();
            if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
                throw new \RuntimeException('Invalid template slug');
            }

            $destRoot = '/var/www/html/storage/templates';
            $destDir = rtrim($destRoot, '/') . '/' . $slug;

            if (!is_dir($destRoot)) {
                @mkdir($destRoot, 0755, true);
            }

            // Replace existing
            if (is_dir($destDir)) {
                self::rimraf($destDir);
            }
            self::copyDir($packageDir, $destDir);

            // Reload from destination so returned object reflects what will be scanned.
            return self::loadPackage($destDir);
        } finally {
            self::rimraf($tmpBase);
        }
    }

    private static function safeExtract(\ZipArchive $zip, string $dest): void
    {
        $maxFiles = 500;
        $maxTotalBytes = 20 * 1024 * 1024; // 20MB
        $total = 0;
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) continue;

            $name = (string)($stat['name'] ?? '');
            if ($name === '' || str_contains($name, "\0")) continue;
            if (str_starts_with($name, '/') || str_contains($name, '../') || str_contains($name, '..\\')) {
                continue;
            }

            $count++;
            if ($count > $maxFiles) {
                break;
            }

            $size = (int)($stat['size'] ?? 0);
            if ($size < 0) $size = 0;
            $total += $size;
            if ($total > $maxTotalBytes) {
                throw new \RuntimeException('Zip is too large');
            }

            $target = rtrim($dest, '/') . '/' . $name;

            if (str_ends_with($name, '/')) {
                @mkdir($target, 0755, true);
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                continue;
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
        }
    }

    private static function resolvePackageRoot(string $tmpBase): string
    {
        if (is_file($tmpBase . '/config.json') && is_file($tmpBase . '/docker-compose.yml')) {
            return $tmpBase;
        }

        $entries = @scandir($tmpBase) ?: [];
        $dirs = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $tmpBase . '/' . $e;
            if (is_dir($p)) {
                $dirs[] = $p;
            }
        }

        if (count($dirs) === 1) {
            $candidate = $dirs[0];
            if (is_file($candidate . '/config.json') && is_file($candidate . '/docker-compose.yml')) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Zip must contain config.json and docker-compose.yml at the root (or in a single top-level folder)');
    }

    private static function loadPackage(string $dir): TemplatePackage
    {
        $configPath = rtrim($dir, '/') . '/config.json';
        $composePath = rtrim($dir, '/') . '/docker-compose.yml';

        $configRaw = file_get_contents($configPath);
        $composeRaw = file_get_contents($composePath);
        if ($configRaw === false || $composeRaw === false) {
            throw new \RuntimeException('Missing config.json or docker-compose.yml');
        }

        $config = json_decode($configRaw, true);
        if (!is_array($config)) {
            throw new \RuntimeException('Invalid config.json');
        }

        $extra = TemplateRegistry::readExtraFiles($dir);
        return new TemplatePackage($dir, $config, (string)$composeRaw, $extra, false);
    }

    private static function rimraf(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    private static function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $rel = substr($file->getPathname(), strlen(rtrim($src, '/')) + 1);
            $target = rtrim($dst, '/') . '/' . $rel;
            if ($file->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @copy($file->getPathname(), $target);
            }
        }
    }
}

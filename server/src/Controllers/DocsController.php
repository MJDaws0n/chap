<?php

namespace Chap\Controllers;

class DocsController extends BaseController
{
    public function index(): void
    {
        $this->serve('index.html');
    }

    public function file(string $file): void
    {
        $this->serve($file);
    }

    private function serve(string $file): void
    {
        $allowed = [
            'index.html' => 'text/html; charset=utf-8',
            'client-api.html' => 'text/html; charset=utf-8',
            'admin-api.html' => 'text/html; charset=utf-8',
            'styles.css' => 'text/css; charset=utf-8',
        ];

        if (!isset($allowed[$file])) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return;
        }

        $docsRoot = realpath(__DIR__ . '/../../../docs');
        if (!$docsRoot) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Docs not available';
            return;
        }

        $path = $docsRoot . '/' . $file;
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return;
        }

        header('Content-Type: ' . $allowed[$file]);
        header('Cache-Control: no-store');
        readfile($path);
    }
}

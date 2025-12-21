<?php

namespace Chap\View;

/**
 * Simple View Renderer
 */
class View
{
    private static string $viewPath = __DIR__ . '/../Views';
    private static string $layoutPath = __DIR__ . '/../Views/layouts';
    private static ?string $layout = 'app';
    private static array $sections = [];
    private static ?string $currentSection = null;

    /**
     * Render a view with optional layout override
     */
    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $viewFile = self::$viewPath . '/' . str_replace('.', '/', $template) . '.php';

        if (!file_exists($viewFile)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        if (!array_key_exists('currentPage', $data)) {
            $data['currentPage'] = self::inferCurrentPage($_SERVER['REQUEST_URI'] ?? '');
        }

        // Set layout if provided
        if ($layout !== null) {
            self::$layout = $layout;
        }

        // Extract data to local variables
        extract($data);

        // Start output buffering
        ob_start();
        
        // Include the view
        include $viewFile;
        
        $content = ob_get_clean();

        // If a layout is set, wrap content in layout
        if (self::$layout !== null) {
            $layoutFile = self::$layoutPath . '/' . self::$layout . '.php';
            
            if (file_exists($layoutFile)) {
                self::$sections['content'] = $content;
                
                // Make data available to layout
                $content = $data['content'] ?? $content;
                
                ob_start();
                include $layoutFile;
                $content = ob_get_clean();
            }
        }

        // Reset layout for next render
        self::$layout = 'app';
        self::$sections = [];

        return $content;
    }

    private static function inferCurrentPage(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        $path = trim($path, '/');

        if ($path === '') {
            return 'dashboard';
        }

        $segments = explode('/', $path);
        $first = $segments[0] ?? '';

        // Normalize known nested routes back to their sidebar section
        return match ($first) {
            'projects' => 'projects',
            'nodes' => 'nodes',
            'templates' => 'templates',
            'teams' => 'teams',
            'git-sources' => 'git-sources',
            'activity' => 'activity',
            'settings' => 'settings',
            'dashboard' => 'dashboard',
            default => $first,
        };
    }

    /**
     * Set layout
     */
    public static function layout(?string $layout): void
    {
        self::$layout = $layout;
    }

    /**
     * Start a section
     */
    public static function section(string $name): void
    {
        self::$currentSection = $name;
        ob_start();
    }

    /**
     * End current section
     */
    public static function endSection(): void
    {
        if (self::$currentSection !== null) {
            self::$sections[self::$currentSection] = ob_get_clean();
            self::$currentSection = null;
        }
    }

    /**
     * Get a section's content
     */
    public static function getSection(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }

    /**
     * Yield content section
     */
    public static function yield(string $name, string $default = ''): void
    {
        echo self::getSection($name, $default);
    }

    /**
     * Include a partial view
     */
    public static function partial(string $template, array $data = []): void
    {
        $viewFile = self::$viewPath . '/partials/' . str_replace('.', '/', $template) . '.php';

        if (!file_exists($viewFile)) {
            throw new \RuntimeException("Partial not found: {$template}");
        }

        extract($data);
        include $viewFile;
    }

    /**
     * Render without layout
     */
    public static function renderPartial(string $template, array $data = []): string
    {
        $previousLayout = self::$layout;
        self::$layout = null;
        
        $content = self::render($template, $data);
        
        self::$layout = $previousLayout;
        
        return $content;
    }
}

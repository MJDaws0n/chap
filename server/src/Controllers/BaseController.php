<?php

namespace Chap\Controllers;

use Chap\Auth\AuthManager;
use Chap\Models\User;
use Chap\View\View;

/**
 * Base Controller
 */
abstract class BaseController
{
    protected ?User $user = null;

    public function __construct()
    {
        $this->user = AuthManager::user();
    }

    /**
     * Render a view
     */
    protected function view(string $template, array $data = [], ?string $layout = null): void
    {
        // Add common data
        $data['user'] = $this->user?->toArray();
        $data['currentTeam'] = $this->user?->currentTeam()?->toArray();
        $data['flash'] = flash();
        $data['isAdmin'] = (bool)($this->user?->is_admin ?? false);
        $data['adminViewAll'] = admin_view_all();
        
        echo View::render($template, $data, $layout);
    }

    /**
     * Return JSON response
     */
    protected function json(mixed $data, int $status = 200): void
    {
        json($data, $status);
    }

    /**
     * Redirect to URL
     */
    protected function redirect(string $url, int $status = 302): void
    {
        redirect($url, $status);
    }

    /**
     * Get request input
     */
    protected function input(string $key, mixed $default = null): mixed
    {
        // Check JSON body
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            return $body[$key] ?? $default;
        }
        
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    /**
     * Get all input
     */
    protected function all(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            return json_decode(file_get_contents('php://input'), true) ?? [];
        }
        
        return array_merge($_GET, $_POST);
    }

    /**
     * Validate input
     */
    protected function validate(array $rules): array
    {
        $input = $this->all();
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $input[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramString] = explode(':', $rule, 2);
                    $params = explode(',', $paramString);
                }

                $error = $this->validateRule($field, $value, $rule, $params, $input);
                if ($error) {
                    $errors[$field] = $error;
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        if (!empty($errors)) {
            if ($this->isApiRequest()) {
                $this->json(['errors' => $errors], 422);
            } else {
                $_SESSION['_errors'] = $errors;
                $_SESSION['_old_input'] = $input;
                $this->redirect($_SERVER['HTTP_REFERER'] ?? '/');
            }
        }

        return $validated;
    }

    /**
     * Validate single rule
     */
    private function validateRule(string $field, mixed $value, string $rule, array $params, array $input): ?string
    {
        return match($rule) {
            'required' => empty($value) && $value !== '0' ? ucfirst($field) . ' is required' : null,
            'email' => !filter_var($value, FILTER_VALIDATE_EMAIL) ? 'Invalid email address' : null,
            'min' => strlen($value) < (int)$params[0] ? ucfirst($field) . " must be at least {$params[0]} characters" : null,
            'max' => strlen($value) > (int)$params[0] ? ucfirst($field) . " must be no more than {$params[0]} characters" : null,
            'confirmed' => $value !== ($input[$field . '_confirmation'] ?? null) ? ucfirst($field) . ' confirmation does not match' : null,
            'unique' => $this->checkUnique($params[0], $field, $value) ? ucfirst($field) . ' already exists' : null,
            'url' => !filter_var($value, FILTER_VALIDATE_URL) && !empty($value) ? 'Invalid URL' : null,
            'numeric' => !is_numeric($value) && !empty($value) ? ucfirst($field) . ' must be a number' : null,
            'in' => !in_array($value, $params) ? ucfirst($field) . ' is invalid' : null,
            default => null
        };
    }

    /**
     * Check if value is unique in table
     */
    private function checkUnique(string $table, string $column, mixed $value): bool
    {
        $db = \Chap\App::db();
        $result = $db->fetch(
            "SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1",
            [$value]
        );
        return $result !== null;
    }

    /**
     * Check if request is API request
     */
    protected function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        
        return str_starts_with($uri, '/api/') || str_contains($accept, 'application/json');
    }

    /**
     * Authorize team access
     */
    protected function authorizeTeam(int $teamId): void
    {
        if (!$this->user) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Unauthorized'], 401);
            } else {
                $this->redirect('/login');
            }
        }

        $team = \Chap\Models\Team::find($teamId);
        if (!$team || (!$this->user->belongsToTeam($team) && !admin_view_all())) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Forbidden'], 403);
            } else {
                flash('error', 'You do not have access to this resource');
                $this->redirect('/dashboard');
            }
        }
    }

    /**
     * Check if the current user can access the given team ID.
     */
    protected function canAccessTeamId(int $teamId): bool
    {
        if (admin_view_all()) {
            return true;
        }

        if (!$this->user) {
            return false;
        }

        $team = \Chap\Models\Team::find($teamId);
        return $team ? $this->user->belongsToTeam($team) : false;
    }

    /**
     * Get current team or fail
     */
    protected function currentTeam(): \Chap\Models\Team
    {
        $team = $this->user?->currentTeam();
        
        if (!$team) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'No team selected'], 400);
            } else {
                flash('error', 'Please select a team');
                $this->redirect('/teams');
            }
            exit;
        }
        
        return $team;
    }

    /**
     * Require admin role in current team
     */
    protected function requireTeamAdmin(): void
    {
        $team = $this->currentTeam();
        
        if (!$this->user->isTeamAdmin($team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Admin access required'], 403);
            } else {
                flash('error', 'You need admin privileges for this action');
                $this->redirect('/dashboard');
            }
        }
    }
}

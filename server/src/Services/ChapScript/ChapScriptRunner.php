<?php

namespace Chap\Services\ChapScript;

/**
 * ChapScript ("ChapScribe")
 *
 * A tiny, JSON-based, non-Turing-complete template/deploy scripting DSL.
 *
 * Design goals:
 * - No code execution, no eval, no filesystem access.
 * - Strict validation.
 * - Deterministic pause/resume for user prompts.
 */
final class ChapScriptRunner
{
    public const VERSION = 1;

    /**
     * @param array<string,mixed> $script
     * @param array<string,mixed> $state
     * @param array<string,string> $env
     * @return array{status:'completed'|'waiting'|'stopped', state:array<string,mixed>, env:array<string,string>, prompt?:array<string,mixed>, message?:string}
     */
    public static function run(array $script, array $state, array $env): array
    {
        self::validateScript($script);
        $state = self::normalizeState($state);

        if (!empty($state['waiting'])) {
            return ['status' => 'waiting', 'state' => $state, 'env' => $env, 'prompt' => $state['prompt'] ?? null];
        }

        $maxOps = 500;
        $ops = 0;

        while (!empty($state['stack'])) {
            if (++$ops > $maxOps) {
                return self::stop($state, $env, 'Script exceeded maximum operations');
            }

            $frameIndex = count($state['stack']) - 1;
            $frame = $state['stack'][$frameIndex];
            $steps = self::resolveStepsByPath($script, $frame['path']);

            if ($frame['index'] >= count($steps)) {
                array_pop($state['stack']);
                continue;
            }

            $step = $steps[$frame['index']];
            if (!is_array($step)) {
                return self::stop($state, $env, 'Invalid step');
            }

            $type = (string)($step['type'] ?? '');
            if ($type === '') {
                return self::stop($state, $env, 'Missing step type');
            }

            if ($type === 'if') {
                $cond = $step['condition'] ?? null;
                $then = $step['then'] ?? [];
                $else = $step['else'] ?? [];

                $truthy = self::evalCondition($cond, $env, $state['locals']);

                // Consume the if step.
                $state['stack'][$frameIndex]['index']++;

                $branch = $truthy ? $then : $else;
                if (is_array($branch) && count($branch) > 0) {
                    $branchKey = $truthy ? 'then' : 'else';
                    $state['stack'][] = [
                        'path' => array_merge($frame['path'], [$frame['index'], $branchKey]),
                        'index' => 0,
                    ];
                }

                continue;
            }

            if ($type === 'set_env') {
                $key = trim((string)($step['key'] ?? ''));
                if (!self::isValidEnvKey($key)) {
                    return self::stop($state, $env, 'Invalid env key');
                }

                $value = self::evalValue($step['value'] ?? null, $env, $state['locals']);
                $env[$key] = (string)$value;

                $state['stack'][$frameIndex]['index']++;
                continue;
            }

            if ($type === 'set_var') {
                $var = trim((string)($step['var'] ?? ''));
                if (!self::isValidVarName($var)) {
                    return self::stop($state, $env, 'Invalid variable name');
                }

                $state['locals'][$var] = self::evalValue($step['value'] ?? null, $env, $state['locals']);
                $state['stack'][$frameIndex]['index']++;
                continue;
            }

            if ($type === 'prompt_confirm') {
                $var = trim((string)($step['var'] ?? ''));
                if (!self::isValidVarName($var)) {
                    return self::stop($state, $env, 'Invalid variable name');
                }

                $prompt = [
                    'type' => 'confirm',
                    'title' => (string)($step['title'] ?? 'Confirm'),
                    'description' => (string)($step['description'] ?? ''),
                    'links' => self::normalizeLinks($step['links'] ?? null),
                    'confirm' => [
                        'text' => (string)($step['confirm']['text'] ?? ($step['confirm_text'] ?? 'Confirm')),
                        'variant' => (string)($step['confirm']['variant'] ?? 'neutral'),
                    ],
                    'cancel' => [
                        'text' => (string)($step['cancel']['text'] ?? ($step['cancel_text'] ?? 'Cancel')),
                    ],
                    'var' => $var,
                ];

                $state['waiting'] = true;
                $state['waiting_for'] = [
                    'kind' => 'confirm',
                    'var' => $var,
                    'path' => $frame['path'],
                    'index' => $frame['index'],
                ];
                $state['prompt'] = $prompt;

                return ['status' => 'waiting', 'state' => $state, 'env' => $env, 'prompt' => $prompt];
            }

            if ($type === 'prompt_value') {
                $var = trim((string)($step['var'] ?? ''));
                if (!self::isValidVarName($var)) {
                    return self::stop($state, $env, 'Invalid variable name');
                }

                $inputType = (string)($step['input_type'] ?? ($step['kind'] ?? 'string'));
                if (!in_array($inputType, ['string', 'number', 'select'], true)) {
                    return self::stop($state, $env, 'Invalid prompt input_type');
                }

                $prompt = [
                    'type' => 'value',
                    'title' => (string)($step['title'] ?? 'Enter a value'),
                    'description' => (string)($step['description'] ?? ''),
                    'links' => self::normalizeLinks($step['links'] ?? null),
                    'confirm' => [
                        'text' => (string)($step['confirm']['text'] ?? ($step['confirm_text'] ?? 'Submit')),
                        'variant' => (string)($step['confirm']['variant'] ?? 'neutral'),
                    ],
                    'cancel' => [
                        'text' => (string)($step['cancel']['text'] ?? ($step['cancel_text'] ?? 'Cancel')),
                    ],
                    'input' => [
                        'type' => $inputType,
                        'placeholder' => (string)($step['placeholder'] ?? ''),
                        'default' => $step['default'] ?? null,
                        'options' => is_array($step['options'] ?? null) ? $step['options'] : [],
                        'required' => array_key_exists('required', $step) ? (bool)$step['required'] : true,
                    ],
                    'var' => $var,
                ];

                $state['waiting'] = true;
                $state['waiting_for'] = [
                    'kind' => 'value',
                    'var' => $var,
                    'input_type' => $inputType,
                    'required' => $prompt['input']['required'],
                    'path' => $frame['path'],
                    'index' => $frame['index'],
                ];
                $state['prompt'] = $prompt;

                return ['status' => 'waiting', 'state' => $state, 'env' => $env, 'prompt' => $prompt];
            }

            if ($type === 'stop') {
                $message = (string)($step['message'] ?? 'Stopped');
                return self::stop($state, $env, $message);
            }

            return self::stop($state, $env, 'Unknown step type: ' . $type);
        }

        $state['waiting'] = false;
        $state['prompt'] = null;
        $state['waiting_for'] = null;
        return ['status' => 'completed', 'state' => $state, 'env' => $env];
    }

    /**
     * Resume a waiting script by applying a user response.
     *
     * @param array<string,mixed> $script
     * @param array<string,mixed> $state
     * @param array<string,string> $env
     * @param array<string,mixed> $response
     */
    public static function resume(array $script, array $state, array $env, array $response): array
    {
        self::validateScript($script);
        $state = self::normalizeState($state);

        if (empty($state['waiting']) || empty($state['waiting_for']) || !is_array($state['waiting_for'])) {
            return self::stop($state, $env, 'Script is not waiting for input');
        }

        $waiting = $state['waiting_for'];
        $kind = (string)($waiting['kind'] ?? '');
        $var = (string)($waiting['var'] ?? '');

        if (!self::isValidVarName($var)) {
            return self::stop($state, $env, 'Invalid waiting var');
        }

        if ($kind === 'confirm') {
            $confirmed = (bool)($response['confirmed'] ?? false);
            $state['locals'][$var] = $confirmed;
        } elseif ($kind === 'value') {
            $required = (bool)($waiting['required'] ?? true);
            $inputType = (string)($waiting['input_type'] ?? 'string');
            $value = $response['value'] ?? null;

            if ($required && ($value === null || (is_string($value) && trim($value) === ''))) {
                return self::stop($state, $env, 'Required value missing');
            }

            if ($inputType === 'number') {
                if ($value === null || $value === '') {
                    $state['locals'][$var] = null;
                } elseif (is_numeric($value)) {
                    $state['locals'][$var] = (float)$value;
                } else {
                    return self::stop($state, $env, 'Invalid number');
                }
            } elseif ($inputType === 'select') {
                // accept string values
                $state['locals'][$var] = $value === null ? null : (string)$value;
            } else {
                $state['locals'][$var] = $value === null ? null : (string)$value;
            }
        } else {
            return self::stop($state, $env, 'Unknown waiting kind');
        }

        // Clear waiting, advance the step index we were paused at.
        $path = $waiting['path'] ?? null;
        $index = $waiting['index'] ?? null;
        if (!is_array($path) || !is_int($index)) {
            return self::stop($state, $env, 'Invalid waiting cursor');
        }

        for ($i = count($state['stack']) - 1; $i >= 0; $i--) {
            $frame = $state['stack'][$i];
            if ($frame['path'] === $path && (int)$frame['index'] === (int)$index) {
                $state['stack'][$i]['index']++;
                break;
            }
        }

        $state['waiting'] = false;
        $state['waiting_for'] = null;
        $state['prompt'] = null;

        return self::run($script, $state, $env);
    }

    /**
     * @param array<string,mixed> $script
     */
    public static function validateScript(array $script): void
    {
        $errors = [];

        $ver = $script['chap_script_version'] ?? ($script['version'] ?? null);
        if ((int)$ver !== self::VERSION) {
            $errors[] = 'chap_script_version must be ' . self::VERSION;
        }

        $steps = $script['steps'] ?? null;
        if (!is_array($steps)) {
            $errors[] = 'steps must be an array';
        } else {
            $count = count($steps);
            if ($count <= 0) {
                $errors[] = 'steps cannot be empty';
            }
            if ($count > 200) {
                $errors[] = 'steps too large';
            }
        }

        if (!empty($errors)) {
            throw new ChapScriptValidationException($errors);
        }

        // Deep validation (best-effort)
        self::validateSteps($script['steps']);
    }

    /**
     * @param array<int,mixed> $steps
     */
    private static function validateSteps(array $steps, int $depth = 0): void
    {
        if ($depth > 8) {
            throw new ChapScriptValidationException(['script nesting too deep']);
        }

        foreach ($steps as $i => $step) {
            if (!is_array($step)) {
                throw new ChapScriptValidationException(["step {$i} must be an object"]);
            }
            $type = (string)($step['type'] ?? '');
            if ($type === '') {
                throw new ChapScriptValidationException(["step {$i} missing type"]);
            }
            if (!in_array($type, ['if', 'set_env', 'set_var', 'prompt_confirm', 'prompt_value', 'stop'], true)) {
                throw new ChapScriptValidationException(["step {$i} has unknown type {$type}"]);
            }

            if ($type === 'set_env') {
                $key = trim((string)($step['key'] ?? ''));
                if (!self::isValidEnvKey($key)) {
                    throw new ChapScriptValidationException(["step {$i} invalid env key"]);
                }
            }

            if ($type === 'set_var' || $type === 'prompt_confirm' || $type === 'prompt_value') {
                $var = trim((string)($step['var'] ?? ''));
                if (!self::isValidVarName($var)) {
                    throw new ChapScriptValidationException(["step {$i} invalid var name"]);
                }
            }

            if ($type === 'prompt_value') {
                $inputType = (string)($step['input_type'] ?? ($step['kind'] ?? 'string'));
                if (!in_array($inputType, ['string', 'number', 'select'], true)) {
                    throw new ChapScriptValidationException(["step {$i} invalid input_type"]);
                }
                if ($inputType === 'select') {
                    $opts = $step['options'] ?? null;
                    if (!is_array($opts) || count($opts) <= 0) {
                        throw new ChapScriptValidationException(["step {$i} select options required"]);
                    }
                }
            }

            if ($type === 'prompt_confirm' || $type === 'prompt_value') {
                // Validate optional links (safe external URLs only)
                if (array_key_exists('links', $step)) {
                    self::normalizeLinks($step['links']);
                }
            }

            if ($type === 'if') {
                $then = $step['then'] ?? [];
                $else = $step['else'] ?? [];
                if (!is_array($then) || !is_array($else)) {
                    throw new ChapScriptValidationException(["step {$i} then/else must be arrays"]);
                }
                self::validateSteps($then, $depth + 1);
                self::validateSteps($else, $depth + 1);
            }
        }
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function normalizeState(array $state): array
    {
        $stack = $state['stack'] ?? null;
        if (!is_array($stack) || empty($stack)) {
            $stack = [[ 'path' => ['steps'], 'index' => 0 ]];
        }

        $locals = $state['locals'] ?? null;
        if (!is_array($locals)) {
            $locals = [];
        }

        return [
            'stack' => array_values($stack),
            'locals' => $locals,
            'waiting' => (bool)($state['waiting'] ?? false),
            'waiting_for' => $state['waiting_for'] ?? null,
            'prompt' => $state['prompt'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $script
     * @param array<int,mixed> $path
     * @return array<int,mixed>
     */
    private static function resolveStepsByPath(array $script, array $path): array
    {
        $cur = $script;
        foreach ($path as $p) {
            if (is_int($p)) {
                if (!is_array($cur) || !array_key_exists($p, $cur)) {
                    return [];
                }
                $cur = $cur[$p];
            } else {
                $k = (string)$p;
                if (!is_array($cur) || !array_key_exists($k, $cur)) {
                    return [];
                }
                $cur = $cur[$k];
            }
        }
        return is_array($cur) ? array_values($cur) : [];
    }

    /**
     * @param mixed $cond
     * @param array<string,string> $env
     * @param array<string,mixed> $locals
     */
    private static function evalCondition(mixed $cond, array $env, array $locals): bool
    {
        if (!is_array($cond)) {
            return false;
        }

        $op = (string)($cond['op'] ?? '');
        if ($op === 'not_truthy') {
            $v = self::evalValue($cond['value'] ?? null, $env, $locals);
            return !self::isTruthy($v);
        }
        if ($op === 'is_truthy') {
            $v = self::evalValue($cond['value'] ?? null, $env, $locals);
            return self::isTruthy($v);
        }

        $left = self::evalValue($cond['left'] ?? null, $env, $locals);
        $right = self::evalValue($cond['right'] ?? null, $env, $locals);

        if ($op === 'eq') {
            return self::scalarEquals($left, $right);
        }
        if ($op === 'neq') {
            return !self::scalarEquals($left, $right);
        }

        return false;
    }

    private static function scalarEquals(mixed $a, mixed $b): bool
    {
        // bool compare
        if (is_bool($a) || is_bool($b)) {
            return self::isTruthy($a) === self::isTruthy($b);
        }

        // numeric compare
        if (is_numeric($a) && is_numeric($b)) {
            return (float)$a === (float)$b;
        }

        return strtolower(trim((string)$a)) === strtolower(trim((string)$b));
    }

    private static function isTruthy(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v) || is_float($v)) return $v !== 0;
        $s = strtolower(trim((string)($v ?? '')));
        return in_array($s, ['1', 'true', 'yes', 'y', 'on', 'accept', 'accepted', 'ok', 'enabled', 'enable'], true);
    }

    /**
     * @param mixed $expr
     * @param array<string,string> $env
     * @param array<string,mixed> $locals
     */
    private static function evalValue(mixed $expr, array $env, array $locals): mixed
    {
        if (is_array($expr)) {
            if (array_key_exists('env', $expr)) {
                $k = trim((string)$expr['env']);
                return $env[$k] ?? null;
            }
            if (array_key_exists('var', $expr)) {
                $k = trim((string)$expr['var']);
                return $locals[$k] ?? null;
            }
            if (array_key_exists('literal', $expr)) {
                return $expr['literal'];
            }
        }

        return $expr;
    }

    private static function isValidEnvKey(string $key): bool
    {
        if ($key === '') return false;
        if (strlen($key) > 128) return false;
        return (bool)preg_match('/^[A-Z_][A-Z0-9_]*$/', $key);
    }

    private static function isValidVarName(string $name): bool
    {
        if ($name === '') return false;
        if (strlen($name) > 64) return false;
        return (bool)preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
    }

    /**
     * @param mixed $links
     * @return array<int,array{label:string,url:string}>
     */
    private static function normalizeLinks(mixed $links): array
    {
        if ($links === null) {
            return [];
        }
        if (!is_array($links)) {
            throw new ChapScriptValidationException(['links must be an array']);
        }

        $out = [];
        $max = 5;
        foreach (array_values($links) as $i => $link) {
            if (count($out) >= $max) {
                break;
            }
            if (!is_array($link)) {
                throw new ChapScriptValidationException(["links[{$i}] must be an object"]);
            }

            $label = trim((string)($link['label'] ?? ''));
            $url = trim((string)($link['url'] ?? ''));
            if ($label === '' || strlen($label) > 80) {
                throw new ChapScriptValidationException(["links[{$i}].label is required"]);
            }
            if (!self::isSafeHttpUrl($url)) {
                throw new ChapScriptValidationException(["links[{$i}].url must be a safe http(s) URL"]);
            }

            $out[] = ['label' => $label, 'url' => $url];
        }

        return $out;
    }

    private static function isSafeHttpUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return false;
        }

        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['https', 'http'], true)) {
            return false;
        }
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,string> $env
     */
    private static function stop(array $state, array $env, string $message): array
    {
        $state['waiting'] = false;
        $state['prompt'] = null;
        $state['waiting_for'] = null;
        return ['status' => 'stopped', 'state' => $state, 'env' => $env, 'message' => $message];
    }
}

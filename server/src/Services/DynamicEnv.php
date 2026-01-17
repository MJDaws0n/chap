<?php

namespace Chap\Services;

final class DynamicEnv
{
    /**
     * @param array<string,string> $envVars
     * @param int[] $allocatedPorts
     * @param array<string,scalar|null> $context
     * @return array{resolved: array<string,string>, errors: array<string,string>}
     */
    public static function resolve(array $envVars, array $allocatedPorts, array $context = []): array
    {
        $errors = [];
        $resolved = [];

        $scalarPlaceholders = [
            'name' => 'name',
            'node' => 'node',
            'repo' => 'repo',
            // Back-compat + user-requested key (typo): repo_brach
            'repo_brach' => 'repo_brach',
            // Friendly alias (correct spelling)
            'repo_branch' => 'repo_branch',
            'cpu' => 'cpu',
            'ram' => 'ram',
        ];

        foreach ($envVars as $key => $value) {
            $valueStr = (string)$value;

            $valueStr = preg_replace_callback('/\{port\[(\d+)\]\}/', function($m) use ($allocatedPorts, $key, &$errors) {
                $idx = (int)$m[1];
                if (!array_key_exists($idx, $allocatedPorts)) {
                    $errors[$key] = "References {port[{$idx}]} but this application only has " . count($allocatedPorts) . " allocated port(s).";
                    return $m[0];
                }
                return (string)$allocatedPorts[$idx];
            }, $valueStr);

            // Resolve scalar placeholders like {name}, {ram}, ...
            $valueStr = preg_replace_callback('/\{([a-z_]+)\}/', function($m) use ($context, $scalarPlaceholders, $key, &$errors) {
                $ph = (string)$m[1];

                if (!array_key_exists($ph, $scalarPlaceholders)) {
                    return $m[0];
                }

                // repo_brach should fall back to repo_branch (and vice versa)
                $lookupKeys = [$ph];
                if ($ph === 'repo_brach') {
                    $lookupKeys[] = 'repo_branch';
                } elseif ($ph === 'repo_branch') {
                    $lookupKeys[] = 'repo_brach';
                }

                $val = null;
                foreach ($lookupKeys as $lk) {
                    if (array_key_exists($lk, $context) && $context[$lk] !== null) {
                        $val = $context[$lk];
                        break;
                    }
                }

                // Optional placeholders: repo + repo branch can resolve to empty.
                $optional = in_array($ph, ['repo', 'repo_brach', 'repo_branch'], true);

                if ($val === null || $val === '' || $val === -1) {
                    if ($optional) {
                        return '';
                    }
                    $errors[$key] = "References {{$ph}} but it is not available.";
                    return $m[0];
                }

                return (string)$val;
            }, $valueStr);

            $resolved[$key] = $valueStr;
        }

        return ['resolved' => $resolved, 'errors' => $errors];
    }

    /**
     * @param array<string,string> $envVars
     * @param int[] $allocatedPorts
     * @param array<string,scalar|null> $context
     * @return array<string,string> errors keyed by env var name
     */
    public static function validate(array $envVars, array $allocatedPorts, array $context = []): array
    {
        $res = self::resolve($envVars, $allocatedPorts, $context);
        return $res['errors'];
    }
}

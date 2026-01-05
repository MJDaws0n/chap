<?php

namespace Chap\Services;

final class DynamicEnv
{
    /**
     * @param array<string,string> $envVars
     * @param int[] $allocatedPorts
     * @return array{resolved: array<string,string>, errors: array<string,string>}
     */
    public static function resolve(array $envVars, array $allocatedPorts): array
    {
        $errors = [];
        $resolved = [];

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

            $resolved[$key] = $valueStr;
        }

        return ['resolved' => $resolved, 'errors' => $errors];
    }

    /**
     * @param array<string,string> $envVars
     * @param int[] $allocatedPorts
     * @return array<string,string> errors keyed by env var name
     */
    public static function validate(array $envVars, array $allocatedPorts): array
    {
        $res = self::resolve($envVars, $allocatedPorts);
        return $res['errors'];
    }
}

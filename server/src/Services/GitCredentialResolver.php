<?php

namespace Chap\Services;

use Chap\Models\GitSource;

/**
 * Resolves git authentication attempts for a repository.
 *
 * Current scope: GitHub Apps for github.com repositories.
 */
class GitCredentialResolver
{
    /**
     * @return array<int, array{type:string,label:string,token:string}>
     */
    public static function gitAuthAttemptsForRepo(int $teamId, string $repoUrl): array
    {
        $parsed = self::parseGitHubRepoUrl($repoUrl);
        if (!$parsed) {
            return [];
        }

        $apps = GitSource::githubAppsForTeam($teamId);
        if (empty($apps)) {
            return [];
        }

        $attempts = [];
        foreach ($apps as $source) {
            try {
                if (!$source->github_app_id || !$source->github_app_private_key) {
                    continue;
                }

                // If an installation id is stored, mint a single token.
                if ($source->github_app_installation_id) {
                    $token = GitHubAppTokenService::mintInstallationToken(
                        (int)$source->github_app_id,
                        (string)$source->github_app_private_key,
                        (int)$source->github_app_installation_id
                    )['token'];

                    $attempts[] = [
                        'type' => 'github_app',
                        'label' => $source->name,
                        'token' => $token,
                    ];
                    continue;
                }

                // Otherwise, list installations and mint a token for each.
                $installations = GitHubAppTokenService::listInstallations(
                    (int)$source->github_app_id,
                    (string)$source->github_app_private_key
                );

                foreach ($installations as $inst) {
                    try {
                        $instId = isset($inst['id']) ? (int)$inst['id'] : 0;
                        if ($instId <= 0) {
                            continue;
                        }

                        $accountLogin = '';
                        if (isset($inst['account']) && is_array($inst['account']) && isset($inst['account']['login'])) {
                            $accountLogin = (string)$inst['account']['login'];
                        }

                        $token = GitHubAppTokenService::mintInstallationToken(
                            (int)$source->github_app_id,
                            (string)$source->github_app_private_key,
                            $instId
                        )['token'];

                        $label = $source->name;
                        if ($accountLogin !== '') {
                            $label .= ' @ ' . $accountLogin;
                        }

                        $attempts[] = [
                            'type' => 'github_app',
                            'label' => $label,
                            'token' => $token,
                        ];
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                // Skip broken apps; other apps may still work.
                continue;
            }
        }

        return $attempts;
    }

    /**
     * @return array{0:string,1:string}|null
     */
    public static function parseGitHubRepoUrl(string $url): ?array
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (preg_match('~^git@github\\.com:([^/\\s]+)/([^\\s]+?)(?:\\.git)?$~', $url, $m)) {
            return [$m[1], $m[2]];
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !str_contains((string)$parts['host'], 'github.com')) {
            return null;
        }

        $path = trim((string)($parts['path'] ?? ''), '/');
        if ($path === '') {
            return null;
        }

        [$owner, $repo] = array_pad(explode('/', $path, 3), 2, null);
        if (!$owner || !$repo) {
            return null;
        }

        $repo = preg_replace('~\\.git$~', '', (string)$repo);
        if ($repo === '') {
            return null;
        }

        return [(string)$owner, (string)$repo];
    }
}

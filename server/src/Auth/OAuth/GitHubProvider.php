<?php

namespace Chap\Auth\OAuth;

use Chap\App;

/**
 * GitHub OAuth Provider
 */
class GitHubProvider
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $scope = 'user:email read:user repo';

    public function __construct()
    {
        $this->clientId = config('github.client_id', '');
        $this->clientSecret = config('github.client_secret', '');
        $this->redirectUri = config('github.redirect_uri', '');
    }

    /**
     * Get authorization URL
     */
    public function getAuthUrl(): string
    {
        $state = $this->generateState();

        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'state' => $state,
        ]);

        return 'https://github.com/login/oauth/authorize?' . $params;
    }

    /**
     * Generate and store state
     */
    private function generateState(): string
    {
        $state = bin2hex(random_bytes(32));
        
        $db = App::db();
        $db->insert('oauth_states', [
            'state' => $state,
            'provider' => 'github',
            'redirect_url' => $this->redirectUri,
            'expires_at' => date('Y-m-d H:i:s', time() + 600), // 10 minutes
        ]);

        return $state;
    }

    /**
     * Verify state
     */
    public function verifyState(string $state): bool
    {
        $db = App::db();
        
        $result = $db->fetch(
            "SELECT * FROM oauth_states WHERE state = ? AND provider = 'github' AND expires_at > NOW()",
            [$state]
        );

        if ($result) {
            // Delete used state
            $db->delete('oauth_states', 'state = ?', [$state]);
            return true;
        }

        return false;
    }

    /**
     * Exchange code for access token
     */
    public function getAccessToken(string $code): ?array
    {
        $ch = curl_init('https://github.com/login/oauth/access_token');
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
            ]),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            return null;
        }

        return $data;
    }

    /**
     * Get user info from GitHub
     */
    public function getUser(string $accessToken): ?array
    {
        $ch = curl_init('https://api.github.com/user');
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'User-Agent: Chap',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        $user = json_decode($response, true);

        // Get primary email if not public
        if (empty($user['email'])) {
            $user['email'] = $this->getPrimaryEmail($accessToken);
        }

        return $user;
    }

    /**
     * Get user's primary email
     */
    private function getPrimaryEmail(string $accessToken): ?string
    {
        $ch = curl_init('https://api.github.com/user/emails');
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'User-Agent: Chap',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        $emails = json_decode($response, true);

        foreach ($emails as $email) {
            if ($email['primary'] && $email['verified']) {
                return $email['email'];
            }
        }

        return null;
    }

    /**
     * Check if GitHub is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    /**
     * Get repositories for user
     */
    public function getRepositories(string $accessToken, int $page = 1, int $perPage = 30): array
    {
        $url = "https://api.github.com/user/repos?page={$page}&per_page={$perPage}&sort=updated";
        
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'User-Agent: Chap',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return [];
        }

        return json_decode($response, true) ?: [];
    }

    /**
     * Get repository branches
     */
    public function getBranches(string $accessToken, string $owner, string $repo): array
    {
        $url = "https://api.github.com/repos/{$owner}/{$repo}/branches";
        
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'User-Agent: Chap',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return [];
        }

        return json_decode($response, true) ?: [];
    }
}

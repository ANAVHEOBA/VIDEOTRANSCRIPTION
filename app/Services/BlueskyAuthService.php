<?php

namespace App\Services;

use App\Models\BlueskyAuth;
use App\Services\Bluesky\BlueskyClient;
use App\Services\Bluesky\DPoPManager;
use App\Services\Bluesky\PKCEManager;
use App\Services\Bluesky\Exceptions\BlueskyException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class BlueskyAuthService
{
    private $client;
    private $dpopManager;
    private $pkceManager;

    public function __construct(
        BlueskyClient $client,
        DPoPManager $dpopManager,
        PKCEManager $pkceManager
    ) {
        $this->client = $client;
        $this->dpopManager = $dpopManager;
        $this->pkceManager = $pkceManager;
    }

    /**
     * Initialize OAuth flow
     */
    public function initializeAuth(int $userId, ?string $handle = null): array
    {
        try {
            // Generate PKCE values
            $codeVerifier = $this->pkceManager->generateCodeVerifier();
            $codeChallenge = $this->pkceManager->generateCodeChallenge($codeVerifier);

            // Generate state
            $state = $this->pkceManager->generateState();

            // Generate DPoP keypair
            $keyPair = $this->dpopManager->generateKeyPair();

            // Store auth state in cache
            $cacheKey = "bluesky_auth_{$state}";
            Cache::put($cacheKey, [
                'user_id' => $userId,
                'code_verifier' => $codeVerifier,
                'dpop_private_key' => $keyPair['private'],
                'handle' => $handle,
            ], 3600); // 1 hour expiry

            // Get authorization URL
            $authUrl = $this->buildAuthorizationUrl([
                'code_challenge' => $codeChallenge,
                'state' => $state,
                'handle' => $handle,
            ]);

            return [
                'auth_url' => $authUrl,
                'state' => $state,
            ];

        } catch (\Exception $e) {
            Log::error('Bluesky auth initialization failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            throw new BlueskyException('Failed to initialize authentication: ' . $e->getMessage());
        }
    }

    /**
     * Handle OAuth callback
     */
    public function handleCallback(string $code, string $state): BlueskyAuth
    {
        try {
            // Retrieve auth state from cache
            $cacheKey = "bluesky_auth_{$state}";
            $authState = Cache::get($cacheKey);

            if (!$authState) {
                throw new BlueskyException('Invalid or expired auth state');
            }

            // Exchange code for tokens
            $tokens = $this->exchangeCodeForTokens(
                $code,
                $authState['code_verifier'],
                $authState['dpop_private_key']
            );

            // Create or update BlueskyAuth record
            $blueskyAuth = BlueskyAuth::updateOrCreate(
                ['user_id' => $authState['user_id']],
                [
                    'did' => $tokens['did'],
                    'handle' => $tokens['handle'],
                    'access_token' => encrypt($tokens['access_token']),
                    'refresh_token' => encrypt($tokens['refresh_token']),
                    'dpop_private_key' => encrypt($authState['dpop_private_key']),
                    'token_expires_at' => Carbon::now()->addSeconds(config('bluesky.tokens.expires_in')),
                    'is_active' => true,
                ]
            );

            // Clear auth state from cache
            Cache::forget($cacheKey);

            return $blueskyAuth;

        } catch (\Exception $e) {
            Log::error('Bluesky auth callback failed', [
                'error' => $e->getMessage(),
                'state' => $state
            ]);
            throw new BlueskyException('Authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * Refresh access token
     */
    public function refreshToken(BlueskyAuth $blueskyAuth): array
    {
        try {
            // Set DPoP key from stored value
            $this->dpopManager->setKeyPair($blueskyAuth->getDPoPPrivateKey());

            // Request new tokens
            $tokens = $this->client->refreshTokens(
                $blueskyAuth->getRefreshToken()
            );

            // Update auth record
            $blueskyAuth->updateTokens($tokens);

            return $tokens;

        } catch (\Exception $e) {
            Log::error('Token refresh failed', [
                'error' => $e->getMessage(),
                'user_id' => $blueskyAuth->user_id
            ]);
            throw new BlueskyException('Failed to refresh token: ' . $e->getMessage());
        }
    }

    /**
     * Revoke Bluesky access
     */
    public function revokeAccess(BlueskyAuth $blueskyAuth): void
    {
        try {
            // Revoke tokens at Bluesky
            $this->client->revokeTokens(
                $blueskyAuth->getAccessToken(),
                $blueskyAuth->getRefreshToken()
            );

            // Deactivate local record
            $blueskyAuth->update([
                'is_active' => false,
                'access_token' => null,
                'refresh_token' => null,
                'dpop_private_key' => null,
            ]);

        } catch (\Exception $e) {
            Log::error('Access revocation failed', [
                'error' => $e->getMessage(),
                'user_id' => $blueskyAuth->user_id
            ]);
            throw new BlueskyException('Failed to revoke access: ' . $e->getMessage());
        }
    }

    /**
     * Build authorization URL
     */
    private function buildAuthorizationUrl(array $params): string
    {
        $baseUrl = config('bluesky.endpoints.base');
        $queryParams = http_build_query([
            'client_id' => config('bluesky.client.id'),
            'redirect_uri' => config('bluesky.client.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('bluesky.scopes')),
            'code_challenge' => $params['code_challenge'],
            'code_challenge_method' => 'S256',
            'state' => $params['state'],
            'login_hint' => $params['handle'] ?? null,
        ]);

        return "{$baseUrl}/oauth/authorize?{$queryParams}";
    }

    /**
     * Exchange authorization code for tokens
     */
    private function exchangeCodeForTokens(
        string $code,
        string $codeVerifier,
        string $dpopPrivateKey
    ): array {
        // Set DPoP key
        $this->dpopManager->setKeyPair($dpopPrivateKey);

        // Exchange code for tokens
        return $this->client->exchangeCode(
            $code,
            $codeVerifier,
            config('bluesky.client.redirect_uri')
        );
    }
}
<?php

namespace App\Http\Controllers;

use App\Services\BlueskyAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\BlueskyAuth;

class BlueskyAuthController extends Controller
{
    private $authService;
    private const TOKEN_EXPIRY = 3600; // 1 hour in seconds

    public function __construct(BlueskyAuthService $authService) 
    {
        $this->authService = $authService;
    }

    /**
     * Redirect to Bluesky authorization page
     */
    public function redirect(Request $request)
    {
        try {
            // Generate PKCE code verifier and challenge
            $codeVerifier = $this->authService->generateCodeVerifier();
            $codeChallenge = $this->authService->generateCodeChallenge($codeVerifier);
            
            // Generate state token
            $state = $this->authService->generateState();
            
            // Store PKCE and state in session
            session([
                'code_verifier' => $codeVerifier,
                'state' => $state
            ]);

            // Generate DPoP key pair
            $dPopKeyPair = $this->authService->generateDPoPKeyPair();
            session(['dpop_private_key' => $dPopKeyPair['private']]);

            // Get authorization URL
            $authUrl = $this->authService->getAuthorizationUrl([
                'code_challenge' => $codeChallenge,
                'state' => $state,
                'dpop_public_key' => $dPopKeyPair['public']
            ]);

            return redirect($authUrl);
        } catch (\Exception $e) {
            Log::error('Bluesky auth redirect failed: ' . $e->getMessage());
            return redirect()->route('home')->with('error', 'Failed to initialize Bluesky authentication');
        }
    }

    /**
     * Handle the callback from Bluesky
     */
    public function callback(Request $request)
    {
        try {
            // Verify state
            $state = session('state');
            if (!$state || $state !== $request->state) {
                throw new \Exception('Invalid state parameter');
            }

            // Exchange code for tokens
            $tokens = $this->authService->getAccessToken([
                'code' => $request->code,
                'code_verifier' => session('code_verifier'),
                'dpop_private_key' => session('dpop_private_key')
            ]);

            // Verify DID
            $did = $this->authService->verifyDID($tokens['did']);

            // Store tokens in database
            BlueskyAuth::updateOrCreate(
                ['user_id' => auth()->id()],
                [
                    'did' => $did,
                    'access_token' => encrypt($tokens['access_token']),
                    'refresh_token' => encrypt($tokens['refresh_token']),
                    'dpop_private_key' => encrypt(session('dpop_private_key')),
                    'token_expires_at' => now()->addSeconds(self::TOKEN_EXPIRY)
                ]
            );

            // Clear session data
            session()->forget(['code_verifier', 'state', 'dpop_private_key']);

            return redirect()->route('home')->with('success', 'Successfully connected to Bluesky');

        } catch (\Exception $e) {
            Log::error('Bluesky auth callback failed: ' . $e->getMessage());
            return redirect()->route('home')->with('error', 'Failed to complete Bluesky authentication');
        }
    }

    /**
     * Refresh the access token
     */
    public function refreshToken(Request $request)
    {
        try {
            $blueskyAuth = BlueskyAuth::where('user_id', auth()->id())->firstOrFail();

            if ($blueskyAuth->token_expires_at > now()) {
                return response()->json(['message' => 'Token still valid']);
            }

            $tokens = $this->authService->refreshAccessToken([
                'refresh_token' => decrypt($blueskyAuth->refresh_token),
                'dpop_private_key' => decrypt($blueskyAuth->dpop_private_key)
            ]);

            $blueskyAuth->update([
                'access_token' => encrypt($tokens['access_token']),
                'refresh_token' => encrypt($tokens['refresh_token']),
                'token_expires_at' => now()->addSeconds(self::TOKEN_EXPIRY)
            ]);

            return response()->json(['message' => 'Token refreshed successfully']);

        } catch (\Exception $e) {
            Log::error('Token refresh failed: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to refresh token'], 500);
        }
    }

    /**
     * Revoke Bluesky access
     */
    public function revoke()
    {
        try {
            $blueskyAuth = BlueskyAuth::where('user_id', auth()->id())->first();
            
            if ($blueskyAuth) {
                // Revoke tokens at Bluesky
                $this->authService->revokeToken(
                    decrypt($blueskyAuth->access_token),
                    decrypt($blueskyAuth->dpop_private_key)
                );
                
                // Delete local record
                $blueskyAuth->delete();
            }

            return redirect()->route('home')->with('success', 'Bluesky access revoked successfully');

        } catch (\Exception $e) {
            Log::error('Token revocation failed: ' . $e->getMessage());
            return redirect()->route('home')->with('error', 'Failed to revoke Bluesky access');
        }
    }
}
<?php

namespace App\Services\Bluesky;

use Illuminate\Support\Str;

class PKCEManager
{
    /**
     * Generate a random code verifier
     */
    public function generateCodeVerifier(): string
    {
        return Str::random(128);
    }

    /**
     * Generate code challenge from verifier
     */
    public function generateCodeChallenge(string $codeVerifier): string
    {
        $hash = hash('sha256', $codeVerifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Verify code challenge matches verifier
     */
    public function verifyChallenge(string $codeVerifier, string $codeChallenge): bool
    {
        $generatedChallenge = $this->generateCodeChallenge($codeVerifier);
        return hash_equals($generatedChallenge, $codeChallenge);
    }

    /**
     * Generate state parameter
     */
    public function generateState(): string
    {
        return Str::random(40);
    }

    /**
     * Verify state parameter
     */
    public function verifyState(string $originalState, string $returnedState): bool
    {
        return hash_equals($originalState, $returnedState);
    }
}
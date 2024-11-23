<?php

namespace App\Services\Bluesky;

use App\Services\Bluesky\Exceptions\BlueskyException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\KeyManagement\JWKFactory;
use Illuminate\Support\Str;

class DPoPManager
{
    private $currentNonce = null;
    private $privateKey = null;
    private $publicKey = null;

    /**
     * Generate new DPoP keypair
     */
    public function generateKeyPair(): array
    {
        $jwk = JWKFactory::createECKey('P-256');
        
        $this->privateKey = $jwk;
        $this->publicKey = $jwk->toPublic();

        return [
            'private' => json_encode($this->privateKey),
            'public' => json_encode($this->publicKey),
        ];
    }

    /**
     * Set existing DPoP keypair
     */
    public function setKeyPair(string $privateKeyJson): void
    {
        $this->privateKey = JWK::createFromJson($privateKeyJson);
        $this->publicKey = $this->privateKey->toPublic();
    }

    /**
     * Generate DPoP proof
     */
    public function generateProof(string $method, string $url, string $accessToken = null): string
    {
        if (!$this->privateKey) {
            throw new BlueskyException('DPoP private key not set');
        }

        // Create algorithm manager
        $algorithmManager = new AlgorithmManager([
            new ES256(),
        ]);

        // Create JWS Builder
        $jwsBuilder = new JWSBuilder($algorithmManager);

        // Create header
        $header = [
            'typ' => 'dpop+jwt',
            'alg' => 'ES256',
            'jwk' => json_decode(json_encode($this->publicKey), true),
        ];

        // Create payload
        $payload = [
            'jti' => Str::uuid()->toString(),
            'htm' => $method,
            'htu' => $url,
            'iat' => time(),
        ];

        // Add nonce if available
        if ($this->currentNonce) {
            $payload['nonce'] = $this->currentNonce;
        }

        // Add access token hash if provided
        if ($accessToken) {
            $payload['ath'] = $this->hashAccessToken($accessToken);
        }

        // Build and sign the token
        $jws = $jwsBuilder
            ->create()
            ->withPayload(json_encode($payload))
            ->addSignature($this->privateKey, $header)
            ->build();

        return base64_encode(json_encode($jws));
    }

    /**
     * Update the current nonce
     */
    public function updateNonce(string $nonce): void
    {
        $this->currentNonce = $nonce;
    }

    /**
     * Hash access token for DPoP proof
     */
    private function hashAccessToken(string $token): string
    {
        return base64_encode(hash('sha256', $token, true));
    }
}
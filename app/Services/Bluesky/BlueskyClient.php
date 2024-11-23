<?php

namespace App\Services\Bluesky;

use App\Services\Bluesky\Exceptions\BlueskyException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlueskyClient
{
    private $dpopManager;
    private $baseUrl;
    private $timeout;

    public function __construct(DPoPManager $dpopManager)
    {
        $this->dpopManager = $dpopManager;
        $this->baseUrl = config('bluesky.endpoints.base');
        $this->timeout = config('bluesky.security.timeout');
    }

    /**
     * Create a new post with media
     */
    public function createPost(string $accessToken, array $data): array
    {
        try {
            $endpoint = config('bluesky.endpoints.post.create');
            
            $postData = [
                'collection' => 'app.bsky.feed.post',
                'repo' => $data['did'],
                'record' => [
                    'text' => $data['text'],
                    '$type' => 'app.bsky.feed.post',
                    'createdAt' => now()->toIso8601String(),
                ]
            ];

            // Add media if present
            if (!empty($data['media'])) {
                $postData['record']['embed'] = [
                    '$type' => 'app.bsky.embed.images',
                    'images' => array_map(function($media) {
                        return [
                            'alt' => $media['alt_text'] ?? '',
                            'image' => $media['blob'],
                        ];
                    }, $data['media'])
                ];
            }

            $response = $this->makeAuthorizedRequest(
                'POST',
                $endpoint,
                $accessToken,
                $postData
            );

            return $response;
        } catch (\Exception $e) {
            Log::error('Bluesky post creation failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw new BlueskyException('Failed to create post: ' . $e->getMessage());
        }
    }

    /**
     * Upload media file
     */
    public function uploadMedia(string $accessToken, string $did, $file): array
    {
        try {
            $endpoint = config('bluesky.endpoints.media.upload');

            // Read file content
            $content = file_get_contents($file->getPathname());
            
            $response = $this->makeAuthorizedRequest(
                'POST',
                $endpoint,
                $accessToken,
                $content,
                [
                    'Content-Type' => $file->getMimeType(),
                ]
            );

            return $response['blob'];
        } catch (\Exception $e) {
            Log::error('Bluesky media upload failed', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName()
            ]);
            throw new BlueskyException('Failed to upload media: ' . $e->getMessage());
        }
    }

    /**
     * Make an authorized request to the Bluesky API
     */
    private function makeAuthorizedRequest(
        string $method,
        string $endpoint,
        string $accessToken,
        $data = null,
        array $headers = []
    ): array {
        $url = $this->baseUrl . $endpoint;
        
        // Generate DPoP proof
        $dpopProof = $this->dpopManager->generateProof(
            $method,
            $url,
            $accessToken
        );

        // Merge headers
        $headers = array_merge($headers, [
            'Authorization' => "Bearer {$accessToken}",
            'DPoP' => $dpopProof,
        ]);

        $response = Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->withOptions([
                'verify' => config('bluesky.security.verify_ssl'),
            ]);

        // Make the request based on method
        if ($method === 'GET') {
            $response = $response->get($url);
        } else {
            $response = $response->$method($url, $data);
        }

        // Handle response
        if ($response->successful()) {
            return $response->json();
        }

        throw new BlueskyException(
            'API request failed: ' . $response->body(),
            $response->status()
        );
    }
}
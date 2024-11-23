<?php

namespace App\Services;

use App\Models\BlueskyAuth;
use App\Services\Bluesky\BlueskyClient;
use App\Services\Bluesky\Exceptions\BlueskyException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class BlueskyPostService
{
    private $client;
    private const RATE_LIMIT_KEY = 'bluesky_post_rate_limit_';
    private const UPLOAD_RATE_LIMIT_KEY = 'bluesky_upload_rate_limit_';

    public function __construct(BlueskyClient $client)
    {
        $this->client = $client;
    }

    /**
     * Create a new post with optional media
     */
    public function createPost(
        BlueskyAuth $blueskyAuth,
        string $text,
        ?array $media = null
    ): array {
        try {
            // Check rate limits
            $this->checkPostRateLimit($blueskyAuth->user_id);

            // Process media if present
            $mediaBlobs = [];
            if ($media) {
                foreach ($media as $item) {
                    if ($item instanceof UploadedFile) {
                        $blob = $this->uploadMedia($blueskyAuth, $item);
                        $mediaBlobs[] = [
                            'blob' => $blob,
                            'alt_text' => $item['alt_text'] ?? ''
                        ];
                    } elseif (is_array($item) && isset($item['url'])) {
                        $blob = $this->uploadMediaFromUrl($blueskyAuth, $item['url']);
                        $mediaBlobs[] = [
                            'blob' => $blob,
                            'alt_text' => $item['alt_text'] ?? ''
                        ];
                    }
                }
            }

            // Create post
            $response = $this->client->createPost(
                $blueskyAuth->getAccessToken(),
                [
                    'did' => $blueskyAuth->did,
                    'text' => $text,
                    'media' => $mediaBlobs
                ]
            );

            // Update rate limit
            $this->incrementPostRateLimit($blueskyAuth->user_id);

            // Update last post time
            $blueskyAuth->updateLastPostTime();

            return $response;

        } catch (\Exception $e) {
            Log::error('Post creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $blueskyAuth->user_id
            ]);
            throw new BlueskyException('Failed to create post: ' . $e->getMessage());
        }
    }

    /**
     * Upload media file
     */
    public function uploadMedia(BlueskyAuth $blueskyAuth, UploadedFile $file): array
    {
        try {
            // Check rate limits
            $this->checkUploadRateLimit($blueskyAuth->user_id);

            // Validate file
            $this->validateMediaFile($file);

            // Upload to Bluesky
            $response = $this->client->uploadMedia(
                $blueskyAuth->getAccessToken(),
                $blueskyAuth->did,
                $file
            );

            // Update rate limit
            $this->incrementUploadRateLimit($blueskyAuth->user_id);

            return $response;

        } catch (\Exception $e) {
            Log::error('Media upload failed', [
                'error' => $e->getMessage(),
                'user_id' => $blueskyAuth->user_id,
                'file' => $file->getClientOriginalName()
            ]);
            throw new BlueskyException('Failed to upload media: ' . $e->getMessage());
        }
    }

    /**
     * Upload media from URL
     */
    public function uploadMediaFromUrl(BlueskyAuth $blueskyAuth, string $url): array
    {
        try {
            // Check rate limits
            $this->checkUploadRateLimit($blueskyAuth->user_id);

            // Download file to temp storage
            $tempFile = $this->downloadFile($url);

            // Validate file
            $this->validateMediaFile($tempFile);

            // Upload to Bluesky
            $response = $this->client->uploadMedia(
                $blueskyAuth->getAccessToken(),
                $blueskyAuth->did,
                $tempFile
            );

            // Cleanup temp file
            @unlink($tempFile->getPathname());

            // Update rate limit
            $this->incrementUploadRateLimit($blueskyAuth->user_id);

            return $response;

        } catch (\Exception $e) {
            Log::error('URL media upload failed', [
                'error' => $e->getMessage(),
                'user_id' => $blueskyAuth->user_id,
                'url' => $url
            ]);
            throw new BlueskyException('Failed to upload media from URL: ' . $e->getMessage());
        }
    }

    /**
     * Delete a post
     */
    public function deletePost(BlueskyAuth $blueskyAuth, string $uri): void
    {
        try {
            $this->client->deletePost(
                $blueskyAuth->getAccessToken(),
                $blueskyAuth->did,
                $uri
            );
        } catch (\Exception $e) {
            Log::error('Post deletion failed', [
                'error' => $e->getMessage(),
                'user_id' => $blueskyAuth->user_id,
                'uri' => $uri
            ]);
            throw new BlueskyException('Failed to delete post: ' . $e->getMessage());
        }
    }

    /**
     * Check post rate limit
     */
    private function checkPostRateLimit(int $userId): void
    {
        $key = self::RATE_LIMIT_KEY . $userId;
        $limit = config('bluesky.rate_limits.posts_per_hour', 50);
        $count = Cache::get($key, 0);

        if ($count >= $limit) {
            throw BlueskyException::rateLimitError('Post limit exceeded');
        }
    }

    /**
     * Increment post rate limit counter
     */
    private function incrementPostRateLimit(int $userId): void
    {
        $key = self::RATE_LIMIT_KEY . $userId;
        $count = Cache::get($key, 0);
        Cache::put($key, $count + 1, now()->addHour());
    }

    /**
     * Check upload rate limit
     */
    private function checkUploadRateLimit(int $userId): void
    {
        $key = self::UPLOAD_RATE_LIMIT_KEY . $userId;
        $limit = config('bluesky.rate_limits.uploads_per_hour', 100);
        $count = Cache::get($key, 0);

        if ($count >= $limit) {
            throw BlueskyException::rateLimitError('Upload limit exceeded');
        }
    }

    /**
     * Increment upload rate limit counter
     */
    private function incrementUploadRateLimit(int $userId): void
    {
        $key = self::UPLOAD_RATE_LIMIT_KEY . $userId;
        $count = Cache::get($key, 0);
        Cache::put($key, $count + 1, now()->addHour());
    }

    /**
     * Validate media file
     */
    private function validateMediaFile(UploadedFile $file): void
    {
        $maxSize = config('bluesky.media.max_size');
        $allowedTypes = array_merge(
            config('bluesky.media.allowed_image_types', []),
            config('bluesky.media.allowed_video_types', [])
        );

        if ($file->getSize() > $maxSize) {
            throw BlueskyException::validationError('File size exceeds limit');
        }

        if (!in_array($file->getMimeType(), $allowedTypes)) {
            throw BlueskyException::validationError('Unsupported file type');
        }
    }

    /**
     * Download file from URL
     */
    private function downloadFile(string $url): UploadedFile
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'bluesky_');
        $contents = file_get_contents($url);
        file_put_contents($tempPath, $contents);

        return new UploadedFile(
            $tempPath,
            basename($url),
            mime_content_type($tempPath),
            null,
            true
        );
    }
}
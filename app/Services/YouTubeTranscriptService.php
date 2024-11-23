<?php

namespace App\Services;

use App\Models\VideoTranscript;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class YouTubeTranscriptService
{
    const CACHE_DURATION = 1440; // 24 hours

    public function getTranscript(string $videoId, string $language = 'en')
    {
        // Check cache first
        $cacheKey = "transcript_{$videoId}_{$language}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Check if transcript exists in database
        $storedTranscript = $this->getStoredTranscript($videoId);
        if ($storedTranscript && $storedTranscript['language'] === $language) {
            $this->cacheTranscript($videoId, $storedTranscript, $language);
            return $storedTranscript;
        }

        // Extract new transcript
        $transcript = $this->extractYouTubeTranscript($videoId, $language);
        
        if ($transcript['success']) {
            // Store in database
            $this->storeTranscript($videoId, $transcript, $language);
            
            // Cache the result
            $this->cacheTranscript($videoId, $transcript, $language);
        }

        return $transcript;
    }

    private function extractYouTubeTranscript(string $videoId, string $language)
    {
        try {
            $result = Process::run([
                'python3',
                base_path('python/transcript_extractor.py'),
                $videoId,
                '--languages',
                $language
            ]);

            if ($result->failed()) {
                Log::error('Python script execution failed', [
                    'video_id' => $videoId,
                    'error' => $result->errorOutput()
                ]);
                throw new \Exception('Failed to extract transcript: ' . $result->errorOutput());
            }

            $transcript = json_decode($result->output(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response from Python script');
            }

            return $transcript;

        } catch (\Exception $e) {
            Log::error('Transcript extraction failed', [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getStoredTranscript(string $videoId)
    {
        try {
            $transcript = VideoTranscript::where('video_id', $videoId)
                ->latest()
                ->first();

            if (!$transcript) {
                return null;
            }

            return [
                'success' => true,
                'video_id' => $transcript->video_id,
                'transcript' => $transcript->transcript,
                'language' => $transcript->language,
                'metadata' => $transcript->metadata,
                'created_at' => $transcript->created_at
            ];

        } catch (\Exception $e) {
            Log::error('Failed to retrieve stored transcript', [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function storeTranscript(string $videoId, array $data, string $language)
    {
        try {
            return VideoTranscript::create([
                'video_id' => $videoId,
                'transcript' => $data['transcript'] ?? null,
                'language' => $language,
                'status' => $data['success'] ? 'completed' : 'failed',
                'error_message' => $data['error'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store transcript', [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function cacheTranscript(string $videoId, array $transcript, string $language): void
    {
        Cache::put(
            "transcript_{$videoId}_{$language}",
            $transcript,
            now()->addMinutes(self::CACHE_DURATION)
        );
    }

    public function clearCache(string $videoId): void
    {
        $languages = ['en', 'es', 'fr', 'de']; // Add more languages as needed
        foreach ($languages as $language) {
            Cache::forget("transcript_{$videoId}_{$language}");
        }
    }
}
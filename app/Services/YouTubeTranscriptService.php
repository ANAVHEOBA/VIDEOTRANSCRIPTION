<?php

namespace App\Services;

use App\Models\VideoTranscript;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class YouTubeTranscriptService
{
    /**
     * Cache duration in minutes
     */
    const CACHE_DURATION = 1440; // 24 hours

    /**
     * Get transcript for a YouTube video ID
     */
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
        
        // Store in database
        $this->storeTranscript($videoId, $transcript, $language);
        
        // Cache the result
        $this->cacheTranscript($videoId, $transcript, $language);

        return $transcript;
    }

    /**
     * Get transcript from local video file
     */
    public function getTranscriptFromFile(string $filePath, string $videoId, string $language = 'en')
    {
        try {
            // Create a pending transcript record
            $transcript = $this->createPendingTranscript($videoId, $language);

            // Extract audio from video
            $audioPath = $this->extractAudio($filePath);

            // Convert speech to text
            $transcriptData = $this->speechToText($audioPath, $language);

            // Format the transcript
            $formattedTranscript = $this->formatTranscript($transcriptData);

            // Update the transcript record
            $this->updateTranscript($transcript, $formattedTranscript);

            // Cleanup temporary files
            $this->cleanup($audioPath);

            return $formattedTranscript;

        } catch (Exception $e) {
            Log::error('Failed to process video file', [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);

            // Mark transcript as failed
            if (isset($transcript)) {
                $transcript->markAsFailed($e->getMessage());
            }

            // Cleanup any temporary files
            if (isset($audioPath)) {
                $this->cleanup($audioPath);
            }

            throw $e;
        }
    }

    /**
     * Extract transcript using Python script
     */
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
                throw new Exception('Failed to extract transcript: ' . $result->errorOutput());
            }

            $transcript = json_decode($result->output(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON response from Python script');
            }

            return $transcript;

        } catch (Exception $e) {
            Log::error('Transcript extraction failed', [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Extract audio from video file
     */
    private function extractAudio(string $videoPath): string
    {
        $outputPath = storage_path('app/temp/' . uniqid('audio_') . '.wav');
        
        $result = Process::run([
            'ffmpeg',
            '-i',
            $videoPath,
            '-vn',
            '-acodec',
            'pcm_s16le',
            '-ar',
            '44100',
            '-ac',
            '2',
            $outputPath
        ]);

        if ($result->failed()) {
            throw new Exception('Failed to extract audio: ' . $result->errorOutput());
        }

        return $outputPath;
    }

    /**
     * Convert speech to text using Whisper
     */
    private function speechToText(string $audioPath, string $language): array
    {
        $result = Process::run([
            'python3',
            base_path('python/speech_to_text.py'),
            $audioPath,
            '--language',
            $language
        ]);

        if ($result->failed()) {
            throw new Exception('Failed to convert speech to text: ' . $result->errorOutput());
        }

        return json_decode($result->output(), true);
    }

    /**
     * Format transcript data
     */
    private function formatTranscript(array $rawTranscript): array
    {
        return [
            'segments' => $rawTranscript['segments'] ?? [],
            'text' => $rawTranscript['text'] ?? '',
            'language' => $rawTranscript['language'] ?? 'en',
            'duration' => $rawTranscript['duration'] ?? 0,
        ];
    }

    /**
     * Create pending transcript record
     */
    private function createPendingTranscript(string $videoId, string $language): VideoTranscript
    {
        return VideoTranscript::create([
            'video_id' => $videoId,
            'language' => $language,
            'status' => 'pending',
            'metadata' => [
                'source' => 'local_file',
                'started_at' => now()->toIso8601String()
            ]
        ]);
    }

    /**
     * Update transcript with extracted data
     */
    private function updateTranscript(VideoTranscript $transcript, array $data): void
    {
        $transcript->update([
            'transcript' => $data,
            'status' => 'completed',
            'metadata' => array_merge($transcript->metadata ?? [], [
                'completed_at' => now()->toIso8601String(),
                'duration' => $data['duration'] ?? 0
            ])
        ]);
    }

    /**
     * Cleanup temporary files
     */
    private function cleanup(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Store transcript in database
     */
    private function storeTranscript(string $videoId, array $transcript, string $language)
    {
        try {
            return VideoTranscript::create([
                'video_id' => $videoId,
                'transcript' => $transcript,
                'language' => $language,
                'status' => 'completed',
                'metadata' => [
                    'extracted_at' => now()->toIso8601String(),
                    'source' => 'youtube_api'
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Failed to store transcript', [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get stored transcript from database
     */
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
                'video_id' => $transcript->video_id,
                'transcript' => $transcript->transcript,
                'language' => $transcript->language,
                'status' => $transcript->status,
                'metadata' => $transcript->metadata,
                'created_at' => $transcript->created_at
            ];

        } catch (Exception $e) {
            Log::error('Failed to retrieve stored transcript', [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get transcript status
     */
    public function getTranscriptStatus(string $videoId)
    {
        $transcript = VideoTranscript::where('video_id', $videoId)
            ->latest()
            ->first();

        if (!$transcript) {
            return ['status' => 'not_found'];
        }

        return [
            'status' => $transcript->status,
            'error_message' => $transcript->error_message,
            'progress' => $this->calculateProgress($transcript),
            'metadata' => $transcript->metadata
        ];
    }

    /**
     * Calculate processing progress
     */
    private function calculateProgress(VideoTranscript $transcript): int
    {
        if ($transcript->status === 'completed') return 100;
        if ($transcript->status === 'failed') return 0;
        if ($transcript->status === 'pending') return 50;
        return 0;
    }

    /**
     * Delete transcript
     */
    public function deleteTranscript(string $videoId): bool
    {
        try {
            $transcript = VideoTranscript::where('video_id', $videoId)->first();
            
            if ($transcript) {
                $transcript->delete();
                $this->clearCache($videoId);
                return true;
            }

            return false;

        } catch (Exception $e) {
            Log::error('Failed to delete transcript', [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Cache transcript
     */
    private function cacheTranscript(string $videoId, array $transcript, string $language): void
    {
        Cache::put(
            "transcript_{$videoId}_{$language}",
            $transcript,
            now()->addMinutes(self::CACHE_DURATION)
        );
    }

    /**
     * Clear transcript cache
     */
    public function clearCache(string $videoId): void
    {
        $languages = ['en', 'es', 'fr', 'de']; // Add more languages as needed
        foreach ($languages as $language) {
            Cache::forget("transcript_{$videoId}_{$language}");
        }
    }
}
<?php

namespace App\Http\Controllers;

use App\Models\VideoTranscript;
use App\Services\YouTubeTranscriptService;
use App\Services\VideoProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Exception;

class VideoTranscriptController extends Controller
{
    protected $transcriptService;
    protected $videoProcessor;
    protected $allowedVideoTypes = ['video/mp4', 'video/quicktime', 'video/x-msvideo'];
    protected $maxFileSize = 524288000; // 500MB in bytes

    public function __construct(YouTubeTranscriptService $transcriptService, VideoProcessor $videoProcessor)
    {
        $this->transcriptService = $transcriptService;
        $this->videoProcessor = $videoProcessor;
    }

    /**
     * Process YouTube video
     */
    public function processYouTube(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'youtube_url' => [
                    'required',
                    'string',
                    'regex:#^(https?://)?(www\.)?(youtube\.com/watch\?v=|youtu\.be/)[a-zA-Z0-9_-]{11}#'
                ],
                'language' => 'nullable|string|size:2',
                'subtitle_style' => 'nullable|array',
                'output_format' => 'nullable|in:text,srt,video'
            ]);
    
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Extract video ID from URL
            $videoId = $this->extractVideoId($request->youtube_url);
            if (!$videoId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid YouTube URL'
                ], 422);
            }

            // Check cache
            $cacheKey = "transcript_{$videoId}_{$request->language}";
            if (Cache::has($cacheKey) && $request->output_format === 'text') {
                return response()->json([
                    'success' => true,
                    'data' => Cache::get($cacheKey)
                ]);
            }

            // Get transcript
            $transcript = $this->transcriptService->getTranscript(
                $videoId,
                $request->input('language', 'en')
            );

            if (!$transcript['success']) {
                return response()->json($transcript, 422);
            }

            // Process based on requested output format
            switch ($request->input('output_format', 'video')) {
                case 'text':
                    $result = $this->formatTranscriptText($transcript['data']['transcript']);
                    Cache::put($cacheKey, $result, now()->addHours(24));
                    return response()->json(['success' => true, 'data' => $result]);

                case 'srt':
                    $srtContent = $this->generateSrtContent($transcript['data']['transcript']);
                    return response($srtContent)
                        ->header('Content-Type', 'text/plain')
                        ->header('Content-Disposition', 'attachment; filename="transcript.srt"');

                case 'video':
                default:
                    return $this->processVideoWithSubtitles($videoId, $transcript, $request->subtitle_style);
            }

        } catch (Exception $e) {
            Log::error('YouTube processing failed', [
                'error' => $e->getMessage(),
                'url' => $request->youtube_url
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process YouTube video',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process local video
     */
    public function processLocal(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'video' => "required|file|mimes:mp4,mov,avi|max:{$this->maxFileSize}",
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Store uploaded video
        $videoFile = $request->file('video');
        $videoPath = $videoFile->store('temp');
        $fullPath = storage_path("app/{$videoPath}");

        // Log the file details
        Log::info('Processing local video', [
            'original_name' => $videoFile->getClientOriginalName(),
            'mime_type' => $videoFile->getMimeType(),
            'size' => $videoFile->getSize(),
            'stored_path' => $fullPath
        ]);

        // Process video using processLocalVideo
        $result = $this->videoProcessor->processLocalVideo($fullPath);

        if (!$result['success']) {
            Storage::delete($videoPath);
            throw new Exception($result['error']);
        }

        // Save to database
        $videoTranscript = VideoTranscript::create([
            'source_type' => 'local',
            'language' => 'en',
            'video_path' => $result['path'], // Changed from processed_video_path
            'status' => 'completed',
            'metadata' => $result['metadata'] ?? []
        ]);

        // Clean up temporary file
        Storage::delete($videoPath);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $videoTranscript->id,
                'video_url' => $result['url'],
                'duration' => $result['metadata']['duration'] ?? null,
                'metadata' => $result['metadata']
            ]
        ]);

    } catch (Exception $e) {
        // Clean up on error
        if (isset($videoPath)) {
            Storage::delete($videoPath);
        }

        Log::error('Local video processing failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'We could not handle your request, please try again later',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Process video with subtitles
     */
    protected function processVideoWithSubtitles($videoId = null, $transcript, $subtitleStyle = [], $localVideoPath = null)
    {
        try {
            // Process video
            $result = $this->videoProcessor->processVideo(
                $localVideoPath ?? $this->downloadYouTubeVideo($videoId),
                $transcript['data']['transcript'],
                ['subtitle_style' => $subtitleStyle]
            );

            if (!$result['success']) {
                throw new Exception($result['error']);
            }

            // Save to database
            $videoTranscript = VideoTranscript::create([
                'video_id' => $videoId,
                'source_type' => $videoId ? 'youtube' : 'local',
                'language' => $transcript['data']['language'],
                'transcript' => $transcript['data']['transcript'],
                'processed_video_path' => $result['path'],
                'metadata' => array_merge(
                    $transcript['data']['metadata'] ?? [],
                    $result['metadata'] ?? []
                )
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $videoTranscript->id,
                    'video_url' => $result['url'],
                    'duration' => $result['metadata']['duration'] ?? null,
                    'language' => $transcript['data']['language'],
                    'word_count' => $transcript['data']['metadata']['word_count'] ?? null
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Video processing failed', [
                'error' => $e->getMessage(),
                'video_id' => $videoId
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process video with subtitles',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extract YouTube video ID from URL
     */
    protected function extractVideoId($url)
{
    $pattern = '#(?:youtube\.com/watch\?v=|youtu\.be/)([a-zA-Z0-9_-]{11})#';
    if (preg_match($pattern, $url, $matches)) {
        return $matches[1];
    }
    return null;
}
    /**
     * Format transcript text
     */
    protected function formatTranscriptText($transcript)
    {
        return collect($transcript)
            ->pluck('text')
            ->map(function ($text) {
                return trim($text);
            })
            ->filter()
            ->join("\n\n");
    }

    /**
     * Generate SRT content
     */
    protected function generateSrtContent($transcript)
    {
        return collect($transcript)->map(function ($segment, $index) {
            return implode("\n", [
                $index + 1,
                $this->formatTimestamp($segment['start']) . ' --> ' . 
                    $this->formatTimestamp($segment['start'] + $segment['duration']),
                $segment['text'],
                ''
            ]);
        })->join("\n");
    }

    /**
     * Format timestamp for SRT
     */
    protected function formatTimestamp($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $ms = round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $ms);
    }

    /**
     * Download YouTube video
     */
    /**
 /**
 * Download YouTube video
 */
protected function downloadYouTubeVideo($videoId)
{
    try {
        // Create temp directory if it doesn't exist
        $tempPath = storage_path('app/temp');
        if (!file_exists($tempPath)) {
            mkdir($tempPath, 0755, true);
        }

        // Generate unique filename
        $outputPath = $tempPath . '/' . $videoId . '_' . time() . '.mp4';

        // Build yt-dlp command
        $command = sprintf(
            '/usr/local/bin/yt-dlp -f "bestvideo[ext=mp4][height<=720]+bestaudio[ext=m4a]/best" --merge-output-format mp4 -o %s %s 2>&1',
            escapeshellarg($outputPath),
            escapeshellarg("https://www.youtube.com/watch?v={$videoId}")
        );

        // Log the command for debugging
        Log::info('Executing YouTube download command', [
            'command' => $command
        ]);

        // Execute command
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        // Log the output for debugging
        Log::info('YouTube download output', [
            'output' => $output,
            'return_var' => $returnVar
        ]);

        if ($returnVar !== 0) {
            Log::error('YouTube download failed', [
                'command' => $command,
                'output' => $output,
                'return_var' => $returnVar
            ]);
            throw new Exception(implode("\n", $output));
        }

        if (!file_exists($outputPath)) {
            throw new Exception("Downloaded file not found: $outputPath");
        }

        // Log success
        Log::info('YouTube download completed', [
            'output_path' => $outputPath,
            'file_size' => filesize($outputPath)
        ]);

        return $outputPath;

    } catch (Exception $e) {
        Log::error('YouTube download failed', [
            'error' => $e->getMessage(),
            'video_id' => $videoId,
            'trace' => $e->getTraceAsString()
        ]);
        throw new Exception("Failed to download YouTube video: " . $e->getMessage());
    }
}
    /**
     * Get transcript by ID
     */
    public function show($id)
    {
        try {
            $transcript = VideoTranscript::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $transcript->id,
                    'video_id' => $transcript->video_id,
                    'source_type' => $transcript->source_type,
                    'language' => $transcript->language,
                    'transcript' => $transcript->transcript,
                    'video_url' => Storage::url($transcript->processed_video_path),
                    'metadata' => $transcript->metadata,
                    'created_at' => $transcript->created_at
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Transcript not found'
            ], 404);
        }
    }

    /**
     * Delete transcript
     */
    public function destroy($id)
    {
        try {
            $transcript = VideoTranscript::findOrFail($id);
            
            // Delete processed video file
            if ($transcript->processed_video_path) {
                Storage::delete($transcript->processed_video_path);
            }

            $transcript->delete();

            return response()->json([
                'success' => true,
                'message' => 'Transcript deleted successfully'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete transcript'
            ], 500);
        }
    }

    
}
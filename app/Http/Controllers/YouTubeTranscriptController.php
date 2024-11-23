<?php

namespace App\Http\Controllers;

use App\Services\YouTubeTranscriptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class YouTubeTranscriptController extends Controller
{
    protected $transcriptService;

    public function __construct(YouTubeTranscriptService $transcriptService)
    {
        $this->transcriptService = $transcriptService;
    }

    /**
     * Extract transcript from YouTube URL or video file
     */
    public function extract(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source_type' => 'required|in:url,file',
            'youtube_url' => 'required_if:source_type,url|url',
            'video_file' => 'required_if:source_type,file|file|mimes:mp4,mov,avi,wmv|max:100000',
            'language' => 'nullable|string|min:2|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $sourceType = $request->input('source_type');
            $language = $request->input('language', 'en');

            if ($sourceType === 'url') {
                return $this->handleYouTubeUrl($request->youtube_url, $language);
            } else {
                return $this->handleVideoFile($request->file('video_file'), $language);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to extract transcript',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle YouTube URL transcript extraction
     */
    private function handleYouTubeUrl($url, $language)
    {
        $videoId = $this->extractVideoId($url);
            
        if (!$videoId) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid YouTube URL'
            ], 422);
        }

        $transcript = $this->transcriptService->getTranscript($videoId, $language);

        return response()->json([
            'success' => true,
            'data' => [
                'source_type' => 'youtube',
                'video_id' => $videoId,
                'transcript' => $transcript
            ]
        ]);
    }

    /**
     * Handle video file transcript extraction
     */
    private function handleVideoFile($file, $language)
    {
        // Generate unique identifier for the video
        $videoId = Str::uuid()->toString();
        
        // Store the file
        $path = $file->store('videos');

        try {
            $transcript = $this->transcriptService->getTranscriptFromFile(
                Storage::path($path),
                $videoId,
                $language
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'source_type' => 'file',
                    'video_id' => $videoId,
                    'transcript' => $transcript
                ]
            ]);

        } finally {
            // Clean up the stored file
            Storage::delete($path);
        }
    }

    /**
     * Get stored transcript by ID
     */
    public function show($videoId)
    {
        try {
            $transcript = $this->transcriptService->getStoredTranscript($videoId);
            
            if (!$transcript) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transcript not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $transcript
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transcript',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get transcript extraction status
     */
    public function status($videoId)
    {
        try {
            $status = $this->transcriptService->getTranscriptStatus($videoId);
            
            return response()->json([
                'success' => true,
                'data' => $status
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a transcript
     */
    public function destroy($videoId)
    {
        try {
            $this->transcriptService->deleteTranscript($videoId);
            
            return response()->json([
                'success' => true,
                'message' => 'Transcript deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete transcript',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk extract transcripts
     */
    public function bulkExtract(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'youtube_urls' => 'required|array|min:1',
            'youtube_urls.*' => 'required|url',
            'language' => 'nullable|string|min:2|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $results = [];
            $language = $request->input('language', 'en');

            foreach ($request->youtube_urls as $url) {
                $videoId = $this->extractVideoId($url);
                
                if ($videoId) {
                    $results[] = [
                        'url' => $url,
                        'video_id' => $videoId,
                        'transcript' => $this->transcriptService->getTranscript($videoId, $language)
                    ];
                } else {
                    $results[] = [
                        'url' => $url,
                        'error' => 'Invalid YouTube URL'
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $results
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process bulk extraction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extract video ID from YouTube URL
     */
    private function extractVideoId($url)
    {
        $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i';
        
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
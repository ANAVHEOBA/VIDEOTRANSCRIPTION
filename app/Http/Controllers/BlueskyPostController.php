<?php

namespace App\Http\Controllers;

use App\Services\BlueskyPostService;
use App\Http\Requests\CreatePostRequest;
use Illuminate\Support\Facades\Log;
use App\Models\BlueskyAuth;

class BlueskyPostController extends Controller
{
    private $postService;

    public function __construct(BlueskyPostService $postService)
    {
        $this->postService = $postService;
    }

    /**
     * Create a new post with media
     */
    public function create(CreatePostRequest $request)
    {
        try {
            $blueskyAuth = BlueskyAuth::where('user_id', auth()->id())->firstOrFail();

            // Check if token needs refresh
            if ($blueskyAuth->token_expires_at <= now()) {
                return response()->json(['error' => 'Token expired'], 401);
            }

            $result = $this->postService->createPost([
                'text' => $request->text,
                'media_urls' => $request->media_urls,
                'access_token' => decrypt($blueskyAuth->access_token),
                'dpop_private_key' => decrypt($blueskyAuth->dpop_private_key),
                'did' => $blueskyAuth->did
            ]);

            return response()->json([
                'success' => true,
                'post_uri' => $result['uri']
            ]);

        } catch (\Exception $e) {
            Log::error('Post creation failed: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to create post'], 500);
        }
    }

    /**
     * Upload media files for a post
     */
    public function uploadMedia(Request $request)
    {
        try {
            $request->validate([
                'media' => 'required|array',
                'media.*' => 'required|file|mimes:jpeg,png,jpg,gif,mp4,mov|max:10240' // 10MB max
            ]);

            $blueskyAuth = BlueskyAuth::where('user_id', auth()->id())->firstOrFail();

            $uploadedUrls = [];
            foreach ($request->file('media') as $mediaFile) {
                $result = $this->postService->uploadMedia([
                    'file' => $mediaFile,
                    'access_token' => decrypt($blueskyAuth->access_token),
                    'dpop_private_key' => decrypt($blueskyAuth->dpop_private_key),
                    'did' => $blueskyAuth->did
                ]);
                
                $uploadedUrls[] = $result['url'];
            }

            return response()->json([
                'success' => true,
                'media_urls' => $uploadedUrls
            ]);

        } catch (\Exception $e) {
            Log::error('Media upload failed: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to upload media'], 500);
        }
    }
}
<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountDepositController;
use App\Http\Controllers\AccountWithdrawalController;
use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\YouTubeTranscriptController;
use App\Http\Controllers\BlueskyAuthController;
use App\Http\Controllers\BlueskyPostController;
use App\Http\Controllers\VideoTranscriptController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
//Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//    return $request->user();
//});


Route::prefix('auth')->group(function () {
//    dd(\request()->isProduction());
    Route::post('register', [AuthenticationController::class, 'register']);
    Route::post('login', [AuthenticationController::class, 'login']);
    Route::middleware("auth:sanctum")->group(function () {
        Route::get("user", [AuthenticationController::class, 'user']);
        Route::get('logout', [AuthenticationController::class, 'logout']);
    });
});

Route::middleware("auth:sanctum")->group(function () {
    Route::prefix('onboarding')->group(function () {
        Route::post('setup/pin', [PinController::class, 'setupPin']);
        Route::middleware('has.set.pin')->group(function () {
            Route::post('validate/pin', [PinController::class, 'validatePin']);
            Route::post('generate/account-number', [AccountController::class, 'store']);
        });
    });

    Route::middleware('has.set.pin')->group(function () {
        Route::prefix('account')->group(function () {
            Route::post('deposit', [AccountDepositController::class, 'store']);
            Route::post('withdraw', [AccountWithdrawalController::class, 'store']);
            Route::post('transfer', [TransferController::class, 'store']);
        });
        Route::prefix('transactions')->group(function () {
            Route::get('history', [TransactionController::class, 'index']);
        });
    });


});


// YouTube Transcript Routes
Route::prefix('v1')->group(function () {
    Route::prefix('transcripts')->group(function () {
        // Extract transcript from YouTube URL
        Route::post('/extract', [YouTubeTranscriptController::class, 'extract'])
            ->name('transcripts.extract');

        // Get stored transcript by video ID
        Route::get('/{videoId}', [YouTubeTranscriptController::class, 'show'])
            ->name('transcripts.show');

        // Get transcript status
        Route::get('/{videoId}/status', [YouTubeTranscriptController::class, 'status'])
            ->name('transcripts.status');

        // Delete transcript
        Route::delete('/{videoId}', [YouTubeTranscriptController::class, 'destroy'])
            ->name('transcripts.destroy');

        // Bulk extract transcripts
        Route::post('/bulk-extract', [YouTubeTranscriptController::class, 'bulkExtract'])
            ->name('transcripts.bulk-extract');
    });
});


Route::prefix('bluesky')->middleware(['auth:sanctum'])->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | Authentication Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('auth')->group(function () {
        // Initialize OAuth flow
        Route::post('initialize', [BlueskyAuthController::class, 'initialize']);
        
        // OAuth callback
        Route::get('callback', [BlueskyAuthController::class, 'callback']);
        
        // Get current auth status
        Route::get('status', [BlueskyAuthController::class, 'status']);
        
        // Refresh token
        Route::post('refresh', [BlueskyAuthController::class, 'refresh']);
        
        // Revoke access
        Route::post('revoke', [BlueskyAuthController::class, 'revoke']);
    });

     /*
    |--------------------------------------------------------------------------
    | Post Management Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('posts')->middleware(['bluesky.auth'])->group(function () {
        // Create new post
        Route::post('/', [BlueskyPostController::class, 'create']);
        
        // Delete post
        Route::delete('{uri}', [BlueskyPostController::class, 'delete']);
        
        // Get user's posts
        Route::get('/', [BlueskyPostController::class, 'index']);
        
        // Get single post
        Route::get('{uri}', [BlueskyPostController::class, 'show']);
    });

    /*
    |--------------------------------------------------------------------------
    | Media Upload Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('media')->middleware(['bluesky.auth'])->group(function () {
        // Upload media
        Route::post('upload', [BlueskyPostController::class, 'uploadMedia']);
        
        // Get upload status
        Route::get('status/{id}', [BlueskyPostController::class, 'mediaStatus']);
    });


     /*
    |--------------------------------------------------------------------------
    | Profile Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('profile')->middleware(['bluesky.auth'])->group(function () {
        // Get profile info
        Route::get('/', [BlueskyPostController::class, 'getProfile']);
        
        // Update profile
        Route::put('/', [BlueskyPostController::class, 'updateProfile']);


    });


    /*
    |--------------------------------------------------------------------------
    | Utility Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('utils')->middleware(['bluesky.auth'])->group(function () {
        // Check rate limits
        Route::get('rate-limits', [BlueskyPostController::class, 'getRateLimits']);
        
        // Resolve handles
        Route::post('resolve-handle', [BlueskyPostController::class, 'resolveHandle']);
    });
});


Route::prefix('v1')->group(function () {
    // Video Transcript Routes
    Route::prefix('transcripts')->group(function () {
        // Process videos
        Route::post('/youtube', [VideoTranscriptController::class, 'processYouTube'])
            ->name('transcripts.youtube');
        Route::post('/local', [VideoTranscriptController::class, 'processLocal'])
            ->name('transcripts.local');
        
        // Get transcript
        Route::get('/{id}', [VideoTranscriptController::class, 'show'])
            ->name('transcripts.show');
        
        // Delete transcript
        Route::delete('/{id}', [VideoTranscriptController::class, 'destroy'])
            ->name('transcripts.destroy');
        
        // List transcripts with filters
        Route::get('/', [VideoTranscriptController::class, 'index'])
            ->name('transcripts.index');
        
        // Update subtitle style
        Route::patch('/{id}/style', [VideoTranscriptController::class, 'updateStyle'])
            ->name('transcripts.update-style');


            // Regenerate video with new style
        Route::post('/{id}/regenerate', [VideoTranscriptController::class, 'regenerateVideo'])
        ->name('transcripts.regenerate');
    
    // Get transcript text only
    Route::get('/{id}/text', [VideoTranscriptController::class, 'getText'])
        ->name('transcripts.get-text');
    
    // Download SRT file
    Route::get('/{id}/srt', [VideoTranscriptController::class, 'downloadSrt'])
        ->name('transcripts.download-srt');
    
    // Get processing status
    Route::get('/{id}/status', [VideoTranscriptController::class, 'getStatus'])
        ->name('transcripts.status');
    
    // Cancel processing
    Route::post('/{id}/cancel', [VideoTranscriptController::class, 'cancelProcessing'])
        ->name('transcripts.cancel');
});
});
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_transcripts', function (Blueprint $table) {
            $table->id();
            $table->string('video_id')->nullable()->index(); // Made nullable for local videos
            $table->enum('source_type', ['youtube', 'local'])->default('youtube');
            $table->string('title')->nullable();
            $table->json('transcript')->nullable();
            $table->string('language')->default('en');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending')
                  ->index();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->float('duration')->nullable();
            $table->integer('word_count')->nullable();
            $table->string('video_path')->nullable(); // Changed from processed_video_path
            $table->string('thumbnail_path')->nullable();
            $table->string('processed_by')->nullable();
            $table->json('subtitle_style')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['video_id', 'language']);
            $table->index(['created_at', 'status']);
            $table->index('source_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_transcripts');
    }
};
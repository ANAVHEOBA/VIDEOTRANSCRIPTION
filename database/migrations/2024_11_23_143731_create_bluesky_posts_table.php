<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bluesky_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->foreignId('bluesky_auth_id')
                  ->constrained('bluesky_auths')
                  ->onDelete('cascade');
            $table->string('post_uri')
                  ->unique()
                  ->comment('Bluesky post URI identifier');
            $table->text('content')
                  ->comment('Post text content');
            $table->json('media')
                  ->nullable()
                  ->comment('Array of media items with URLs and metadata');
            $table->timestamp('posted_at')
                  ->comment('When the post was created on Bluesky');
            $table->timestamps();

            // Indexes
            $table->index('posted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bluesky_posts');
    }
};
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
        Schema::create('bluesky_auths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->string('did')
                  ->unique()
                  ->comment('Decentralized identifier for the Bluesky account');
            $table->string('handle')
                  ->nullable()
                  ->comment('Bluesky handle (username)');
            $table->text('access_token')
                  ->comment('Encrypted OAuth access token');
            $table->text('refresh_token')
                  ->comment('Encrypted OAuth refresh token');
            $table->text('dpop_private_key')
                  ->comment('Encrypted DPoP private key');
            $table->timestamp('token_expires_at')
                  ->comment('When the current access token expires');
            $table->timestamp('last_post_at')
                  ->nullable()
                  ->comment('When the last post was made');
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether this connection is currently active');
            $table->timestamps();

            // Indexes
            $table->index('token_expires_at');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bluesky_auths');
    }
};
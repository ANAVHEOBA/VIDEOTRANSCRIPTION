<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class BlueskyAuth extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'did',
        'handle',
        'access_token',
        'refresh_token',
        'dpop_private_key',
        'token_expires_at',
        'last_post_at',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_post_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
        'dpop_private_key',
    ];

    /**
     * Get the user that owns the Bluesky auth.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the access token has expired
     */
    public function hasExpiredToken(): bool
    {
        return $this->token_expires_at->isPast();
    }

    /**
     * Check if token needs refresh (within 5 minutes of expiration)
     */
    public function needsTokenRefresh(): bool
    {
        return $this->token_expires_at->subMinutes(5)->isPast();
    }

    /**
     * Update token information
     */
    public function updateTokens(array $tokens): void
    {
        $this->update([
            'access_token' => encrypt($tokens['access_token']),
            'refresh_token' => encrypt($tokens['refresh_token']),
            'token_expires_at' => Carbon::now()->addSeconds(3600), // 1 hour
        ]);
    }

    /**
     * Get decrypted access token
     */
    public function getAccessToken(): string
    {
        return decrypt($this->access_token);
    }

    /**
     * Get decrypted refresh token
     */
    public function getRefreshToken(): string
    {
        return decrypt($this->refresh_token);
    }

    /**
     * Get decrypted DPoP private key
     */
    public function getDPoPPrivateKey(): string
    {
        return decrypt($this->dpop_private_key);
    }

    /**
     * Update last post timestamp
     */
    public function updateLastPostTime(): void
    {
        $this->update(['last_post_at' => Carbon::now()]);
    }

    /**
     * Scope a query to only include active connections
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include connections needing token refresh
     */
    public function scopeNeedsRefresh($query)
    {
        return $query->where('token_expires_at', '<=', Carbon::now()->addMinutes(5));
    }
}
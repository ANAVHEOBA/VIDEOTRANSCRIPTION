<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class VideoTranscript extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'video_transcripts';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'video_id',
        'source_type',
        'title',
        'transcript',
        'language',
        'status',
        'error_message',
        'metadata',
        'duration',
        'word_count',
        'processed_by'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'transcript' => 'array',
        'metadata' => 'array',
        'duration' => 'float',
        'word_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * The model's default values for attributes.
     */
    protected $attributes = [
        'status' => 'pending',
        'source_type' => 'youtube',
        'language' => 'en'
    ];

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /**
     * Source type constants
     */
    const SOURCE_YOUTUBE = 'youtube';
    const SOURCE_LOCAL = 'local';

    /**
     * Scope a query to only include successful transcripts.
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include failed transcripts.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to only include pending transcripts.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include processing transcripts.
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope a query to only include YouTube transcripts.
     */
    public function scopeYoutube(Builder $query): Builder
    {
        return $query->where('source_type', self::SOURCE_YOUTUBE);
    }

    /**
     * Scope a query to only include local file transcripts.
     */
    public function scopeLocal(Builder $query): Builder
    {
        return $query->where('source_type', self::SOURCE_LOCAL);
    }

    /**
     * Check if the transcript was successfully extracted
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the transcript failed to extract
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the transcript is pending extraction
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the transcript is being processed
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Get formatted transcript text
     */
    public function getFormattedTranscriptAttribute(): string
    {
        if (!$this->transcript || !is_array($this->transcript)) {
            return '';
        }

        return collect($this->transcript)
            ->pluck('text')
            ->filter()
            ->join(' ');
    }

    /**
     * Mark transcript as failed
     */
    public function markAsFailed(string $errorMessage = null): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage
        ]);
    }

    /**
     * Mark transcript as completed
     */
    public function markAsCompleted(array $data = []): bool
    {
        return $this->update(array_merge([
            'status' => self::STATUS_COMPLETED,
            'error_message' => null
        ], $data));
    }

    /**
     * Mark transcript as processing
     */
    public function markAsProcessing(): bool
    {
        return $this->update([
            'status' => self::STATUS_PROCESSING,
            'error_message' => null
        ]);
    }
}
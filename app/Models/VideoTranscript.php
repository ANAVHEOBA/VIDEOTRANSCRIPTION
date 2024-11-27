<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class VideoTranscript extends Model
{
    use HasFactory, SoftDeletes;

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
        'video_path', // Changed from processed_video_path
        'thumbnail_path',
        'processed_by',
        'subtitle_style',
        'processing_started_at',
        'processing_completed_at'
    ];

    protected $casts = [
        'transcript' => 'array',
        'metadata' => 'array',
        'subtitle_style' => 'array',
        'duration' => 'float',
        'word_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime'
    ];

    protected $attributes = [
        'status' => 'pending',
        'language' => 'en',
        'source_type' => 'youtube'
    ];

    protected $appends = ['video_url', 'thumbnail_url'];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    // Source type constants
    const SOURCE_YOUTUBE = 'youtube';
    const SOURCE_LOCAL = 'local';

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeYoutube($query)
    {
        return $query->where('source_type', self::SOURCE_YOUTUBE);
    }

    public function scopeLocal($query)
    {
        return $query->where('source_type', self::SOURCE_LOCAL);
    }

    // Accessors
    public function getVideoUrlAttribute()
    {
        return $this->processed_video_path 
            ? Storage::url($this->processed_video_path)
            : null;
    }

    public function getThumbnailUrlAttribute()
    {
        return $this->thumbnail_path 
            ? Storage::url($this->thumbnail_path)
            : null;
    }

    // Helper methods
    public function markAsProcessing()
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'processing_started_at' => now()
        ]);
    }

    public function markAsCompleted()
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'processing_completed_at' => now()
        ]);
    }

    public function markAsFailed($error)
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
            'processing_completed_at' => now()
        ]);
    }

    public function getDuration()
    {
        return $this->duration ?? 0;
    }

    public function getWordCount()
    {
        return $this->word_count ?? 0;
    }

    public function getProcessingDuration()
    {
        if ($this->processing_started_at && $this->processing_completed_at) {
            return $this->processing_completed_at->diffInSeconds($this->processing_started_at);
        }
        return null;
    }
}
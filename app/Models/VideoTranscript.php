<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class VideoTranscript extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'video_id',
        'transcript',
        'language',
        'status',
        'error_message',
        'metadata'
    ];

    protected $casts = [
        'transcript' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    protected $attributes = [
        'status' => 'pending',
        'language' => 'en'
    ];
}
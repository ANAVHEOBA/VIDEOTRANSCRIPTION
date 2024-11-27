<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],


    'ffmpeg' => [
        'path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
        'ffprobe' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
        'threads' => env('FFMPEG_THREADS', 2),
        'timeout' => env('FFMPEG_TIMEOUT', 3600),
    ],

    'video' => [
        'max_size' => env('VIDEO_MAX_SIZE', 100000000),
        'allowed_mimes' => explode(',', env('VIDEO_ALLOWED_MIMES', 'mp4,mov,avi')),
        'output_format' => env('VIDEO_OUTPUT_FORMAT', 'mp4'),
        'max_duration' => env('VIDEO_MAX_DURATION', 3600),
        'disk' => env('VIDEO_DISK', 'public'),
        'temp_path' => env('VIDEO_TEMP_PATH', 'temp/videos'),
        'output_path' => env('VIDEO_OUTPUT_PATH', 'processed/videos'),
        'thumbnail_path' => env('VIDEO_THUMBNAIL_PATH', 'thumbnails'),
    ],

];

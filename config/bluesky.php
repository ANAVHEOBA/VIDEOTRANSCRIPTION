<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bluesky Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration settings for interacting with the
    | Bluesky API using OAuth authentication.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Client Configuration
    |--------------------------------------------------------------------------
    */
    'client' => [
        // Your client ID (URL to metadata)
        'id' => env('BLUESKY_CLIENT_ID', 'https://your-app.com/oauth/client-metadata.json'),
        
        // Application type: 'web' or 'native'
        'type' => env('BLUESKY_CLIENT_TYPE', 'web'),
        
        // Client name displayed during authorization
        'name' => env('BLUESKY_CLIENT_NAME', 'Your App Name'),
        
        // Client homepage URL
        'uri' => env('BLUESKY_CLIENT_URI', 'https://your-app.com'),
        
        // OAuth redirect URI
        'redirect_uri' => env('BLUESKY_REDIRECT_URI', 'https://your-app.com/auth/bluesky/callback'),
        
        // Client logo URL shown during authorization
        'logo_uri' => env('BLUESKY_LOGO_URI', 'https://your-app.com/logo.png'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth Scopes
    |--------------------------------------------------------------------------
    */
    'scopes' => [
        'atproto',         // Required base scope
        'email',           // Optional: access to email
        'publish',         // Optional: ability to publish posts
        'media',           // Optional: ability to upload media
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Configuration
    |--------------------------------------------------------------------------
    */
    'tokens' => [
        // Access token lifetime in seconds
        'expires_in' => env('BLUESKY_TOKEN_EXPIRES', 3600),
        
        // Buffer time in seconds before token expiration to trigger refresh
        'refresh_buffer' => env('BLUESKY_REFRESH_BUFFER', 300),
        
        // Maximum attempts for token refresh
        'max_refresh_attempts' => env('BLUESKY_MAX_REFRESH_ATTEMPTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Endpoints
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        // Base API URL
        'base' => env('BLUESKY_API_URL', 'https://bsky.social'),
        
        // Authorization endpoints
        'auth' => [
            'metadata' => '/.well-known/oauth-authorization-server',
            'token' => '/xrpc/com.atproto.server.createSession',
            'refresh' => '/xrpc/com.atproto.server.refreshSession',
        ],
        
        // Post creation endpoints
        'post' => [
            'create' => '/xrpc/com.atproto.repo.createRecord',
            'delete' => '/xrpc/com.atproto.repo.deleteRecord',
        ],
        
        // Media upload endpoints
        'media' => [
            'upload' => '/xrpc/com.atproto.repo.uploadBlob',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Configuration
    |--------------------------------------------------------------------------
    */
    'media' => [
        // Maximum file size in bytes (default: 10MB)
        'max_size' => env('BLUESKY_MEDIA_MAX_SIZE', 10 * 1024 * 1024),
        
        // Allowed image types
        'allowed_image_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
        ],
        
        // Allowed video types
        'allowed_video_types' => [
            'video/mp4',
            'video/quicktime',
        ],
        
        // Maximum dimensions for images
        'max_image_dimensions' => [
            'width' => 4096,
            'height' => 4096,
        ],
        
        // Maximum video duration in seconds
        'max_video_duration' => 300, // 5 minutes
        
        // Maximum number of media items per post
        'max_items_per_post' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        // Maximum posts per user per hour
        'posts_per_hour' => env('BLUESKY_POSTS_PER_HOUR', 50),
        
        // Maximum media uploads per user per hour
        'uploads_per_hour' => env('BLUESKY_UPLOADS_PER_HOUR', 100),
        
        // Delay between API requests in milliseconds
        'request_delay' => env('BLUESKY_REQUEST_DELAY', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    */
    'security' => [
        // Enable SSL verification
        'verify_ssl' => env('BLUESKY_VERIFY_SSL', true),
        
        // Request timeout in seconds
        'timeout' => env('BLUESKY_REQUEST_TIMEOUT', 30),
        
        // Enable debug mode for detailed logging
        'debug' => env('BLUESKY_DEBUG', false),
        
        // PKCE method (S256 is required)
        'pkce_method' => 'S256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        // Enable caching of API responses
        'enabled' => env('BLUESKY_CACHE_ENABLED', true),
        
        // Cache prefix
        'prefix' => 'bluesky_',
        
        // Default cache duration in seconds
        'duration' => env('BLUESKY_CACHE_DURATION', 3600),
        
        // Cache store to use
        'store' => env('BLUESKY_CACHE_STORE', 'redis'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */
    'logging' => [
        // Enable detailed logging
        'enabled' => env('BLUESKY_LOGGING_ENABLED', true),
        
        // Log channel to use
        'channel' => env('BLUESKY_LOG_CHANNEL', 'bluesky'),
        
        // Events to log
        'events' => [
            'auth' => true,      // Authentication events
            'posts' => true,     // Post creation events
            'media' => true,     // Media upload events
            'errors' => true,    // Error events
        ],
    ],
];
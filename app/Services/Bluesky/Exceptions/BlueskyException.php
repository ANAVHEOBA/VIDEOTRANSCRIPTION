<?php

namespace App\Services\Bluesky\Exceptions;

use Exception;

class BlueskyException extends Exception
{
    protected $data;

    public function __construct(string $message = '', int $code = 0, array $data = [])
    {
        $this->data = $data;
        parent::__construct($message, $code);
    }

    /**
     * Get additional error data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Create an authentication error
     */
    public static function authError(string $message, array $data = []): self
    {
        return new self('Authentication error: ' . $message, 401, $data);
    }

    /**
     * Create a rate limit error
     */
    public static function rateLimitError(string $message, array $data = []): self
    {
        return new self('Rate limit exceeded: ' . $message, 429, $data);
    }

    /**
     * Create a validation error
     */
    public static function validationError(string $message, array $data = []): self
    {
        return new self('Validation error: ' . $message, 422, $data);
    }

    /**
     * Create an API error
     */
    public static function apiError(string $message, int $code = 500, array $data = []): self
    {
        return new self('API error: ' . $message, $code, $data);
    }
}
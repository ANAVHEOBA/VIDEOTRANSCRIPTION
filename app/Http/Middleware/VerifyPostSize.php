<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidatePostSize as Middleware;

class VerifyPostSize extends Middleware
{
    protected $max = 100 * 1024 * 1024; // 100 MB
}
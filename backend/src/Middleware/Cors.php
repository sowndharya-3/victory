<?php

namespace App\Middleware;

class Cors
{
    public static function handle(): void
    {
        $origin = getenv('FRONTEND_ORIGIN') ?: '';
        if ($origin !== '') {
            header("Access-Control-Allow-Origin: {$origin}");
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Vary: Origin');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}

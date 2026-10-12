<?php

require_once __DIR__ . '/../config/env.php';
loadEnv(__DIR__ . '/../.env');

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

header('Content-Type: application/json');

try {
    \App\Database\Db::connection();
    echo json_encode(['status' => 'db-connected']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'db-connection-failed']);
}

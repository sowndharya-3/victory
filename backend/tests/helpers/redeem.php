<?php
// Child process for the concurrent redeem test: php redeem.php <userId> <code>
require_once __DIR__ . '/../../config/env.php';
loadEnv(__DIR__ . '/../../.env');
spl_autoload_register(function (string $c) {
    if (str_starts_with($c, 'App\\')) {
        $p = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 4)) . '.php';
        if (file_exists($p)) {
            require $p;
        }
    }
});
try {
    \App\Codes\AccessCodeService::redeem((int) $argv[1], $argv[2]);
    echo 'ok';
} catch (\App\Http\ApiException $e) {
    echo $e->errorCode;
}

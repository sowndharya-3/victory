<?php

namespace App\Middleware;

use App\Http\ApiException;
use Throwable;

class ErrorHandler
{
    public static function wrap(callable $fn): void
    {
        try {
            $fn();
        } catch (ApiException $e) {
            http_response_code($e->statusCode);
            $body = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
            if ($e->fields) {
                $body['error']['fields'] = $e->fields;
            }
            echo json_encode($body);
        } catch (Throwable $e) {
            // Class + message only: never the request body, payload or secrets.
            error_log(get_class($e) . ': ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => ['code' => 'server_error', 'message' => 'Something went wrong']]);
        }
    }
}

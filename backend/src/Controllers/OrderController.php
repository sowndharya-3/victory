<?php

namespace App\Controllers;

use App\Http\ApiException;
use App\Middleware\AuthMiddleware;
use App\Orders\OrderService;
use App\Razorpay\GatewayFactory;

class OrderController
{
    public function create(): void
    {
        $payload = AuthMiddleware::requireRole(['customer']);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new ApiException('Invalid JSON body', 400, 'validation_error');
        }
        $result = (new OrderService(GatewayFactory::make()))->create((int) $payload['sub'], $data);
        http_response_code(201);
        echo json_encode($result);
    }
}

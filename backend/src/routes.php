<?php

use App\Controllers\HealthController;
use App\Controllers\AuthController;
use App\Controllers\AdminController;
use App\Controllers\MeController;

$router->get('/api/health', [new HealthController(), 'check']);
$router->post('/api/auth/register', [new AuthController(), 'register']);
$router->post('/api/auth/login', [new AuthController(), 'login']);
$router->post('/api/admin/login', [new AdminController(), 'login']);
$router->get('/api/me', [new MeController(), 'show']);

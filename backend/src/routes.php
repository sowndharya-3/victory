<?php

use App\Controllers\HealthController;
use App\Controllers\AuthController;
use App\Controllers\AdminController;
use App\Controllers\MeController;
use App\Controllers\BookController;

$router->get('/api/health', [new HealthController(), 'check']);
$router->post('/api/auth/register', [new AuthController(), 'register']);
$router->post('/api/auth/login', [new AuthController(), 'login']);
$router->post('/api/admin/login', [new AdminController(), 'login']);
$router->get('/api/me', [new MeController(), 'show']);
$router->get('/api/books', [new BookController(), 'list']);
$router->get('/api/books/{id}', [new BookController(), 'show']);

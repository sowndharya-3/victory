<?php

use App\Controllers\HealthController;
use App\Controllers\AuthController;
use App\Controllers\AdminController;
use App\Controllers\MeController;
use App\Controllers\BookController;
use App\Controllers\AdminBookController;
use App\Controllers\CheckoutController;
use App\Controllers\ReferralController;

$router->get('/api/health', [new HealthController(), 'check']);
$router->post('/api/auth/register', [new AuthController(), 'register']);
$router->post('/api/auth/login', [new AuthController(), 'login']);
$router->post('/api/admin/login', [new AdminController(), 'login']);
$router->get('/api/me', [new MeController(), 'show']);
$router->get('/api/books', [new BookController(), 'list']);
$router->get('/api/books/{id}', [new BookController(), 'show']);

$adminBooks = new AdminBookController();
$router->get('/api/admin/books', [$adminBooks, 'list']);
$router->get('/api/admin/books/{id}', [$adminBooks, 'show']);
$router->post('/api/admin/books', [$adminBooks, 'create']);
$router->put('/api/admin/books/{id}', [$adminBooks, 'update']);
$router->post('/api/admin/books/{id}/publish', [$adminBooks, 'publish']);
$router->post('/api/admin/books/{id}/unpublish', [$adminBooks, 'unpublish']);
$router->post('/api/admin/books/{id}/archive', [$adminBooks, 'archive']);
$router->post('/api/admin/books/{id}/cover', [$adminBooks, 'uploadCover']);
$router->post('/api/admin/books/{id}/index', [$adminBooks, 'uploadIndex']);
$router->post('/api/admin/books/{id}/preview-pages/{pageNumber}', [$adminBooks, 'uploadPreviewPage']);
$router->delete('/api/admin/books/{id}/preview-pages/{pageNumber}', [$adminBooks, 'deletePreviewPage']);
$router->post('/api/admin/books/{id}/pdf', [$adminBooks, 'uploadPdf']);

$router->get('/api/checkout/quote', [new CheckoutController(), 'quote']);
$router->get('/api/referral/validate/{code}', [new ReferralController(), 'validate']);

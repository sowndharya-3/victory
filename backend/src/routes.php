<?php

use App\Controllers\HealthController;
use App\Controllers\AuthController;
use App\Controllers\AdminController;
use App\Controllers\MeController;
use App\Controllers\BookController;
use App\Controllers\AdminBookController;
use App\Controllers\CheckoutController;
use App\Controllers\ReferralController;
use App\Controllers\OrderController;
use App\Controllers\WebhookController;
use App\Controllers\AccessCodeController;
use App\Controllers\TrackController;
use App\Controllers\RefundController;
use App\Controllers\AdminRefundController;
use App\Controllers\ReaderController;

$router->get('/api/health', [new HealthController(), 'check']);
$router->post('/api/auth/register', [new AuthController(), 'register']);
$router->post('/api/auth/login', [new AuthController(), 'login']);
$router->post('/api/admin/login', [new AdminController(), 'login']);
$router->get('/api/me', [new MeController(), 'show']);
$router->get('/api/books', [new BookController(), 'list']);
$router->get('/api/books/{id}', [new BookController(), 'show']);
$reader = new ReaderController(); // customer + non-revoked book_access; the PDF itself has no route
$router->get('/api/books/{id}/access', [$reader, 'access']);
$router->get('/api/books/{id}/read/pages/{n}', [$reader, 'page']);

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
$router->post('/api/orders', [new OrderController(), 'create']);
$router->post('/api/orders/{id}/verify', [new OrderController(), 'verify']);
$router->get('/api/me/orders', [new OrderController(), 'mine']);
$router->get('/api/me/orders/{id}', [new OrderController(), 'showMine']);
$router->post('/api/webhooks/razorpay', [new WebhookController(), 'razorpay']);

$codes = new AccessCodeController();
$router->get('/api/me/orders/{id}/access-code', [$codes, 'reveal']);
$router->post('/api/me/orders/{id}/reissue-code', [$codes, 'reissue']);
$router->post('/api/me/redeem', [$codes, 'redeem']);
$router->get('/api/me/library', [$codes, 'library']);
$router->get('/api/track/{code}', [new TrackController(), 'show']);

$refunds = new RefundController();
$router->post('/api/me/orders/{id}/refund-request', [$refunds, 'request']);
$router->get('/api/me/refunds', [$refunds, 'mine']);
$adminRefunds = new AdminRefundController();
$router->get('/api/admin/refunds', [$adminRefunds, 'list']);
$router->post('/api/admin/refunds/{id}/approve', [$adminRefunds, 'approve']);
$router->post('/api/admin/refunds/{id}/reject', [$adminRefunds, 'reject']);
$router->post('/api/admin/orders/{id}/refund', [$adminRefunds, 'open']);

<?php

namespace App\Controllers;

use App\Database\Db;
use App\Middleware\AuthMiddleware;

/**
 * Purchases, revenue and pending work count paid orders only. revenue is net (refunded orders leave 'paid');
 * successfulPayments also counts later-refunded orders; pendingOrders = paid hard copies not yet shipped.
 */
class AdminDashboardController
{
    public function show(): void
    {
        AuthMiddleware::requireRole(['admin']);
        $r = Db::connection()->query(
            "SELECT
               (SELECT COUNT(*) FROM books WHERE published = 1 AND archived = 0) AS published_books,
               (SELECT COUNT(*) FROM users) AS total_customers,
               (SELECT COUNT(*) FROM orders WHERE status = 'paid' AND purchase_type = 'digital') AS digital_purchases,
               (SELECT COUNT(*) FROM orders WHERE status = 'paid' AND purchase_type = 'hardcopy') AS hardcopy_orders,
               (SELECT COUNT(*) FROM orders WHERE status IN ('paid', 'refunded')) AS successful_payments,
               (SELECT COUNT(*) FROM orders WHERE status = 'paid' AND purchase_type = 'hardcopy' AND fulfilment_status IN ('confirmed', 'processing')) AS pending_orders,
               (SELECT COALESCE(SUM(amount), 0) FROM orders WHERE status = 'paid') AS revenue,
               (SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE status = 'refunded') AS refunded_amount,
               (SELECT COUNT(*) FROM refunds WHERE status IN ('requested', 'approved', 'processing')) AS pending_refunds,
               (SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE status = 'pending') AS commission_pending,
               (SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE status = 'payable') AS commission_payable"
        )->fetch();
        echo json_encode([
            'publishedBooks' => (int) $r['published_books'],
            'totalCustomers' => (int) $r['total_customers'],
            'digitalPurchases' => (int) $r['digital_purchases'],
            'hardcopyOrders' => (int) $r['hardcopy_orders'],
            'successfulPayments' => (int) $r['successful_payments'],
            'pendingOrders' => (int) $r['pending_orders'],
            'revenue' => (float) $r['revenue'],
            'refundedAmount' => (float) $r['refunded_amount'],
            'pendingRefunds' => (int) $r['pending_refunds'],
            'commissionPending' => (float) $r['commission_pending'],
            'commissionPayable' => (float) $r['commission_payable'],
        ]);
    }
}

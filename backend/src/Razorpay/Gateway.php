<?php

namespace App\Razorpay;

interface Gateway
{
    /** @return array{id:string} */
    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array;

    /** @return array{id:string,order_id:string,amount:int,currency:string,status:string,error_description?:string} */
    public function fetchPayment(string $paymentId): array;

    /** @return list<array> payment arrays */
    public function fetchOrderPayments(string $razorpayOrderId): array;
}

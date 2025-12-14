<?php

namespace App\PaymentGateways\Contract;

use App\Models\Gateway;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;

interface IPaymentGateway
{
    /**
     * Validate gateway config.
     *
     * @param array<string, mixed> $config
     */
    public static function checkConfig(array $config): bool;

    /**
     * Initialize a payment at the gateway and return the gateway payment id.
     */
    public function initPayment(Order $order, Gateway $gateway, Payment $payment): array;

    /**
     * Finalize/interpret a webhook payload and return normalized data.
     *
     * Expected return keys:
     * - gateway_payment_id: string
     * - status: string (e.g. pending|paid|failed|canceled)
     * - payment_date: ?string (Y-m-d H:i:s) optional
     * - notes: ?string optional
     *
     * @param Request $payload
     * @return array{gateway_payment_id:string,status:string,payment_date?:string|null,notes?:string|null}
     */
    public function finalizePayment(Gateway $gateway, Request $request): array;
}

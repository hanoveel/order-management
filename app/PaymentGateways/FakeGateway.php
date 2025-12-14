<?php

namespace App\PaymentGateways;

use App\Models\Gateway;
use App\Models\Order;
use App\Models\Payment;
use App\PaymentGateways\Contract\IPaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FakeGateway implements IPaymentGateway
{
    public static function checkConfig(array $config): bool
    {
        return true;
    }

    public function initPayment(Order $order, Gateway $gateway, Payment $payment): array
    {
        // In real gateways you would call their API and return their payment reference.
        return [
            'gateway_payment_id' => 'fake_' . Str::uuid()->toString(),
            'data' => []
        ];
    }

    public function finalizePayment(Gateway $gateway, Request $request): array
    {
        $payload = $request->all();

        $gatewayPaymentId = (string)($payload['payment_id'] ?? '');
        if ($gatewayPaymentId === '') {
            throw new \InvalidArgumentException('Missing gateway_payment_id');
        }

        $status = (string)($payload['status'] ?? 'pending');

        return [
            'gateway_payment_id' => $gatewayPaymentId,
            'status' => $status,
            'payment_date' => isset($payload['paid_at']) ? (string)$payload['paid_at'] : null,
            'notes' => isset($payload['notes']) ? (string)$payload['notes'] : null,
        ];
    }
}

<?php

namespace App\Http\Controllers\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Gateway;
use App\Models\Order;
use App\Models\Payment;
use App\PaymentGateways\Contract\IPaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * Process payment for a confirmed order (creates Payment + initPayment at gateway).
     */
    public function process(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'gateway_id' => ['required', 'integer', 'exists:gateways,id'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // NOTE: adjust this to your actual confirmed status representation.
        // If you use enums, replace 'confirmed' with OrderStatus::CONFIRMED->value
        if ($order->status != OrderStatus::CONFIRMED->value) {
            return response()->json([
                'message' => 'Order must be confirmed before processing payment.',
            ], 422);
        }

        $gateway = Gateway::query()->findOrFail((int)$data['gateway_id']);
        $gatewayDriver = $this->resolveGatewayDriver($gateway);

        $payment = DB::transaction(function () use ($order, $gateway, $gatewayDriver, $data): Payment {
            $payment = Payment::query()->create([
                'order_id' => $order->id,
                'gateway_id' => $gateway->id,
                'gateway_payment_id' => null,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_date' => now(),

                'status' => PaymentStatus::PENDING->value,
                'notes' => $data['notes'] ?? null,
            ]);

            $gatewayPaymentId = $gatewayDriver->initPayment($order, $gateway, $payment);

            $payment->update([
                'gateway_payment_id' => $gatewayPaymentId,
            ]);

            return $payment->fresh(['order', 'gateway']);
        });

        return response()->json([
            'message' => 'Payment initialized.',
            'data' => $payment,
        ], 201);
    }

    /**
     * Webhook endpoint for gateway updates (finalize payment).
     * Usually public (no auth), but you should protect it with signature verification per gateway.
     */
    public function webhook(Request $request, Gateway $gateway): JsonResponse
    {
        $gatewayDriver = $this->resolveGatewayDriver($gateway);
        $result = $gatewayDriver->finalizePayment($gateway, $request);

        $payment = Payment::query()
            ->where('gateway_id', $gateway->id)
            ->where('gateway_payment_id', $result['gateway_payment_id'])
            ->first();

        if (!$payment) {
            return response()->json([
                'message' => 'Payment not found for given gateway_payment_id.',
            ], 404);
        }

        $payment->update([
            'status' => $result['status'],
            'payment_date' => $result['payment_date'] ?? now()->format('Y-m-d H:i:s'),
            'notes' => $result['notes'] ?? $payment->notes,
        ]);

        return response()->json([
            'message' => 'Webhook processed.',
            'data' => $payment->fresh(['order', 'gateway']),
        ]);
    }

    /**
     * List payments (all or filtered by order_id) with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Payment::query()->with(['order', 'gateway'])->latest('id');

        if (isset($data['order_id'])) {
            $query->where('order_id', (int)$data['order_id']);
        }

        $payments = $query->paginate((int)($data['per_page'] ?? 15), ['*'], 'page', (int)($data['page'] ?? 1));

        return response()->json($payments);
    }

    /**
     * Resolve gateway driver implementation.
     *
     * IMPORTANT: adjust the mapping to how your Gateway model stores the driver type.
     * Common options are: $gateway->driver, $gateway->code, $gateway->name, etc.
     */
    private function resolveGatewayDriver(Gateway $gateway): IPaymentGateway
    {
        $class = 'App\\PaymentGateways\\' . $gateway->class_name;

        return app($class);
    }
}

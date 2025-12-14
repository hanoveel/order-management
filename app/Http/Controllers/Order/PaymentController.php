<?php

namespace App\Http\Controllers\Order;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\Payment\PaymentIndexRequest;
use App\Http\Requests\Order\Payment\PaymentProcessRequest;
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
    public function process(PaymentProcessRequest $request, Order $order): JsonResponse
    {
        $data = $request->validated();

        if ($order->payment()->exists()) {
            return response()->json([
                'message' => 'This order already has a payment.',
            ], 422);
        }

        $gateway = Gateway::query()->findOrFail((int)$data['gateway_id']);
        $gatewayDriver = $this->resolveGatewayDriver($gateway);

        $payment = DB::transaction(function () use ($order, $gateway, $gatewayDriver, $data): Payment {
            if ($order->payment()->exists()) {
                abort(422, 'This order already has a payment.');
            }

            $payment = Payment::query()->create([
                'order_id' => $order->id,
                'gateway_id' => $gateway->id,
                'gateway_payment_id' => null,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_date' => now(),

                'status' => PaymentStatus::PENDING->value,
                'notes' => $data['notes'] ?? null,
            ]);

            $result = $gatewayDriver->initPayment($order, $gateway, $payment);
            $gatewayPaymentId = $result['gateway_payment_id'];

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

    // *
    // * Webhook endpoint for gateway updates (finalize payment).
    // * Usually public (no auth), but you should protect it with signature verification per gateway.

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
    public function index(PaymentIndexRequest $request): JsonResponse
    {
        $data = $request->validated();

        $query = Payment::query()->with(['order', 'gateway'])->latest('id');

        if (isset($data['order_id'])) {
            $query->where('order_id', (int)$data['order_id']);
        }

        $payments = $query->paginate(
            (int)($data['per_page'] ?? 15),
            ['*'],
            'page',
            (int)($data['page'] ?? 1)
        );

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

<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\Gateway\StoreGatewayRequest;
use App\Http\Requests\Order\Gateway\UpdateGatewayRequest;
use App\Models\Gateway;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;

class GatewayController extends Controller
{
    /**
     * List gateways (no pagination).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Gateway::query()->orderBy('id')->get(),
        ]);
    }

    /**
     * Create a gateway.
     */
    public function store(StoreGatewayRequest $request): JsonResponse
    {
        $data = $request->validated();

        $gateway = Gateway::query()->create([
            'name' => $data['name'],
            'class_name' => $data['class_name'],
            'config' => $data['config'],
        ]);

        return response()->json([
            'message' => 'Gateway created successfully.',
            'data' => $gateway,
        ], 201);
    }

    /**
     * Modify a gateway.
     */
    public function update(UpdateGatewayRequest $request, Gateway $gateway): JsonResponse
    {
        $data = $request->validated();

        $gateway->fill([
            'name' => $data['name'],
            'class_name' => $data['class_name'],
            'config' => $data['config'],
        ])->save();

        return response()->json([
            'message' => 'Gateway updated successfully.',
            'data' => $gateway->fresh(),
        ]);
    }

    /**
     * Delete a gateway that doesn't have any payments.
     */
    public function destroy(Gateway $gateway): JsonResponse
    {
        $hasPayments = Payment::query()
            ->where('gateway_id', $gateway->id)
            ->exists();

        if ($hasPayments) {
            return response()->json([
                'message' => 'Gateway cannot be deleted because it has payments.',
            ], 422);
        }

        $gateway->delete();

        return response()->json([
            'message' => 'Gateway deleted successfully.',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderIndexRequest;
use App\Http\Requests\Order\OrderStoreRequest;
use App\Http\Requests\Order\OrderUpdateRequest;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Enums\OrderStatus;

class OrderController extends Controller
{
    /**
     * GET /api/orders?include_items=1
     */
    public function index(OrderIndexRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user('api');
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $query = Order::query()
            ->where('user_id', $userId)
            ->with(['items' => static function ($q) {
                $q->orderBy('id');
            }])
            ->orderByDesc('id');

        if (array_key_exists('status', $validated)) {
            $query->where('status', $validated['status']);
        }

        if (array_key_exists('from', $validated)) {
            $query->where('order_date', '>=', $validated['from']);
        }

        if (array_key_exists('to', $validated)) {
            $query->where('order_date', '<=', $validated['to']);
        }

        $page = isset($validated['page']) ? (int) $validated['page'] : 1;

        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 15;
        $perPage = max(1, min($perPage, 100));

        $orders = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/orders
     */
    public function store(OrderStoreRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user('api');
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $result = DB::transaction(function () use ($validated, $userId) {
            $order = Order::create([
                'user_id' => $userId,
                'status' => OrderStatus::PENDING->value,
                'order_date' => $validated['order_date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $createdItems = [];
            foreach ($validated['items'] as $item) {
                $createdItems[] = OrderItem::create([
                    'order_id' => $order->id,
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $order->setAttribute('items', $createdItems);

            return $order;
        });

        return response()->json($result, 201);
    }

    /**
     * PUT /api/orders/{order}
     * Sync strategy:
     * - items with id => update
     * - items without id => create
     * - existing items not present in payload => delete
     */
    public function update(OrderUpdateRequest $request, int $order)
    {
        $validated = $request->validated();

        $user = $request->user('api');
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $orderModel = Order::query()
            ->where('id', $order)
            ->where('user_id', $userId)
            ->first();

        if (!$orderModel) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($orderModel->status !== OrderStatus::PENDING->value) {
            return response()->json(['message' => 'Only pending orders can be updated'], 422);
        }

        $result = DB::transaction(function () use ($validated, $orderModel) {
            $updateData = [];

            if (array_key_exists('order_date', $validated)) {
                $updateData['order_date'] = $validated['order_date'];
            }
            if (array_key_exists('notes', $validated)) {
                $updateData['notes'] = $validated['notes'];
            }

            if (!empty($updateData)) {
                $orderModel->update($updateData);
            }

            if (array_key_exists('items', $validated)) {
                $incoming = $validated['items'];

                $existingItems = OrderItem::query()
                    ->where('order_id', $orderModel->id)
                    ->get()
                    ->keyBy('id');

                $keepIds = [];

                $finalItems = [];

                foreach ($incoming as $item) {
                    if (isset($item['id'])) {
                        $existing = $existingItems->get((int) $item['id']);
                        if (!$existing) {
                            return response()->json(['message' => 'Invalid item id for this order'], 422);
                        }

                        $existing->update([
                            'product_name' => $item['product_name'],
                            'quantity' => $item['quantity'],
                            'price' => $item['price'],
                            'notes' => $item['notes'] ?? null,
                        ]);

                        $keepIds[] = $existing->id;
                        $finalItems[] = $existing->fresh();
                        continue;
                    }

                    $created = OrderItem::create([
                        'order_id' => $orderModel->id,
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'notes' => $item['notes'] ?? null,
                    ]);

                    $keepIds[] = $created->id;
                    $finalItems[] = $created;
                }

                OrderItem::query()
                    ->where('order_id', $orderModel->id)
                    ->whereNotIn('id', $keepIds)
                    ->delete();

                $orderModel->setAttribute('items', $finalItems);
            } else {
                $orderModel->setAttribute('items', OrderItem::query()->where('order_id', $orderModel->id)->orderBy('id')->get());
            }

            return $orderModel->load('items');
        });

        if ($result instanceof \Illuminate\Http\JsonResponse) {
            return $result;
        }

        return response()->json($result);
    }

    /**
     * DELETE /api/orders/{order}
     */
    public function destroy(Request $request, int $order)
    {
        $user = $request->user('api');
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $orderModel = Order::query()
            ->where('id', $order)
            ->where('user_id', $userId)
            ->first();

        if (!$orderModel) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($orderModel->payment()->exists()) {
            return response()->json(['message' => 'Orders with payments cannot be deleted'], 422);
        }

        DB::transaction(function () use ($orderModel) {
            OrderItem::query()->where('order_id', $orderModel->id)->delete();
            $orderModel->delete();
        });

        return response()->noContent();
    }

    /**
     * POST /api/orders/{order}/confirm
     * Allowed transition: PENDING -> CONFIRMED
     */
    public function confirm(Request $request, int $order)
    {
        $user = $request->user('api');
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $orderModel = Order::query()
            ->where('id', $order)
            ->where('user_id', $userId)
            ->first();

        if (!$orderModel) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($orderModel->status !== OrderStatus::PENDING->value) {
            return response()->json(['message' => 'Only pending orders can be confirmed'], 422);
        }

        DB::transaction(function () use ($orderModel) {
            $orderModel->update([
                'status' => OrderStatus::CONFIRMED->value,
            ]);
        });

        $fresh = Order::query()
            ->where('id', $orderModel->id)
            ->with(['items' => static function ($q) {
                $q->orderBy('id');
            }])
            ->first();

        return response()->json($fresh);
    }

    /**
     * POST /api/orders/{order}/cancel
     * Allowed transition: PENDING -> CANCELLED
     */
    public function cancel(Request $request, int $order)
    {
        $user = $request->user('api');
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $orderModel = Order::query()
            ->where('id', $order)
            ->where('user_id', $userId)
            ->first();

        if (!$orderModel) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($orderModel->status !== OrderStatus::PENDING->value) {
            return response()->json(['message' => 'Only pending orders can be cancelled'], 422);
        }

        DB::transaction(function () use ($orderModel) {
            $orderModel->update([
                'status' => OrderStatus::CANCELLED->value,
            ]);
        });

        $fresh = Order::query()
            ->where('id', $orderModel->id)
            ->with(['items' => static function ($q) {
                $q->orderBy('id');
            }])
            ->first();

        return response()->json($fresh);
    }
}

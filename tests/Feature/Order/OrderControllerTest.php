<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Gateway;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_authenticated_users_orders_and_supports_pagination(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Create 3 orders for userA, 1 for userB
        $this->actingAs($userA, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-01 10:00:00',
            'notes' => 'A1',
            'items' => [
                ['product_name' => 'P1', 'quantity' => 1, 'price' => 10.50],
            ],
        ]);

        $this->actingAs($userA, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-02 10:00:00',
            'notes' => 'A2',
            'items' => [
                ['product_name' => 'P2', 'quantity' => 2, 'price' => 3.25],
            ],
        ]);

        $this->actingAs($userA, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-03 10:00:00',
            'notes' => 'A3',
            'items' => [
                ['product_name' => 'P3', 'quantity' => 3, 'price' => 1.00],
            ],
        ]);

        $this->actingAs($userB, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-04 10:00:00',
            'notes' => 'B1',
            'items' => [
                ['product_name' => 'PB', 'quantity' => 1, 'price' => 99.99],
            ],
        ]);

        $response = $this->actingAs($userA, 'api')
            ->getJson('/api/orders?per_page=2&page=1');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $this->assertCount(2, $response->json('data'));
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 3);

        foreach ($response->json('data') as $order) {
            $this->assertSame($userA->id, $order['user_id']);
        }
    }

    public function test_index_can_filter_by_status_and_date_range(): void
    {
        $user = User::factory()->create();

        $r1 = $this->actingAs($user, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-01 10:00:00',
            'notes' => 'O1',
            'items' => [
                ['product_name' => 'P1', 'quantity' => 1, 'price' => 10.00],
            ],
        ])->assertStatus(201);

        $r2 = $this->actingAs($user, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-10 10:00:00',
            'notes' => 'O2',
            'items' => [
                ['product_name' => 'P2', 'quantity' => 1, 'price' => 20.00],
            ],
        ])->assertStatus(201);

        $id1 = (int) $r1->json('id');
        $id2 = (int) $r2->json('id');

        // Make one order confirmed so we can filter by status.
        DB::table('orders')->where('id', $id2)->update(['status' => OrderStatus::CONFIRMED->value]);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/orders?status=' . OrderStatus::CONFIRMED->value . '&from=2025-12-05&to=2025-12-31');

        $response->assertStatus(200);

        $ids = array_map(static fn ($o) => (int) $o['id'], $response->json('data'));
        $this->assertSame([$id2], $ids);

        $response->assertJsonPath('data.0.status', OrderStatus::CONFIRMED->value);
    }

    public function test_store_creates_order_with_items_and_returns_201(): void
    {
        $user = User::factory()->create();

        $payload = [
            'order_date' => '2025-12-05 10:00:00',
            'notes' => 'Please deliver after 5pm',
            'items' => [
                ['product_name' => 'Widget', 'quantity' => 2, 'price' => 12.34, 'notes' => 'Blue'],
                ['product_name' => 'Gadget', 'quantity' => 1, 'price' => 99.99],
            ],
        ];

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/orders', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('status', OrderStatus::PENDING->value)
            ->assertJsonPath('order_date', '2025-12-05 10:00:00');

        $orderId = (int) $response->json('id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING->value,
            'order_date' => '2025-12-05 10:00:00',
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_name' => 'Widget',
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_name' => 'Gadget',
            'quantity' => 1,
        ]);
    }

    public function test_update_syncs_items_update_create_and_delete(): void
    {
        $user = User::factory()->create();

        $createResponse = $this->actingAs($user, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-06 10:00:00',
            'notes' => 'Initial',
            'items' => [
                ['product_name' => 'KeepAndUpdate', 'quantity' => 1, 'price' => 10.00],
                ['product_name' => 'ToBeDeleted', 'quantity' => 1, 'price' => 20.00],
            ],
        ])->assertStatus(201);

        $orderId = (int) $createResponse->json('id');

        $existingItems = OrderItem::query()
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get()
            ->values();

        $keep = $existingItems[0];
        $delete = $existingItems[1];

        $updatePayload = [
            'order_date' => '2025-12-07 10:00:00',
            'notes' => 'Updated notes',
            'items' => [
                [
                    'id' => $keep->id,
                    'product_name' => 'KeepAndUpdate',
                    'quantity' => 5,
                    'price' => 11.11,
                    'notes' => 'Now updated',
                ],
                [
                    'product_name' => 'NewItem',
                    'quantity' => 2,
                    'price' => 3.33,
                ],
            ],
        ];

        $response = $this->actingAs($user, 'api')
            ->putJson('/api/orders/' . $orderId, $updatePayload);

        $response->assertStatus(200)
            ->assertJsonPath('id', $orderId)
            ->assertJsonPath('order_date', '2025-12-07 10:00:00')
            ->assertJsonPath('notes', 'Updated notes');

        $this->assertDatabaseHas('order_items', [
            'id' => $keep->id,
            'order_id' => $orderId,
            'quantity' => 5,
        ]);

        $this->assertDatabaseMissing('order_items', [
            'id' => $delete->id,
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_name' => 'NewItem',
            'quantity' => 2,
        ]);
    }

    public function test_update_returns_404_when_order_not_found_for_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $createResponse = $this->actingAs($userA, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-08 10:00:00',
            'items' => [
                ['product_name' => 'X', 'quantity' => 1, 'price' => 1.00],
            ],
        ])->assertStatus(201);

        $orderId = (int) $createResponse->json('id');

        $response = $this->actingAs($userB, 'api')
            ->putJson('/api/orders/' . $orderId, [
                'notes' => 'Attempted update',
            ]);

        $response->assertStatus(404)
            ->assertJson(['message' => 'Order not found']);
    }

    public function test_update_returns_422_when_order_not_pending(): void
    {
        $user = User::factory()->create();

        $createResponse = $this->actingAs($user, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-09 10:00:00',
            'items' => [
                ['product_name' => 'X', 'quantity' => 1, 'price' => 1.00],
            ],
        ])->assertStatus(201);

        $orderId = (int) $createResponse->json('id');

        DB::table('orders')->where('id', $orderId)->update(['status' => OrderStatus::CONFIRMED->value]);

        $response = $this->actingAs($user, 'api')
            ->putJson('/api/orders/' . $orderId, [
                'notes' => 'Try update',
            ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Only pending orders can be updated']);
    }

    public function test_update_returns_422_when_payload_references_item_not_in_order(): void
    {
        $user = User::factory()->create();

        $createResponse = $this->actingAs($user, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-10 10:00:00',
            'items' => [
                ['product_name' => 'X', 'quantity' => 1, 'price' => 1.00],
            ],
        ])->assertStatus(201);

        $orderId = (int) $createResponse->json('id');

        $response = $this->actingAs($user, 'api')
            ->putJson('/api/orders/' . $orderId, [
                'items' => [
                    [
                        'id' => 999999, // not belonging to the order
                        'product_name' => 'Y',
                        'quantity' => 1,
                        'price' => 2.00,
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Invalid item id for this order']);
    }

    public function test_destroy_deletes_pending_order_and_its_items(): void
    {
        $user = User::factory()->create();

        $createResponse = $this->actingAs($user, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-11 10:00:00',
            'items' => [
                ['product_name' => 'A', 'quantity' => 1, 'price' => 1.00],
                ['product_name' => 'B', 'quantity' => 2, 'price' => 2.00],
            ],
        ])->assertStatus(201);

        $orderId = (int) $createResponse->json('id');

        $this->assertDatabaseHas('orders', ['id' => $orderId]);
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'product_name' => 'A']);

        $response = $this->actingAs($user, 'api')
            ->deleteJson('/api/orders/' . $orderId);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('orders', ['id' => $orderId]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $orderId]);
    }

    public function test_destroy_returns_422_when_order_has_payment(): void
    {
        $user = User::factory()->create();

        $createResponse = $this->actingAs($user, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-11 10:00:00',
            'items' => [
                ['product_name' => 'A', 'quantity' => 1, 'price' => 1.00],
            ],
        ])->assertStatus(201);

        $orderId = (int) $createResponse->json('id');

        $gateway = Gateway::factory()->create();

        Payment::factory()->create([
            'order_id' => $orderId,
            'gateway_id' => $gateway->id,
        ]);

        $this->assertDatabaseHas('orders', ['id' => $orderId]);
        $this->assertDatabaseHas('payments', ['order_id' => $orderId]);

        $response = $this->actingAs($user, 'api')
            ->deleteJson('/api/orders/' . $orderId);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Orders with payments cannot be deleted']);

        $this->assertDatabaseHas('orders', ['id' => $orderId]);
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId]);
        $this->assertDatabaseHas('payments', ['order_id' => $orderId]);
    }

    public function test_destroy_returns_422_when_order_status_not_allowed(): void
    {
        $user = User::factory()->create();

        $createResponse = $this->actingAs($user, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-12 10:00:00',
            'items' => [
                ['product_name' => 'X', 'quantity' => 1, 'price' => 1.00],
            ],
        ])->assertStatus(201);

        $orderId = (int) $createResponse->json('id');

        DB::table('orders')->where('id', $orderId)->update(['status' => OrderStatus::CONFIRMED->value]);

        $response = $this->actingAs($user, 'api')
            ->deleteJson('/api/orders/' . $orderId);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('orders', ['id' => $orderId]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $orderId]);
    }

    public function test_confirm_transitions_pending_to_confirmed(): void
    {
        $user = User::factory()->create();

        $createResponse = $this->actingAs($user, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-13 10:00:00',
            'items' => [
                ['product_name' => 'X', 'quantity' => 1, 'price' => 1.00],
            ],
        ])->assertStatus(201);

        $orderId = (int) $createResponse->json('id');

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/orders/' . $orderId . '/confirm');

        $response->assertStatus(200)
            ->assertJsonPath('id', $orderId)
            ->assertJsonPath('status', OrderStatus::CONFIRMED->value);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => OrderStatus::CONFIRMED->value,
        ]);
    }

    public function test_cancel_transitions_pending_to_cancelled(): void
    {
        $user = User::factory()->create();

        $createResponse = $this->actingAs($user, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-14 10:00:00',
            'items' => [
                ['product_name' => 'X', 'quantity' => 1, 'price' => 1.00],
            ],
        ])->assertStatus(201);

        $orderId = (int) $createResponse->json('id');

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/orders/' . $orderId . '/cancel');

        $response->assertStatus(200)
            ->assertJsonPath('id', $orderId)
            ->assertJsonPath('status', OrderStatus::CANCELLED->value);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => OrderStatus::CANCELLED->value,
        ]);
    }

    public function test_confirm_returns_422_if_not_pending(): void
    {
        $user = User::factory()->create();

        $createResponse = $this->actingAs($user, 'api')->postJson('/api/orders', [
            'order_date' => '2025-12-15 10:00:00',
            'items' => [
                ['product_name' => 'X', 'quantity' => 1, 'price' => 1.00],
            ],
        ])->assertStatus(201);

        $orderId = (int) $createResponse->json('id');

        DB::table('orders')->where('id', $orderId)->update(['status' => OrderStatus::CANCELLED->value]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/orders/' . $orderId . '/confirm');

        $response->assertStatus(422)
            ->assertJson(['message' => 'Only pending orders can be confirmed']);
    }
}

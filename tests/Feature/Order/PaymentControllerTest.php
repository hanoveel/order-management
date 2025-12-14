<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Gateway;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_creates_payment_for_confirmed_order(): void
    {
        $user = User::factory()->create();
        $gateway = Gateway::factory()->create([
            'class_name' => 'FakeGateway',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::CONFIRMED->value,
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson("/api/orders/{$order->id}/payments/process", [
            'gateway_id' => $gateway->id,
            'payment_method' => 'card',
            'notes' => 'Init payment',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'Payment initialized.');
        $response->assertJsonPath('data.order_id', $order->id);
        $response->assertJsonPath('data.gateway_id', $gateway->id);
        $response->assertJsonPath('data.status', PaymentStatus::PENDING->value);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'gateway_id' => $gateway->id,
            'status' => PaymentStatus::PENDING->value,
            'payment_method' => 'card',
            'notes' => 'Init payment',
        ]);

        $paymentId = $response->json('data.id');
        $this->assertNotNull($paymentId);

        $payment = Payment::query()->findOrFail($paymentId);
        $this->assertNotNull($payment->gateway_payment_id);
    }

    public function test_process_rejects_when_order_not_confirmed(): void
    {
        $user = User::factory()->create();
        $gateway = Gateway::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING->value,
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson("/api/orders/{$order->id}/payments/process", [
            'gateway_id' => $gateway->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Order must be confirmed before processing payment.');

        $this->assertDatabaseMissing('payments', [
            'order_id' => $order->id,
            'gateway_id' => $gateway->id,
        ]);
    }

    public function test_webhook_returns_404_when_payment_not_found(): void
    {
        $gateway = Gateway::factory()->create([
            'class_name' => 'FakeGateway',
        ]);

        $response = $this->postJson("/api/payments/webhook/{$gateway->id}", [
            'payment_id' => 'does-not-exist',
            'status' => PaymentStatus::SUCCESSFUL->value,
        ]);

        $response->assertNotFound();
        $response->assertJsonPath('message', 'Payment not found for given gateway_payment_id.');
    }

    public function test_webhook_updates_payment_status(): void
    {
        $gateway = Gateway::factory()->create([
            'class_name' => 'FakeGateway',
        ]);

        $payment = Payment::factory()->create([
            'gateway_id' => $gateway->id,
            'status' => PaymentStatus::PENDING->value,
        ]);

        $response = $this->postJson("/api/payments/webhook/{$gateway->id}", [
            'payment_id' => $payment->gateway_payment_id,
            'status' => PaymentStatus::SUCCESSFUL->value,
            'notes' => 'Paid via webhook',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Webhook processed.');
        $response->assertJsonPath('data.id', $payment->id);
        $response->assertJsonPath('data.status', PaymentStatus::SUCCESSFUL->value);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::SUCCESSFUL->value,
            'notes' => 'Paid via webhook',
        ]);
    }

    public function test_index_lists_payments_and_can_filter_by_order_id(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $orderA = Order::factory()->create(['user_id' => $user->id]);
        $orderB = Order::factory()->create(['user_id' => $user->id]);

        Payment::factory()->count(2)->create(['order_id' => $orderA->id]);
        Payment::factory()->count(1)->create(['order_id' => $orderB->id]);

        $all = $this->getJson('/api/payments?per_page=50');
        $all->assertOk();
        $this->assertCount(3, $all->json('data'));

        $filtered = $this->getJson("/api/payments?order_id={$orderA->id}&per_page=50");
        $filtered->assertOk();
        $this->assertCount(2, $filtered->json('data'));

        foreach ($filtered->json('data') as $row) {
            $this->assertSame($orderA->id, $row['order_id']);
        }
    }
}

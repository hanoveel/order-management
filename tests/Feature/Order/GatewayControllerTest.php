<?php

namespace Tests\Feature\Order;

use App\Models\Gateway;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GatewayControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api');

        return $user;
    }

    public function test_index_lists_gateways(): void
    {
        $this->authUser();

        Gateway::query()->create([
            'name' => 'G1',
            'class_name' => 'FakeGateway',
            'config' => ['api_key' => 'k1'],
        ]);

        Gateway::query()->create([
            'name' => 'G2',
            'class_name' => 'FakeGateway',
            'config' => ['api_key' => 'k2'],
        ]);

        $res = $this->getJson('/api/gateways');

        $res->assertOk();
        $res->assertJsonStructure([
            'data' => [
                ['id', 'name', 'class_name', 'config'],
            ],
        ]);

        $this->assertCount(2, $res->json('data'));
    }

    public function test_store_creates_gateway(): void
    {
        $this->authUser();

        $payload = [
            'name' => 'Stripe',
            'class_name' => 'FakeGateway',
            'config' => ['api_key' => 'secret'],
        ];

        $res = $this->postJson('/api/gateways', $payload);

        $res->assertCreated();
        $res->assertJsonPath('message', 'Gateway created successfully.');
        $res->assertJsonPath('data.name', 'Stripe');
        $res->assertJsonPath('data.class_name', 'FakeGateway');
        $res->assertJsonPath('data.config.api_key', 'secret');

        $this->assertDatabaseHas('gateways', [
            'name' => 'Stripe',
            'class_name' => 'FakeGateway',
        ]);
    }

    public function test_update_modifies_gateway(): void
    {
        $this->authUser();

        $gateway = Gateway::query()->create([
            'name' => 'Old',
            'class_name' => 'FakeGateway',
            'config' => ['api_key' => 'old'],
        ]);

        $payload = [
            'name' => 'New',
            'class_name' => 'FakeGateway',
            'config' => ['api_key' => 'new'],
        ];

        $res = $this->putJson('/api/gateways/' . $gateway->id, $payload);

        $res->assertOk();
        $res->assertJsonPath('message', 'Gateway updated successfully.');
        $res->assertJsonPath('data.id', $gateway->id);
        $res->assertJsonPath('data.name', 'New');
        $res->assertJsonPath('data.config.api_key', 'new');

        $this->assertDatabaseHas('gateways', [
            'id' => $gateway->id,
            'name' => 'New',
            'class_name' => 'FakeGateway',
        ]);
    }

    public function test_destroy_deletes_gateway_when_no_payments(): void
    {
        $this->authUser();

        $gateway = Gateway::query()->create([
            'name' => 'ToDelete',
            'class_name' => 'FakeGateway',
            'config' => ['api_key' => 'k'],
        ]);

        $res = $this->deleteJson('/api/gateways/' . $gateway->id);

        $res->assertOk();
        $res->assertJsonPath('message', 'Gateway deleted successfully.');

        $this->assertDatabaseMissing('gateways', ['id' => $gateway->id]);
    }

    public function test_destroy_rejected_when_gateway_has_payments(): void
    {
        $this->authUser();

        $gateway = Gateway::query()->create([
            'name' => 'HasPayments',
            'class_name' => 'FakeGateway',
            'config' => ['api_key' => 'k'],
        ]);

        Payment::factory()->create([
            'gateway_id' => $gateway->id,
        ]);

        $res = $this->deleteJson('/api/gateways/' . $gateway->id);

        $res->assertStatus(422);
        $res->assertJsonPath('message', 'Gateway cannot be deleted because it has payments.');

        $this->assertDatabaseHas('gateways', ['id' => $gateway->id]);
    }
}

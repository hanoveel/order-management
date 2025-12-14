<?php

namespace Database\Factories;

use App\Models\Gateway;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class PaymentFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->create()->id,
            'gateway_id' => Gateway::factory()->create()->id,
            'gateway_payment_id' => fake()->uuid(),
            'payment_method' => fake()->word(),
            'payment_date' => now()->format('Y-m-d H:i:s'),
            'notes' => fake()->sentence(20)
        ];
    }
}

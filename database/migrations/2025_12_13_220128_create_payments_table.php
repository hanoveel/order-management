<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\PaymentStatus;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->foreignId('gateway_id')
                ->constrained('gateways')
                ->restrictOnDelete();

            $table->string('gateway_payment_id')->nullable();
            $table->string('payment_method')->nullable();

            $table->dateTime('payment_date')->nullable();
            $table->string('status')->default(PaymentStatus::PENDING->value)->index();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['gateway_id', 'gateway_payment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

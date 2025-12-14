<?php

namespace App\Http\Requests\Order\Payment;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PaymentProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is protected by auth:api middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'gateway_id' => ['required', 'integer', 'exists:gateways,id'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $order = $this->route('order');

            if ($order instanceof Order && $order->status != OrderStatus::CONFIRMED->value) {
                $validator->errors()->add('order', 'Order must be confirmed before processing payment.');
            }
        });
    }
}

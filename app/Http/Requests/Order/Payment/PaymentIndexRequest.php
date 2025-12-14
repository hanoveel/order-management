<?php

namespace App\Http\Requests\Order\Payment;

use Illuminate\Foundation\Http\FormRequest;

class PaymentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is protected by auth:api middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'order_id' => $this->input('order_id') !== null ? (int) $this->input('order_id') : null,
            'per_page' => $this->input('per_page') !== null ? (int) $this->input('per_page') : null,
            'page' => $this->input('page') !== null ? (int) $this->input('page') : null,
        ]);
    }
}

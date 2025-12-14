<?php

namespace App\Http\Requests\Order\Gateway;

use App\PaymentGateways\Contract\IPaymentGateway;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

class StoreGatewayRequest extends FormRequest
{
    private const GATEWAYS_NAMESPACE_PREFIX = 'App\\PaymentGateways\\';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize class_name to FQCN before validation runs.
     */
    protected function prepareForValidation(): void
    {
        $className = $this->input('class_name');

        if (!is_string($className)) {
            return;
        }

        $className = ltrim($className, '\\');
        $className = self::GATEWAYS_NAMESPACE_PREFIX . $className;

        $this->merge([
            'class_name_fqcn' => $className,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'class_name' => ['required', 'string', 'max:255'],
            'config' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $fqcn = $this->input('class_name_fqcn');
            $config = $this->input('config');

            if (!is_string($fqcn)) {
                $validator->errors()->add('class_name', 'class_name must be a string.');
                return;
            }

            if (!is_array($config)) {
                $validator->errors()->add('config', 'config must be an array.');
                return;
            }

            $this->validateGatewayClass($validator, $fqcn);

            if ($validator->errors()->has('class_name')) {
                return;
            }

            $this->validateGatewayConfig($validator, $fqcn, $config);
        });
    }

    private function validateGatewayClass(Validator $validator, string $fqcn): void
    {
        if (!str_starts_with($fqcn, self::GATEWAYS_NAMESPACE_PREFIX)) {
            $validator->errors()->add('class_name', 'class_name must be a class under App\\PaymentGateways.');
            return;
        }

        if (!class_exists($fqcn)) {
            $validator->errors()->add('class_name', 'Gateway class does not exist.');
            return;
        }

        if (!is_subclass_of($fqcn, IPaymentGateway::class)) {
            $validator->errors()->add('class_name', 'Gateway class must implement ' . IPaymentGateway::class . '.');
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    private function validateGatewayConfig(Validator $validator, string $fqcn, array $config): void
    {
        try {
            /** @var class-string<IPaymentGateway> $fqcn */
            $ok = $fqcn::checkConfig($config);
        } catch (Throwable $e) {
            $validator->errors()->add('config', 'Invalid gateway config: ' . $e->getMessage());
            return;
        }

        if ($ok !== true) {
            $validator->errors()->add('config', 'Invalid gateway config.');
        }
    }
}

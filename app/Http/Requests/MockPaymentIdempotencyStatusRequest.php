<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Payments\MockPaymentProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MockPaymentIdempotencyStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'operation_type' => trim((string) $this->route('operation_type')),
            'idempotency_key' => trim((string) $this->route('idempotency_key')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operation_type' => [
                'required',
                'string',
                Rule::in([
                    MockPaymentProvider::TYPE_CHARGE,
                    MockPaymentProvider::TYPE_SEND,
                ]),
            ],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}

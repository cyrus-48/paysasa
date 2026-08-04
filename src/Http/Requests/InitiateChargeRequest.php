<?php

declare(strict_types=1);

namespace Paysasa\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reference validation rules for a host application building its own
 * "POST /api/payments" endpoint on top of the fluent API — not wired to
 * any route by the package itself. Validating at this HTTP boundary (per
 * OWASP input-validation guidance) keeps malformed input from ever
 * reaching a driver.
 */
class InitiateChargeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'phone' => ['required_without:card_token', 'string'],
            'card_token' => ['required_without:phone', 'string'],
            'reference' => ['required', 'string', 'max:100'],
            'description' => ['sometimes', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}

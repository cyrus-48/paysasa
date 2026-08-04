<?php

declare(strict_types=1);

namespace Paysasa\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Paysasa\Payments\Models\Payment
 *
 * Deliberately exposes `uuid` as the public id (never the auto-increment
 * `id`) and omits internal-only columns (provider_account_id, deleted_at).
 * Use this in your own API controllers: PaymentResource::make($payment).
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'provider' => $this->provider->value,
            'category' => $this->category->value,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'provider_reference' => $this->provider_reference,
            'receipt_number' => $this->receipt_number,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'failure_reason' => $this->when($this->status->value === 'failed', $this->failure_reason),
            'initiated_at' => $this->initiated_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

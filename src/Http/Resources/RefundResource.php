<?php

declare(strict_types=1);

namespace Paysasa\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Paysasa\Payments\Models\Refund */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'payment_id' => $this->payment->uuid,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'provider_reference' => $this->provider_reference,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

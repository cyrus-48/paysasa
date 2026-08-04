<?php

declare(strict_types=1);

namespace Paysasa\Payments\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Models\Payment;

class PaymentFailed implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly ?PaymentResponse $response = null,
        public readonly ?\Throwable $exception = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel("paysasa.payments.{$this->payment->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'payment.failed';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->response?->message ?? $this->exception?->getMessage()];
    }
}

<?php

declare(strict_types=1);

namespace Paysasa\Payments\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Models\Payment;

/**
 * Fired once a payment reaches PaymentStatus::Successful, whether that
 * happened synchronously (card charge) or asynchronously (M-Pesa webhook).
 * Broadcasts on a private channel so a frontend can react in real time to
 * an STK push completing on the customer's phone.
 */
class PaymentSuccessful implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly PaymentResponse $response,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("paysasa.payments.{$this->payment->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'payment.successful';
    }

    public function broadcastWith(): array
    {
        return $this->response->toArray();
    }
}

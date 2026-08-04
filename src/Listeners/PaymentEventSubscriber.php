<?php

declare(strict_types=1);

namespace Paysasa\Payments\Listeners;

use Illuminate\Events\Dispatcher;
use Paysasa\Payments\Events\PaymentCancelled;
use Paysasa\Payments\Events\PaymentExpired;
use Paysasa\Payments\Events\PaymentFailed;
use Paysasa\Payments\Events\PaymentInitiated;
use Paysasa\Payments\Events\PaymentRefunded;
use Paysasa\Payments\Events\PaymentReversed;
use Paysasa\Payments\Events\PaymentSuccessful;
use Paysasa\Payments\Events\WebhookProcessed;
use Paysasa\Payments\Events\WebhookReceived;
use Paysasa\Payments\Support\PaymentLogger;

/**
 * Built-in Event Subscriber Pattern implementation: writes every lifecycle
 * transition to payment_logs so a payment's full history is queryable
 * without the host app wiring up its own listeners. This runs alongside,
 * not instead of, your own listeners — register additional ones the
 * normal Laravel way in your EventServiceProvider:
 *
 *   protected $listen = [
 *       \Paysasa\Payments\Events\PaymentSuccessful::class => [
 *           \App\Listeners\SendReceiptEmail::class,
 *           \App\Listeners\MarkInvoicePaid::class,
 *       ],
 *   ];
 *
 * Or inline: Event::listen(PaymentSuccessful::class, fn ($e) => ...);
 */
class PaymentEventSubscriber
{
    public function __construct(protected PaymentLogger $logger)
    {
    }

    public function handlePaymentInitiated(PaymentInitiated $event): void
    {
        $this->logger->info('Payment initiated', ['reference' => $event->payment->reference], $event->payment);
    }

    public function handlePaymentSuccessful(PaymentSuccessful $event): void
    {
        $this->logger->info('Payment successful', $event->response->toArray(), $event->payment);
    }

    public function handlePaymentFailed(PaymentFailed $event): void
    {
        $this->logger->error('Payment failed', [
            'message' => $event->response?->message ?? $event->exception?->getMessage(),
        ], $event->payment);
    }

    public function handlePaymentCancelled(PaymentCancelled $event): void
    {
        $this->logger->info('Payment cancelled', [], $event->payment);
    }

    public function handlePaymentExpired(PaymentExpired $event): void
    {
        $this->logger->warning('Payment expired', [], $event->payment);
    }

    public function handlePaymentRefunded(PaymentRefunded $event): void
    {
        $this->logger->info('Payment refunded', $event->response->rawResponse ? ['amount' => $event->response->amount] : [], $event->payment);
    }

    public function handlePaymentReversed(PaymentReversed $event): void
    {
        $this->logger->info('Payment reversed', [], $event->payment);
    }

    public function handleWebhookReceived(WebhookReceived $event): void
    {
        $this->logger->info('Webhook received', ['provider' => $event->webhook->provider], provider: $event->webhook->provider);
    }

    public function handleWebhookProcessed(WebhookProcessed $event): void
    {
        $this->logger->info('Webhook processed', $event->response->toArray(), provider: $event->response->provider->value);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            PaymentInitiated::class => 'handlePaymentInitiated',
            PaymentSuccessful::class => 'handlePaymentSuccessful',
            PaymentFailed::class => 'handlePaymentFailed',
            PaymentCancelled::class => 'handlePaymentCancelled',
            PaymentExpired::class => 'handlePaymentExpired',
            PaymentRefunded::class => 'handlePaymentRefunded',
            PaymentReversed::class => 'handlePaymentReversed',
            WebhookReceived::class => 'handleWebhookReceived',
            WebhookProcessed::class => 'handleWebhookProcessed',
        ];
    }
}

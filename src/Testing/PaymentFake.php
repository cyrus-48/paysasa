<?php

declare(strict_types=1);

namespace Paysasa\Payments\Testing;

use Illuminate\Support\Str;
use Paysasa\Payments\Contracts\PaymentBuilder;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\RefundStatus;
use Paysasa\Payments\Managers\PaymentManager;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Swapped in for the real PaymentManager by Payment::fake(). Deliberately
 * bypasses persistence, events, queues, and every real HTTP call — a
 * charge recorded here never touches the database or a provider, mirroring
 * how Http::fake()/Mail::fake() work. Use this in feature tests for
 * business logic that calls Payment::driver(...)->charge(), and assert on
 * the outcome with assertCharged()/assertChargedTimes()/assertNothingCharged().
 */
class PaymentFake extends PaymentManager
{
    /** @var array<int, array{driver: string, request: ChargeRequest}> */
    protected array $chargedRequests = [];

    /** @var array<int, array{driver: string, request: RefundRequest}> */
    protected array $refundedRequests = [];

    protected array $queuedResponses = [];

    protected ?PaymentResponse $defaultResponse = null;

    public function __construct()
    {
        // Deliberately does not call parent::__construct(): the fake never
        // needs the container to resolve a real driver.
    }

    public function getDefaultDriver(): string
    {
        return config('paysasa.default', 'mpesa');
    }

    public function queueResponse(PaymentResponse $response): static
    {
        $this->queuedResponses[] = $response;

        return $this;
    }

    public function shouldReturn(PaymentResponse $response): static
    {
        $this->defaultResponse = $response;

        return $this;
    }

    public function forMerchant(string $merchantId): static
    {
        return $this;
    }

    public function extend($driver, \Closure $callback): static
    {
        return $this;
    }

    public function driver($driver = null): PaymentBuilder
    {
        return new FakePaymentBuilder($this, $driver ?? $this->getDefaultDriver());
    }

    public function driverInstance(?string $driver = null): never
    {
        throw new \RuntimeException(
            'driverInstance() resolves a real driver and is unavailable on PaymentFake. '.
            'Use Payment::driver(...) plus queueResponse()/assertCharged() instead.',
        );
    }

    public function recordCharge(string $driver, ChargeRequest $request): PaymentResponse
    {
        $this->chargedRequests[] = compact('driver', 'request');

        return $this->nextResponse($driver);
    }

    public function recordRefund(string $driver, RefundRequest $request): RefundResponse
    {
        $this->refundedRequests[] = compact('driver', 'request');

        return new RefundResponse(PaymentProvider::from($driver), RefundStatus::Completed, refundId: (string) Str::uuid(), amount: $request->amount);
    }

    protected function nextResponse(string $driver): PaymentResponse
    {
        if ($this->queuedResponses !== []) {
            return array_shift($this->queuedResponses);
        }

        return $this->defaultResponse ?? PaymentResponse::makeSuccessful(
            PaymentProvider::from($driver),
            transactionId: (string) Str::uuid(),
            providerReference: 'FAKE-'.Str::random(10),
            message: 'Faked successful payment',
        );
    }

    public function assertCharged(?\Closure $callback = null): void
    {
        PHPUnit::assertTrue(
            collect($this->chargedRequests)->contains(fn ($c) => $callback === null || $callback($c['request'], $c['driver'])),
            'The expected charge was not made.',
        );
    }

    public function assertChargedOn(string $driver): void
    {
        $this->assertCharged(fn ($request, $chargedDriver) => $chargedDriver === $driver);
    }

    public function assertChargedTimes(int $times, ?string $driver = null): void
    {
        $matching = $driver === null
            ? $this->chargedRequests
            : array_filter($this->chargedRequests, fn ($c) => $c['driver'] === $driver);

        PHPUnit::assertCount($times, $matching, "Expected {$times} charge(s), found ".count($matching).'.');
    }

    public function assertNothingCharged(): void
    {
        PHPUnit::assertEmpty($this->chargedRequests, 'Expected no charges, but at least one was made.');
    }

    public function assertRefunded(?\Closure $callback = null): void
    {
        PHPUnit::assertTrue(
            collect($this->refundedRequests)->contains(fn ($r) => $callback === null || $callback($r['request'], $r['driver'])),
            'The expected refund was not made.',
        );
    }
}

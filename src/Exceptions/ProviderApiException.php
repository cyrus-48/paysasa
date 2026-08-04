<?php

declare(strict_types=1);

namespace Paysasa\Payments\Exceptions;

/**
 * Wraps a non-2xx / transport-level failure from a provider's API. Carries
 * the raw response body so drivers and application code can inspect
 * provider-specific error codes without parsing exception messages.
 */
class ProviderApiException extends PaymentException
{
    public function __construct(
        string $message,
        protected ?int $statusCode = null,
        protected mixed $rawResponse = null,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $context, $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function rawResponse(): mixed
    {
        return $this->rawResponse;
    }

    public function isRetryable(): bool
    {
        // 5xx and 429 are considered transient; 4xx (other than 429) are not.
        return $this->statusCode === null || $this->statusCode >= 500 || $this->statusCode === 429;
    }
}

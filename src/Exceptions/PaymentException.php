<?php

declare(strict_types=1);

namespace Paysasa\Payments\Exceptions;

use RuntimeException;

/**
 * Base exception for every failure raised by this package. Catch this to
 * handle all Paysasa errors uniformly, or catch a specific subclass for
 * targeted recovery (e.g. retry on ProviderApiException, but not on
 * InvalidConfigurationException).
 */
class PaymentException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context Structured context merged into log/audit entries.
     */
    public function __construct(
        string $message,
        protected array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function context(): array
    {
        return $this->context;
    }
}

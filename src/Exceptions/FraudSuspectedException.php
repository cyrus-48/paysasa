<?php

declare(strict_types=1);

namespace Paysasa\Payments\Exceptions;

/**
 * Thrown by a Contracts\FraudCheck implementation to abort a charge before
 * it ever reaches the provider. Caught by the InitiatePayment action and
 * converted into a Failed PaymentResponse plus a PaymentFailed event.
 */
class FraudSuspectedException extends PaymentException
{
}

<?php

declare(strict_types=1);

namespace Paysasa\Payments\Support;

use Illuminate\Support\Facades\Log;
use Paysasa\Payments\Models\Payment;
use Paysasa\Payments\Models\PaymentLog;

/**
 * Writes to both the structured `payment_logs` table (queryable per
 * payment) and the configured Laravel log channel (for centralized log
 * aggregation), redacting sensitive keys from context in both places.
 */
class PaymentLogger
{
    public function __construct(protected array $config)
    {
    }

    public function log(string $level, string $message, array $context = [], ?Payment $payment = null, ?string $provider = null): void
    {
        if (! ($this->config['enabled'] ?? true)) {
            return;
        }

        $redacted = $this->redact($context);

        Log::channel($this->config['channel'] ?? 'stack')->{$level}("[paysasa] {$message}", $redacted);

        PaymentLog::create([
            'payment_id' => $payment?->id,
            'provider' => $provider ?? $payment?->provider?->value,
            'level' => $level,
            'message' => $message,
            'context' => $redacted,
        ]);
    }

    public function info(string $message, array $context = [], ?Payment $payment = null, ?string $provider = null): void
    {
        $this->log('info', $message, $context, $payment, $provider);
    }

    public function warning(string $message, array $context = [], ?Payment $payment = null, ?string $provider = null): void
    {
        $this->log('warning', $message, $context, $payment, $provider);
    }

    public function error(string $message, array $context = [], ?Payment $payment = null, ?string $provider = null): void
    {
        $this->log('error', $message, $context, $payment, $provider);
    }

    protected function redact(array $context): array
    {
        $fields = array_flip($this->config['redact_fields'] ?? []);

        $walk = function (array $data) use (&$walk, $fields): array {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = $walk($value);
                    continue;
                }

                if (isset($fields[is_string($key) ? strtolower($key) : $key])) {
                    $data[$key] = '[REDACTED]';
                }
            }

            return $data;
        };

        if (! ($this->config['log_raw_payloads'] ?? true)) {
            unset($context['raw_response'], $context['raw_request']);
        }

        return $walk($context);
    }
}

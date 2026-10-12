<?php

declare(strict_types=1);

namespace Paysasa\Payments\Console\Commands;

use Illuminate\Console\Command;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Models\Webhook;

class UnmatchedWebhooksCommand extends Command
{
    protected $signature = 'paysasa:webhooks:unmatched';

    protected $description = 'List unmatched webhooks awaiting manual resolution';

    public function handle(): int
    {
        $unmatched = Webhook::query()
            ->where('status', WebhookStatus::Unmatched)
            ->latest()
            ->limit(50)
            ->get();

        if ($unmatched->isEmpty()) {
            $this->info('No unmatched webhooks found.');

            return self::SUCCESS;
        }

        $rows = $unmatched->map(fn (Webhook $w): array => [
            (string) $w->id,
            (string) $w->provider,
            (string) ($w->dedupe_key ?? ''),
            (string) $w->match_attempts,
            $w->created_at?->toDateTimeString() ?? '',
            (string) ($w->error_message ?? ''),
        ])->toArray();

        $this->table(['ID', 'Provider', 'Dedupe Key', 'Attempts', 'Received At', 'Error'], $rows);

        return self::SUCCESS;
    }
}

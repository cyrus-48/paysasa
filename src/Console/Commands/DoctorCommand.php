<?php

declare(strict_types=1);

namespace Paysasa\Payments\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Paysasa\Payments\Support\Doctor\CheckResult;
use Paysasa\Payments\Support\Doctor\Doctor;
use Paysasa\Payments\Support\Doctor\Status;

/**
 * Diagnoses configuration problems: settings, callback URLs, webhook
 * verification and reachability, and a live credential check for every driver
 * that is in use. Exits non-zero when anything fails, so it can gate a deploy.
 */
class DoctorCommand extends Command
{
    protected $signature = 'paysasa:doctor
        {drivers?* : Only check these drivers (default: every driver with credentials, plus the default driver)}
        {--offline : Do not contact any provider or URL (config checks only)}
        {--no-probe : Skip pinging your own callback URLs}
        {--strict : Exit non-zero on warnings as well as failures}
        {--json : Print the results as JSON}';

    protected $description = 'Diagnose configuration, callback URLs, webhook reachability and credentials for each enabled driver';

    public function handle(Doctor $doctor): int
    {
        /** @var list<string> $only */
        $only = array_values((array) $this->argument('drivers'));

        $results = $doctor->run($only, ! $this->option('offline'), ! $this->option('no-probe'));

        $counts = [
            Status::Pass->value => 0,
            Status::Warn->value => 0,
            Status::Fail->value => 0,
            Status::Skip->value => 0,
        ];

        foreach ($results as $result) {
            $counts[$result->status->value]++;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'summary' => $counts,
                'results' => array_map(fn (CheckResult $r) => $r->toArray(), $results),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($results, $counts);
        }

        $failed = $counts[Status::Fail->value] > 0
            || ($this->option('strict') && $counts[Status::Warn->value] > 0);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param list<CheckResult>   $results
     * @param array<string, int>  $counts
     */
    private function render(array $results, array $counts): void
    {
        $current = null;

        foreach ($results as $result) {
            if ($result->group !== $current) {
                $current = $result->group;
                $this->newLine();
                $this->line('<options=bold>'.$current.'</>');
            }

            $color = $result->status->color();
            $this->line(sprintf(
                '  <fg=%s>%s</> %s  %s',
                $color,
                $result->status->symbol(),
                str_pad($result->check, 24),
                OutputFormatter::escape($result->message),
            ));

            if ($result->hint !== null && in_array($result->status, [Status::Warn, Status::Fail], true)) {
                $this->line('      <fg=gray>→ '.OutputFormatter::escape($result->hint).'</>');
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '<fg=green>%d passed</>, <fg=yellow>%d warning(s)</>, <fg=red>%d failure(s)</>, %d skipped.',
            $counts[Status::Pass->value],
            $counts[Status::Warn->value],
            $counts[Status::Fail->value],
            $counts[Status::Skip->value],
        ));

        if ($counts[Status::Fail->value] === 0 && $counts[Status::Warn->value] === 0) {
            $this->line('Everything the doctor can check looks right. It cannot prove that providers can reach your server; make a sandbox payment to confirm end to end.');
        }
    }
}

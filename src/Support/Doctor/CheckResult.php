<?php

declare(strict_types=1);

namespace Paysasa\Payments\Support\Doctor;

/** One line of doctor output: what was checked, how it went, and what to do about it. */
final class CheckResult
{
    public function __construct(
        public readonly string $group,
        public readonly string $check,
        public readonly Status $status,
        public readonly string $message,
        public readonly ?string $hint = null,
    ) {
    }

    /** @return array{group: string, check: string, status: string, message: string, hint: string|null} */
    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'check' => $this->check,
            'status' => $this->status->value,
            'message' => $this->message,
            'hint' => $this->hint,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Paysasa\Payments\Support\Doctor;

/** Outcome of a single doctor check. */
enum Status: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skip = 'skip';

    public function symbol(): string
    {
        return match ($this) {
            self::Pass => '✔',
            self::Warn => '!',
            self::Fail => '✘',
            self::Skip => '–',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pass => 'green',
            self::Warn => 'yellow',
            self::Fail => 'red',
            self::Skip => 'gray',
        };
    }
}

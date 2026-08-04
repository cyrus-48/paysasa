<?php

declare(strict_types=1);

namespace Paysasa\Payments\DTOs;

/**
 * Provider-agnostic customer payload. `phone` is required for mobile money
 * drivers, `email` for card drivers — a given driver simply ignores fields
 * it doesn't need, rather than every caller needing to know which fields
 * matter per provider.
 */
final class CustomerData
{
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly array $extra = [],
    ) {
    }

    /** Build from any object exposing id/name/email/phone (e.g. an Eloquent User model). */
    public static function fromModel(object $model): self
    {
        return new self(
            id: isset($model->id) ? (string) $model->id : null,
            name: $model->name ?? null,
            email: $model->email ?? null,
            phone: $model->phone ?? $model->phone_number ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'extra' => $this->extra,
        ];
    }
}

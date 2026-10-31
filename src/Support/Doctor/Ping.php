<?php

declare(strict_types=1);

namespace Paysasa\Payments\Support\Doctor;

/**
 * The side-effect-free "are you there?" handshake between `paysasa:doctor`
 * and the webhook route.
 *
 * Doctor GETs one of your callback URLs with an `X-Paysasa-Doctor` header
 * holding an HMAC of the route segment, keyed with the application key.
 * WebhookController answers a valid ping straight away, before anything is
 * stored or verified, so probing never creates webhook rows. Only a caller
 * that knows APP_KEY can produce the token, so the endpoint is not an open
 * oracle for guessing opaque route aliases.
 */
final class Ping
{
    public const HEADER = 'X-Paysasa-Doctor';

    public static function token(string $routeSegment): string
    {
        return hash_hmac('sha256', 'paysasa-doctor:'.$routeSegment, (string) config('app.key'));
    }

    public static function isValid(string $routeSegment, string $token): bool
    {
        return (string) config('app.key') !== '' && hash_equals(self::token($routeSegment), $token);
    }
}

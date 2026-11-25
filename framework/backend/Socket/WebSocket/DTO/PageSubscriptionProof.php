<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

/** Wire field shared by a page response and an action that uses its proof. */
final class PageSubscriptionProof
{
    public const string pageSubscriptionProof = 'pageSubscriptionProof';

    private const int HEX_LENGTH = 64;

    /**
     * @param mixed $value Untrusted action envelope value
     * @return ?string Proof in wire form, or null so the owner can refuse it with the action's correlation
     */
    public static function read(mixed $value): ?string
    {
        return is_string($value) && strlen($value) === self::HEX_LENGTH && ctype_xdigit($value) ? $value : null;
    }
}

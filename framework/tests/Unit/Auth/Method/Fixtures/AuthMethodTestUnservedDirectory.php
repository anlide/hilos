<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method\Fixtures;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodDirectory;

/**
 * A method directory with no password and no passkey: every method it wires needs something set up.
 */
final class AuthMethodTestUnservedDirectory extends AuthMethodDirectory
{
    /**
     * @return list<string> Method keys in button order
     */
    protected static function methods(): array
    {
        return [
            ...parent::methods(),
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            ...array_keys(AuthMethodTestProviderDirectory::all()),
        ];
    }
}

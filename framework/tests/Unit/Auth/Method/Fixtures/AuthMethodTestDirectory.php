<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method\Fixtures;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodDirectory;

/**
 * The method directory a demo declares: the four built-in methods, then two providers.
 */
final class AuthMethodTestDirectory extends AuthMethodDirectory
{
    /**
     * @return list<string> Method keys in button order
     */
    protected static function methods(): array
    {
        return [
            ...parent::methods(),
            AuthMethodKey::PASSWORD,
            AuthMethodKey::PASSKEY,
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            ...array_keys(AuthMethodTestProviderDirectory::all()),
        ];
    }
}

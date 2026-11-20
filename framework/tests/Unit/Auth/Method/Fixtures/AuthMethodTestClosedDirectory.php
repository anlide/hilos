<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method\Fixtures;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodDirectory;

/**
 * A method directory that registers nobody and signs its accounts in with a password alone.
 *
 * The shape of a project whose accounts are only handed out: no method of its own opens an
 * account, so its start passes, and every registration action is refused - the ones of the
 * methods it never wired included.
 */
final class AuthMethodTestClosedDirectory extends AuthMethodDirectory
{
    /**
     * @return list<string> Method keys in button order
     */
    protected static function methods(): array
    {
        return [
            ...parent::methods(),
            AuthMethodKey::PASSWORD,
        ];
    }

    /**
     * @return bool Always false: this project registers nobody
     */
    protected static function registers(): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Auth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodDirectory;

/**
 * FileGalleryManagerAuthMethodDirectory - the sign-in methods this demo has wired.
 *
 * The password and nothing else: a registration and a recovery confirm the address by a code
 * the framework mails, which needs no method of its own here. Passkeys, the mailed link, the
 * phone code and the providers are not wired in this demo.
 */
final class FileGalleryManagerAuthMethodDirectory extends AuthMethodDirectory
{
    /**
     * The password.
     *
     * @return list<string> Method keys (see AuthMethodKey)
     */
    protected static function methods(): array
    {
        return [
            ...parent::methods(),
            AuthMethodKey::PASSWORD,
        ];
    }
}

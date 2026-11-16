<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Exception;

use Hilos\Core\Exception\ValidationException;

/** An operation addressed to a person is refused because their erasure has begun. */
final class AccountErasingException extends ValidationException
{
}

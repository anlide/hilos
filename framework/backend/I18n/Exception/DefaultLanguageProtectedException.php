<?php

declare(strict_types=1);

namespace Hilos\I18n\Exception;

use Hilos\Core\Exception\ValidationException;

/** The configured default language must remain present and switched on. */
final class DefaultLanguageProtectedException extends ValidationException
{
}

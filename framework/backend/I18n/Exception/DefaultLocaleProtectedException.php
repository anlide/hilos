<?php

declare(strict_types=1);

namespace Hilos\I18n\Exception;

use Hilos\Core\Exception\ValidationException;

/** The countryless locale of the configured default language must remain switched on. */
final class DefaultLocaleProtectedException extends ValidationException
{
}

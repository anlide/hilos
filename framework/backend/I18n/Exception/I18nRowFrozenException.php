<?php

declare(strict_types=1);

namespace Hilos\I18n\Exception;

use Hilos\Core\Exception\ValidationException;

/** A switched-on reference row must be switched off before editing. */
class I18nRowFrozenException extends ValidationException
{
}

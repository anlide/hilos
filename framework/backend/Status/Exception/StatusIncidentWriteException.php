<?php

declare(strict_types=1);

namespace Hilos\Status\Exception;

use Hilos\Core\Exception\ValidationException;

/** A Status operation conflicts with the incident or its immutable timeline. */
class StatusIncidentWriteException extends ValidationException
{
}

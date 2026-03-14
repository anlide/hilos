<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

/**
 * Exception when item is not found for update operation (e.g. id is null).
 */
class ItemNotFoundForUpdateException extends ValidationException
{
}

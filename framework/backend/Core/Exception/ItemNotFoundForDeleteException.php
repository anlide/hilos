<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

/**
 * Exception when item is not found for delete operation (e.g. id is null).
 */
class ItemNotFoundForDeleteException extends ValidationException
{
}

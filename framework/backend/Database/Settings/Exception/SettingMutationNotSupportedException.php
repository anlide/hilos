<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Exception;

/**
 * Exception thrown when the read-only settings accessor receives a write operation.
 */
class SettingMutationNotSupportedException extends SettingException
{
}

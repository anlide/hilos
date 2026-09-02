<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Exception;

/**
 * Exception thrown when a preset declares one of its members without a value.
 */
class SettingPresetIncompleteException extends SettingException
{
}

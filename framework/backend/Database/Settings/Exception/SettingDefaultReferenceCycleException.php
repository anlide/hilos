<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Exception;

/**
 * Thrown when catalog default references form a cycle.
 */
final class SettingDefaultReferenceCycleException extends SettingException
{
}

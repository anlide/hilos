<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Cron\Exception;

/**
 * A cron rule name does not match the origin it was declared with.
 *
 * The message names the refused name, the reserved prefix and the origin the name requires.
 * The rule is not created.
 */
final class CronRuleNameException extends CronException
{
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Cron;

/**
 * Who declared a cron rule name: the project or the framework.
 *
 * A parameter of validation, not a value on the wire or in storage. A project name may not
 * start with {@see CronRule::FRAMEWORK_NAME_PREFIX}; a framework name must.
 */
enum CronRuleOrigin
{
    /** Declared by a project. The name must not start with the reserved prefix. */
    case PROJECT;

    /** Declared by the framework. The name must start with the reserved prefix. */
    case FRAMEWORK;
}

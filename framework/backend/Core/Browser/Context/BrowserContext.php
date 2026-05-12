<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Context;

/**
 * Base browser-facing context.
 *
 * Project subclasses register browser-facing state helpers during configuration.
 */
abstract class BrowserContext
{
    /**
     * Registers browser-facing state helpers.
     *
     * Called during Hilos::init().
     */
    abstract public function configure(): void;
}

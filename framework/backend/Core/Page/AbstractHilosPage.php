<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Agent\Hilos\AbstractHilosAgent;

/**
 * Base class for framework-level Hilos admin page handlers.
 *
 * Projects extend these pages to bind framework admin routes to their own page
 * catalog, agents, and browser setup.
 *
 * @property AbstractHilosAgent $agent Framework admin agent for page operations
 */
abstract class AbstractHilosPage extends AbstractPage
{
    /**
     * The framework admin surface is closed by default: every hilos page
     * requires the admin privilege unless it explicitly declares PUBLIC or
     * AUTHENTICATED ({@see PageAccessLevel}). Enforced by {@see PageAccessGate}.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;
}

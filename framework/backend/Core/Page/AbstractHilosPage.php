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

    // TODO: [change-log] Before each DB write that should attribute hilos_change_log rows, set MySQL session
    // variable (e.g. SET @hilos_user_id = <userId>;) so triggers can read the acting user. Wire this in the
    // database layer or connection wrapper used for authenticated requests.
}

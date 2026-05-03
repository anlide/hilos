<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Agent\Hilos\AbstractHilosAgent;

/**
 * AbstractHilosPage - Abstract base class for Hilos admin page handlers.
 *
 * Base class for all framework-level Hilos admin pages.
 * Projects can extend these pages via inheritance.
 *
 * @property AbstractHilosAgent $agent
 */
abstract class AbstractHilosPage extends AbstractPage
{
    // TODO: [change-log] Before each DB write that should attribute hilos_change_log rows, set MySQL session
    // variable (e.g. SET @hilos_user_id = <userId>;) so triggers can read the acting user. Wire this in the
    // database layer or connection wrapper used for authenticated requests.
}

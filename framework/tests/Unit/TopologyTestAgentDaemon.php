<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Base daemon stub for topology and signal router unit tests.
 */
abstract class TopologyTestAgentDaemon extends AbstractAgentDaemon
{
    /**
     * Test daemons do not require monopolistic workers by default.
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}

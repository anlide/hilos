<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Agent\Daemon;

use Hilos\Auth\OAuth\Agent\AbstractOAuthAgentDaemon;

/**
 * OAuthAgentDaemon - daemon proxy for the tasks OAuth login agent (HIL-623).
 *
 * Inherits the framework placement: a cluster-leader-pinned monopolistic singleton. The
 * agent pipelines its exchanges, so one instance serves a login burst concurrently; no
 * demo-specific placement override is needed.
 */
final class OAuthAgentDaemon extends AbstractOAuthAgentDaemon
{
}

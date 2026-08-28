<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Core\Agent\Daemon;

use Hilos\Auth\OAuth\Agent\AbstractOAuthAgentDaemon;

/**
 * OAuthAgentDaemon - daemon proxy for the simple-poll OAuth login agent (HIL-634).
 *
 * Inherits the framework placement: a cluster-leader-pinned monopolistic singleton. The
 * agent pipelines its exchanges, so one instance serves a login burst concurrently; no
 * demo-specific placement override is needed.
 */
final class OAuthAgentDaemon extends AbstractOAuthAgentDaemon
{
}

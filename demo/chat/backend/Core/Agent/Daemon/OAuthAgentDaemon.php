<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Hilos\Auth\OAuth\Agent\AbstractOAuthAgentDaemon;

/**
 * OAuthAgentDaemon - daemon proxy for the chat OAuth login agent (HIL-281).
 *
 * Inherits the framework placement: a cluster-leader-pinned monopolistic singleton.
 * The agent pipelines its exchanges, so one instance serves a login burst
 * concurrently; no chat-specific placement override is needed.
 */
final class OAuthAgentDaemon extends AbstractOAuthAgentDaemon
{
}

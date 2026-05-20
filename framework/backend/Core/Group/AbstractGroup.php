<?php

declare(strict_types=1);

namespace Hilos\Core\Group;

/**
 * Base class for WebSocket group subscription ownership declarations.
 *
 * Concrete groups declare their group id and the agent type that owns
 * GROUP_SUBSCRIBE / GROUP_UNSUBSCRIBE / GROUP_UPDATE_SUBSCRIPTION signals.
 * Register group classes in the project Hilos facade GROUPS registry.
 */
abstract class AbstractGroup
{
    /** Group name or identifier, overridden by concrete groups. */
    public const string GROUP = '';

    /** Agent type that owns subscription signals for this group. */
    public const string SUBSCRIPTION_AGENT_TYPE = '';
}

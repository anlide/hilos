<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Config;

/**
 * Where a per-instance page reads the index of the agent that serves it.
 *
 * An instance may be named by a page parameter or the connection identity. A node
 * parameter instead names the node whose replica of a node-scoped agent serves it.
 */
enum PageAgentIndexSource: string
{
    /** The index is the value of a named subscription param. */
    case PARAM = 'param';

    /** A named subscription param identifies the node hosting the serving replica. */
    case NODE_PARAM = 'node_param';

    /** The index is the durable user id behind the connection. */
    case SESSION_USER = 'session_user';

    /** A public page follows the session user, with an unindexed owner for guests. */
    case SESSION_USER_OR_GUEST = 'session_user_or_guest';

    /**
     * Whether the page belongs to the person behind the connection.
     *
     * Signing in or out hands the live subscription to the new owner.
     *
     * @return bool Whether the subscription follows the connection's identity
     */
    public function followsConnectionIdentity(): bool
    {
        return $this === self::SESSION_USER || $this === self::SESSION_USER_OR_GUEST;
    }
}

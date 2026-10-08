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
}

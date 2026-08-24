<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Config;

use Hilos\Core\Browser\Context\BrowserContext;

/**
 * Where a per-instance page reads the index of the agent that serves it.
 *
 * Two sources, because an instance is named in exactly two ways. Either the address
 * of the page names it - `/chat/42` - and the index travels as a subscription param;
 * or the page is "mine" - a profile - and the instance is the person behind the
 * connection, whom the master reads through the identity seam it already judges
 * access with ({@see BrowserContext::connectionIdentity}).
 */
enum PageAgentIndexSource: string
{
    /** The index is the value of a named subscription param. */
    case PARAM = 'param';

    /** The index is the durable user id behind the connection. */
    case SESSION_USER = 'session_user';
}

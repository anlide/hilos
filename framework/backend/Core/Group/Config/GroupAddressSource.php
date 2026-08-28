<?php

declare(strict_types=1);

namespace Hilos\Core\Group\Config;

use Hilos\Core\Browser\Context\BrowserContext;

/**
 * What names the entity a group belongs to, and therefore who may name it.
 *
 * A group is addressed by an entity rather than by an arbitrary string, and the kind of
 * entity settles the question of rights. A group of "my" entity - my notifications, my
 * languages - is named by the SERVER out of the identity behind the connection
 * ({@see BrowserContext::connectionIdentity}), so another person's cannot be NAMED at all;
 * a group of a named foreign entity - a room, a poll - is named by the client, and the
 * group class decides admission. A singleton group belongs to no entity and its bare name
 * is the whole address.
 */
enum GroupAddressSource: string
{
    /** The group belongs to nothing in particular; its declared name is the whole address. */
    case SINGLETON = 'singleton';

    /** The group belongs to the durable user behind the connection, and the server names it. */
    case SESSION_USER = 'session_user';

    /** The group belongs to the browser session behind the connection, and the server names it. */
    case SESSION = 'session';

    /** The group belongs to an entity the client names, and the group class judges admission. */
    case PARAM = 'param';
}

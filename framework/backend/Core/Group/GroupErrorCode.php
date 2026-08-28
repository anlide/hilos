<?php

declare(strict_types=1);

namespace Hilos\Core\Group;

use Hilos\Core\Group\Config\GroupAddressSource;
use Hilos\Core\Page\Exception\PageInternalErrorException;

/**
 * GroupErrorCode - machine-readable reasons a group join is refused.
 *
 * The client reads the code, not the message: the message is written for a log and for a
 * developer, and it moves. Four reasons, and there is no fifth - a join is refused because
 * nobody serves the group, because its owner said no, because the name was addressed the
 * wrong way, or because the server does not yet know who is asking.
 */
final class GroupErrorCode
{
    /** No registered group class answers this name. */
    public const string NOT_SERVED = 'group_not_served';

    /** The group class refused this connection ({@see AbstractGroup::assertSubscribable()}). */
    public const string FORBIDDEN = 'group_forbidden';

    /** The name carried a param for a group the server addresses itself, or carried none for one it does not ({@see GroupAddressSource}). */
    public const string ADDRESS_MISMATCH = 'group_address_mismatch';

    /** The group is addressed by who is behind the connection, and nobody is. */
    public const string UNAUTHENTICATED = 'group_unauthenticated';

    /**
     * Not a refusal at all but a crash, told apart from the four above by carrying no
     * `group_` prefix: the join failed on something nobody decided, the way its page twin
     * ({@see PageInternalErrorException}) reports the same accident.
     */
    public const string INTERNAL_ERROR = 'internal_error';
}

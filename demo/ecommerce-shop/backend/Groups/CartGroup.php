<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Groups;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Core\Group\GroupMembership;

/**
 * CartGroup - the server group of one cart: every tab of a guest session, or every tab and device of a person.
 *
 * The connections agent ({@see EcommerceShopAgent}) puts each socket in the group of the cart it
 * holds now, on every session-state frame ({@see GroupMembership::join()}); the cart's owner
 * sends the whole cart to the group - the guest carts library ({@see GuestCartsLibraryAgent})
 * for a session, the person's agent ({@see UserAgent}) for an account. The name is built by the
 * server only, so the group is not in `Hilos::GROUPS`: a browser cannot name it, and cannot ask
 * for another session's cart.
 */
final class CartGroup
{
    /** Name the group answers to, and the head of every full name. */
    public const string NAME = 'cart';

    /**
     * Builds the group name of a guest session's cart.
     *
     * @param int $sessionId Row of the session
     * @return string Group name the session's tabs join
     */
    public static function forSession(int $sessionId): string
    {
        return self::NAME . ':guest-' . $sessionId;
    }

    /**
     * Builds the group name of a person's cart.
     *
     * @param int $userId Person whose cart it is
     * @return string Group name the person's tabs and devices join
     */
    public static function forUser(int $userId): string
    {
        return self::NAME . ':user-' . $userId;
    }

    /**
     * Names the group of the cart a session holds now: the person's when one is signed in, the session's otherwise.
     *
     * @param SessionStateSignalData $frame What the session is now
     * @return ?string Group name, or null when the frame names neither a person nor a session row
     */
    public static function of(SessionStateSignalData $frame): ?string
    {
        if ($frame->userId !== null) {
            return self::forUser($frame->userId);
        }
        if ($frame->sessionId !== null) {
            return self::forSession($frame->sessionId);
        }

        return null;
    }
}

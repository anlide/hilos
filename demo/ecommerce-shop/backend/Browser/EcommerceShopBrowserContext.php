<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser;

use Demo\EcommerceShop\Hilos;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;

/**
 * EcommerceShopBrowserContext - Browser-facing context ($browser layer) for ecommerce-shop.
 *
 * Says who is behind a socket - the admin pages ask it through the page access gate. It
 * computes no field of its own: a person's presence and session count, the password fields
 * and the scheduled deletion's date are the framework's, computed for its card of one person
 * (HIL-1254), and the other admin tables go out through the framework self-snapshot path.
 */
final class EcommerceShopBrowserContext extends BrowserContext
{
    /**
     * Resolves who is behind an accept key from the demo's runtime connection
     * registry, where the handshake records the acceptKey -> user mapping. Lets the
     * page access gate identify the subscriber.
     *
     * A registry row is written for every connection the handshake sees, so no row
     * means the row has not crossed the RT sync into this worker yet rather than
     * "nobody is there" - the frame waits instead of being refused as anonymous
     * (HIL-599). A demo that mounts no registry at all is the opposite case and answers a
     * settled nobody: where the answer can never arrive there is nothing to wait for.
     *
     * Fail-closed stops there (HIL-575). It belongs where the question is who this
     * connection is; it does not belong to a read that was refused, which says nothing
     * about the connection and everything about the wiring. Answered "anonymous", a
     * refusal signed everybody out of a node that was merely wired wrong.
     *
     * @param string $acceptKey Subscriber accept key
     * @return ConnectionIdentity User behind the connection, or the pending state
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        try {
            $connections = Hilos::$rt?->connections;
            if ($connections === null) {
                return ConnectionIdentity::resolved(null);
            }

            $connection = $connections[$acceptKey];

            return $connection === null
                ? ConnectionIdentity::pending()
                : ConnectionIdentity::resolved($connection->userId);
        } catch (RtCollectionNotFoundException) {
            return ConnectionIdentity::resolved(null);
        }
    }
}

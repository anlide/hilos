<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Item;

use Closure;
use Demo\EcommerceShop\Database\Actions\Collection\SessionProductCartsActions;
use Demo\EcommerceShop\Database\Object\Collection\Sessions as ObjectSessions;
use Demo\EcommerceShop\Database\Object\Item\Session as ObjectSession;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\Session\SessionToken;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Item\SessionActions as FrameworkSessionActions;
use Hilos\HilosException;
use Throwable;

/**
 * Framework session writes that assign the departing guest cart with the bind.
 *
 * @property-read ObjectSession $object
 */
final class SessionActions extends FrameworkSessionActions
{
    /**
     * @param int $userId User id to bind the session to
     * @throws ItemNotFoundForUpdateException When the session is absent or has moved off its token
     * @throws HilosException When the session or cart assignment cannot be saved
     */
    public function bindUser(int $userId): void
    {
        $this->bindAndAssign($userId, null, fn () => parent::bindUser($userId));
    }

    /**
     * @param string $newToken Newly minted session token
     * @param int $userId User id to bind the session to
     * @throws InvalidFormatException When the new token is invalid
     * @throws DuplicateValueException When the new token is already held
     * @throws ItemNotFoundForUpdateException When the session is absent or has moved off its token
     * @throws HilosException When the session or cart assignment cannot be saved
     */
    public function rotateTokenAndBindUser(string $newToken, int $userId): void
    {
        $this->bindAndAssign($userId, $newToken, fn () => parent::rotateTokenAndBindUser($newToken, $userId));
    }

    /**
     * @param int $userId User id requested by the caller
     * @param ?string $newToken Rotation candidate, when minting
     * @param Closure(): void $bind Framework write at the same transaction boundary
     * @throws HilosException When the storage checks, write guard or framework bind refuses
     */
    private function bindAndAssign(int $userId, ?string $newToken, Closure $bind): void
    {
        $this->ensureCanWrite();
        $session = $this->object;
        if ($session->id === null) {
            $bind();
            return;
        }

        /** @var ObjectSessions $sessions */
        $sessions = $this->getObjectCollection();
        try {
            SessionProductCartsActions::inPrimaryTransaction(static function () use ($userId, $newToken, $bind, $session, $sessions): void {
                $stored = $sessions->lockStoredSession($session->id);
                if ($stored === null || $stored->token !== $session->token) {
                    throw new ItemNotFoundForUpdateException('The session no longer holds its token.');
                }
                if ($newToken !== null) {
                    SessionToken::ensureValid($newToken);
                    if ($sessions->hasLockedToken($newToken)) {
                        throw new DuplicateValueException('Session with this token already exists');
                    }
                }

                $leavesGuestCart = $stored->user_id === null && $stored->impersonator_user_id === null && $userId > 0;
                $hasLines = $leavesGuestCart && Hilos::$db->sessionProductCarts->hasLockedCartLine($session->id, $stored->cart_epoch);
                $session->acceptStored($stored);
                $bind();
                if (!$leavesGuestCart) {
                    return;
                }

                $session->cartEpoch = $stored->cart_epoch + 1;
                $session->sync();
                if ($hasLines) {
                    Hilos::$db->shopGuestCartTransfers->actions->create($session->id, $stored->cart_epoch, $userId);
                }
            });
        } catch (HilosException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('Could not bind the session and assign its guest cart.', previous: $failure);
        }
    }
}

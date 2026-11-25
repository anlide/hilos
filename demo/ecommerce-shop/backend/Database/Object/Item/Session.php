<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Entity\Item\Session as EntitySession;
use Demo\EcommerceShop\Database\Object\Collection\Sessions as ObjectSessions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Session as FrameworkSession;
use Hilos\HilosException;

/**
 * A shop session and its active guest product cart epoch.
 *
 * @property int $cartEpoch
 */
final class Session extends FrameworkSession
{
    public const string ENTITY_CLASS = EntitySession::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessions::class;

    public const int INITIAL_CART_EPOCH = 0;
    public const string cartEpoch = 'cartEpoch';

    /**
     * Creates a session whose first guest product cart starts at epoch zero.
     *
     * @return static New session object
     * @throws DatabaseException When the Entity class is missing
     */
    public static function create(): static
    {
        $session = parent::create();
        $session->cartEpoch = self::INITIAL_CART_EPOCH;
        return $session;
    }

    /**
     * Reads the active cart epoch or an inherited session field.
     *
     * @param string $property Property name
     * @return mixed Property value
     * @throws DatabaseException When entity access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::cartEpoch => $this->entity->cart_epoch,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the active cart epoch or an inherited session field.
     *
     * @param string $property Property name
     * @param mixed $value New value
     * @throws DatabaseException When the inherited setter fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::cartEpoch => $this->entity->cart_epoch = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Reconciles the held session with the locked row before the framework writes through it.
     *
     * @param EntitySession $stored Session as read under its PRIMARY lock
     * @throws HilosException When the backing database or rollback scope is unavailable
     */
    public function acceptStored(EntitySession $stored): void
    {
        $before = $this->entitySync->toArray();
        $after = $stored->toArray();
        if ($after === $before) {
            return;
        }

        Database::onRollback(
            fn () => $this->restoreSaved($before, related: true),
            index: $stored::getDatabase()->connectionIndex,
        );
        $this->restoreSaved($after, related: true);
    }
}

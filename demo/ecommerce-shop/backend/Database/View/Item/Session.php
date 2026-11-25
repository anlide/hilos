<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Actions\Item\SessionActions;
use Demo\EcommerceShop\Database\Object\Item\Session as ObjectSession;
use Hilos\Database\Actions\Item\SessionActions as FrameworkSessionActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\Session as FrameworkSession;
use Hilos\HilosException;

/**
 * Read-only shop session.
 *
 * SCAFFOLD: Guest product cart lookups will use the active epoch.
 *
 * @property-read int $cartEpoch Number of the active guest product cart
 * @property-read SessionActions $actions
 */
final class Session extends FrameworkSession
{
    /**
     * Reads the active cart epoch or an inherited session property.
     *
     * @param string $name Property name
     * @return int|string|bool|FrameworkSessionActions|null Property value or actions
     * @throws ActionsClassException When the actions class for the session collection is not configured
     * @throws PropertyNotFoundException When the property is not exposed by the session item
     * @throws HilosException When the inherited getter cannot read a property
     */
    public function __get(string $name): int|string|bool|FrameworkSessionActions|null
    {
        return match ($name) {
            ObjectSession::cartEpoch => $this->_object->cartEpoch,
            default => parent::__get($name),
        };
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Groups;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Hilos;
use Hilos\I18n\DTO\AvailableLanguagesSignalData;

/**
 * The live list of available languages.
 *
 * A project activates it by registering a subclass with SUBSCRIPTION_AGENT_TYPE
 * set to the i18n library that builds the snapshot.
 */
abstract class AbstractHilosLanguagesGroup extends AbstractGroup
{
    public const string GROUP = 'hilos_languages';

    /**
     * Every connection, including a guest's, may join. The list is public and shared by all.
     *
     * @param string $acceptKey WebSocket accept key of the joining connection
     * @param array<string, string> $params Subscription params carried by the join frame
     */
    protected function assertSubscribable(string $acceptKey, array $params): void
    {
    }

    /**
     * @param array<string, string> $params Subscription params carried by the join frame
     * @return AvailableLanguagesSignalData Full list for the join response
     * @throws DatabaseException When the reference cannot be read
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured
     * @throws CollectionNotFoundException When a reference collection is missing
     * @throws DbCollectionNotReadableException When a reference collection is unreadable
     * @throws ItemNotFoundException When a required reference row is missing
     */
    protected function buildGroupPayload(array $params): AvailableLanguagesSignalData
    {
        return Hilos::$db->languages->available();
    }
}

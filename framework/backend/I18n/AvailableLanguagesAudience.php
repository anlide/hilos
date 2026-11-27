<?php

declare(strict_types=1);

namespace Hilos\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Groups\AbstractHilosLanguagesGroup;
use Hilos\Hilos;
use Hilos\I18n\Library\I18nLibraryAgent;

/** Coalesces committed catalog writes into one snapshot per pass of the writer's worker. */
final class AvailableLanguagesAudience implements SourceChangeSubscriberInterface
{
    private static bool $dirty = false;

    /**
     * The library that wrote the rows sends the update to the whole group across the cluster.
     *
     * @param SourceChange $change Committed source fact
     * @param SourceChangeProvenance $provenance Origin used to ignore applied remote writes
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->isDb() && $provenance === SourceChangeProvenance::LocalWrite && in_array($change->sourceKey, [
            HilosDbContext::languages,
            HilosDbContext::locales,
            HilosDbContext::countries,
            HilosDbContext::countryNames,
        ], true)) {
            self::$dirty = true;
        }
    }

    /** Forgets any queued update when the library stops. */
    public static function reset(): void
    {
        self::$dirty = false;
    }

    /**
     * Clears the pending update once the frame is queued. A failed snapshot build
     * leaves the update pending for the worker's next pass.
     *
     * @param I18nLibraryAgent $agent Catalog writer serving the group
     * @throws DatabaseException When the reference cannot be read
     * @throws InvalidArgumentException When a loaded object has the wrong type or the signal name is empty
     * @throws LogicException When collection classes are not configured
     * @throws CollectionNotFoundException When a reference collection is missing
     * @throws DbCollectionNotReadableException When a reference collection is unreadable
     * @throws ItemNotFoundException When a required reference row is missing
     */
    public static function onAgentTick(I18nLibraryAgent $agent): void
    {
        if (!self::$dirty) {
            return;
        }

        $agent->sendToGroup(
            HilosSignalConstants::HILOS_LANGUAGES_STATE,
            AbstractHilosLanguagesGroup::GROUP,
            Hilos::$db->languages->available(),
        );
        self::$dirty = false;
    }
}

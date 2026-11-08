<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Closure;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\View\Context\RtContext;

/** Process-private invalidation basis for runtime membership, never a replicated row or a time source. */
final class RtMembershipVersion
{
    private static int $revision = 0;

    /** @var ?Closure(?string, ?string): void One master's fact-only ownership cue; no source graph is retained */
    private static ?Closure $ownershipWake = null;

    /** @return int Current local membership/registry invalidation revision, without advancing it */
    public static function current(): int
    {
        return self::$revision;
    }

    /**
     * A rollback advances again; an earlier cached basis cannot become current by ABA.
     * The cue precedes point mutations and must never read presence or publish inline.
     * Unmounted or replaced collections advance the legacy revision without waking ownership.
     *
     * @param RtStates|RtContext|null $source Exact collection, context mount, or null for a generic basis reset
     * @param ?string $stateId Presence edge in that collection, null for a collection/context reset
     */
    public static function advance(RtStates|RtContext|null $source = null, ?string $stateId = null): void
    {
        self::$revision++;
        if (self::$ownershipWake === null) {
            return;
        }
        if ($source instanceof RtStates) {
            $name = $source->getCollectionName();
            if ($name === null || Hilos::$rt?->getStateCollection($name) !== $source) {
                return;
            }
            (self::$ownershipWake)($name, $stateId);
        } elseif ($source === null || $source === Hilos::$rt) {
            (self::$ownershipWake)(null, null);
        }
    }

    /**
     * Installs the process's single native ownership binding, separate from domain source reactions.
     * Collection and row are plain metadata qualified against the current runtime's exact store.
     *
     * @param Closure(?string, ?string): void $wake Collection reset or row edge; both null mean generic basis reset
     */
    public static function setOwnershipWake(Closure $wake): void
    {
        self::$ownershipWake = $wake;
    }

    /** @param Closure(?string, ?string): void $wake Exact departing binding; a replacement survives */
    public static function removeOwnershipWake(Closure $wake): void
    {
        if (self::$ownershipWake === $wake) {
            self::$ownershipWake = null;
        }
    }
}

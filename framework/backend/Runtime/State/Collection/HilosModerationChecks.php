<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Runtime\State\Item\HilosModerationCheck;
use OutOfBoundsException;

/**
 * HilosModerationChecks - the checks connections are waiting on, of every kind (HIL-1625).
 *
 * Framework-owned state collection mounted by {@see ModerationFeature::mount()} and by nothing
 * else: a project without moderation has no check to hold. A row appears when an initiator opens
 * a check and goes when the initiator applies its verdict, gives the wait up or replaces the
 * attempt, so the collection is the size of the checks in flight.
 *
 * @extends RtStates<HilosModerationCheck>
 */
final class HilosModerationChecks extends RtStates
{
    public const string STATE_CLASS = HilosModerationCheck::class;

    /**
     * @param ?string $key Row id `acceptKey|kind`, or null for a missing optional key
     * @return ?HilosModerationCheck Check row, or null when none is kept under that id
     */
    public function get(?string $key): ?HilosModerationCheck
    {
        /** @var ?HilosModerationCheck $state */
        $state = parent::get($key);

        return $state;
    }

    /**
     * @param mixed $offset Row id `acceptKey|kind`
     * @return HilosModerationCheck Required check row
     * @throws OutOfBoundsException When no check is stored under the key
     */
    public function offsetGet(mixed $offset): HilosModerationCheck
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Moderation check not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Moderation check not found: {$offset}");
    }
}

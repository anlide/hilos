<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\DbSyncApplicator;

/**
 * DbReHydrateSignalData - payload of the whole-database re-hydrate signal (HIL-479).
 *
 * The database underneath the running node was replaced, so every DB-backed collection has to
 * be re-read ({@see DbSyncApplicator::applyReHydrate()}). The fact names no collection and no
 * row - which is why this payload is empty and, unlike its siblings in this namespace, it does
 * not implement {@see DbSyncSignalDataInterface}: there is no key to dedupe or route on. It
 * exists because the signal router carries a payload on every signal, not because a receiver
 * has anything to read here.
 */
final class DbReHydrateSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @return array<string, mixed> Empty payload
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data Source data (ignored - the payload is empty)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}

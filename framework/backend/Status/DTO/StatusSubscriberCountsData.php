<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;

/** Public count projection; the internal aggregate's total and audience bound stay private. */
final readonly class StatusSubscriberCountsData
{
    public const string DATA = 'statusSubscribers';
    public const string confirmedCount = 'confirmedCount';
    public const string pendingCount = 'pendingCount';

    /**
     * @param int $confirmedCount Confirmed subscriptions
     * @param int $pendingCount Subscriptions awaiting confirmation
     * @throws InvalidFormatException When a count is negative
     */
    public function __construct(public int $confirmedCount, public int $pendingCount)
    {
        if ($confirmedCount < 0 || $pendingCount < 0) {
            throw new InvalidFormatException('Subscriber counts must be nonnegative.');
        }
    }

    /**
     * Null runtime means this process holds no runtime state. Freeze silence and unavailable
     * storage return null; a missing read declaration remains a wiring refusal.
     *
     * @return ?self Fresh counts, or null when the source cannot currently be read
     * @throws HilosException When source declarations or the aggregate contract are invalid
     */
    public static function read(): ?self
    {
        if (Hilos::$db === null || Hilos::$rt?->hilosProtectedModeRuntime === null
            || Hilos::$rt->hilosProtectedModeRuntime->silencesUnstoppedWriters()) {
            return null;
        }
        if (SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, HilosDbContext::statusSubscribers)
            && !SourceInterestRegistry::isReady(SourceChange::KIND_DB, HilosDbContext::statusSubscribers)) {
            return null;
        }
        try {
            $counts = Hilos::$db->statusSubscribers->counts();
        } catch (DatabaseException) {
            return null;
        }
        return new self($counts->confirmed, $counts->pending);
    }

    /** @return array<string, WireField> Aggregate values contain no personal fields */
    public static function wireFields(): array
    {
        return [self::confirmedCount => WireField::notPersonal(), self::pendingCount => WireField::notPersonal()];
    }

    /** @return array{confirmedCount: int, pendingCount: int} Page wire counts */
    public function toArray(): array
    {
        return [self::confirmedCount => $this->confirmedCount, self::pendingCount => $this->pendingCount];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\CollectionNotFullyLoadedException;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Status\StatusPublicWork;

/** One short-lived projection for the Works form catalog and its independent summary. */
final readonly class StatusWorksData
{
    public const string DATA = 'statusWorks';
    public const string components = 'components';
    public const string componentKey = 'componentKey';
    public const string name = 'name';
    public const string upcomingCount = 'upcomingCount';
    public const string nextWork = 'nextWork';
    public const string count = 'count';
    public const string id = 'id';
    public const string title = 'title';
    public const string description = 'description';
    public const string componentKeys = 'componentKeys';
    public const string startsAt = 'startsAt';
    public const string endsAt = 'endsAt';

    /**
     * @param list<array{componentKey: string, name: string}> $components Form catalog wire entries
     * @param int $upcomingCount Unexpired unfinished announcements
     * @param ?StatusPublicWork $nextWork Nearest announcement, null for a known empty set
     */
    public function __construct(public array $components, public int $upcomingCount, public ?StatusPublicWork $nextWork)
    {
    }

    /**
     * Uses the existing aggregate and bounded interval read; no loaded-set iteration or retained state.
     * Both the work and bridge sources must be ready before a summary can be called known.
     *
     * @return ?self Complete form and summary, or null while a source cannot be read
     * @throws HilosException When source declarations or catalog contracts are invalid
     */
    public static function read(): ?self
    {
        if (Hilos::$db === null || Hilos::$rt?->hilosProtectedModeRuntime === null
            || Hilos::$rt->hilosProtectedModeRuntime->silencesUnstoppedWriters()) {
            return null;
        }
        foreach ([HilosDbContext::statusWorks, HilosDbContext::statusWorkComponents] as $source) {
            if (SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, $source)
                && !SourceInterestRegistry::isReady(SourceChange::KIND_DB, $source)) {
                return null;
            }
        }
        try {
            $summary = Hilos::$db->statusWorks->summary(TimeHelper::nowMs());
            $next = $summary->nextStartsAt === null ? null : Hilos::$db->statusWorks->matchingAnnouncement($summary->nextStartsAt);
            if ($summary->upcoming > 0 && $next === null) {
                return null;
            }
            $next = $next === null ? null : StatusPublicWork::fromWork($next);
        } catch (DatabaseException | CollectionNotFullyLoadedException) {
            return null;
        }
        $components = [];
        foreach (Hilos::statusComponents() as $component) {
            $components[] = [self::componentKey => $component->componentKey, self::name => $component->name];
        }
        return new self($components, $summary->upcoming, $next);
    }

    /** @return array<string, WireField> Public catalog and announcement fields */
    public static function wireFields(): array
    {
        return [
            self::components => WireField::each([
                self::componentKey => WireField::notPersonal(), self::name => WireField::notPersonal(),
            ]),
            self::upcomingCount => WireField::notPersonal(),
            self::nextWork => WireField::each(self::workWireFields()),
        ];
    }

    /** @return array<string, WireField> The six public Work fields, including the opaque public id */
    public static function workWireFields(): array
    {
        return [
            self::id => WireField::notPersonal(), self::title => WireField::notPersonal(), self::description => WireField::notPersonal(),
            self::componentKeys => WireField::notPersonal(), self::startsAt => WireField::notPersonal(), self::endsAt => WireField::notPersonal(),
        ];
    }

    /** @return array<string, mixed> Complete first-response form catalog and independent summary */
    public function toArray(): array
    {
        return [self::components => $this->components, self::upcomingCount => $this->upcomingCount, self::nextWork => $this->nextWork?->toArray()];
    }
}

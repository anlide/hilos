<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\StatusWorks as ObjectStatusWorks;
use Hilos\Database\Object\Item\StatusWork as ObjectStatusWork;
use Hilos\Database\Object\Item\StatusWorkComponent as ObjectStatusWorkComponent;
use Hilos\Database\View\Collection\StatusWorks as DbCollectionStatusWorks;
use Hilos\Database\View\Item\StatusWork;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/**
 * Joins the caller's transaction; no external side effects occur here.
 *
 * @extends DbActions<StatusWork, ObjectStatusWorks>
 * @property-read DbCollectionStatusWorks $collection
 * @property-read ObjectStatusWorks $objectCollection
 */
class StatusWorksActions extends DbActions
{

    private const int PUBLIC_ID_BYTES = 16;

    /**
     * The caller combines the new work and component set in one transaction.
     *
     * @param string $title Public title
     * @param ?string $description Optional public description
     * @param int $startsAt Inclusive UTC epoch millisecond start
     * @param int $endsAt Exclusive UTC epoch millisecond end
     * @param int $at Creation moment in UTC epoch milliseconds
     * @param list<string> $componentKeys Selected catalog-key values
     * @return StatusWork Newly persisted immutable public identity at domain version one
     * @throws RandomException When secure entropy is unavailable
     * @throws HilosException When validation, ownership or persistence refuses creation
     */
    public function create(string $title, ?string $description, int $startsAt, int $endsAt, int $at, array $componentKeys = []): StatusWork
    {
        $this->ensureCanCreate();
        if (!Database::hasOpenTransactions()) {
            throw new DatabaseException('Work and component writes require the caller transaction');
        }
        ObjectStatusWork::validateContent($title, $description, $startsAt, $endsAt, $at);
        $componentKeys = ObjectStatusWorkComponent::normalizeKeys($componentKeys);
        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $work = $objectClass::create();
        $work->public_id = RandomHelper::secureHex(self::PUBLIC_ID_BYTES);
        $work->title = $title;
        $work->description = $description;
        $work->starts_at = $startsAt;
        $work->ends_at = $endsAt;
        $work->created_at = $at;
        $work->updated_at = $at;
        $work->version = 1;
        $work->sync();
        $this->addObjectToCollection($work);
        Hilos::$db->statusWorkComponents->actions->replaceForWork($work->id, ...$componentKeys);
        return $this->createDbItemFromObject($work);
    }
}

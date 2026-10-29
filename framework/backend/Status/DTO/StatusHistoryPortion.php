<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\TableConstants;

/** Detached source prefix in canonical order; the merged reader applies edgeSkip once across both sources. */
final readonly class StatusHistoryPortion
{
    /**
     * @param list<StatusHistoryEntry> $entries Typed snapshots, at most edgeSkip + limit, excluding the SQL probe
     * @param bool $hasMore Whether the requested direction has rows beyond this prefix
     * @throws ValidationException When snapshots exceed the maximum prefix or have an invalid type
     */
    public function __construct(public array $entries, public bool $hasMore)
    {
        if (!array_is_list($entries) || count($entries) > TableConstants::COUNT_CEILING + StatusHistoryQuery::MAX_WINDOW_ROWS) {
            throw new ValidationException('History portion exceeds its bounded prefix.');
        }
        foreach ($entries as $entry) {
            if (!$entry instanceof StatusHistoryEntry) {
                throw new ValidationException('History portion requires typed entries.');
            }
        }
    }
}

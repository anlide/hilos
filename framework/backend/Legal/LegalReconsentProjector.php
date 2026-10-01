<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Hilos;
use Hilos\Legal\Exception\LegalException;

/**
 * Builds what the "the terms have changed" screen shows (HIL-500).
 *
 * One person's documents that wait for a decision - inside their window or past their deadline -
 * each with the revision the person holds, the one in force, what changed between the two and the
 * full text of the one in force. The same shape, seen through the eyes of whoever held the previous
 * revision, is the administrator's preview on the document's page.
 */
final class LegalReconsentProjector
{
    /**
     * The documents one person has to decide on, in declaration order.
     *
     * A faulty catalog answers with none: what there is to accept is unknown, the same verdict the
     * account standing gives it.
     *
     * @param int $userId Person whose documents to show
     * @param string $today Calendar date to judge, YYYY-MM-DD
     * @return list<array<string, mixed>> Browser boundary: each document in its window or lapsed, with both
     *     revisions, the changes between them and the clauses of the one in force
     * @throws DatabaseException When the acceptance records cannot be read
     * @throws InvalidArgumentException When a loaded object does not match its collection or the query is invalid
     * @throws LogicException When the acceptance collection is not configured
     * @throws ObjectGetIdStringNotImplementedException When a loaded row lacks its primary key
     */
    public static function documents(int $userId, string $today): array
    {
        $db = Hilos::$db;
        if ($db === null) {
            return [];
        }

        $accepted = [];
        foreach ($db->legalAcceptances->ofUser($userId) as $acceptance) {
            $accepted[$acceptance->document][] = $acceptance->revisionId;
        }
        try {
            $documents = [];
            foreach (LegalCatalogResolver::documents() as $document) {
                $standing = LegalStandingResolver::standingOf($document, $accepted[$document->value] ?? [], $today);
                if ($standing->held === null
                    || ($standing->standing !== LegalStanding::WINDOW && $standing->standing !== LegalStanding::LAPSED)
                ) {
                    continue;
                }
                $current = LegalCatalogResolver::latestRevision($document);
                $documents[] = [
                    'document' => $document->value,
                    'standing' => $standing->standing->value,
                    'deadline' => $standing->deadline,
                    'held' => LegalWire::revision($standing->held),
                    'current' => LegalWire::revision($current),
                    'changes' => LegalWire::changes(LegalRevisionComparison::between($document, $standing->held->id, $current->id)),
                    'clauses' => LegalWire::clauses(LegalCatalogResolver::compose($document, $current->id)),
                ];
            }
        } catch (LegalException) {
            return [];
        }

        return $documents;
    }

    /**
     * The screen as a holder of the previous revision sees it today - the administrator's preview.
     *
     * The first revision has nothing before it: nobody holds anything older, so there is no standing,
     * no deadline and nothing to compare, only the clauses in force.
     *
     * @param LegalDocument $document Document previewed
     * @param string $today Calendar date to judge, YYYY-MM-DD
     * @return array<string, mixed> Browser boundary: the standing of the previous revision's holder, both revisions,
     *     the changes between them and the clauses of the one in force
     * @throws LegalException When the document declares no revision, or a declaration or its text cannot be read
     */
    public static function preview(LegalDocument $document, string $today): array
    {
        $current = LegalCatalogResolver::latestRevision($document);
        $held = LegalCatalogResolver::predecessor($document, $current->id);
        $clauses = LegalWire::clauses(LegalCatalogResolver::compose($document, $current->id));
        if ($held === null) {
            return [
                'document' => $document->value,
                'standing' => null,
                'deadline' => null,
                'held' => null,
                'current' => LegalWire::revision($current),
                'changes' => [],
                'clauses' => $clauses,
            ];
        }

        $standing = LegalStandingResolver::standingOf($document, [$held->id], $today);

        return [
            'document' => $document->value,
            'standing' => $standing->standing->value,
            'deadline' => $standing->deadline,
            'held' => LegalWire::revision($held),
            'current' => LegalWire::revision($current),
            'changes' => LegalWire::changes(LegalRevisionComparison::between($document, $held->id, $current->id)),
            'clauses' => $clauses,
        ];
    }
}

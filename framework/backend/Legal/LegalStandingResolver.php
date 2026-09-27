<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Legal\Exception\LegalException;
use Hilos\Utils\Helpers\TimeHelper;

/** Computes coverage and provenance in declaration order (HIL-498). */
final class LegalStandingResolver
{
    /** Length of a YYYY-MM-DD calendar date in the SQL timestamp. */
    private const int DATE_LENGTH = 10;

    /** @return string Server's current calendar date */
    public static function today(): string
    {
        return substr(TimeHelper::getSqlDateTime(), 0, self::DATE_LENGTH);
    }

    /**
     * Unknown accepted ids do not cover any declared revision.
     *
     * @param LegalDocument $document Document judged
     * @param list<string> $acceptedRevisionIds Stored revision keys, a boundary list of acceptance records
     * @param string $today Calendar date to judge, YYYY-MM-DD
     * @return LegalDocumentStanding Latest held revision and its outstanding deadline
     * @throws LegalException When the catalog declaration is faulty
     */
    public static function standingOf(LegalDocument $document, array $acceptedRevisionIds, string $today): LegalDocumentStanding
    {
        $held = null;
        $deadline = null;
        foreach (LegalCatalogResolver::revisions($document) as $revision) {
            if (in_array($revision->id, $acceptedRevisionIds, true)) {
                $held = $revision;
                $deadline = null;
            } elseif ($held !== null && $revision->significance === LegalSignificance::SUBSTANTIAL) {
                if ($deadline === null || $revision->effectiveOn < $deadline) {
                    $deadline = $revision->effectiveOn;
                }
            }
        }

        return new LegalDocumentStanding($document, match (true) {
            $held === null => LegalStanding::NONE,
            $deadline === null => LegalStanding::COVERED,
            $today >= $deadline => LegalStanding::LAPSED,
            default => LegalStanding::WINDOW,
        }, $held, $deadline);
    }

    /**
     * @param LegalDocument $document Document of the revision
     * @param string $id Revision id
     * @return LegalRevisionOrigin Whether the project or standard changed, or this is the first revision
     * @throws LegalException When the revision or its catalog is invalid
     */
    public static function origin(LegalDocument $document, string $id): LegalRevisionOrigin
    {
        $previous = LegalCatalogResolver::predecessor($document, $id);
        if ($previous === null) {
            return LegalRevisionOrigin::FIRST;
        }

        return LegalCatalogResolver::revision($document, $id)->setVersion > $previous->setVersion
            ? LegalRevisionOrigin::STANDARD : LegalRevisionOrigin::PROJECT;
    }
}

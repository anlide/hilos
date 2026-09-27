<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Legal\Exception\LegalException;

/** Builds the four diagnostic row payloads for the legal administration boundary. */
final class LegalChecks
{
    /**
     * @param array<string, LegalTally> $tallies Admin projection by document key
     * @return list<array{check: string, ok: bool, items: list<array<string, mixed>>}> Diagnostic wire rows
     * @throws LegalException When the catalog declaration is faulty
     */
    public static function run(array $tallies): array
    {
        $undeclared = $zeroWindow = $newer = $deviations = [];
        $allDeviate = true;
        foreach ($tallies as $tally) {
            foreach ($tally->undeclared as $revisionId => $people) {
                $undeclared[] = ['document' => $tally->document, 'revisionId' => (string) $revisionId, 'people' => $people];
            }
        }
        foreach (LegalCatalogResolver::documents() as $document) {
            foreach (LegalCatalogResolver::revisions($document) as $index => $revision) {
                if (
                    $index > 0 && $revision->significance === LegalSignificance::SUBSTANTIAL
                    && $revision->effectiveOn === $revision->publishedOn
                ) {
                    $zeroWindow[] = ['document' => $document->value, 'revisionId' => $revision->id];
                }
            }
            $current = LegalCatalogResolver::latestRevision($document);
            $latestSet = StandardSetCatalog::latest($document);
            if ($latestSet->version > $current->setVersion) {
                $newer[] = [
                    'document' => $document->value,
                    'documentSetVersion' => $current->setVersion,
                    'setVersion' => $latestSet->version,
                    'significance' => $latestSet->significance->value,
                ];
            }
            $count = count($current->deviations);
            $deviations[] = ['document' => $document->value, 'deviations' => $count];
            $allDeviate = $allDeviate && $count > 0;
        }

        return [
            ['check' => LegalCheck::UNDECLARED_REVISION->value, 'ok' => $undeclared === [], 'items' => $undeclared],
            ['check' => LegalCheck::ZERO_WINDOW->value, 'ok' => $zeroWindow === [], 'items' => $zeroWindow],
            ['check' => LegalCheck::NEWER_STANDARD_SET->value, 'ok' => $newer === [], 'items' => $newer],
            ['check' => LegalCheck::DEVIATIONS->value, 'ok' => $allDeviate, 'items' => $deviations],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Legal\Exception\LegalTextFileMissingException;

/** Serializes legal model values at the browser boundary (HIL-498). */
final class LegalWire
{
    /**
     * @param LegalRevision $revision Published declaration
     * @return array<string, mixed> Revision metadata
     */
    public static function revision(LegalRevision $revision): array
    {
        return [
            'revisionId' => $revision->id,
            'publishedOn' => $revision->publishedOn,
            'effectiveOn' => $revision->effectiveOn,
            'significance' => $revision->significance->value,
            'setVersion' => $revision->setVersion,
            'deviationCount' => count($revision->deviations),
        ];
    }

    /**
     * @param ComposedDocument $document Composed declaration
     * @return list<array<string, mixed>> Full effective clauses with their provenance
     * @throws LegalTextFileMissingException When a clause's file cannot be read
     */
    public static function clauses(ComposedDocument $document): array
    {
        $clauses = [];
        foreach ($document->clauses as $clause) {
            $clauses[] = [
                'clauseKey' => $clause->standard->key,
                'standardStatement' => $clause->standard->statement,
                ...self::side(LegalClauseSide::of($clause)),
            ];
        }

        return $clauses;
    }

    /**
     * @param list<LegalClauseChange> $changes Effective clause differences
     * @return list<array<string, mixed>> Differences on the wire
     */
    public static function changes(array $changes): array
    {
        return array_map(static fn (LegalClauseChange $change): array => [
            'clauseKey' => $change->clauseKey,
            'title' => $change->title,
            'kind' => $change->kind->value,
            'before' => $change->before === null ? null : self::side($change->before),
            'after' => $change->after === null ? null : self::side($change->after),
        ], $changes);
    }

    /**
     * @param LegalClauseSide $side Effective wording
     * @return array<string, mixed> One comparison side
     */
    private static function side(LegalClauseSide $side): array
    {
        return [
            'source' => $side->source->value,
            'statement' => $side->statement,
            'text' => $side->text,
            'direction' => $side->direction?->value,
        ];
    }
}

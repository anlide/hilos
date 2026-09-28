<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Legal\Exception\LegalException;

/** Builds the current documents shown together at registration's consent step. */
final class LegalConsentProjector
{
    /**
     * @return list<array<string, mixed>> Browser boundary: current revisions and their composed clauses
     * @throws LegalException When a declaration or its text cannot be read
     */
    public static function documents(): array
    {
        $documents = [];
        foreach (LegalCatalogResolver::documents() as $document) {
            $revision = LegalCatalogResolver::latestRevision($document);
            $documents[] = [
                'document' => $document->value,
                'revision' => LegalWire::revision($revision),
                'clauses' => LegalWire::clauses(LegalCatalogResolver::compose($document, $revision->id)),
            ];
        }

        return $documents;
    }

    /**
     * @return array<string, string> Boundary map of document values to current revision ids
     * @throws LegalException When the catalog cannot be read
     */
    public static function acceptance(): array
    {
        $accepted = [];
        foreach (LegalCatalogResolver::documents() as $document) {
            $accepted[$document->value] = LegalCatalogResolver::latestRevision($document)->id;
        }

        return $accepted;
    }
}

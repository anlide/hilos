<?php

declare(strict_types=1);

namespace Hilos\Legal\DTO;

use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalTally;
use Hilos\Legal\LegalWire;
use Hilos\Legal\StandardSetCatalog;

/** Declared-document overview without combining people across documents. */
final readonly class LegalOverviewData
{
    public const string DATA = 'legalOverview';
    public const string DOCUMENT_COUNT = 'documentCount';
    public const string DOCUMENTS = 'documents';
    public const string NEWER_SET_COUNT = 'newerSetCount';

    /**
     * @param int $documentCount Declared document count
     * @param list<LegalOverviewDocumentData> $documents Counts in catalog order
     * @param int $newerSetCount Documents whose adopted standard set has a newer version
     */
    public function __construct(
        public int $documentCount,
        public array $documents,
        public int $newerSetCount,
    ) {
    }

    /**
     * @param array<string, LegalTally> $tallies Complete acceptance projection, including every declared document
     * @return self Overview of declared documents only
     * @throws LegalException When the catalog is invalid
     * @throws UnknownRevisionException When a declared document has no current revision
     */
    public static function fromTallies(array $tallies): self
    {
        $documents = [];
        $newerSetCount = 0;
        foreach (LegalCatalogResolver::documents() as $document) {
            $documents[] = new LegalOverviewDocumentData(
                document: $document->value,
                documentLabel: LegalWire::documentLabel($document->value),
                window: $tallies[$document->value]->window,
                lapsed: $tallies[$document->value]->lapsed,
            );
            if (StandardSetCatalog::latest($document)->version > LegalCatalogResolver::latestRevision($document)->setVersion) {
                $newerSetCount++;
            }
        }

        return new self(count($documents), $documents, $newerSetCount);
    }

    /**
     * @return array{
     *     documentCount: int,
     *     documents: list<array{document: string, documentLabel: string, window: int, lapsed: int}>,
     *     newerSetCount: int
     * } Browser overview
     */
    public function toArray(): array
    {
        return [
            self::DOCUMENT_COUNT => $this->documentCount,
            self::DOCUMENTS => array_map(static fn (LegalOverviewDocumentData $document): array => $document->toArray(), $this->documents),
            self::NEWER_SET_COUNT => $this->newerSetCount,
        ];
    }
}

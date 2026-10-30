<?php

declare(strict_types=1);

namespace Hilos\Legal\DTO;

/** Separate acceptance-window counts for one declared document. */
final readonly class LegalOverviewDocumentData
{
    public const string DOCUMENT = 'document';
    public const string DOCUMENT_LABEL = 'documentLabel';
    public const string WINDOW = 'window';
    public const string LAPSED = 'lapsed';

    /**
     * @param string $document Declared document key
     * @param string $documentLabel Catalog document label
     * @param int $window People before their outstanding deadline
     * @param int $lapsed People at or past their outstanding deadline
     */
    public function __construct(
        public string $document,
        public string $documentLabel,
        public int $window,
        public int $lapsed,
    ) {
    }

    /** @return array{document: string, documentLabel: string, window: int, lapsed: int} Browser document counts */
    public function toArray(): array
    {
        return [
            self::DOCUMENT => $this->document,
            self::DOCUMENT_LABEL => $this->documentLabel,
            self::WINDOW => $this->window,
            self::LAPSED => $this->lapsed,
        ];
    }
}

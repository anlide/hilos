<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Legal\Exception\DocumentWithoutRevisionsException;
use Hilos\Legal\Exception\DuplicateDeviationException;
use Hilos\Legal\Exception\DuplicateRevisionIdException;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\Exception\LegalTextFileMissingException;
use Hilos\Legal\Exception\MisplacedRevisionException;
use Hilos\Legal\Exception\SignificanceLoweredException;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\Exception\UnknownStandardClauseException;

/**
 * LegalCatalogResolver - Reads the legal catalog: the declared revisions and their composition.
 *
 * Every read of a legal document goes through here, so the composition rule is stated once: a
 * revision walks the standard set version it adopts in that set's order, and each clause carries
 * the revision's deviation over it where one is declared.
 *
 * The whole catalog is validated on the first read of any document and kept with the answer, so
 * one faulty deviation is refused before any document composes for a live person. Validation is
 * on access rather than at boot, as every other catalog of the framework refuses its faults; the
 * deploy-time half is each project's snapshot test.
 *
 * @see StandardSetCatalog
 * @see LegalCatalogProviderInterface
 */
final class LegalCatalogResolver
{
    /**
     * Validated catalog, kept per provider class.
     *
     * A process runs one project facade and therefore fills one entry. Keyed by the provider
     * rather than held flat so that a second facade - which only a test binds - gets its own
     * answer instead of the first one's.
     *
     * @var array<string, array<string, list<LegalRevision>>> Revisions per document value per provider class
     */
    private static array $catalogs = [];

    /**
     * Returns the documents the installation declares, in `LegalDocument` case order.
     *
     * Empty when the facade binds no legal catalog: such an installation publishes no documents.
     *
     * @return list<LegalDocument> Declared documents
     *
     * @throws LegalException When the catalog declaration is faulty or a text file it names is missing
     */
    public static function documents(): array
    {
        $catalog = self::catalog();

        return array_values(array_filter(
            LegalDocument::cases(),
            static fn (LegalDocument $document): bool => isset($catalog[$document->value]),
        ));
    }

    /**
     * Returns a document's revisions in declaration order; empty for a document nobody declares.
     *
     * @param LegalDocument $document Document whose revisions to list
     * @return list<LegalRevision> Declared revisions of the document
     *
     * @throws LegalException When the catalog declaration is faulty or a text file it names is missing
     */
    public static function revisions(LegalDocument $document): array
    {
        return self::catalog()[$document->value] ?? [];
    }

    /**
     * @param LegalDocument $document Document the revision belongs to
     * @param string $id Revision id
     * @return LegalRevision Declared revision
     *
     * @throws UnknownRevisionException When the document declares no revision under that id
     * @throws LegalException When the catalog declaration is faulty or a text file it names is missing
     */
    public static function revision(LegalDocument $document, string $id): LegalRevision
    {
        foreach (self::revisions($document) as $revision) {
            if ($revision->id === $id) {
                return $revision;
            }
        }

        throw new UnknownRevisionException("Legal document {$document->value} declares no revision {$id}");
    }

    /**
     * Returns the revision declared last for a document.
     *
     * Last in declaration order and nothing more: which revision is in force on a given date needs
     * effective dates to mean something, which they do not yet (HIL-498).
     *
     * @param LegalDocument $document Document whose revision to read
     * @return LegalRevision Last declared revision
     *
     * @throws UnknownRevisionException When the installation declares no revision of the document
     * @throws LegalException When the catalog declaration is faulty or a text file it names is missing
     */
    public static function latestRevision(LegalDocument $document): LegalRevision
    {
        $revisions = self::revisions($document);
        if ($revisions === []) {
            throw new UnknownRevisionException("Legal document {$document->value} declares no revisions");
        }

        return $revisions[array_key_last($revisions)];
    }

    /**
     * Composes a revision over the standard set version it adopts.
     *
     * @param LegalDocument $document Document the revision belongs to
     * @param string $id Revision id
     * @return ComposedDocument Revision with every clause of its set, in the set's order
     *
     * @throws UnknownRevisionException When the document declares no revision under that id
     * @throws LegalException When the catalog declaration is faulty or a text file it names is missing
     */
    public static function compose(LegalDocument $document, string $id): ComposedDocument
    {
        $revision = self::revision($document, $id);
        // Validation already proved the set version exists, so this read cannot refuse.
        $set = StandardSetCatalog::set($document, $revision->setVersion);

        $deviations = [];
        foreach ($revision->deviations as $deviation) {
            $deviations[$deviation->clauseKey] = $deviation;
        }

        $clauses = [];
        foreach ($set->clauses as $clause) {
            $clauses[] = new ComposedClause($clause, $deviations[$clause->key] ?? null);
        }

        return new ComposedDocument($document, $revision, $set, $clauses);
    }

    /**
     * Returns the full text of a clause or a deviation, without surrounding blank lines.
     *
     * Plain UTF-8, paragraphs separated by one blank line, no markup.
     *
     * @param string $textFile Absolute path of the text file a clause or deviation declares
     * @return string Text of the file
     *
     * @throws LegalTextFileMissingException When the file is absent, unreadable or holds no text
     */
    public static function text(string $textFile): string
    {
        try {
            $text = trim(FsPath::read($textFile));
        } catch (FileNotFoundException | FileReadException $e) {
            throw new LegalTextFileMissingException($textFile, $e);
        }

        if ($text === '') {
            throw new LegalTextFileMissingException($textFile);
        }

        return $text;
    }

    /**
     * @return array<string, list<LegalRevision>> Validated revisions per document value; empty when no catalog is bound
     */
    private static function catalog(): array
    {
        $provider = Hilos::legalCatalogClass();
        if ($provider === null) {
            return [];
        }

        return self::$catalogs[$provider] ??= self::validated($provider::revisions());
    }

    /**
     * Validates a whole declaration and hands it back unchanged.
     *
     * @param array<array-key, list<LegalRevision>> $declared Revisions per document key, as the provider declared them
     * @return array<string, list<LegalRevision>> The same declaration, proven sound
     */
    private static function validated(array $declared): array
    {
        $checkedTextFiles = [];
        foreach ($declared as $documentKey => $revisions) {
            $documentKey = (string) $documentKey;
            if ($revisions === []) {
                throw new DocumentWithoutRevisionsException($documentKey);
            }

            $ids = [];
            $predecessor = null;
            foreach ($revisions as $revision) {
                if ($revision->document->value !== $documentKey) {
                    throw new MisplacedRevisionException($documentKey, $revision->document, $revision->id);
                }
                if (isset($ids[$revision->id])) {
                    throw new DuplicateRevisionIdException($revision->document, $revision->id);
                }
                $ids[$revision->id] = true;

                $set = StandardSetCatalog::set($revision->document, $revision->setVersion);
                if ($predecessor !== null && $revision->lowersAdoptedSetSignificance($predecessor, $set)) {
                    throw new SignificanceLoweredException($revision->document, $revision->id, $set->version, $set->significance);
                }
                $predecessor = $revision;

                $textFiles = array_merge(
                    array_map(static fn (StandardClause $clause): string => $clause->textFile, $set->clauses),
                    self::validatedDeviationTextFiles($revision, $set),
                );
                foreach ($textFiles as $textFile) {
                    if (!isset($checkedTextFiles[$textFile])) {
                        self::text($textFile);
                        $checkedTextFiles[$textFile] = true;
                    }
                }
            }
        }

        return $declared;
    }

    /**
     * Checks that a revision's deviations each name a distinct clause of its set.
     *
     * @param LegalRevision $revision Revision whose deviations to check
     * @param StandardSet $set Set version the revision adopts
     * @return list<string> Text files the deviations declare
     *
     * @throws UnknownStandardClauseException When a deviation names a clause the set does not carry
     * @throws DuplicateDeviationException When two deviations name one clause
     */
    private static function validatedDeviationTextFiles(LegalRevision $revision, StandardSet $set): array
    {
        $clauseKeys = array_map(static fn (StandardClause $clause): string => $clause->key, $set->clauses);
        $deviated = [];
        foreach ($revision->deviations as $deviation) {
            if (!in_array($deviation->clauseKey, $clauseKeys, true)) {
                throw new UnknownStandardClauseException($revision->document, $revision->id, $deviation->clauseKey, $set->version);
            }
            if (isset($deviated[$deviation->clauseKey])) {
                throw new DuplicateDeviationException($revision->document, $revision->id, $deviation->clauseKey);
            }
            $deviated[$deviation->clauseKey] = $deviation->textFile;
        }

        return array_values($deviated);
    }
}

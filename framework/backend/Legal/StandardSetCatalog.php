<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Legal\Exception\UnknownStandardSetVersionException;

/**
 * StandardSetCatalog - the framework's standard sets of both legal documents, every version ever published.
 *
 * The standard set is framework functionality and identical in every Hilos project; a project
 * departs from it only through the deviations of its own revisions. Past versions are never
 * removed: a revision standing on an older set must keep composing after a newer one ships, and
 * publishing a revised standard text is simply the next version appended here.
 *
 * Declared by methods rather than by one constant array, as the page catalog is: a clause is a
 * typed object, and PHP does not allow `new` inside a class constant.
 *
 * @see LegalCatalogResolver
 */
final class StandardSetCatalog
{
    /** @var string Clause: personal data is not sold */
    public const string CLAUSE_NO_SALE = 'standard.no_sale';

    /** @var string Clause: an account is deleted on request */
    public const string CLAUSE_DELETION = 'standard.deletion';

    /** @var string Clause: personal data can be exported at any time */
    public const string CLAUSE_EXPORT = 'standard.export';

    /** @var string Clause: passwords are never stored in readable form */
    public const string CLAUSE_PASSWORDS = 'standard.passwords';

    /** @var string Clause: how long access logs are kept */
    public const string CLAUSE_ACCESS_LOG = 'standard.access_log';

    /** @var string Clause: what a sign-in session records */
    public const string CLAUSE_SESSION_DATA = 'standard.session_data';

    /** @var string Clause: a person is told when their data leaks */
    public const string CLAUSE_BREACH_NOTICE = 'standard.breach_notice';

    /** @var string Clause: who can reach an uploaded file */
    public const string CLAUSE_FILE_ACCESS = 'standard.file_access';

    /** @var string Clause: how long content is kept */
    public const string CLAUSE_RETENTION = 'standard.retention';

    /** @var string Clause: who reads content */
    public const string CLAUSE_MODERATION = 'standard.moderation';

    /** @var string Clause: what availability is promised */
    public const string CLAUSE_AVAILABILITY = 'standard.availability';

    /** @var string Clause: what gets an account blocked */
    public const string CLAUSE_ACCOUNT_RULES = 'standard.account_rules';

    /** @var string Clause: who owns written content */
    public const string CLAUSE_OWNERSHIP = 'standard.ownership';

    /** @var string Directory of the framework's clause text files, one subdirectory per document */
    private const string TEXT_DIRECTORY = __DIR__ . '/Text';

    /** @var string Publication date of the terms standard set version 1 */
    private const string TERMS_SET_1_PUBLISHED_ON = '2026-09-17';

    /** @var string Publication date of the privacy standard set version 1 */
    private const string PRIVACY_SET_1_PUBLISHED_ON = '2026-09-17';

    /**
     * Built sets, per document value.
     *
     * Both halves are fixed for the life of the process, so each document's sets are built once.
     *
     * @var array<string, list<StandardSet>> Set versions in ascending order per document value
     */
    private static array $sets = [];

    /**
     * @param LegalDocument $document Document whose sets to list
     * @return list<StandardSet> Every published set version of the document, ascending
     */
    public static function sets(LegalDocument $document): array
    {
        return self::$sets[$document->value] ??= match ($document) {
            LegalDocument::TERMS => self::termsSets(),
            LegalDocument::PRIVACY => self::privacySets(),
        };
    }

    /**
     * @param LegalDocument $document Document whose set to read
     * @param int $version Set version
     * @return StandardSet Set of that version
     *
     * @throws UnknownStandardSetVersionException When the framework never published that version
     */
    public static function set(LegalDocument $document, int $version): StandardSet
    {
        foreach (self::sets($document) as $set) {
            if ($set->version === $version) {
                return $set;
            }
        }

        throw new UnknownStandardSetVersionException($document, $version);
    }

    /**
     * @param LegalDocument $document Document whose set to read
     * @return StandardSet Highest published set version of the document
     */
    public static function latest(LegalDocument $document): StandardSet
    {
        $sets = self::sets($document);

        return $sets[array_key_last($sets)];
    }

    /**
     * @return list<StandardSet> Terms set versions, ascending
     */
    private static function termsSets(): array
    {
        $document = LegalDocument::TERMS;
        $on = self::TERMS_SET_1_PUBLISHED_ON;

        return [
            new StandardSet($document, 1, $on, LegalSignificance::SUBSTANTIAL, [
                self::clause($document, self::CLAUSE_FILE_ACCESS, 'Files are reachable only by their owner.', $on),
                self::clause($document, self::CLAUSE_RETENTION, 'Content is kept for 12 months.', $on),
                self::clause($document, self::CLAUSE_MODERATION, 'Content is read by automation only.', $on),
                self::clause(
                    $document,
                    self::CLAUSE_AVAILABILITY,
                    'The service is provided as is, with no uptime promise.',
                    $on,
                ),
                self::clause($document, self::CLAUSE_ACCOUNT_RULES, 'What gets an account blocked.', $on),
                self::clause($document, self::CLAUSE_OWNERSHIP, 'What you write stays yours.', $on),
            ]),
        ];
    }

    /**
     * @return list<StandardSet> Privacy set versions, ascending
     */
    private static function privacySets(): array
    {
        $document = LegalDocument::PRIVACY;
        $on = self::PRIVACY_SET_1_PUBLISHED_ON;

        return [
            new StandardSet($document, 1, $on, LegalSignificance::SUBSTANTIAL, [
                self::clause($document, self::CLAUSE_NO_SALE, 'Your data is not sold.', $on),
                self::clause($document, self::CLAUSE_DELETION, 'Your account is deleted on request.', $on),
                self::clause($document, self::CLAUSE_EXPORT, 'You can export your data at any time.', $on),
                self::clause($document, self::CLAUSE_PASSWORDS, 'Passwords are never stored in readable form.', $on),
                self::clause($document, self::CLAUSE_ACCESS_LOG, 'Access logs are kept for 12 months.', $on),
                self::clause($document, self::CLAUSE_SESSION_DATA, 'What a sign-in session records about you.', $on),
                self::clause($document, self::CLAUSE_BREACH_NOTICE, 'You are told if your data leaks.', $on),
            ]),
        ];
    }

    /**
     * Declares one clause whose text file is named by its key and the date that text was written.
     *
     * A clause unchanged in a later set version passes the date of the version that wrote it, so
     * both versions point at the same file.
     *
     * @param LegalDocument $document Document the clause belongs to
     * @param string $key Clause key
     * @param string $statement One-line human statement
     * @param string $writtenOn Date the clause's text was written, `YYYY-MM-DD`
     * @return StandardClause Declared clause
     */
    private static function clause(LegalDocument $document, string $key, string $statement, string $writtenOn): StandardClause
    {
        return new StandardClause($key, $statement, self::TEXT_DIRECTORY . "/{$document->value}/{$key}.{$writtenOn}.txt");
    }
}

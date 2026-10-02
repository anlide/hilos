<?php

declare(strict_types=1);

namespace Hilos\Auth\AccessLog;

use Hilos\Hilos;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\StandardSetCatalog;

/**
 * What the framework records about the use of an account, read from the project's privacy text (HIL-1174).
 *
 * The text is the switch, and there is no other: the current privacy revision - the one declared
 * last - deviating from standard.access_log keeps no access log, and deviating from
 * standard.session_data keeps no address on a session. Which way the text deviates is not read;
 * a deviation means the standard promise is not the one the project makes. An installation
 * without a legal catalog, or without a privacy document, keeps both, as the standard says.
 *
 * Each answer is kept per catalog provider for the life of the process, as
 * {@see LegalCatalogResolver} keeps the catalog: a process runs one facade, and a second one,
 * which only a test binds, gets its own answer instead of the first one's.
 */
final class AccessLogPolicy
{
    /** How long a row of the access log lives, as standard.access_log promises. */
    public const int RETENTION_MONTHS = 12;

    /** @var array<string, bool> Whether the access log is kept, per catalog provider class */
    private static array $keepsLog = [];

    /** @var array<string, bool> Whether a session keeps its address, per catalog provider class */
    private static array $keepsSessionAddress = [];

    /**
     * @return bool Whether the access log is written and kept
     * @throws LegalException When the catalog declaration is faulty or a text file it names is missing
     */
    public static function keepsLog(): bool
    {
        $provider = Hilos::legalCatalogClass();
        if ($provider === null) {
            return true;
        }

        return self::$keepsLog[$provider] ??= !self::currentPrivacyDeviatesFrom(StandardSetCatalog::CLAUSE_ACCESS_LOG);
    }

    /**
     * @return bool Whether a session keeps the address of its last connection
     * @throws LegalException When the catalog declaration is faulty or a text file it names is missing
     */
    public static function keepsSessionAddress(): bool
    {
        $provider = Hilos::legalCatalogClass();
        if ($provider === null) {
            return true;
        }

        return self::$keepsSessionAddress[$provider]
            ??= !self::currentPrivacyDeviatesFrom(StandardSetCatalog::CLAUSE_SESSION_DATA);
    }

    /**
     * Returns the moment before which a row of the access log is past its life.
     *
     * @param int $now Current moment as a Unix timestamp
     * @return string SQL datetime RETENTION_MONTHS months before the given moment
     */
    public static function cutoff(int $now): string
    {
        return date('Y-m-d H:i:s', (int)strtotime('-' . self::RETENTION_MONTHS . ' months', $now));
    }

    /**
     * @param string $clauseKey Standard privacy clause to look for
     * @return bool Whether the current privacy revision deviates from that clause; false when none is declared
     * @throws LegalException When the catalog declaration is faulty or a text file it names is missing
     */
    private static function currentPrivacyDeviatesFrom(string $clauseKey): bool
    {
        $revisions = LegalCatalogResolver::revisions(LegalDocument::PRIVACY);
        if ($revisions === []) {
            return false;
        }

        foreach ($revisions[array_key_last($revisions)]->deviations as $deviation) {
            if ($deviation->clauseKey === $clauseKey) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalDocumentStanding;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalStanding;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\LegalTally;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * The one place the standing of an account is composed (HIL-945).
 *
 * Three facts go in, each read from where it is stored or derived: the block from the person's
 * row, the scheduled deletion from the person's standing request, and the lapsed documents from
 * the person's acceptance records against the declared revisions on the server's date. The freeze
 * is then the lapsed documents under the installation's refusal setting - nothing stores it, so
 * a revision published with a past effective date freezes everyone it catches the moment the
 * node starts with it, with no sweep and no cron.
 *
 * The page guard ({@see PageAccessGate}) asks here on every delivery, so each process keeps the
 * verdict per person in memory. The memory belongs to one epoch - the server's date and the
 * refusal setting - and a new epoch starts empty, which is how a deadline that has just passed and
 * a setting just changed reach every verdict without anybody announcing them. Within an epoch a
 * person's verdict is dropped by the change of any fact it was built from:
 * {@see AccountStandingChangeSubscriber} hears the writes of the three sources in every process,
 * because all three are read process-wide.
 *
 * A faulty catalog lapses nobody: what there is to accept is unknown, and closing the product to
 * everyone for the author's mistake is not the answer - the fault is shown in red on the root of
 * the legal section instead. A project without a catalog declares no documents, so nobody lapses
 * there either. People who never accepted anything are not lapsed ({@see LegalStanding::NONE}).
 */
final class AccountStandingResolver
{
    /** Glue between the two halves of the epoch key; a calendar date never contains it. */
    private const string EPOCH_SEPARATOR = '|';

    /** @var array<int, AccountStanding> Verdicts composed in this epoch, keyed by person */
    private static array $standings = [];

    /** @var array<string, list<int>> People lapsed on each document in this epoch, keyed by document */
    private static array $lapsedUserIds = [];

    /** Date and refusal setting the remembered verdicts were composed under, or null before the first */
    private static ?string $epoch = null;

    /**
     * The standing of one person, from memory when this epoch already composed it.
     *
     * @param int $userId Person whose standing to tell
     * @return AccountStanding The composed verdict
     * @throws DatabaseException When the person, the deletion request, the acceptance records or the setting cannot be read
     * @throws SettingException When the refusal setting is invalid
     * @throws InvalidArgumentException When a loaded object does not match its collection or a query is invalid
     * @throws LogicException When a collection is not configured
     * @throws ObjectGetIdStringNotImplementedException When a loaded row lacks its primary key
     */
    public static function of(int $userId): AccountStanding
    {
        $today = LegalStandingResolver::today();
        $refusal = LegalSettings::refusal();
        self::enterEpoch($today, $refusal);

        return self::$standings[$userId] ??= self::read($userId, $today, $refusal);
    }

    /**
     * Whether the person is frozen: using the product is closed to them, the exits stay open.
     *
     * @param int $userId Person asked about
     * @return bool Whether the person is frozen now
     * @throws DatabaseException When the person, the deletion request, the acceptance records or the setting cannot be read
     * @throws SettingException When the refusal setting is invalid
     * @throws InvalidArgumentException When a loaded object does not match its collection or a query is invalid
     * @throws LogicException When a collection is not configured
     * @throws ObjectGetIdStringNotImplementedException When a loaded row lacks its primary key
     */
    public static function isFrozen(int $userId): bool
    {
        return self::of($userId)->frozen;
    }

    /**
     * Composes the verdict out of the three facts - the only composition there is.
     *
     * Shown is the fact that takes the most away: blocked, then frozen, then deletion scheduled.
     *
     * @param bool $blocked Whether an administrator blocked the account
     * @param ?int $deletionEffectiveAt Moment the scheduled erasure falls due in milliseconds, or null when none is scheduled
     * @param list<LegalDocumentStanding> $lapsed Documents past their deadline for this person
     * @param string $refusal Treatment of a refusal after the deadline ({@see LegalSettings::refusal()})
     * @return AccountStanding The verdict
     */
    public static function compose(bool $blocked, ?int $deletionEffectiveAt, array $lapsed, string $refusal): AccountStanding
    {
        $frozen = $lapsed !== [] && $refusal === LegalSettings::REFUSAL_FREEZE;

        return new AccountStanding(
            match (true) {
                $blocked => AccountStandingKind::BLOCKED,
                $frozen => AccountStandingKind::FROZEN,
                $deletionEffectiveAt !== null => AccountStandingKind::DELETION_SCHEDULED,
                default => AccountStandingKind::NONE,
            },
            $blocked,
            $frozen,
            $deletionEffectiveAt,
            $lapsed,
        );
    }

    /**
     * The people lapsed on one document, whatever the refusal setting says.
     *
     * One query for the whole table, then the same judgement {@see LegalTally} gives each held
     * revision, so the list and the third count of the legal section's root agree by construction.
     * Remembered per document for the epoch, like a verdict, and dropped with any verdict: the
     * list of people is read on every tick of an open filtered list.
     *
     * @param LegalDocument $document Document asked about
     * @return list<int> People whose latest declared acceptance of the document is past its deadline
     * @throws DatabaseException When the acceptance records or the setting cannot be read
     * @throws SettingException When the refusal setting is invalid
     */
    public static function lapsedUserIds(LegalDocument $document): array
    {
        $today = LegalStandingResolver::today();
        self::enterEpoch($today, LegalSettings::refusal());

        return self::$lapsedUserIds[$document->value] ??= self::readLapsedUserIds($document, $today);
    }

    /**
     * Drops one person's verdict: a fact it was composed from has just changed.
     *
     * The lists of lapsed people go with it: they are few, cheap to read again, and a change of
     * one person's acceptance is a change of them.
     *
     * @param int $userId Person whose verdict is stale
     */
    public static function forget(int $userId): void
    {
        unset(self::$standings[$userId]);
        self::$lapsedUserIds = [];
    }

    /**
     * Drops every verdict: a change was heard that does not say whose it is.
     */
    public static function forgetAll(): void
    {
        self::$standings = [];
        self::$lapsedUserIds = [];
    }

    /**
     * Starts a new epoch when the date or the refusal setting moved: nothing remembered survives it.
     *
     * @param string $today Server's calendar date, YYYY-MM-DD
     * @param string $refusal Refusal setting
     */
    private static function enterEpoch(string $today, string $refusal): void
    {
        $epoch = $today . self::EPOCH_SEPARATOR . $refusal;
        if ($epoch !== self::$epoch) {
            self::$standings = [];
            self::$lapsedUserIds = [];
            self::$epoch = $epoch;
        }
    }

    /**
     * Reads the three facts of one person and composes them.
     *
     * @param int $userId Person whose standing to compose
     * @param string $today Server's calendar date of this epoch, YYYY-MM-DD
     * @param string $refusal Refusal setting of this epoch
     * @return AccountStanding The verdict, with nothing held when the process has no database layer
     * @throws DatabaseException When the person, the deletion request or the acceptance records cannot be read
     * @throws InvalidArgumentException When a loaded object does not match its collection or a query is invalid
     * @throws LogicException When a collection is not configured
     * @throws ObjectGetIdStringNotImplementedException When a loaded row lacks its primary key
     */
    private static function read(int $userId, string $today, string $refusal): AccountStanding
    {
        $db = Hilos::$db;
        if ($db === null) {
            return self::compose(false, null, [], $refusal);
        }

        $deletion = $db->accountDeletions->liveOf($userId);

        return self::compose(
            ($db->users[$userId] ?? null)?->block === true,
            $deletion === null ? null : TimeHelper::sqlToMs($deletion->effectiveAt),
            self::lapsedOf($db, $userId, $today),
            $refusal,
        );
    }

    /**
     * The documents one person is past the deadline of on the given date.
     *
     * @param HilosDbContext $db Database layer the acceptance records are read from
     * @param int $userId Person judged
     * @param string $today Server's calendar date, YYYY-MM-DD
     * @return list<LegalDocumentStanding> Lapsed documents in declaration order; none under a faulty catalog
     * @throws DatabaseException When the acceptance records cannot be read
     * @throws InvalidArgumentException When a loaded object does not match its collection or the query is invalid
     * @throws LogicException When the acceptance collection is not configured
     * @throws ObjectGetIdStringNotImplementedException When a loaded row lacks its primary key
     */
    private static function lapsedOf(HilosDbContext $db, int $userId, string $today): array
    {
        try {
            $documents = LegalCatalogResolver::documents();
        } catch (LegalException) {
            return [];
        }
        if ($documents === []) {
            return [];
        }

        $accepted = [];
        foreach ($db->legalAcceptances->ofUser($userId) as $acceptance) {
            $accepted[$acceptance->document][] = $acceptance->revisionId;
        }
        $lapsed = [];
        try {
            foreach ($documents as $document) {
                $standing = LegalStandingResolver::standingOf($document, $accepted[$document->value] ?? [], $today);
                if ($standing->standing === LegalStanding::LAPSED) {
                    $lapsed[] = $standing;
                }
            }
        } catch (LegalException) {
            return [];
        }

        return $lapsed;
    }

    /**
     * Reads the people lapsed on one document on the given date.
     *
     * @param LegalDocument $document Document asked about
     * @param string $today Server's calendar date of this epoch, YYYY-MM-DD
     * @return list<int> People whose latest declared acceptance of the document is past its deadline
     * @throws DatabaseException When the acceptance records cannot be read
     */
    private static function readLapsedUserIds(LegalDocument $document, string $today): array
    {
        try {
            $revisionIds = in_array($document, LegalCatalogResolver::documents(), true)
                ? array_map(static fn (LegalRevision $revision): string => $revision->id, LegalCatalogResolver::revisions($document))
                : [];
            $lapsedRevisionIds = array_filter(
                $revisionIds,
                static fn (string $id): bool => LegalStandingResolver::standingOf($document, [$id], $today)->standing
                    === LegalStanding::LAPSED,
            );
        } catch (LegalException) {
            return [];
        }
        if ($lapsedRevisionIds === [] || Hilos::$db === null) {
            return [];
        }

        $userIds = [];
        foreach (Hilos::$db->legalAcceptances->heldByUser($document->value, $revisionIds) as $userId => $revisionId) {
            if (in_array($revisionId, $lapsedRevisionIds, true)) {
                $userIds[] = $userId;
            }
        }

        return $userIds;
    }
}

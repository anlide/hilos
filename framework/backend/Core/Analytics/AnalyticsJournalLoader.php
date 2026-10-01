<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;

/**
 * Loads one ready journal file into the analytics tables, whole and once.
 *
 * The file is one transaction: its records are applied in order, the file is remembered in
 * `hilos_analytics_journal_file` and the transaction commits; a failure rolls everything back,
 * and the same file is loaded again later from its first line. Remembering the file in the
 * same transaction as its rows is what makes a repeated load - a lost confirmation, a writer
 * that moved - write nothing: the second load finds the file remembered and stops there.
 *
 * A record that cannot be applied is passed over and counted ({@see AnalyticsJournalSkip}):
 * the broken tail a machine crash leaves, a type this writer does not know, a session no
 * description wrote, a rename onto a taken token, a fact of a vanished API request. A file is
 * never refused for its records - one poisoned file would stop its node's journal for good.
 *
 * The record catalog is {@see AnalyticsJournalRecord}; the rules of each record are in
 * docs/agents/architecture/analytics.md.
 */
final class AnalyticsJournalLoader
{
    private const string KIND_SESSION_KEY = 'session_key';
    private const string KIND_NAME = 'name';
    private const string KIND_SHORT_NAME = 'short_name';
    private const string KIND_NULLABLE_SHORT_NAME = 'nullable_short_name';
    private const string KIND_LONG_NAME = 'long_name';
    private const string KIND_NULLABLE_TEXT = 'nullable_text';
    private const string KIND_INT = 'int';
    private const string KIND_UNSIGNED_INT = 'unsigned_int';
    private const string KIND_NULLABLE_INT = 'nullable_int';
    private const string KIND_BOOL = 'bool';
    private const string KIND_PAYLOAD = 'payload';

    /** @var int Widest value of a `VARCHAR(100)` column the records feed: names, tokens, accept keys */
    private const int NAME_MAX_CHARS = 100;

    /** @var int Widest value of a `VARCHAR(50)` column the records feed: agent type and index, identity type */
    private const int SHORT_NAME_MAX_CHARS = 50;

    /** @var int Widest value of the `VARCHAR(255)` identity value */
    private const int LONG_NAME_MAX_CHARS = 255;

    /** @var int Largest value of an `INT UNSIGNED` column the records feed: the worker index */
    private const int UNSIGNED_INT_MAX = 4294967295;

    /**
     * The fields every record type must carry, and of what kind; the type key itself aside.
     *
     * A value of the wrong kind - or too wide or too large for its column, which the database would refuse
     * and so refuse the whole file over and over - makes the record malformed.
     *
     * @var array<string, array<string, string>>
     */
    private const array FIELDS = [
        AnalyticsJournalRecord::TYPE_WORKER_SESSION => [
            AnalyticsJournalRecord::KEY_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_WORKER_INDEX => self::KIND_UNSIGNED_INT,
            AnalyticsJournalRecord::KEY_MONOPOLISTIC => self::KIND_BOOL,
            AnalyticsJournalRecord::KEY_STARTED_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_WORKER_SESSION_STOP => [
            AnalyticsJournalRecord::KEY_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_AGENT_SESSION => [
            AnalyticsJournalRecord::KEY_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_WORKER_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_AGENT_TYPE => self::KIND_SHORT_NAME,
            AnalyticsJournalRecord::KEY_AGENT_INDEX => self::KIND_NULLABLE_SHORT_NAME,
            AnalyticsJournalRecord::KEY_STARTED_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_AGENT_SESSION_STOP => [
            AnalyticsJournalRecord::KEY_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_AGENT_USER_ACTION => [
            AnalyticsJournalRecord::KEY_AGENT_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_USER_ACTION_ID => self::KIND_NULLABLE_INT,
            AnalyticsJournalRecord::KEY_SIGNAL => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_PAYLOAD => self::KIND_PAYLOAD,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_AGENT_SYSTEM_SIGNAL => [
            AnalyticsJournalRecord::KEY_AGENT_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_SIGNAL => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_PAYLOAD => self::KIND_PAYLOAD,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_AGENT_CRON_SIGNAL => [
            AnalyticsJournalRecord::KEY_AGENT_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_CRON => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_PAYLOAD => self::KIND_PAYLOAD,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_WORKER_SYSTEM_SIGNAL => [
            AnalyticsJournalRecord::KEY_WORKER_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_SIGNAL => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_PAYLOAD => self::KIND_PAYLOAD,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_API_AGENT_ACTION => [
            AnalyticsJournalRecord::KEY_API_REQUEST_ID => self::KIND_INT,
            AnalyticsJournalRecord::KEY_AGENT_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_SIGNAL => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_PAYLOAD => self::KIND_PAYLOAD,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_WS_CONNECTION_ATTACH => [
            AnalyticsJournalRecord::KEY_ACCEPT_KEY => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_SESSION_TOKEN => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_USER_AGENT => self::KIND_NULLABLE_TEXT,
            AnalyticsJournalRecord::KEY_ACCEPT_LANGUAGE => self::KIND_NULLABLE_TEXT,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_BROWSER_SESSION_RENAME => [
            AnalyticsJournalRecord::KEY_OLD_TOKEN => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_NEW_TOKEN => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_BROWSER_SESSION_IDENTITY => [
            AnalyticsJournalRecord::KEY_SESSION_TOKEN => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_IDENTITY_TYPE => self::KIND_SHORT_NAME,
            AnalyticsJournalRecord::KEY_IDENTITY_VALUE => self::KIND_LONG_NAME,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
    ];

    /**
     * @param AnalyticsStore $store Store the writer loads through; its cache outlives one file
     */
    public function __construct(private readonly AnalyticsStore $store)
    {
    }

    /**
     * Loads a ready journal file, unless an earlier load already remembered it.
     *
     * @param string $nodeId Cluster node id of the file's node, '' outside a cluster
     * @param string $fileName Name of the ready file
     * @param list<string> $lines Every line of the file, in order, without line breaks
     * @return AnalyticsJournalLoadOutcome What was written and what was passed over
     * @throws HilosException When the database refuses the load; nothing of the file is written then
     */
    public function load(string $nodeId, string $fileName, array $lines): AnalyticsJournalLoadOutcome
    {
        if ($this->store->isFileLoaded($nodeId, $fileName)) {
            return AnalyticsJournalLoadOutcome::alreadyLoaded();
        }

        Database::transactionStart();
        $this->store->beginStaging();
        try {
            $recordCount = 0;
            $skipped = [];
            foreach ($lines as $line) {
                $record = json_decode($line, true);
                if (is_array($record) && ($record[AnalyticsJournalRecord::KEY_TYPE] ?? null) === AnalyticsJournalRecord::TYPE_JOURNAL) {
                    continue;
                }

                $recordCount++;
                $skip = is_array($record) ? $this->apply($record) : AnalyticsJournalSkip::MALFORMED;
                if ($skip !== null) {
                    $skipped[$skip->value] = ($skipped[$skip->value] ?? 0) + 1;
                }

                if ($this->store->pendingFactCount() >= AnalyticsStore::FACT_PORTION_ROWS) {
                    $skipped = $this->countDropped($skipped, $this->store->flushFacts());
                }
            }

            $skipped = $this->countDropped($skipped, $this->store->flushFacts());
            $this->store->markFileLoaded($nodeId, $fileName, $recordCount, (int)floor(microtime(true) * 1000));
            Database::transactionCommit();
        } catch (HilosException $failure) {
            // First, so a rollback that fails in its turn cannot leave numbers of rows that never were.
            $this->store->discardStaging();
            Database::transactionRollback();
            throw $failure;
        }

        $this->store->commitStaging();

        return new AnalyticsJournalLoadOutcome(false, $recordCount, $skipped);
    }

    /**
     * Applies one decoded record.
     *
     * @param array<mixed> $record Decoded record
     * @return ?AnalyticsJournalSkip Why the record was passed over, or null when it was applied
     * @throws DatabaseException When a statement fails
     */
    private function apply(array $record): ?AnalyticsJournalSkip
    {
        $type = $record[AnalyticsJournalRecord::KEY_TYPE] ?? null;
        if (!is_string($type)) {
            return AnalyticsJournalSkip::MALFORMED;
        }

        $fields = self::FIELDS[$type] ?? null;
        if ($fields === null) {
            return AnalyticsJournalSkip::UNKNOWN_TYPE;
        }

        if (!$this->isWellFormed($record, $fields)) {
            return AnalyticsJournalSkip::MALFORMED;
        }

        return match ($type) {
            AnalyticsJournalRecord::TYPE_WORKER_SESSION => $this->applyWorkerSession($record),
            AnalyticsJournalRecord::TYPE_WORKER_SESSION_STOP => $this->applyWorkerSessionStop($record),
            AnalyticsJournalRecord::TYPE_AGENT_SESSION => $this->applyAgentSession($record),
            AnalyticsJournalRecord::TYPE_AGENT_SESSION_STOP => $this->applyAgentSessionStop($record),
            AnalyticsJournalRecord::TYPE_AGENT_USER_ACTION,
            AnalyticsJournalRecord::TYPE_AGENT_SYSTEM_SIGNAL,
            AnalyticsJournalRecord::TYPE_AGENT_CRON_SIGNAL,
            AnalyticsJournalRecord::TYPE_API_AGENT_ACTION => $this->applyAgentFact($type, $record),
            AnalyticsJournalRecord::TYPE_WORKER_SYSTEM_SIGNAL => $this->applyWorkerSystemSignal($record),
            AnalyticsJournalRecord::TYPE_WS_CONNECTION_ATTACH => $this->applyWsConnectionAttach($record),
            AnalyticsJournalRecord::TYPE_BROWSER_SESSION_RENAME => $this->applyBrowserSessionRename($record),
            AnalyticsJournalRecord::TYPE_BROWSER_SESSION_IDENTITY => $this->applyBrowserSessionIdentity($record),
        };
    }

    /**
     * @param array<mixed> $record Well-formed worker session description
     * @return ?AnalyticsJournalSkip Always null: the session is inserted, or found when an earlier description wrote it
     * @throws DatabaseException When the upsert fails
     */
    private function applyWorkerSession(array $record): ?AnalyticsJournalSkip
    {
        $this->store->ensureWorkerSession(
            $record[AnalyticsJournalRecord::KEY_KEY],
            $record[AnalyticsJournalRecord::KEY_WORKER_INDEX],
            $record[AnalyticsJournalRecord::KEY_MONOPOLISTIC],
            $record[AnalyticsJournalRecord::KEY_STARTED_TS],
        );

        return null;
    }

    /**
     * @param array<mixed> $record Well-formed worker session stop
     * @return ?AnalyticsJournalSkip Unknown session, or null when the session was stamped (or already was)
     * @throws DatabaseException When a statement fails
     */
    private function applyWorkerSessionStop(array $record): ?AnalyticsJournalSkip
    {
        $workerSessionId = $this->store->findWorkerSession($record[AnalyticsJournalRecord::KEY_KEY]);
        if ($workerSessionId === null) {
            return AnalyticsJournalSkip::UNKNOWN_SESSION;
        }

        $this->store->stopWorkerSession($workerSessionId, $record[AnalyticsJournalRecord::KEY_TS]);

        return null;
    }

    /**
     * @param array<mixed> $record Well-formed agent session description
     * @return ?AnalyticsJournalSkip Unknown worker session, or null when the agent session stands
     * @throws DatabaseException When a statement fails
     */
    private function applyAgentSession(array $record): ?AnalyticsJournalSkip
    {
        $workerSessionId = $this->store->findWorkerSession($record[AnalyticsJournalRecord::KEY_WORKER_KEY]);
        if ($workerSessionId === null) {
            return AnalyticsJournalSkip::UNKNOWN_SESSION;
        }

        $this->store->ensureAgentSession(
            $record[AnalyticsJournalRecord::KEY_KEY],
            $workerSessionId,
            $record[AnalyticsJournalRecord::KEY_AGENT_TYPE],
            $record[AnalyticsJournalRecord::KEY_AGENT_INDEX],
            $record[AnalyticsJournalRecord::KEY_STARTED_TS],
        );

        return null;
    }

    /**
     * @param array<mixed> $record Well-formed agent session stop
     * @return ?AnalyticsJournalSkip Unknown session, or null when the session was stamped (or already was)
     * @throws DatabaseException When a statement fails
     */
    private function applyAgentSessionStop(array $record): ?AnalyticsJournalSkip
    {
        $agentSessionId = $this->store->findAgentSession($record[AnalyticsJournalRecord::KEY_KEY]);
        if ($agentSessionId === null) {
            return AnalyticsJournalSkip::UNKNOWN_SESSION;
        }

        $this->store->stopAgentSession($agentSessionId, $record[AnalyticsJournalRecord::KEY_TS]);

        return null;
    }

    /**
     * Buffers a fact of an agent: a reaction to a user action, a system or cron signal, a signal inside an HTTP request.
     *
     * @param string $type Record type, one of the four agent facts
     * @param array<mixed> $record Well-formed record of that type
     * @return ?AnalyticsJournalSkip Unknown agent session, or null when the fact was buffered
     * @throws DatabaseException When a dictionary value cannot be written
     */
    private function applyAgentFact(string $type, array $record): ?AnalyticsJournalSkip
    {
        $agentSessionId = $this->store->findAgentSession($record[AnalyticsJournalRecord::KEY_AGENT_KEY]);
        if ($agentSessionId === null) {
            return AnalyticsJournalSkip::UNKNOWN_SESSION;
        }

        $payload = $record[AnalyticsJournalRecord::KEY_PAYLOAD];
        $ts = $record[AnalyticsJournalRecord::KEY_TS];
        match ($type) {
            AnalyticsJournalRecord::TYPE_AGENT_USER_ACTION => $this->store->addAgentUserAction(
                $agentSessionId,
                $record[AnalyticsJournalRecord::KEY_USER_ACTION_ID],
                $record[AnalyticsJournalRecord::KEY_SIGNAL],
                $payload,
                $ts,
            ),
            AnalyticsJournalRecord::TYPE_AGENT_SYSTEM_SIGNAL => $this->store->addAgentSystemSignal(
                $agentSessionId,
                $record[AnalyticsJournalRecord::KEY_SIGNAL],
                $payload,
                $ts,
            ),
            AnalyticsJournalRecord::TYPE_AGENT_CRON_SIGNAL => $this->store->addAgentCronSignal(
                $agentSessionId,
                $record[AnalyticsJournalRecord::KEY_CRON],
                $payload,
                $ts,
            ),
            AnalyticsJournalRecord::TYPE_API_AGENT_ACTION => $this->store->addApiAgentAction(
                $record[AnalyticsJournalRecord::KEY_API_REQUEST_ID],
                $agentSessionId,
                $record[AnalyticsJournalRecord::KEY_SIGNAL],
                $payload,
                $ts,
            ),
        };

        return null;
    }

    /**
     * @param array<mixed> $record Well-formed worker system signal
     * @return ?AnalyticsJournalSkip Unknown worker session, or null when the fact was buffered
     * @throws DatabaseException When a dictionary value cannot be written
     */
    private function applyWorkerSystemSignal(array $record): ?AnalyticsJournalSkip
    {
        $workerSessionId = $this->store->findWorkerSession($record[AnalyticsJournalRecord::KEY_WORKER_KEY]);
        if ($workerSessionId === null) {
            return AnalyticsJournalSkip::UNKNOWN_SESSION;
        }

        $this->store->addWorkerSystemSignal(
            $workerSessionId,
            $record[AnalyticsJournalRecord::KEY_SIGNAL],
            $record[AnalyticsJournalRecord::KEY_PAYLOAD],
            $record[AnalyticsJournalRecord::KEY_TS],
        );

        return null;
    }

    /**
     * @param array<mixed> $record Well-formed connection attach
     * @return ?AnalyticsJournalSkip Always null: the browser session is inserted or refreshed, then the connection gets it
     * @throws DatabaseException When a statement fails
     */
    private function applyWsConnectionAttach(array $record): ?AnalyticsJournalSkip
    {
        $browserSessionId = $this->store->ensureBrowserSession(
            $record[AnalyticsJournalRecord::KEY_SESSION_TOKEN],
            $record[AnalyticsJournalRecord::KEY_USER_AGENT],
            $record[AnalyticsJournalRecord::KEY_ACCEPT_LANGUAGE],
            $record[AnalyticsJournalRecord::KEY_TS],
        );
        $this->store->attachWsConnection($record[AnalyticsJournalRecord::KEY_ACCEPT_KEY], $browserSessionId);

        return null;
    }

    /**
     * @param array<mixed> $record Well-formed browser session rename
     * @return ?AnalyticsJournalSkip Rename conflict, or null when renamed or when there was nothing to rename
     * @throws DatabaseException When a statement fails
     */
    private function applyBrowserSessionRename(array $record): ?AnalyticsJournalSkip
    {
        $rename = $this->store->renameBrowserSession(
            $record[AnalyticsJournalRecord::KEY_OLD_TOKEN],
            $record[AnalyticsJournalRecord::KEY_NEW_TOKEN],
            $record[AnalyticsJournalRecord::KEY_TS],
        );

        return $rename === BrowserSessionRename::CONFLICT ? AnalyticsJournalSkip::RENAME_CONFLICT : null;
    }

    /**
     * @param array<mixed> $record Well-formed browser session identity
     * @return ?AnalyticsJournalSkip Always null: the identity is written, the session opened when it had none
     * @throws DatabaseException When a statement fails
     */
    private function applyBrowserSessionIdentity(array $record): ?AnalyticsJournalSkip
    {
        $this->store->setBrowserSessionIdentity(
            $record[AnalyticsJournalRecord::KEY_SESSION_TOKEN],
            $record[AnalyticsJournalRecord::KEY_IDENTITY_TYPE],
            $record[AnalyticsJournalRecord::KEY_IDENTITY_VALUE],
            $record[AnalyticsJournalRecord::KEY_TS],
        );

        return null;
    }

    /**
     * Whether every field the type declares is present and of its kind.
     *
     * @param array<mixed> $record Decoded record
     * @param array<string, string> $fields Field key to its kind
     * @return bool True when the record can be applied as it stands
     */
    private function isWellFormed(array $record, array $fields): bool
    {
        foreach ($fields as $key => $kind) {
            if (!array_key_exists($key, $record) || !$this->isOfKind($record[$key], $kind)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value Field value
     * @param string $kind One of the KIND_* constants
     * @return bool True when the value is of the kind
     */
    private function isOfKind(mixed $value, string $kind): bool
    {
        return match ($kind) {
            self::KIND_SESSION_KEY => is_string($value) && preg_match(AnalyticsJournalRecord::SESSION_KEY_PATTERN, $value) === 1,
            self::KIND_NAME => $this->isName($value, self::NAME_MAX_CHARS),
            self::KIND_SHORT_NAME => $this->isName($value, self::SHORT_NAME_MAX_CHARS),
            self::KIND_NULLABLE_SHORT_NAME => $value === null || (is_string($value) && mb_strlen($value) <= self::SHORT_NAME_MAX_CHARS),
            self::KIND_LONG_NAME => $this->isName($value, self::LONG_NAME_MAX_CHARS),
            self::KIND_NULLABLE_TEXT => $value === null || is_string($value),
            self::KIND_INT => is_int($value) && $value >= 0,
            self::KIND_UNSIGNED_INT => is_int($value) && $value >= 0 && $value <= self::UNSIGNED_INT_MAX,
            self::KIND_NULLABLE_INT => $value === null || (is_int($value) && $value >= 0),
            self::KIND_BOOL => is_bool($value),
            self::KIND_PAYLOAD => $value === null || is_array($value),
        };
    }

    /**
     * @param mixed $value Field value
     * @param int $maxChars Widest value its column holds
     * @return bool True for a non-empty string that fits
     */
    private function isName(mixed $value, int $maxChars): bool
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= $maxChars;
    }

    /**
     * Adds the facts a flush dropped for a vanished API request to the skip counts.
     *
     * @param array<string, int> $skipped Skip counts so far
     * @param int $dropped Rows the flush dropped
     * @return array<string, int> Skip counts with the dropped rows
     */
    private function countDropped(array $skipped, int $dropped): array
    {
        if ($dropped > 0) {
            $reason = AnalyticsJournalSkip::MISSING_API_REQUEST->value;
            $skipped[$reason] = ($skipped[$reason] ?? 0) + $dropped;
        }

        return $skipped;
    }
}

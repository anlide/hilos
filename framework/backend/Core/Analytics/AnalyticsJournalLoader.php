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
 * description wrote, or a page or action naming an unknown connection. A file is
 * never refused for its records - one poisoned file would stop its node's journal for good.
 * Loss records and counts of skipped records or oversized lines enter the loss table in the
 * same transaction as the file mark, so a second load cannot double them.
 *
 * The record catalog is {@see AnalyticsJournalRecord}; the rules of each record are in
 * docs/agents/architecture/analytics.md.
 */
final class AnalyticsJournalLoader
{
    private const string KIND_SESSION_KEY = 'session_key';
    private const string KIND_NULLABLE_SESSION_KEY = 'nullable_session_key';
    private const string KIND_NAME = 'name';
    private const string KIND_NULLABLE_NAME = 'nullable_name';
    private const string KIND_METHOD = 'method';
    private const string KIND_SHORT_NAME = 'short_name';
    private const string KIND_NULLABLE_SHORT_NAME = 'nullable_short_name';
    private const string KIND_LONG_NAME = 'long_name';
    private const string KIND_NULLABLE_TEXT = 'nullable_text';
    private const string KIND_INT = 'int';
    private const string KIND_UNSIGNED_INT = 'unsigned_int';
    private const string KIND_NULLABLE_SMALL_UNSIGNED_INT = 'nullable_small_unsigned_int';
    private const string KIND_NULLABLE_UNSIGNED_INT = 'nullable_unsigned_int';
    private const string KIND_BOOL = 'bool';
    private const string KIND_PAYLOAD = 'payload';
    private const string KIND_LOSS_REASON = 'loss_reason';
    private const string KIND_POSITIVE_INT = 'positive_int';
    private const string KIND_BIG_ID = 'big_id';
    private const string KIND_NULLABLE_BIG_ID = 'nullable_big_id';
    private const string KIND_EVENT_KIND = 'event_kind';

    /** @var int Widest value of a `VARCHAR(100)` column the records feed: names, tokens, accept keys */
    private const int NAME_MAX_CHARS = 100;

    /** @var int Widest value of a `VARCHAR(50)` column the records feed: agent type and index, identity type */
    private const int SHORT_NAME_MAX_CHARS = 50;

    /** @var int Widest value of the `VARCHAR(255)` identity value */
    private const int LONG_NAME_MAX_CHARS = 255;

    /** @var int Largest value of an `INT UNSIGNED` column the records feed: the worker index */
    private const int UNSIGNED_INT_MAX = 4294967295;
    private const int SMALL_UNSIGNED_INT_MAX = 65535;
    private const int METHOD_MAX_CHARS = 10;

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
            AnalyticsJournalRecord::KEY_USER_ACTION_KEY => self::KIND_NULLABLE_SESSION_KEY,
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
            AnalyticsJournalRecord::KEY_API_REQUEST_KEY => self::KIND_SESSION_KEY,
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
        AnalyticsJournalRecord::TYPE_WS_CONNECTION_OPEN => [
            AnalyticsJournalRecord::KEY_ACCEPT_KEY => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_IP => self::KIND_NULLABLE_TEXT,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_WS_CONNECTION_CLOSE => [
            AnalyticsJournalRecord::KEY_ACCEPT_KEY => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_WS_CONNECTION_IP_CHANGE => [
            AnalyticsJournalRecord::KEY_ACCEPT_KEY => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_IP => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_PAGE_SESSION_OPEN => [
            AnalyticsJournalRecord::KEY_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_ACCEPT_KEY => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_PAGE => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_PARAMS => self::KIND_PAYLOAD,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_PAGE_SESSION_UPDATE => [
            AnalyticsJournalRecord::KEY_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_PARAMS => self::KIND_PAYLOAD,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_PAGE_SESSION_CLOSE => [
            AnalyticsJournalRecord::KEY_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_USER_ACTION => [
            AnalyticsJournalRecord::KEY_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_ACCEPT_KEY => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_PAGE_KEY => self::KIND_NULLABLE_SESSION_KEY,
            AnalyticsJournalRecord::KEY_ACTION => self::KIND_NAME,
            AnalyticsJournalRecord::KEY_PAYLOAD => self::KIND_PAYLOAD,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_API_REQUEST => [
            AnalyticsJournalRecord::KEY_KEY => self::KIND_SESSION_KEY,
            AnalyticsJournalRecord::KEY_SESSION_TOKEN => self::KIND_NULLABLE_NAME,
            AnalyticsJournalRecord::KEY_METHOD => self::KIND_METHOD,
            AnalyticsJournalRecord::KEY_PATH => self::KIND_LONG_NAME,
            AnalyticsJournalRecord::KEY_PARAMS => self::KIND_PAYLOAD,
            AnalyticsJournalRecord::KEY_USER_AGENT => self::KIND_NULLABLE_TEXT,
            AnalyticsJournalRecord::KEY_ACCEPT_LANGUAGE => self::KIND_NULLABLE_TEXT,
            AnalyticsJournalRecord::KEY_STARTED_TS => self::KIND_INT,
            AnalyticsJournalRecord::KEY_STATUS => self::KIND_NULLABLE_SMALL_UNSIGNED_INT,
            AnalyticsJournalRecord::KEY_DURATION_MS => self::KIND_NULLABLE_UNSIGNED_INT,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_PERSON_EVENT => [
            AnalyticsJournalRecord::KEY_SESSION_TOKEN => self::KIND_NULLABLE_NAME,
            AnalyticsJournalRecord::KEY_USER_ID => self::KIND_BIG_ID,
            AnalyticsJournalRecord::KEY_SUBJECT_USER_ID => self::KIND_NULLABLE_BIG_ID,
            AnalyticsJournalRecord::KEY_SESSION_ID => self::KIND_NULLABLE_BIG_ID,
            AnalyticsJournalRecord::KEY_EVENT_KIND => self::KIND_EVENT_KIND,
            AnalyticsJournalRecord::KEY_ACTION => self::KIND_NULLABLE_NAME,
            AnalyticsJournalRecord::KEY_PAGE => self::KIND_NULLABLE_NAME,
            AnalyticsJournalRecord::KEY_PARAMS => self::KIND_PAYLOAD,
            AnalyticsJournalRecord::KEY_IP => self::KIND_NULLABLE_TEXT,
            AnalyticsJournalRecord::KEY_TS => self::KIND_INT,
        ],
        AnalyticsJournalRecord::TYPE_LOSS => [
            AnalyticsJournalRecord::KEY_REASON => self::KIND_LOSS_REASON,
            AnalyticsJournalRecord::KEY_EVENTS => self::KIND_POSITIVE_INT,
            AnalyticsJournalRecord::KEY_FROM_TS => self::KIND_INT,
            AnalyticsJournalRecord::KEY_TO_TS => self::KIND_INT,
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
     * @param int $passedOver Lines the journal reader omitted for exceeding its limit
     * @return AnalyticsJournalLoadOutcome What was written and what was passed over
     * @throws HilosException When the database refuses the load; nothing of the file is written then
     */
    public function load(string $nodeId, string $fileName, array $lines, int $passedOver = 0): AnalyticsJournalLoadOutcome
    {
        if ($this->store->isFileLoaded($nodeId, $fileName)) {
            return AnalyticsJournalLoadOutcome::alreadyLoaded();
        }

        Database::transactionStart();
        $this->store->beginStaging();
        try {
            $recordCount = 0;
            $skipped = [];
            $openedTs = null;
            $closedTs = null;
            foreach ($lines as $line) {
                $record = json_decode($line, true);
                if (is_array($record) && ($record[AnalyticsJournalRecord::KEY_TYPE] ?? null) === AnalyticsJournalRecord::TYPE_JOURNAL) {
                    $opened = $record[AnalyticsJournalRecord::KEY_OPENED_TS] ?? null;
                    if (is_int($opened) && $opened >= 0) {
                        $openedTs = $opened;
                    }
                    continue;
                }

                if (is_array($record) && ($record[AnalyticsJournalRecord::KEY_TYPE] ?? null) === AnalyticsJournalRecord::TYPE_JOURNAL_END) {
                    $closed = $record[AnalyticsJournalRecord::KEY_CLOSED_TS] ?? null;
                    if (is_int($closed) && $closed >= 0) {
                        $closedTs = $closed;
                    }
                    continue;
                }

                $recordCount++;
                $skip = is_array($record) ? $this->apply($record, $nodeId) : AnalyticsJournalSkip::MALFORMED;
                if ($skip !== null) {
                    $skipped[$skip->value] = ($skipped[$skip->value] ?? 0) + 1;
                }

                if ($this->store->pendingFactCount() >= AnalyticsStore::FACT_PORTION_ROWS) {
                    $this->store->flushFacts();
                }
            }

            $this->store->flushFacts();
            $loadedTs = (int)floor(microtime(true) * 1000);
            $fromTs = $openedTs ?? $loadedTs;
            $toTs = $closedTs ?? $fromTs;
            foreach ($skipped as $reason => $count) {
                $this->store->insertLoss($nodeId, $reason, $count, $fromTs, $toTs);
            }
            if ($passedOver > 0) {
                $this->store->insertLoss($nodeId, AnalyticsLossReason::LINE_TOO_LONG->value, $passedOver, $fromTs, $toTs);
            }
            $this->store->markFileLoaded($nodeId, $fileName, $recordCount, $loadedTs);
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
     * @param string $nodeId Node whose file is loading
     * @return ?AnalyticsJournalSkip Why the record was passed over, or null when it was applied
     * @throws DatabaseException When a statement fails
     */
    private function apply(array $record, string $nodeId): ?AnalyticsJournalSkip
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
        if ($type === AnalyticsJournalRecord::TYPE_PERSON_EVENT && !$this->isPersonEventShape($record)) {
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
            AnalyticsJournalRecord::TYPE_WS_CONNECTION_OPEN => $this->applyWsConnectionOpen($record),
            AnalyticsJournalRecord::TYPE_WS_CONNECTION_CLOSE => $this->applyWsConnectionClose($record),
            AnalyticsJournalRecord::TYPE_WS_CONNECTION_IP_CHANGE => $this->applyWsConnectionIpChange($record),
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_OPEN => $this->applyPageSessionOpen($record),
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_UPDATE => $this->applyPageSessionUpdate($record),
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_CLOSE => $this->applyPageSessionClose($record),
            AnalyticsJournalRecord::TYPE_USER_ACTION => $this->applyUserAction($record),
            AnalyticsJournalRecord::TYPE_API_REQUEST => $this->applyApiRequest($record),
            AnalyticsJournalRecord::TYPE_PERSON_EVENT => $this->applyPersonEvent($record),
            AnalyticsJournalRecord::TYPE_LOSS => $this->applyLoss($record, $nodeId),
        };
    }

    /**
     * @param array<mixed> $record Well-formed loss record
     * @param string $nodeId Node whose file carried the count
     * @return ?AnalyticsJournalSkip Always null after insertion
     * @throws DatabaseException When the insert fails
     */
    private function applyLoss(array $record, string $nodeId): ?AnalyticsJournalSkip
    {
        $this->store->insertLoss(
            $nodeId,
            $record[AnalyticsJournalRecord::KEY_REASON],
            $record[AnalyticsJournalRecord::KEY_EVENTS],
            $record[AnalyticsJournalRecord::KEY_FROM_TS],
            $record[AnalyticsJournalRecord::KEY_TO_TS],
        );

        return null;
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
                $record[AnalyticsJournalRecord::KEY_USER_ACTION_KEY],
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
                $record[AnalyticsJournalRecord::KEY_API_REQUEST_KEY],
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
        $this->store->attachWsConnection($record[AnalyticsJournalRecord::KEY_ACCEPT_KEY], $browserSessionId, $record[AnalyticsJournalRecord::KEY_TS]);

        return null;
    }

    /**
     * @param array<mixed> $record Well-formed browser session rename
     * @return ?AnalyticsJournalSkip Always null: the rename, merge or alias is recorded
     * @throws DatabaseException When a statement fails
     */
    private function applyBrowserSessionRename(array $record): ?AnalyticsJournalSkip
    {
        $this->store->renameBrowserSession(
            $record[AnalyticsJournalRecord::KEY_OLD_TOKEN],
            $record[AnalyticsJournalRecord::KEY_NEW_TOKEN],
            $record[AnalyticsJournalRecord::KEY_TS],
        );

        return null;
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
     * @param array<mixed> $record Well-formed connection opening
     * @return ?AnalyticsJournalSkip Always null after the upsert
     * @throws DatabaseException When the upsert fails
     */
    private function applyWsConnectionOpen(array $record): ?AnalyticsJournalSkip
    {
        $this->store->openWsConnection($record[AnalyticsJournalRecord::KEY_ACCEPT_KEY],
            $record[AnalyticsJournalRecord::KEY_IP], $record[AnalyticsJournalRecord::KEY_TS]);
        return null;
    }

    /**
     * @param array<mixed> $record Well-formed connection close
     * @return ?AnalyticsJournalSkip Always null after the update
     * @throws DatabaseException When the update fails
     */
    private function applyWsConnectionClose(array $record): ?AnalyticsJournalSkip
    {
        $this->store->closeWsConnection($record[AnalyticsJournalRecord::KEY_ACCEPT_KEY], $record[AnalyticsJournalRecord::KEY_TS]);
        return null;
    }

    /**
     * @param array<mixed> $record Well-formed address change
     * @return ?AnalyticsJournalSkip Unknown connection, or null after the change
     * @throws DatabaseException When a statement fails
     */
    private function applyWsConnectionIpChange(array $record): ?AnalyticsJournalSkip
    {
        return $this->store->trackWsConnectionIpChange($record[AnalyticsJournalRecord::KEY_ACCEPT_KEY],
            $record[AnalyticsJournalRecord::KEY_IP], $record[AnalyticsJournalRecord::KEY_TS]) === null
            ? AnalyticsJournalSkip::UNKNOWN_CONNECTION : null;
    }

    /**
     * @param array<mixed> $record Well-formed page opening
     * @return ?AnalyticsJournalSkip Unknown connection, or null after the opening
     * @throws DatabaseException When a statement fails
     */
    private function applyPageSessionOpen(array $record): ?AnalyticsJournalSkip
    {
        $id = $this->store->openPageSession($record[AnalyticsJournalRecord::KEY_KEY],
            $record[AnalyticsJournalRecord::KEY_ACCEPT_KEY], $record[AnalyticsJournalRecord::KEY_PAGE],
            $record[AnalyticsJournalRecord::KEY_PARAMS], $record[AnalyticsJournalRecord::KEY_TS]);
        return $id === null ? AnalyticsJournalSkip::UNKNOWN_CONNECTION : null;
    }

    /**
     * @param array<mixed> $record Well-formed page update
     * @return ?AnalyticsJournalSkip Always null after the update
     * @throws DatabaseException When a statement fails
     */
    private function applyPageSessionUpdate(array $record): ?AnalyticsJournalSkip
    {
        $this->store->updatePageSession($record[AnalyticsJournalRecord::KEY_KEY],
            $record[AnalyticsJournalRecord::KEY_PARAMS], $record[AnalyticsJournalRecord::KEY_TS]);
        return null;
    }

    /**
     * @param array<mixed> $record Well-formed page close
     * @return ?AnalyticsJournalSkip Always null after the close
     * @throws DatabaseException When the update fails
     */
    private function applyPageSessionClose(array $record): ?AnalyticsJournalSkip
    {
        $this->store->closePageSession($record[AnalyticsJournalRecord::KEY_KEY], $record[AnalyticsJournalRecord::KEY_TS]);
        return null;
    }

    /**
     * @param array<mixed> $record Well-formed user action
     * @return ?AnalyticsJournalSkip Unknown connection, or null after insertion
     * @throws DatabaseException When a statement fails
     */
    private function applyUserAction(array $record): ?AnalyticsJournalSkip
    {
        $id = $this->store->insertUserAction($record[AnalyticsJournalRecord::KEY_KEY],
            $record[AnalyticsJournalRecord::KEY_ACCEPT_KEY], $record[AnalyticsJournalRecord::KEY_PAGE_KEY],
            $record[AnalyticsJournalRecord::KEY_ACTION], $record[AnalyticsJournalRecord::KEY_PAYLOAD],
            $record[AnalyticsJournalRecord::KEY_TS]);
        return $id === null ? AnalyticsJournalSkip::UNKNOWN_CONNECTION : null;
    }

    /**
     * @param array<mixed> $record Well-formed completed HTTP request
     * @return ?AnalyticsJournalSkip Always null after insertion
     * @throws DatabaseException When a statement fails
     */
    private function applyApiRequest(array $record): ?AnalyticsJournalSkip
    {
        $this->store->insertApiRequest($record[AnalyticsJournalRecord::KEY_KEY],
            $record[AnalyticsJournalRecord::KEY_SESSION_TOKEN], $record[AnalyticsJournalRecord::KEY_METHOD],
            $record[AnalyticsJournalRecord::KEY_PATH], $record[AnalyticsJournalRecord::KEY_PARAMS],
            $record[AnalyticsJournalRecord::KEY_USER_AGENT], $record[AnalyticsJournalRecord::KEY_ACCEPT_LANGUAGE],
            $record[AnalyticsJournalRecord::KEY_STARTED_TS], $record[AnalyticsJournalRecord::KEY_STATUS],
            $record[AnalyticsJournalRecord::KEY_DURATION_MS], $record[AnalyticsJournalRecord::KEY_TS]);
        return null;
    }

    /**
     * @param array<mixed> $record Well-formed authenticated event
     * @return ?AnalyticsJournalSkip Always null after insertion
     * @throws DatabaseException When the insert fails
     */
    private function applyPersonEvent(array $record): ?AnalyticsJournalSkip
    {
        $this->store->insertPersonEvent(new AnalyticsPersonEvent(
            $record[AnalyticsJournalRecord::KEY_SESSION_TOKEN],
            $record[AnalyticsJournalRecord::KEY_USER_ID],
            $record[AnalyticsJournalRecord::KEY_SUBJECT_USER_ID],
            $record[AnalyticsJournalRecord::KEY_SESSION_ID],
            $record[AnalyticsJournalRecord::KEY_EVENT_KIND],
            $record[AnalyticsJournalRecord::KEY_ACTION],
            $record[AnalyticsJournalRecord::KEY_PAGE],
            $record[AnalyticsJournalRecord::KEY_PARAMS],
            $record[AnalyticsJournalRecord::KEY_IP],
            $record[AnalyticsJournalRecord::KEY_TS],
        ));
        return null;
    }

    /**
     * @param array<mixed> $record Validated person event fields
     * @return bool Whether names and route params match their event kind
     */
    private function isPersonEventShape(array $record): bool
    {
        $kind = $record[AnalyticsJournalRecord::KEY_EVENT_KIND];
        $isAction = $kind === AnalyticsPersonEvent::ACTION;
        $isPage = $kind === AnalyticsPersonEvent::PAGE_OPEN || $kind === AnalyticsPersonEvent::PAGE_UPDATE;
        $isTakeover = $kind === AnalyticsPersonEvent::TAKEOVER_START || $kind === AnalyticsPersonEvent::TAKEOVER_STOP;

        return ($record[AnalyticsJournalRecord::KEY_ACTION] !== null) === $isAction
            && ($record[AnalyticsJournalRecord::KEY_PAGE] !== null) === $isPage
            && ($isPage || $record[AnalyticsJournalRecord::KEY_PARAMS] === null)
            && (!$isTakeover || $record[AnalyticsJournalRecord::KEY_SUBJECT_USER_ID] !== null);
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
            self::KIND_NULLABLE_SESSION_KEY => $value === null || (
                is_string($value) && preg_match(AnalyticsJournalRecord::SESSION_KEY_PATTERN, $value) === 1
            ),
            self::KIND_NAME => $this->isName($value, self::NAME_MAX_CHARS),
            self::KIND_NULLABLE_NAME => $value === null || $this->isName($value, self::NAME_MAX_CHARS),
            self::KIND_METHOD => $this->isName($value, self::METHOD_MAX_CHARS),
            self::KIND_SHORT_NAME => $this->isName($value, self::SHORT_NAME_MAX_CHARS),
            self::KIND_NULLABLE_SHORT_NAME => $value === null || (is_string($value) && mb_strlen($value) <= self::SHORT_NAME_MAX_CHARS),
            self::KIND_LONG_NAME => $this->isName($value, self::LONG_NAME_MAX_CHARS),
            self::KIND_NULLABLE_TEXT => $value === null || is_string($value),
            self::KIND_INT => is_int($value) && $value >= 0,
            self::KIND_UNSIGNED_INT => is_int($value) && $value >= 0 && $value <= self::UNSIGNED_INT_MAX,
            self::KIND_NULLABLE_SMALL_UNSIGNED_INT => $value === null || (is_int($value) && $value >= 0 && $value <= self::SMALL_UNSIGNED_INT_MAX),
            self::KIND_NULLABLE_UNSIGNED_INT => $value === null || (is_int($value) && $value >= 0 && $value <= self::UNSIGNED_INT_MAX),
            self::KIND_BOOL => is_bool($value),
            self::KIND_PAYLOAD => $value === null || is_array($value),
            self::KIND_LOSS_REASON => is_string($value) && AnalyticsLossReason::tryFrom($value) !== null,
            self::KIND_POSITIVE_INT => is_int($value) && $value >= 1 && $value <= self::UNSIGNED_INT_MAX,
            self::KIND_BIG_ID => is_int($value) && $value > 0,
            self::KIND_NULLABLE_BIG_ID => $value === null || (is_int($value) && $value > 0),
            self::KIND_EVENT_KIND => is_string($value) && in_array($value, AnalyticsPersonEvent::KINDS, true),
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

}

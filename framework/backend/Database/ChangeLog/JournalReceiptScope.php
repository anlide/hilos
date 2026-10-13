<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Utils\Logger;

/**
 * Creates a receipt around a synchronous write and restores the previous connection attribution
 * ({@see run()}), joins agent frames, and retains one attempt across local parts until finish().
 */
final class JournalReceiptScope
{
    private const string FRAME_ID = 'id';

    private const string FRAME_HANDOVERS = 'handovers';

    /** @var list<array{id: int, handovers: int}> Active receipt scopes, outermost first */
    private static array $ids = [];

    /** @return ?int Receipt assigned to a new primary connection */
    public static function currentId(): ?int
    {
        return self::$ids === [] ? null : self::$ids[array_key_last(self::$ids)][self::FRAME_ID];
    }

    /** @return ?int Receipt stamped on the outgoing agent signal, or null outside a receipt */
    public static function stampHandover(): ?int
    {
        if (self::$ids === []) {
            return null;
        }

        $index = array_key_last(self::$ids);
        self::$ids[$index][self::FRAME_HANDOVERS]++;
        return self::$ids[$index][self::FRAME_ID];
    }

    /**
     * Retains the current dispatch receipt for an accepted local continuation.
     * The hold is published when this scope closes, alongside outgoing agent holds. Release it
     * through finish() only after that dispatch returns, never inside the retaining scope.
     *
     * @return ?int Retained receipt, or null outside a configured receipt scope
     */
    public static function retainCurrent(): ?int
    {
        return self::stampHandover();
    }

    /**
     * @param JournalReceiptData $data Attribution for this operation
     * @param callable $callback Work performed under the receipt
     * @return mixed Callback result
     * @throws DatabaseException When receipt SQL or connection attribution fails
     */
    public static function run(JournalReceiptData $data, callable $callback): mixed
    {
        if (!in_array(ChangeLogDatabase::CONNECTION_INDEX, Database::getConfiguredIndices(), true)) {
            return $callback();
        }

        $previousIndex = Database::getCurrentIndex();
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $id = null;

        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $id = self::insertReceipt($data, false);
            self::$ids[] = [self::FRAME_ID => $id, self::FRAME_HANDOVERS => 0];
            Database::setJournalReceiptId($id);
            Database::useConnection($previousIndex);

            return $callback();
        } finally {
            try {
                if ($id !== null) {
                    $frame = array_pop(self::$ids);
                    Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
                    try {
                        Database::setJournalReceiptId(self::currentId());
                    } finally {
                        // A receipt whose work went on in frames to agents lives until the last
                        // recipient has finished under it (open_handovers); without frames an empty
                        // one is deleted at once. One left open is hidden until its partition goes.
                        if ($frame[self::FRAME_HANDOVERS] > 0) {
                            Database::sql(
                                "UPDATE {$database}.`hilos_change_log_receipt` "
                                . 'SET `open_handovers` = `open_handovers` + ? WHERE `id` = ?',
                                [$frame[self::FRAME_HANDOVERS], $id],
                                tryReconnect: false,
                            );
                        } else {
                            Database::sqlRun(
                                "DELETE FROM {$database}.`hilos_change_log_receipt` WHERE `id` = ? "
                                . "AND NOT EXISTS (SELECT 1 FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?)",
                                [$id, $id],
                            );
                        }
                    }
                }
            } finally {
                Database::useConnection($previousIndex);
            }
        }
    }

    /**
     * Creates one local hold before any business transaction; the caller owns the id until finish().
     * No receipt is installed on the connection between parts. Lost attempts follow the orphan policy.
     *
     * @param JournalReceiptData $data Attribution captured at the beginning of this attempt
     * @return ?int Receipt id, or null when the journal is not configured
     * @throws DatabaseException When creating the receipt or switching connections fails
     */
    public static function start(JournalReceiptData $data): ?int
    {
        if (!in_array(ChangeLogDatabase::CONNECTION_INDEX, Database::getConfiguredIndices(), true)) {
            return null;
        }

        $previousIndex = Database::getCurrentIndex();
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            return self::insertReceipt($data, true);
        } finally {
            Database::useConnection($previousIndex);
        }
    }

    /**
     * Runs one synchronous part without consuming the attempt's local hold.
     * Outgoing frames keep this receipt; a journaled write updates its actual writer.
     *
     * @param ?int $receiptId Receipt owned by the caller, or null without a configured journal
     * @param string $agentId Agent performing this part
     * @param callable $callback Work whose transactions end within this call
     * @return mixed Callback result
     * @throws DatabaseException When switching to the primary connection fails
     */
    public static function runPart(?int $receiptId, string $agentId, callable $callback): mixed
    {
        return $receiptId === null ? $callback() : self::runExistingReceipt($receiptId, $agentId, $callback, false);
    }

    /**
     * Releases exactly the local hold. The caller calls this once on completion, failure or stop,
     * then clears its id; outstanding agent frames close through join(). Finalization failures are logged.
     *
     * @param ?int $receiptId Receipt owned by the completed attempt, or null without a journal
     * @throws DatabaseException When switching connections or resolving the journal database fails
     */
    public static function finish(?int $receiptId): void
    {
        if ($receiptId === null || !in_array(ChangeLogDatabase::CONNECTION_INDEX, Database::getConfiguredIndices(), true)) {
            return;
        }

        $previousIndex = Database::getCurrentIndex();
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            try {
                Database::sql(
                    "UPDATE {$database}.`hilos_change_log_receipt` SET `open_handovers` = `open_handovers` - 1 WHERE `id` = ?",
                    [$receiptId],
                    tryReconnect: false,
                );
                self::deleteEmptyReceipt($database, $receiptId);
            } catch (DatabaseException $failure) {
                Logger::error("Cannot finish journal receipt {$receiptId}: {$failure->getMessage()}");
            }
        } finally {
            Database::useConnection($previousIndex);
        }
    }

    /**
     * Runs an agent frame under its sender's receipt without changing its original attribution.
     *
     * @param int $receiptId Receipt carried by the frame
     * @param string $agentId Agent handling the frame
     * @param callable $callback Agent and page handlers for the frame
     * @return mixed Callback result
     * @throws DatabaseException When switching to the primary connection fails
     */
    public static function join(int $receiptId, string $agentId, callable $callback): mixed
    {
        return self::runExistingReceipt($receiptId, $agentId, $callback, true);
    }

    /**
     * @param int $receiptId Existing receipt for this frame or local part
     * @param string $agentId Actual handler
     * @param callable $callback Work under the existing receipt
     * @param bool $releaseHandover Whether this call consumes an incoming agent frame
     * @return mixed Callback result, with bookkeeping failures logged without replacing its exception
     * @throws DatabaseException When switching to the primary connection fails
     */
    private static function runExistingReceipt(int $receiptId, string $agentId, callable $callback, bool $releaseHandover): mixed
    {
        if (!in_array(ChangeLogDatabase::CONNECTION_INDEX, Database::getConfiguredIndices(), true)) {
            return $callback();
        }

        $previousIndex = Database::getCurrentIndex();
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        self::$ids[] = [self::FRAME_ID => $receiptId, self::FRAME_HANDOVERS => 0];

        try {
            try {
                Database::setJournalReceiptId($receiptId);
            } catch (DatabaseException $failure) {
                Logger::error("Cannot set journal receipt {$receiptId} for agent {$agentId}: {$failure->getMessage()}");
            }

            $before = null;
            try {
                Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?", [$receiptId]);
                $before = (int) Database::field('count');
            } catch (DatabaseException $failure) {
                Logger::error("Cannot count journal rows for receipt {$receiptId} and agent {$agentId}: {$failure->getMessage()}");
            }

            Database::useConnection($previousIndex);
            return $callback();
        } finally {
            $frame = array_pop(self::$ids);
            try {
                Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
                try {
                    Database::setJournalReceiptId(self::currentId());
                } catch (DatabaseException $failure) {
                    Logger::error("Cannot restore journal receipt after {$receiptId} for agent {$agentId}: {$failure->getMessage()}");
                }

                try {
                    if ($before === null) {
                        Database::sql(
                            "UPDATE {$database}.`hilos_change_log_receipt` "
                            . 'SET `open_handovers` = `open_handovers` + ? WHERE `id` = ?',
                            [$frame[self::FRAME_HANDOVERS] - (int) $releaseHandover, $receiptId],
                            tryReconnect: false,
                        );
                    } else {
                        Database::sql(
                            "UPDATE {$database}.`hilos_change_log_receipt` "
                            . "SET `open_handovers` = `open_handovers` + ?, `agent` = "
                            . "IF((SELECT COUNT(*) FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?) > ?, ?, `agent`) "
                            . 'WHERE `id` = ?',
                            [$frame[self::FRAME_HANDOVERS] - (int) $releaseHandover, $receiptId, $before, $agentId, $receiptId],
                            tryReconnect: false,
                        );
                    }

                    // A missing frame or failed conclusion leaves an orphan hidden until its partition expires.
                    if ($releaseHandover) {
                        self::deleteEmptyReceipt($database, $receiptId);
                    }
                } catch (DatabaseException $failure) {
                    Logger::error("Cannot conclude journal receipt {$receiptId} for agent {$agentId}: {$failure->getMessage()}");
                }
            } finally {
                Database::useConnection($previousIndex);
            }
        }
    }

    /**
     * @param JournalReceiptData $data Captured attribution
     * @param bool $retainLocal Whether to retain one local attempt between its parts
     * @return int New receipt on the primary connection
     * @throws DatabaseException When the receipt insert fails; an ambiguous insert is never replayed
     */
    private static function insertReceipt(JournalReceiptData $data, bool $retainLocal): int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $columns = '`actor_user_id`, `subject_user_id`, `session_id`, `channel`, `action`, `agent`, `source`, `created_at`';
        $values = '?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6)';
        // Migration run() also works before the handover column has been introduced.
        if ($retainLocal) {
            $columns .= ', `open_handovers`';
            $values .= ', 1';
        }
        if (!Database::isConnected(DatabaseConnectionDefaults::PRIMARY_INDEX)) {
            Database::sql('SELECT 1');
        }
        try {
            Database::sql(
                "INSERT INTO {$database}.`hilos_change_log_receipt` ({$columns}) VALUES ({$values})",
                [
                    $data->actorUserId,
                    $data->subjectUserId,
                    $data->sessionId,
                    $data->channel,
                    $data->action,
                    $data->agent,
                    $data->source,
                ],
                tryReconnect: false,
            );
        } catch (DatabaseException $failure) {
            // A lost INSERT response is ambiguous. Never replay it; drop a dead link
            // so the next scope can open a clean one instead of retrying the write.
            try {
                Database::setJournalReceiptId(self::currentId());
            } catch (DatabaseException) {
                // Keep the INSERT failure; setJournalReceiptId has discarded the link.
            }
            throw $failure;
        }
        return Database::lastInsertId();
    }

    /**
     * @param string $database Quoted journal database identifier
     * @param int $receiptId Receipt whose last hold has been released
     * @throws DatabaseException When deleting an empty receipt fails
     */
    private static function deleteEmptyReceipt(string $database, int $receiptId): void
    {
        Database::sqlRun(
            "DELETE FROM {$database}.`hilos_change_log_receipt` WHERE `id` = ? "
            . 'AND `open_handovers` = 0 '
            . "AND NOT EXISTS (SELECT 1 FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?)",
            [$receiptId, $receiptId],
        );
    }
}

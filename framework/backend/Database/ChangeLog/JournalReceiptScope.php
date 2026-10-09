<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Utils\Logger;

/** Creates a receipt around a synchronous write and restores the previous connection attribution. */
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
            if (!Database::isConnected(DatabaseConnectionDefaults::PRIMARY_INDEX)) {
                Database::sql('SELECT 1');
            }
            try {
                Database::sql(
                    "INSERT INTO {$database}.`hilos_change_log_receipt` "
                    . '(`actor_user_id`, `subject_user_id`, `session_id`, `channel`, `action`, `agent`, `source`, `created_at`) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
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
            $id = Database::lastInsertId();
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
                            [$frame[self::FRAME_HANDOVERS] - 1, $receiptId],
                            tryReconnect: false,
                        );
                    } else {
                        Database::sql(
                            "UPDATE {$database}.`hilos_change_log_receipt` "
                            . "SET `open_handovers` = `open_handovers` + ?, `agent` = "
                            . "IF((SELECT COUNT(*) FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?) > ?, ?, `agent`) "
                            . 'WHERE `id` = ?',
                            [$frame[self::FRAME_HANDOVERS] - 1, $receiptId, $before, $agentId, $receiptId],
                            tryReconnect: false,
                        );
                    }

                    // A missing frame or failed conclusion leaves an orphan hidden until its partition expires.
                    Database::sqlRun(
                        "DELETE FROM {$database}.`hilos_change_log_receipt` WHERE `id` = ? "
                        . 'AND `open_handovers` = 0 '
                        . "AND NOT EXISTS (SELECT 1 FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?)",
                        [$receiptId, $receiptId],
                    );
                } catch (DatabaseException $failure) {
                    Logger::error("Cannot conclude journal receipt {$receiptId} for agent {$agentId}: {$failure->getMessage()}");
                }
            } finally {
                Database::useConnection($previousIndex);
            }
        }
    }
}

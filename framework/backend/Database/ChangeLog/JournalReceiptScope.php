<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;

/** Creates a receipt around a synchronous write and restores the previous connection attribution. */
final class JournalReceiptScope
{
    /** @var list<int> Active receipt identifiers, outermost first */
    private static array $ids = [];

    /** @return ?int Receipt assigned to a new primary connection */
    public static function currentId(): ?int
    {
        return self::$ids === [] ? null : self::$ids[array_key_last(self::$ids)];
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
            self::$ids[] = $id;
            Database::setJournalReceiptId($id);
            Database::useConnection($previousIndex);

            return $callback();
        } finally {
            try {
                if ($id !== null) {
                    array_pop(self::$ids);
                    Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
                    try {
                        Database::setJournalReceiptId(self::currentId());
                    } finally {
                        // An interrupted operation can leave an orphan. Feed readers must also
                        // require a journal row; physical orphan sweeping belongs to HIL-1454.
                        Database::sqlRun(
                            "DELETE FROM {$database}.`hilos_change_log_receipt` WHERE `id` = ? "
                            . "AND NOT EXISTS (SELECT 1 FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?)",
                            [$id, $id],
                        );
                    }
                }
            } finally {
                Database::useConnection($previousIndex);
            }
        }
    }
}

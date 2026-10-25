<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceOwner;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\ChangeLogReceipts as DbChangeLogReceipts;
use Hilos\Database\View\Item\ChangeLogReceipt as DbChangeLogReceipt;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Logger;

/**
 * Creates a receipt around a synchronous write and restores the previous connection attribution
 * ({@see run()}), joins agent frames, and retains one attempt across local parts until finish().
 *
 * Bookkeeping runs as a fresh runner that holds this class's claim only for that call. The business
 * callback keeps the agent, origin and request it was entered with.
 */
final class JournalReceiptScope implements TruthSourceOwner
{
    public const array OWNS_DB = [
        HilosDbContext::changeLogReceipts => [TruthSourceOperation::Add, TruthSourceOperation::Update, TruthSourceOperation::Remove],
    ];

    private const string FRAME_ID = 'id';

    private const string FRAME_HANDOVERS = 'handovers';

    private const string FRAME_RECEIPT = 'receipt';

    /** Prefix of a process-local runner that holds the receipt claim for one bookkeeping call. */
    private const string RUNNER_PREFIX = 'journal_receipt_scope:';

    /** @var list<array{id: int, handovers: int, receipt: ?DbChangeLogReceipt}> Active receipt scopes, outermost first */
    private static array $ids = [];

    /** Process-local suffix so nested scopes do not share a runner id. */
    private static int $runnerSerial = 0;

    /** @return TruthSourceOperations Receipt creation, handover updates and empty-receipt removal */
    public static function defaultTruthSourceOperations(): TruthSourceOperations
    {
        return TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Update, TruthSourceOperation::Remove);
    }

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
     * @throws HilosException When opening, stamping or closing the receipt fails
     */
    public static function run(JournalReceiptData $data, callable $callback): mixed
    {
        if (!self::journalConfigured()) {
            return $callback();
        }

        $previousIndex = Database::getCurrentIndex();
        $opened = null;
        $pushed = false;

        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            try {
                $opened = self::underRunner(
                    static fn (): DbChangeLogReceipt => self::receiptCollection($data)->actions->createReceipt($data, false),
                );
            } catch (HilosException $failure) {
                self::clearDeadReceiptLink();
                throw $failure;
            }

            $id = $opened->id;
            if (!is_int($id)) {
                self::clearDeadReceiptLink();
                throw new DatabaseException('Journal receipt insert did not return an id');
            }

            self::$ids[] = [
                self::FRAME_ID => $id,
                self::FRAME_HANDOVERS => 0,
                self::FRAME_RECEIPT => $opened,
            ];
            $pushed = true;
            Database::setJournalReceiptId($id);
            Database::useConnection($previousIndex);

            return $callback();
        } finally {
            try {
                if ($pushed) {
                    $frame = array_pop(self::$ids);
                    Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
                    try {
                        Database::setJournalReceiptId(self::currentId());
                    } finally {
                        $receipt = $frame[self::FRAME_RECEIPT] ?? null;
                        if ($receipt instanceof DbChangeLogReceipt) {
                            self::underRunner(static function () use ($frame, $data, $receipt): void {
                                if ($frame[self::FRAME_HANDOVERS] > 0) {
                                    $receipt->actions->adjustHandovers($frame[self::FRAME_HANDOVERS]);
                                    return;
                                }
                                if ($data->channel === JournalReceiptData::CHANNEL_MIGRATION) {
                                    $receipt->actions->deleteEmptyMigrationReceipt();
                                    return;
                                }
                                $receipt->actions->deleteIfEmpty();
                            });
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
     * @throws HilosException When creating the receipt or switching connections fails
     */
    public static function start(JournalReceiptData $data): ?int
    {
        if (!self::journalConfigured()) {
            return null;
        }

        $previousIndex = Database::getCurrentIndex();
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            try {
                $opened = self::underRunner(
                    static fn (): DbChangeLogReceipt => self::receiptCollection($data)->actions->createReceipt($data, true),
                );
            } catch (HilosException $failure) {
                self::clearDeadReceiptLink();
                throw $failure;
            }

            $id = $opened->id;
            if (!is_int($id)) {
                self::clearDeadReceiptLink();
                throw new DatabaseException('Journal receipt insert did not return an id');
            }

            return $id;
        } finally {
            Database::useConnection($previousIndex);
        }
    }

    /**
     * Runs one synchronous part without consuming the attempt's local hold.
     * Outgoing frames keep this receipt; attribution follows delivered journaled row facts.
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
     * @throws DatabaseException When switching connections fails
     */
    public static function finish(?int $receiptId): void
    {
        if ($receiptId === null || !self::journalConfigured()) {
            return;
        }

        $previousIndex = Database::getCurrentIndex();
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            try {
                self::underRunner(static function () use ($receiptId): void {
                    // The receipt read below reconnects an empty slot. A killed primary link is
                    // often already dropped by the failed stamp restore, and a fresh link would
                    // let the decrement stand. This probe refuses that reconnect; a dead link
                    // leaves the hold where the failed attempt put it.
                    Database::sql('SELECT 1', tryReconnect: false);
                    $receipt = self::storedReceipt($receiptId);
                    if ($receipt === null) {
                        return;
                    }
                    $receipt->actions->adjustHandovers(-1);
                    $receipt->actions->deleteIfEmpty();
                });
            } catch (HilosException $failure) {
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
     */
    private static function runExistingReceipt(int $receiptId, string $agentId, callable $callback, bool $releaseHandover): mixed
    {
        if (!self::journalConfigured()) {
            return $callback();
        }

        $previousIndex = Database::getCurrentIndex();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);

        $receipt = null;
        try {
            $receipt = self::underRunner(static fn (): ?DbChangeLogReceipt => self::storedReceipt($receiptId));
        } catch (HilosException $failure) {
            Logger::error("Cannot read journal receipt {$receiptId} for agent {$agentId}: {$failure->getMessage()}");
        }

        self::$ids[] = [
            self::FRAME_ID => $receiptId,
            self::FRAME_HANDOVERS => 0,
            self::FRAME_RECEIPT => $receipt,
        ];

        try {
            try {
                Database::setJournalReceiptId($receiptId);
            } catch (DatabaseException $failure) {
                Logger::error("Cannot set journal receipt {$receiptId} for agent {$agentId}: {$failure->getMessage()}");
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
                    Logger::error(
                        "Cannot restore journal receipt after {$receiptId} for agent {$agentId}: {$failure->getMessage()}",
                    );
                }

                $held = $frame[self::FRAME_RECEIPT] ?? null;
                if ($held instanceof DbChangeLogReceipt) {
                    try {
                        self::underRunner(static function () use ($frame, $releaseHandover, $held): void {
                            $held->actions->adjustHandovers(
                                $frame[self::FRAME_HANDOVERS] - (int) $releaseHandover,
                            );
                            if ($releaseHandover) {
                                $held->actions->deleteIfEmpty();
                            }
                        });
                    } catch (HilosException $failure) {
                        Logger::error(
                            "Cannot conclude journal receipt {$receiptId} for agent {$agentId}: {$failure->getMessage()}",
                        );
                    }
                }
            } finally {
                Database::useConnection($previousIndex);
            }
        }
    }

    /**
     * The mounted receipt collection when this process has one. A migration whose collection
     * is not mounted gets a chain that lives for this call, including when the facade is up
     * and simply has no receipt view. Any other channel without that mount is refused.
     */
    private static function receiptCollection(JournalReceiptData $data): DbChangeLogReceipts
    {
        if (Hilos::$db !== null) {
            $mounted = Hilos::$db->getDbItemCollection(HilosDbContext::changeLogReceipts);
            if ($mounted instanceof DbChangeLogReceipts) {
                return $mounted;
            }
        }
        if ($data->channel === JournalReceiptData::CHANNEL_MIGRATION) {
            return DbChangeLogReceipts::forMigration();
        }

        throw new DatabaseException('Journal receipt collection is not mounted');
    }

    /** Fresh key read of one receipt, or null when that row is already gone. */
    private static function storedReceipt(int $receiptId): ?DbChangeLogReceipt
    {
        if (Hilos::$db === null) {
            throw new DatabaseException('Journal receipt collection is not mounted');
        }

        return Hilos::$db->changeLogReceipts[$receiptId] ?? null;
    }

    /**
     * @template T
     * @param callable(): T $bookkeeping
     * @return T
     */
    private static function underRunner(callable $bookkeeping): mixed
    {
        self::$runnerSerial++;
        $runnerId = self::RUNNER_PREFIX . self::$runnerSerial;
        try {
            OwnershipDeclaration::claimDb(self::class, $runnerId);

            return ExecutionContext::run(
                new ExecutionFrame(
                    $runnerId,
                    ExecutionContext::currentAcceptKey(),
                    ExecutionContext::currentRequestId(),
                ),
                $bookkeeping,
            );
        } finally {
            TruthSourceRegistry::unregisterAgent($runnerId);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($runnerId));
        }
    }

    private static function clearDeadReceiptLink(): void
    {
        try {
            Database::setJournalReceiptId(self::currentId());
        } catch (DatabaseException) {
            // Keep the insert failure; setJournalReceiptId has discarded the link.
        }
    }

    private static function journalConfigured(): bool
    {
        return in_array(ChangeLogDatabase::CONNECTION_INDEX, Database::getConfiguredIndices(), true);
    }
}

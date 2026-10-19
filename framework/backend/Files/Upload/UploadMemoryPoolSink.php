<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Socket\Client\WorkerClient;
use Hilos\Core\Exception\LogicException;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;

/**
 * Master-side entry for socket admission, budget and registered worker consumption reports.
 */
interface UploadMemoryPoolSink
{
    /**
     * Return this real socket's metadata input over the master's single pool. No DB/RT writes or I/O.
     *
     * @param string $acceptKey Identity minted by the real WebSocket handshake
     * @return UploadMemoryInput The socket's stable parser admission state
     * @throws LogicException When the real socket identity is invalid
     */
    public function getUploadInput(string $acceptKey): UploadMemoryInput;

    /**
     * Retain this association before routing can park or serialize the queued raw payload.
     * The receipt stays pending until an actual destination is chosen or the payload is finally dropped.
     *
     * @param WebSocketFrameBinarySignalDTO $frame The same object queued in SignalRouter
     * @param UploadMemoryReceipt $receipt Admission covering every raw byte of this logical message
     */
    public function onUploadFrameQueued(WebSocketFrameBinarySignalDTO $frame, UploadMemoryReceipt $receipt): void;

    /**
     * The parser has deleted its raw input and released only that input and unused grants.
     * Queued payloads remain accounted by onUploadFrameQueued until delivery/final drop.
     *
     * @param string $acceptKey Departed real socket identity
     * @throws HilosException When the master cannot reconcile unused capacity on the departing channel
     */
    public function onUploadConnectionClosed(string $acceptKey): void;

    /**
     * Runs on the master loop: implementations must not read the database, write RT, or block.
     *
     * @param WorkerClient $worker Registered channel that supplied the report
     * @param int $memoryPoolBytes Positive effective upload budget in bytes
     * @throws HilosException When the owner cannot reconcile its metadata or queue a grant
     */
    public function onUploadSettings(WorkerClient $worker, int $memoryPoolBytes): void;

    /**
     * Runs on the master loop. Settle only receipts assigned to this exact channel incarnation;
     * byte counts come from the master's ledger. No DB, RT writes or blocking work belongs here.
     *
     * @param WorkerClient $worker Registered channel that completed these deliveries
     * @param list<string> $receiptIds Processed or finally discarded raw-byte receipts
     * @throws HilosException When the owner cannot reconcile its metadata or queue a grant
     */
    public function onUploadConsumed(WorkerClient $worker, array $receiptIds): void;
}

<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\ReHydrateRound;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerDbReHydratedDTO - one worker's answer to the re-hydrate announcement (HIL-436).
 *
 * Sent worker -> daemon right after {@see DbSyncApplicator::applyReHydrate()} returns, on both
 * outcomes: this is what turns the announcement from a shout into a {@see ReHydrateRound}. A
 * failed re-read used to end in a lone `Logger::error` on the worker, where nobody restoring a
 * database would ever see it; now it travels as a negative answer and reaches the operator.
 *
 * The frame does not name its sender: it arrives on that worker's own link, and the daemon reads
 * the index off the {@see WorkerClient} it came in on.
 */
class WorkerDbReHydratedDTO extends WorkerDTO
{
    /** @var string Whether the worker re-read its collections successfully */
    public const string FIELD_OK = 'ok';

    /** @var string Failure text when it did not */
    public const string FIELD_ERROR = 'error';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DB_REHYDRATED;

    /**
     * @param bool $ok Whether this worker re-read every DB-backed collection
     * @param ?string $error Failure text when it did not, null on success
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Get message type.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_OK => $this->ok,
            self::FIELD_ERROR => $this->error,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * A frame whose verdict did not survive the wire is read as a failure rather than a success:
     * the round it feeds is fail-closed, and the missing field is itself a problem worth naming.
     *
     * @param array<string, mixed> $data Source data (ok, error)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $error = $data[self::FIELD_ERROR] ?? null;

        return new static(
            ok: (bool)($data[self::FIELD_OK] ?? false),
            error: $error === null ? null : (string)$error,
        );
    }
}

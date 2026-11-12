<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Status\Exception\StatusException;

/**
 * Detached closed half-open silence interval, in signed UTC epoch milliseconds.
 */
final readonly class StatusPublicSilencePeriod
{
    public const string PUBLIC_ID_PATTERN = '/^[a-f0-9]{32}$/D';

    /**
     * @param string $id Opaque public identity, never an internal primary key
     * @param int $startedAt Last successful heartbeat before the silence
     * @param int $endedAt First successful coherent publication after the silence
     * @throws StatusException When identity or interval order is invalid
     */
    public function __construct(public string $id, public int $startedAt, public int $endedAt)
    {
        if (preg_match(self::PUBLIC_ID_PATTERN, $id) !== 1 || $endedAt <= $startedAt) {
            throw new StatusException('Public silence requires an opaque identity and a strictly later end.');
        }
    }

    /**
     * @param array<string, mixed> $data Exact decoded public fields
     * @return self Detached closed interval
     * @throws StatusException When fields, types, identity or interval order are invalid
     */
    public static function fromArray(array $data): self
    {
        $keys = ['id', 'startedAt', 'endedAt'];
        if (count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []) {
            throw new StatusException('Invalid public silence fields.');
        }
        return new self(
            StatusPublicJson::text($data['id']),
            StatusPublicJson::signedInteger($data['startedAt']),
            StatusPublicJson::signedInteger($data['endedAt']),
        );
    }

    /** @return array{id: string, startedAt: int, endedAt: int} Only closed public facts */
    public function toArray(): array
    {
        return ['id' => $this->id, 'startedAt' => $this->startedAt, 'endedAt' => $this->endedAt];
    }
}

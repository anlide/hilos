<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Status\Exception\StatusException;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/**
 * Private transit facts; the caller proves both boundaries, never this value's clock.
 */
final readonly class StatusSilenceObservation
{
    public const int FORMAT_VERSION = 1;
    private const int PUBLIC_ID_BYTES = 16;

    /**
     * @param string $id Stable opaque public identity
     * @param int $startedAt Proven last successful heartbeat, signed UTC epoch milliseconds
     * @param ?int $endedAt Proven resumed publication, null until its receipt
     * @throws StatusException When identity or interval order is invalid
     */
    public function __construct(public string $id, public int $startedAt, public ?int $endedAt)
    {
        if (preg_match(StatusPublicSilencePeriod::PUBLIC_ID_PATTERN, $id) !== 1
            || ($endedAt !== null && $endedAt <= $startedAt)) {
            throw new StatusException('Silence transit requires an opaque identity and a strictly later end when closed.');
        }
    }

    /**
     * The publisher qualifies the silence before creating its pending observation.
     *
     * @param int $startedAt Proven last successful heartbeat, signed UTC epoch milliseconds
     * @return self Open observation with a secure identity
     * @throws StatusException When the generated identity is invalid
     * @throws RandomException When secure entropy is unavailable
     */
    public static function start(int $startedAt): self
    {
        return new self(RandomHelper::secureHex(self::PUBLIC_ID_BYTES), $startedAt, null);
    }

    /**
     * @param string $json Private transit contents
     * @return self Strict format-v1 open or closed observation
     * @throws StatusException When JSON, version, fields or facts are malformed
     */
    public static function fromJson(string $json): self
    {
        $data = StatusPublicJson::fields(StatusPublicJson::decode($json), ['formatVersion', 'period']);
        if ($data['formatVersion'] !== self::FORMAT_VERSION) {
            throw new StatusException('Unsupported silence observation format.');
        }
        $period = StatusPublicJson::fields($data['period'], ['id', 'startedAt', 'endedAt']);
        return new self(
            StatusPublicJson::text($period['id']),
            StatusPublicJson::signedInteger($period['startedAt']),
            $period['endedAt'] === null ? null : StatusPublicJson::signedInteger($period['endedAt']),
        );
    }

    /**
     * @param int $endedAt Proven resumed publication, signed UTC epoch milliseconds
     * @return self Closed observation retaining its original identity and start
     * @throws StatusException When the end is invalid or changes an already closed interval
     */
    public function complete(int $endedAt): self
    {
        if ($this->endedAt !== null) {
            if ($this->endedAt !== $endedAt) {
                throw new StatusException('A closed silence observation cannot change its end.');
            }
            return $this;
        }
        return new self($this->id, $this->startedAt, $endedAt);
    }

    /**
     * @return StatusPublicSilencePeriod Closed facts without the private transit envelope
     * @throws StatusException When the observation is still open
     */
    public function toPublicPeriod(): StatusPublicSilencePeriod
    {
        if ($this->endedAt === null) {
            throw new StatusException('An open silence observation has no closed public interval.');
        }
        return new StatusPublicSilencePeriod($this->id, $this->startedAt, $this->endedAt);
    }

    /** @return array<string, mixed> Private format-v1 payload, with an explicit nullable end */
    public function toArray(): array
    {
        return [
            'formatVersion' => self::FORMAT_VERSION,
            'period' => ['id' => $this->id, 'startedAt' => $this->startedAt, 'endedAt' => $this->endedAt],
        ];
    }
}

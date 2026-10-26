<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Database\View\Item\StatusIncident;
use Hilos\Status\Exception\StatusException;

/** Published incident values only; the opaque id is stable across rebuilds. */
final readonly class StatusPublicOutage
{
    /**
     * @param string $id Opaque public identity
     * @param string $componentKey Catalog key recorded on the incident
     * @param StatusSeverity $severity Greatest published severity
     * @param int $detectedAt Detection epoch milliseconds UTC
     * @param int $publishedAt Publication epoch milliseconds UTC
     * @param ?int $resolvedAt Resolution epoch milliseconds UTC, or null while open
     * @param list<StatusPublicUpdate> $updates Public timeline in descending time/id source order
     */
    private function __construct(
        public string $id,
        public string $componentKey,
        public StatusSeverity $severity,
        public int $detectedAt,
        public int $publishedAt,
        public ?int $resolvedAt,
        public array $updates,
    ) {
    }

    /**
     * @param StatusIncident $incident One published source row; its relations are not read
     * @return self Detached public values with an empty timeline
     * @throws StatusException When the source is not a valid persisted published incident
     */
    public static function fromIncident(StatusIncident $incident): self
    {
        $severity = StatusSeverity::tryFrom($incident->peakSeverity ?? $incident->severity);
        if ($incident->id === null || $incident->publishedAt === null || $severity === null) {
            throw new StatusException('Public status requires a persisted published incident with a known severity.');
        }
        return new self(
            hash('sha256', 'hilos.status.outage.v1:' . $incident->id),
            $incident->componentKey,
            $severity,
            $incident->detectedAt,
            $incident->publishedAt,
            $incident->resolvedAt,
            [],
        );
    }

    /**
     * @param array<string, mixed> $data Exact public outage fields at the disk boundary
     * @return self Detached public incident
     * @throws StatusException When any field violates public v1
     */
    public static function fromArray(array $data): self
    {
        $data = StatusPublicJson::fields((object)$data, [
            'id', 'componentKey', 'severity', 'detectedAt', 'publishedAt', 'resolvedAt', 'updates',
        ]);
        $severity = StatusSeverity::tryFrom(StatusPublicJson::text($data['severity']));
        if ($severity === null) {
            throw new StatusException('Invalid public Status outage severity.');
        }
        $updates = [];
        foreach (StatusPublicJson::items($data['updates']) as $value) {
            $updates[] = StatusPublicUpdate::fromArray(StatusPublicJson::fields(is_array($value) ? (object)$value : $value, [
                'at', 'source', 'text',
            ]));
        }
        return new self(
            StatusPublicJson::text($data['id']), StatusPublicJson::text($data['componentKey']), $severity,
            StatusPublicJson::integer($data['detectedAt']), StatusPublicJson::integer($data['publishedAt']),
            $data['resolvedAt'] === null ? null : StatusPublicJson::integer($data['resolvedAt']), $updates,
        );
    }

    /**
     * @param list<StatusPublicUpdate> $updates Complete sanitized timeline in canonical order
     * @return self New value with the complete timeline
     */
    public function withUpdates(array $updates): self
    {
        return new self($this->id, $this->componentKey, $this->severity, $this->detectedAt, $this->publishedAt, $this->resolvedAt, $updates);
    }

    /** @return array<string, mixed> Literal public v1 outage */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'componentKey' => $this->componentKey,
            'severity' => $this->severity->value,
            'detectedAt' => $this->detectedAt,
            'publishedAt' => $this->publishedAt,
            'resolvedAt' => $this->resolvedAt,
            'updates' => array_map(static fn(StatusPublicUpdate $update): array => $update->toArray(), $this->updates),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Status\Exception\StatusException;

/** Public v1 values only; safe to retain when the database is unavailable or replaced. */
final readonly class StatusPublicSnapshot
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param string $revision Opaque content revision assigned by the publisher
     * @param int $generatedAt Generation epoch milliseconds UTC
     * @param int $pollIntervalSeconds Positive reader polling interval
     * @param int $staleAfterSeconds Positive heartbeat freshness threshold
     * @param list<StatusPublicComponent> $components Catalog declaration order
     * @param list<StatusPublicCandidate> $candidates Current Checking facts in catalog order
     * @param list<StatusPublicOutage> $outages Complete published history in descending detection/id source order
     * @throws StatusException When publication metadata is invalid
     */
    public function __construct(
        public string $revision,
        public int $generatedAt,
        public int $pollIntervalSeconds,
        public int $staleAfterSeconds,
        public array $components,
        public array $candidates,
        public array $outages,
    ) {
        if ($revision === '' || $generatedAt < 0 || $pollIntervalSeconds <= 0 || $staleAfterSeconds <= 0) {
            throw new StatusException('Public status requires a revision, a valid moment and positive reader intervals.');
        }
    }

    /**
     * @param string $json Complete status.json contents
     * @return self Detached public v1 projection; no DB or settings access
     * @throws StatusException When any public field has an invalid shape or value
     */
    public static function fromJson(string $json): self
    {
        $data = StatusPublicJson::fields(StatusPublicJson::decode($json), [
            'schemaVersion', 'revision', 'generatedAt', 'config', 'components', 'candidates', 'outages',
            'scheduledWorks', 'maintenancePeriods',
        ]);
        if ($data['schemaVersion'] !== self::SCHEMA_VERSION
            || StatusPublicJson::items($data['scheduledWorks']) !== []
            || StatusPublicJson::items($data['maintenancePeriods']) !== []) {
            throw new StatusException('Unsupported public Status schema or scheduled facts.');
        }
        $config = StatusPublicJson::fields($data['config'], ['pollIntervalSeconds', 'staleAfterSeconds']);
        $components = [];
        foreach (StatusPublicJson::items($data['components']) as $value) {
            $component = StatusPublicJson::fields($value, ['componentKey', 'name', 'description', 'state']);
            $state = StatusComponentState::tryFrom(StatusPublicJson::text($component['state']));
            if ($state === null) {
                throw new StatusException('Invalid public Status component state.');
            }
            $components[] = new StatusPublicComponent(
                StatusPublicJson::text($component['componentKey']), StatusPublicJson::text($component['name']),
                $component['description'] === null ? null : StatusPublicJson::text($component['description'], true), $state,
            );
        }
        $candidates = [];
        foreach (StatusPublicJson::items($data['candidates']) as $value) {
            $candidate = StatusPublicJson::fields($value, ['componentKey', 'detectedAt']);
            $candidates[] = new StatusPublicCandidate(
                StatusPublicJson::text($candidate['componentKey']), StatusPublicJson::integer($candidate['detectedAt']),
            );
        }
        $outages = [];
        foreach (StatusPublicJson::items($data['outages']) as $value) {
            $outages[] = StatusPublicOutage::fromArray(StatusPublicJson::fields($value, [
                'id', 'componentKey', 'severity', 'detectedAt', 'publishedAt', 'resolvedAt', 'updates',
            ]));
        }
        return new self(
            StatusPublicJson::text($data['revision']), StatusPublicJson::integer($data['generatedAt']),
            StatusPublicJson::integer($config['pollIntervalSeconds']), StatusPublicJson::integer($config['staleAfterSeconds']),
            $components, $candidates, $outages,
        );
    }

    /**
     * Copies permitted current facts without querying ORM, runtime, environment or settings.
     * Known published history survives Unknown and maintenance verdicts.
     *
     * @param StatusSnapshot $current Temporary source read; no source objects are retained
     * @param string $revision New opaque content revision
     * @param int $generatedAt Generation epoch milliseconds UTC
     * @return self New current values with the same complete history
     * @throws StatusException When publication metadata is invalid
     */
    public function withCurrent(StatusSnapshot $current, string $revision, int $generatedAt): self
    {
        $components = [];
        $candidates = [];
        foreach ($current->components as $component) {
            $components[] = new StatusPublicComponent(
                $component->component->componentKey,
                $component->component->name,
                $component->component->description,
                $component->state,
            );
            if ($component->state === StatusComponentState::CHECKING && $component->incident !== null
                && $component->incident->publishedAt === null && $component->incident->resolvedAt === null) {
                $candidates[] = new StatusPublicCandidate($component->component->componentKey, $component->incident->detectedAt);
            }
        }
        return new self(
            $revision, $generatedAt, $this->pollIntervalSeconds, $this->staleAfterSeconds, $components, $candidates, $this->outages,
        );
    }

    /**
     * @param list<StatusPublicOutage> $outages Complete published history in canonical order
     * @return self New value with the complete history
     * @throws StatusException When publication metadata is invalid
     */
    public function withOutages(array $outages): self
    {
        return new self(
            $this->revision, $this->generatedAt, $this->pollIntervalSeconds, $this->staleAfterSeconds,
            $this->components, $this->candidates, $outages,
        );
    }

    /** @return array<string, mixed> Complete literal status.json v1; scheduled facts belong to HIL-1692 */
    public function toArray(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'revision' => $this->revision,
            'generatedAt' => $this->generatedAt,
            'config' => ['pollIntervalSeconds' => $this->pollIntervalSeconds, 'staleAfterSeconds' => $this->staleAfterSeconds],
            'components' => array_map(static fn(StatusPublicComponent $component): array => $component->toArray(), $this->components),
            'candidates' => array_map(static fn(StatusPublicCandidate $candidate): array => $candidate->toArray(), $this->candidates),
            'outages' => array_map(static fn(StatusPublicOutage $outage): array => $outage->toArray(), $this->outages),
            'scheduledWorks' => [],
            'maintenancePeriods' => [],
        ];
    }
}

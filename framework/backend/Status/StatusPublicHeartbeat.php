<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Status\Exception\StatusException;

/** The successful publication receipt and the literal heartbeat.json value. */
final readonly class StatusPublicHeartbeat
{
    /**
     * @param string $revision Paired content revision
     * @param int $aliveAt Publication epoch milliseconds UTC
     * @param int $sourceSchemaVersion Input version for pair recovery; serialization always writes v2
     * @throws StatusException When heartbeat metadata is invalid
     */
    public function __construct(
        public string $revision,
        public int $aliveAt,
        public int $sourceSchemaVersion = StatusPublicSnapshot::SCHEMA_VERSION,
    )
    {
        if ($revision === '' || $aliveAt < 0) {
            throw new StatusException('Public Status heartbeat requires a revision and a valid moment.');
        }
    }

    /**
     * @param string $json Complete public heartbeat file
     * @return self Strictly decoded supported heartbeat
     * @throws StatusException When the file does not satisfy a supported public schema
     */
    public static function fromJson(string $json): self
    {
        $data = StatusPublicJson::fields(StatusPublicJson::decode($json), ['schemaVersion', 'revision', 'aliveAt']);
        if (!in_array($data['schemaVersion'], [StatusPublicSnapshot::LEGACY_SCHEMA_VERSION, StatusPublicSnapshot::SCHEMA_VERSION], true)) {
            throw new StatusException('Unsupported public Status heartbeat schema.');
        }
        return new self(StatusPublicJson::text($data['revision']), StatusPublicJson::integer($data['aliveAt']), $data['schemaVersion']);
    }

    /** @return array<string, mixed> Literal heartbeat.json v2 */
    public function toArray(): array
    {
        return ['schemaVersion' => StatusPublicSnapshot::SCHEMA_VERSION, 'revision' => $this->revision, 'aliveAt' => $this->aliveAt];
    }
}

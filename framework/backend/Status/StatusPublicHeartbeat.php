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
     * @throws StatusException When heartbeat metadata is invalid
     */
    public function __construct(public string $revision, public int $aliveAt)
    {
        if ($revision === '' || $aliveAt < 0) {
            throw new StatusException('Public Status heartbeat requires a revision and a valid moment.');
        }
    }

    /**
     * @param string $json Complete public heartbeat file
     * @return self Strictly decoded v1 heartbeat
     * @throws StatusException When the file does not satisfy public v1
     */
    public static function fromJson(string $json): self
    {
        $data = StatusPublicJson::fields(StatusPublicJson::decode($json), ['schemaVersion', 'revision', 'aliveAt']);
        if ($data['schemaVersion'] !== StatusPublicSnapshot::SCHEMA_VERSION) {
            throw new StatusException('Unsupported public Status heartbeat schema.');
        }
        return new self(StatusPublicJson::text($data['revision']), StatusPublicJson::integer($data['aliveAt']));
    }

    /** @return array<string, mixed> Literal heartbeat.json v1 */
    public function toArray(): array
    {
        return ['schemaVersion' => StatusPublicSnapshot::SCHEMA_VERSION, 'revision' => $this->revision, 'aliveAt' => $this->aliveAt];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Database\View\Item\StatusIncidentUpdate;
use Hilos\Status\Exception\StatusException;

/** Sanitized timeline value, detached from its database row and request identity. */
final readonly class StatusPublicUpdate
{
    private const string SOURCE_AUTOMATIC = 'automatic';

    /**
     * @param int $at Update epoch milliseconds UTC
     * @param string $source Public automatic or team source
     * @param string $text Safe fact wording or published team plain text
     */
    private function __construct(public int $at, public string $source, public string $text)
    {
    }

    /**
     * Unknown fact codes have no public wording; their stored diagnostic text is never copied.
     *
     * @param StatusIncidentUpdate $update One bounded source row
     * @return ?self Sanitized value, or null for an unknown automatic fact
     */
    public static function fromUpdate(StatusIncidentUpdate $update): ?self
    {
        $kind = StatusUpdateKind::tryFrom($update->kind);
        if ($kind === StatusUpdateKind::TEAM) {
            return new self($update->at, StatusUpdateKind::TEAM->value, $update->text);
        }
        $text = match ($kind) {
            StatusUpdateKind::RESOLVED => 'Resolved.',
            StatusUpdateKind::PUBLISHED, StatusUpdateKind::SEVERITY_CHANGED => match (StatusSeverity::tryFrom($update->severity)) {
                StatusSeverity::PARTIAL_OUTAGE => 'Partial outage.',
                StatusSeverity::MAJOR_OUTAGE => 'Major outage.',
                default => null,
            },
            default => null,
        };
        return $text === null ? null : new self($update->at, self::SOURCE_AUTOMATIC, $text);
    }

    /**
     * @param array<string, mixed> $data Exact public timeline fields at the disk boundary
     * @return self Detached public update; text has no HTML semantics
     * @throws StatusException When any field violates public v1
     */
    public static function fromArray(array $data): self
    {
        $data = StatusPublicJson::fields((object)$data, ['at', 'source', 'text']);
        $source = StatusPublicJson::text($data['source']);
        if (!in_array($source, [self::SOURCE_AUTOMATIC, StatusUpdateKind::TEAM->value], true)) {
            throw new StatusException('Invalid public Status update source.');
        }
        return new self(StatusPublicJson::integer($data['at']), $source, StatusPublicJson::text($data['text'], true));
    }

    /** @return array<string, mixed> Literal public v1 update; text has no HTML semantics */
    public function toArray(): array
    {
        return ['at' => $this->at, 'source' => $this->source, 'text' => $this->text];
    }
}

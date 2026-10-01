<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Legal\LegalReconsentProjector;

/**
 * The administrator's preview of the "the terms have changed" screen, carried by the tracked action reply (HIL-500).
 *
 * One document through the eyes of whoever held its previous revision ({@see LegalReconsentProjector::preview()}).
 * The first revision has no previous one: then the standing, the deadline and the held revision are null and
 * nothing changed.
 */
final class LegalReconsentPreviewReplyDTO extends ActionReplyDTO
{
    /**
     * @param string $document Document previewed
     * @param ?string $standing Standing of the previous revision's holder today, or null for the first revision
     * @param ?string $deadline Deadline of that holder, YYYY-MM-DD, or null when none is outstanding
     * @param ?array<string, mixed> $held Previous revision on the wire, or null for the first revision
     * @param array<string, mixed> $current Revision in force on the wire
     * @param list<array<string, mixed>> $changes Changes from the previous revision to the one in force
     * @param list<array<string, mixed>> $clauses Clauses of the revision in force
     */
    public function __construct(
        public readonly string $document,
        public readonly ?string $standing,
        public readonly ?string $deadline,
        public readonly ?array $held,
        public readonly array $current,
        public readonly array $changes,
        public readonly array $clauses,
    ) {
    }

    /** @return array<string, mixed> Preview for the browser */
    public function toArray(): array
    {
        return [
            'document' => $this->document,
            'standing' => $this->standing,
            'deadline' => $this->deadline,
            'held' => $this->held,
            'current' => $this->current,
            'changes' => $this->changes,
            'clauses' => $this->clauses,
        ];
    }

    /**
     * @param array<string, mixed> $data Serialized reply
     * @return static Restored preview
     * @throws InvalidFormatException When a member is missing or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, 'document'),
            self::optionalString($data, 'standing'),
            self::optionalString($data, 'deadline'),
            self::optionalArray($data, 'held'),
            self::requireArray($data, 'current'),
            self::requireArray($data, 'changes'),
            self::requireArray($data, 'clauses'),
        );
    }
}

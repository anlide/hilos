<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/** Read-only legal revision answer; clause serialization is owned by LegalWire (HIL-498). */
final class LegalRevisionTextReplyDTO extends ActionReplyDTO
{
    public const string document = 'document';
    public const string revisionId = 'revisionId';
    public const string clauses = 'clauses';

    /**
     * @param string $document document on the wire
     * @param string $revisionId revisionId on the wire
     * @param list<array<string, mixed>> $clauses clauses on the wire
     */
    public function __construct(
        public readonly string $document,
        public readonly string $revisionId,
        public readonly array $clauses,
    ) {
    }

    /**
     * @param array<string, mixed> $data Reply payload
     * @return static Parsed reply
     * @throws InvalidFormatException When a required field is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::document),
            self::requireString($data, self::revisionId),
            self::requireArray($data, self::clauses),
        );
    }

    /** @return array<string, mixed> Reply on the action acknowledgement */
    public function toArray(): array
    {
        return [
            self::document => $this->document,
            self::revisionId => $this->revisionId,
            self::clauses => $this->clauses,
        ];
    }
}

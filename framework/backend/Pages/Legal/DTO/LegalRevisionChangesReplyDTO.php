<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/** Read-only legal revision answer; clause serialization is owned by LegalWire (HIL-498). */
final class LegalRevisionChangesReplyDTO extends ActionReplyDTO
{
    public const string document = 'document';
    public const string fromRevisionId = 'fromRevisionId';
    public const string toRevisionId = 'toRevisionId';
    public const string changes = 'changes';

    /**
     * @param string $document document on the wire
     * @param string $fromRevisionId fromRevisionId on the wire
     * @param string $toRevisionId toRevisionId on the wire
     * @param list<array<string, mixed>> $changes changes on the wire
     */
    public function __construct(
        public readonly string $document,
        public readonly string $fromRevisionId,
        public readonly string $toRevisionId,
        public readonly array $changes,
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
            self::requireString($data, self::fromRevisionId),
            self::requireString($data, self::toRevisionId),
            self::requireArray($data, self::changes),
        );
    }

    /** @return array<string, mixed> Reply on the action acknowledgement */
    public function toArray(): array
    {
        return [
            self::document => $this->document,
            self::fromRevisionId => $this->fromRevisionId,
            self::toRevisionId => $this->toRevisionId,
            self::changes => $this->changes,
        ];
    }
}

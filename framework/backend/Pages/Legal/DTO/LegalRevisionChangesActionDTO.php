<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Names one published revision to read (HIL-498). */
final class LegalRevisionChangesActionDTO extends ActionPayloadDTO
{
    public const string document = 'document';
    public const string revisionId = 'revisionId';

    /**
     * @param string $document Document key
     * @param string $revisionId Revision key
     */
    public function __construct(public readonly string $document, public readonly string $revisionId)
    {
    }

    /** @return string Action wire name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_LEGAL_REVISION_CHANGES;
    }

    /**
     * @param array<string, mixed> $data Action payload
     * @return static Parsed request
     * @throws InvalidFormatException When a required field is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::document), self::requireString($data, self::revisionId));
    }

    /** @return array<string, string> Request on the wire */
    public function toArray(): array
    {
        return [self::document => $this->document, self::revisionId => $this->revisionId];
    }
}

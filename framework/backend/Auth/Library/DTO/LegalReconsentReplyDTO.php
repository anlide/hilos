<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Legal\LegalReconsentProjector;

/** The "the terms have changed" screen's complete content, carried by the tracked action reply (HIL-500). */
final class LegalReconsentReplyDTO extends ActionReplyDTO
{
    /**
     * @param string $refusal What a refusal after the deadline does: freeze or keep reminding
     * @param list<array<string, mixed>> $documents Documents waiting for a decision ({@see LegalReconsentProjector::documents()})
     */
    public function __construct(
        public readonly string $refusal,
        public readonly array $documents,
    ) {
    }

    /** @return array<string, mixed> Re-consent content for the browser */
    public function toArray(): array
    {
        return ['refusal' => $this->refusal, 'documents' => $this->documents];
    }

    /**
     * @param array<string, mixed> $data Serialized reply
     * @return static Restored re-consent content
     * @throws InvalidFormatException When the refusal or the document list is missing or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'refusal'), self::requireArray($data, 'documents'));
    }
}

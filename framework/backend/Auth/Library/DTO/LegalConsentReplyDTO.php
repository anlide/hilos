<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/** The consent step's complete content, carried by the tracked action reply. */
final class LegalConsentReplyDTO extends ActionReplyDTO
{
    /**
     * @param string $form Project's consent form
     * @param list<array<string, mixed>> $documents LegalWire boundary shapes of current documents
     */
    public function __construct(
        public readonly string $form,
        public readonly array $documents,
    ) {
    }

    /** @return array<string, mixed> Consent content for the browser */
    public function toArray(): array
    {
        return ['form' => $this->form, 'documents' => $this->documents];
    }

    /**
     * @param array<string, mixed> $data Serialized reply
     * @return static Restored consent content
     * @throws InvalidFormatException When the form or document list is missing or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'form'), self::requireArray($data, 'documents'));
    }
}

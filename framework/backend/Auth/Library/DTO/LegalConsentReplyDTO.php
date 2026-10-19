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
     * @param string $unpublishedMessage Refusal when no terms are published
     * @param string $revisedMessage Notice when the displayed revisions changed
     */
    public function __construct(
        public readonly string $form,
        public readonly array $documents,
        public readonly string $unpublishedMessage,
        public readonly string $revisedMessage,
    ) {
    }

    /** @return array<string, mixed> Consent content for the browser */
    public function toArray(): array
    {
        return [
            'form' => $this->form,
            'documents' => $this->documents,
            'unpublishedMessage' => $this->unpublishedMessage,
            'revisedMessage' => $this->revisedMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data Serialized reply
     * @return static Restored consent content
     * @throws InvalidFormatException When the form, documents or either message is missing or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, 'form'),
            self::requireArray($data, 'documents'),
            self::requireString($data, 'unpublishedMessage'),
            self::requireString($data, 'revisedMessage'),
        );
    }
}

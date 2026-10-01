<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Public read of the "the terms have changed" screen as a holder of a document's previous revision sees it (HIL-500). */
final class LegalReconsentPreviewActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * @param string $document Document previewed, as the catalog names it
     */
    public function __construct(public readonly string $document)
    {
    }

    /** @return string Re-consent preview action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_LEGAL_RECONSENT_PREVIEW;
    }

    /**
     * @param array<string, mixed> $data Action payload
     * @return static The previewed document
     * @throws InvalidFormatException When the document is missing or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'document'));
    }

    /** @return array<string, mixed> Preview request payload */
    public function toArray(): array
    {
        return ['document' => $this->document];
    }
}

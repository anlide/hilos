<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** A signed-in person accepts the revisions in force of the documents the screen showed (HIL-500). */
final class LegalAcceptActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * @param array<string, string> $acceptedRevisions Revision accepted per document, as the screen showed it
     */
    public function __construct(public readonly array $acceptedRevisions)
    {
    }

    /** @return string Acceptance action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_LEGAL_ACCEPT;
    }

    /**
     * @param array<string, mixed> $data Action payload
     * @return static The accepted revisions
     * @throws InvalidFormatException When the map is missing or a revision is not a non-empty string
     */
    public static function fromArray(array $data): static
    {
        $acceptedRevisions = self::requireStringMap($data, 'acceptedRevisions');
        if (in_array('', $acceptedRevisions, true)) {
            throw new InvalidFormatException('Payload carries an empty revision under key acceptedRevisions');
        }

        return new static($acceptedRevisions);
    }

    /** @return array<string, mixed> Acceptance payload */
    public function toArray(): array
    {
        return ['acceptedRevisions' => $this->acceptedRevisions];
    }
}

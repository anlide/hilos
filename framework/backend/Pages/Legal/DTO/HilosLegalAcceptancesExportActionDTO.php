<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Legal\Export\LegalAcceptancesExportMessages;

/**
 * The filters and the search the acceptances table showed when an administrator ordered its export (HIL-1234).
 *
 * Each is optional, and an empty one means the same as an absent one: no filter. The filter comes from the
 * browser and reaches SQL only as a bound value; its length is held to the columns it is compared with and
 * to the one it is kept in.
 */
final class HilosLegalAcceptancesExportActionDTO extends ActionPayloadDTO
{
    /** Payload key: the document filter. */
    public const string document = 'document';

    /** Payload key: the revision filter. */
    public const string revisionId = 'revisionId';

    /** Payload key: the search. */
    public const string search = 'search';

    public const array SECRET_FIELDS = [];

    /** Longest document key an acceptance record holds. */
    private const int DOCUMENT_MAX_CHARACTERS = 16;

    /** Longest revision id an acceptance record holds. */
    private const int REVISION_MAX_CHARACTERS = 32;

    /** Longest search an order keeps. */
    private const int SEARCH_MAX_CHARACTERS = 200;

    /**
     * @param ?string $document Document filter, or null for every document
     * @param ?string $revisionId Revision filter, or null for every revision
     * @param ?string $search Search over names and emails, or null for none
     */
    public function __construct(
        public readonly ?string $document,
        public readonly ?string $revisionId,
        public readonly ?string $search,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::LEGAL_ACCEPTANCES_EXPORT;
    }

    /**
     * @param array<string, mixed> $data Raw payload
     * @return static Instance
     * @throws InvalidFormatException When a field is present and not a string
     * @throws ValidationException When a field is longer than its column
     */
    public static function fromArray(array $data): static
    {
        return new static(
            document: self::filter(self::optionalString($data, self::document), self::DOCUMENT_MAX_CHARACTERS),
            revisionId: self::filter(self::optionalString($data, self::revisionId), self::REVISION_MAX_CHARACTERS),
            search: self::filter(self::optionalString($data, self::search), self::SEARCH_MAX_CHARACTERS),
        );
    }

    /**
     * @return array<string, mixed> The filters and the search
     */
    public function toArray(): array
    {
        return [
            self::document => $this->document,
            self::revisionId => $this->revisionId,
            self::search => $this->search,
        ];
    }

    /**
     * @param ?string $value Field as the browser sent it
     * @param int $maxCharacters Longest value the field may hold
     * @return ?string The trimmed value, or null when it is absent or empty
     * @throws ValidationException When the value is longer than allowed
     */
    private static function filter(?string $value, int $maxCharacters): ?string
    {
        $value = $value === null ? null : trim($value);
        if ($value === null || $value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxCharacters) {
            throw new ValidationException(LegalAcceptancesExportMessages::INVALID_FILTER);
        }

        return $value;
    }
}

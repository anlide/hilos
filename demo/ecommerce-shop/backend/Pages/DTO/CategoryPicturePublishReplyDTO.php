<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * The answer to a category picture publication: the registry file the new category will show, and
 * the address of its copy for the modal preview.
 *
 * The id goes back with the add request; the address lets the modal draw the picture it is about to
 * keep without building the path itself.
 */
final class CategoryPicturePublishReplyDTO extends ActionReplyDTO
{
    /** Wire key for the id of the published registry file. */
    public const string fileId = 'fileId';

    /** Wire key for the address of the file's modal preview copy. */
    public const string picture = 'picture';

    /**
     * @param int $fileId Id of the published registry file
     * @param string $picture Address of the file's modal preview copy
     */
    public function __construct(
        public readonly int $fileId,
        public readonly string $picture,
    ) {
    }

    /** @return array{fileId: int, picture: string} Reply payload */
    public function toArray(): array
    {
        return [self::fileId => $this->fileId, self::picture => $this->picture];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the file id or the address is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            fileId: self::requireInt($data, self::fileId),
            picture: self::requireString($data, self::picture),
        );
    }
}

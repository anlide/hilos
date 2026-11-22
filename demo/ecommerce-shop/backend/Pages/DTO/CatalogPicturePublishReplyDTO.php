<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * The published catalog picture's registry file and the address of its modal preview.
 *
 * The form sends the id when it saves and draws the preview at the supplied address.
 */
final class CatalogPicturePublishReplyDTO extends ActionReplyDTO
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

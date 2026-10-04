<?php

declare(strict_types=1);

namespace Hilos\Files\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\ContentHash;

/**
 * One handed-over file inside {@see FilePublishSignalData}: where its temporary file is and what
 * its registry row will say (HIL-136). Storage names the row's size after keeping the file.
 */
final class FilePublishItemData extends BaseDTO
{
    public const string tmpIndex = 'tmpIndex';
    public const string filename = 'filename';
    public const string mimeType = 'mimeType';
    public const string ownerUserId = 'ownerUserId';
    public const string contentHash = 'contentHash';

    /**
     * @param string $tmpIndex Index of the temporary file in the tmp directory
     * @param string $filename Name the uploader gave the file
     * @param string $mimeType Type read from the content when the target read it, the declared one otherwise
     * @param int $ownerUserId Person signed in on the connection when the file was published
     * @param string $contentHash Fingerprint of the file's content ({@see ContentHash})
     */
    public function __construct(
        public readonly string $tmpIndex,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $ownerUserId,
        public readonly string $contentHash,
    ) {
    }

    /**
     * @return array{tmpIndex: string, filename: string, mimeType: string, ownerUserId: int, contentHash: string}
     *     DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::tmpIndex => $this->tmpIndex,
            self::filename => $this->filename,
            self::mimeType => $this->mimeType,
            self::ownerUserId => $this->ownerUserId,
            self::contentHash => $this->contentHash,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a field is absent, the owner is not a positive id, or the fingerprint is malformed
     */
    public static function fromArray(array $data): static
    {
        $ownerUserId = self::requireInt($data, self::ownerUserId);
        if ($ownerUserId <= 0) {
            throw new InvalidFormatException('Payload key ' . self::ownerUserId . ' holds no positive user id');
        }
        $contentHash = self::requireString($data, self::contentHash);
        if (!ContentHash::isValid($contentHash)) {
            throw new InvalidFormatException('Payload key ' . self::contentHash . ' holds no content fingerprint');
        }

        return new static(
            tmpIndex: self::requireString($data, self::tmpIndex),
            filename: self::requireString($data, self::filename),
            mimeType: self::requireString($data, self::mimeType),
            ownerUserId: $ownerUserId,
            contentHash: $contentHash,
        );
    }
}

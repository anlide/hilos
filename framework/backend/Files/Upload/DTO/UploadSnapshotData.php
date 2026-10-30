<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\ContentHash;
use Hilos\Files\Upload\UploadFrame;
use Hilos\Fs\FsTmpDirectory;

/** Immutable identity of received bytes; neither a filesystem path nor file contents. */
final class UploadSnapshotData extends BaseDTO
{
    public const string clientUploadId = 'clientUploadId';
    public const string tmpIndex = 'tmpIndex';
    public const string contentHash = 'contentHash';

    /** Lowercase hex filename produced by {@see FsTmpDirectory::create()}, from sixteen random bytes. */
    private const string TMP_INDEX_PATTERN = '/\A[0-9a-f]{32}\z/';

    /**
     * @param string $clientUploadId Valid client upload id
     * @param string $tmpIndex Hex filename from the temporary directory
     * @param string $contentHash Lowercase SHA-256 fingerprint
     * @throws InvalidFormatException When an id, temporary name or fingerprint is malformed
     */
    public function __construct(
        public readonly string $clientUploadId,
        public readonly string $tmpIndex,
        public readonly string $contentHash,
    ) {
        if (!UploadFrame::isValidId($clientUploadId)) {
            throw new InvalidFormatException('Invalid snapshot clientUploadId.');
        }
        if (preg_match(self::TMP_INDEX_PATTERN, $tmpIndex) !== 1) {
            throw new InvalidFormatException('Invalid snapshot tmpIndex.');
        }
        if (!ContentHash::isValid($contentHash)) {
            throw new InvalidFormatException('Invalid snapshot contentHash.');
        }
    }

    /** @return array<string, string> Snapshot identity for runtime serialization */
    public function toArray(): array
    {
        return [
            self::clientUploadId => $this->clientUploadId,
            self::tmpIndex => $this->tmpIndex,
            self::contentHash => $this->contentHash,
        ];
    }

    /**
     * @param array<string, mixed> $data Serialized snapshot identity
     * @return static Immutable snapshot
     * @throws InvalidFormatException When a required string is missing, mistyped or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::clientUploadId),
            self::requireString($data, self::tmpIndex),
            self::requireString($data, self::contentHash),
        );
    }
}

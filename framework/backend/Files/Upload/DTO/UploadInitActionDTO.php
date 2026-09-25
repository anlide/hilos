<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Files\Upload\UploadFrame;

/**
 * UploadInitActionDTO - a browser declares one file before sending its chunks (HIL-135).
 *
 * The reader refuses what no honest client sends, the way every action reader does: an upload
 * id the chunk signature could not carry, a file name that is nothing but a path, a negative
 * size. The file name loses any path a browser put in front of it here, once, so nothing
 * downstream sees a directory; everything that is a matter of the target's policy - the size
 * limit, the type - is judged by the uploads agent, which answers with a sentence.
 */
final class UploadInitActionDTO extends ActionPayloadDTO
{
    /** Longest file name kept, in characters. */
    public const int MAX_FILENAME_LENGTH = 255;

    /** Path separators a browser may leave in front of a file name. */
    private const string PATH_SEPARATORS = '/\\';

    /**
     * @param string $target Name of the upload target in the project's UPLOAD_TARGETS
     * @param string $clientUploadId Id the client gave the upload, unique on its connection
     * @param string $filename File name without any path
     * @param string $mimeType Type as the browser declared it
     * @param int $size Declared size in bytes
     */
    public function __construct(
        public readonly string $target,
        public readonly string $clientUploadId,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
    ) {
    }

    /**
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_UPLOAD_INIT;
    }

    /**
     * @param array<string, mixed> $data Payload data
     * @return static Declaration with the file name stripped of its path
     * @throws InvalidFormatException When a field is absent or of the wrong type, the upload id is not
     *     one a chunk can carry, the file name is empty or longer than {@see self::MAX_FILENAME_LENGTH}
     *     characters once stripped, or the size is negative
     */
    public static function fromArray(array $data): static
    {
        $clientUploadId = self::requireString($data, 'clientUploadId');
        if (!UploadFrame::isValidId($clientUploadId)) {
            throw new InvalidFormatException(
                'Invalid clientUploadId. Expected 1 to ' . UploadFrame::MAX_ID_LENGTH . ' characters of A-Z, a-z, 0-9, _ and -.'
            );
        }

        $filename = self::stripPath(self::requireString($data, 'filename'));
        if ($filename === '' || mb_strlen($filename) > self::MAX_FILENAME_LENGTH) {
            throw new InvalidFormatException(
                'Invalid filename. Expected 1 to ' . self::MAX_FILENAME_LENGTH . ' characters after the path is removed.'
            );
        }

        $size = self::requireInt($data, 'size');
        if ($size < 0) {
            throw new InvalidFormatException('Invalid size. Expected a non-negative number of bytes.');
        }

        return new static(
            target: self::requireString($data, 'target'),
            clientUploadId: $clientUploadId,
            filename: $filename,
            mimeType: self::requireString($data, 'mimeType'),
            size: $size,
        );
    }

    /**
     * @return array{target: string, clientUploadId: string, filename: string, mimeType: string, size: int} Declaration payload
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'clientUploadId' => $this->clientUploadId,
            'filename' => $this->filename,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
        ];
    }

    /**
     * Keeps the last segment of a name after either path separator, trimmed.
     *
     * @param string $name File name as the browser sent it
     * @return string Name without any path
     */
    private static function stripPath(string $name): string
    {
        $lastSeparator = strrpos(strtr($name, self::PATH_SEPARATORS, '//'), '/');

        return trim($lastSeparator === false ? $name : substr($name, $lastSeparator + 1));
    }
}

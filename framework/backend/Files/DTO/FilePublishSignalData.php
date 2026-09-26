<?php

declare(strict_types=1);

namespace Hilos\Files\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Files\FileVisibility;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Files\Upload\UploadsAgent;

/**
 * Uploads agent → files library: keep these handed-over temporary files and register them
 * unbound; answer the asker under its name (HIL-136).
 *
 * {@see UploadsAgent} sends it once the uploads it names have passed every check and their rows
 * are gone: from here on the temporary files are {@see AbstractFilesLibraryAgent}'s. The files
 * come in the order of the ids, one for each.
 */
final class FilePublishSignalData extends BaseDTO implements SignalDataInterface
{
    public const string acceptKey = 'acceptKey';
    public const string clientUploadIds = 'clientUploadIds';
    public const string visibility = 'visibility';
    public const string replySignal = 'replySignal';
    public const string files = 'files';

    /**
     * @param string $acceptKey Accept key of the connection the uploads belonged to
     * @param list<string> $clientUploadIds Ids the client gave the uploads, in the order of the answer
     * @param string $visibility Who may be given the files, a {@see FileVisibility} value
     * @param string $replySignal Name of the agent signal the answer comes under
     * @param list<FilePublishItemData> $files The handed-over files, one for each id and in its order
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly array $clientUploadIds,
        public readonly string $visibility,
        public readonly string $replySignal,
        public readonly array $files,
    ) {
    }

    /**
     * @return array{acceptKey: string, clientUploadIds: list<string>, visibility: string, replySignal: string,
     *     files: list<array<string, mixed>>} DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::clientUploadIds => $this->clientUploadIds,
            self::visibility => $this->visibility,
            self::replySignal => $this->replySignal,
            self::files => array_map(static fn(FilePublishItemData $file): array => $file->toArray(), $this->files),
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a field is absent or malformed, or the files are not one for each id
     */
    public static function fromArray(array $data): static
    {
        $clientUploadIds = UploadPublishSignalData::requireClientUploadIds($data, self::clientUploadIds);
        $values = self::requireArray($data, self::files);
        if (!array_is_list($values) || count($values) !== count($clientUploadIds)) {
            throw new InvalidFormatException('Payload key ' . self::files . ' holds no file for each upload id');
        }

        $files = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new InvalidFormatException('Payload key ' . self::files . ' holds a value that is not a file');
            }
            $files[] = FilePublishItemData::fromArray($value);
        }

        return new static(
            acceptKey: self::requireString($data, self::acceptKey),
            clientUploadIds: $clientUploadIds,
            visibility: UploadPublishSignalData::requireVisibility($data, self::visibility),
            replySignal: UploadPublishSignalData::requireName($data, self::replySignal),
            files: $files,
        );
    }
}

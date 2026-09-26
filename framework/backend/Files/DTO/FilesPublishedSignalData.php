<?php

declare(strict_types=1);

namespace Hilos\Files\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Files\HilosFiles;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;

/**
 * The answer to {@see HilosFiles::publishUploads()}, under the name the project gave it (HIL-136).
 *
 * Sent by {@see AbstractFilesLibraryAgent} when the files are kept and registered, and by
 * {@see UploadsAgent} when it refuses the request before anything moved. Either way it is bound to
 * the connection the uploads came from, so a page receives it the way the chat page receives a
 * moderation verdict.
 *
 * `error` null means published: `fileIds` then holds one registry id for each upload id, in its
 * order. Otherwise `error` is a sentence for the person and `fileIds` is empty.
 */
final class FilesPublishedSignalData extends BaseDTO implements SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    public const string acceptKey = 'acceptKey';
    public const string clientUploadIds = 'clientUploadIds';
    public const string fileIds = 'fileIds';
    public const string error = 'error';

    /**
     * @param string $acceptKey Accept key of the connection the uploads came from
     * @param list<string> $clientUploadIds Ids the client gave the uploads, as the request named them
     * @param list<int> $fileIds Registry ids in the order of the upload ids; empty when refused
     * @param ?string $error Sentence for the person, or null when the files are published
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly array $clientUploadIds,
        public readonly array $fileIds,
        public readonly ?string $error,
    ) {
    }

    /**
     * The answer that the files are kept and registered.
     *
     * @param FilePublishSignalData $request Request the library answers
     * @param list<int> $fileIds Registry ids in the order of the request's upload ids
     * @return self Published answer
     */
    public static function published(FilePublishSignalData $request, array $fileIds): self
    {
        return new self($request->acceptKey, $request->clientUploadIds, $fileIds, null);
    }

    /**
     * The answer that nothing is published.
     *
     * @param string $acceptKey Accept key of the connection the uploads came from
     * @param list<string> $clientUploadIds Ids the client gave the uploads, as the request named them
     * @param string $error Sentence for the person
     * @return self Refused answer
     */
    public static function refused(string $acceptKey, array $clientUploadIds, string $error): self
    {
        return new self($acceptKey, $clientUploadIds, [], $error);
    }

    /**
     * @return string Accept key of the connection the uploads came from
     */
    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * @return array{acceptKey: string, clientUploadIds: list<string>, fileIds: list<int>, error: ?string} DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::clientUploadIds => $this->clientUploadIds,
            self::fileIds => $this->fileIds,
            self::error => $this->error,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a field is absent or malformed, or the ids do not match the outcome
     */
    public static function fromArray(array $data): static
    {
        $clientUploadIds = UploadPublishSignalData::requireClientUploadIds($data, self::clientUploadIds);

        if (!array_key_exists(self::error, $data) || ($data[self::error] !== null && !is_string($data[self::error]))) {
            throw new InvalidFormatException('Payload carries no string or null under key ' . self::error);
        }
        $error = $data[self::error];

        $values = self::requireArray($data, self::fileIds);
        $fileIds = [];
        foreach ($values as $value) {
            if (!is_int($value) || $value <= 0) {
                throw new InvalidFormatException('Payload key ' . self::fileIds . ' holds a value that is not a positive integer');
            }
            $fileIds[] = $value;
        }
        $matchesOutcome = $error === null ? count($fileIds) === count($clientUploadIds) : $fileIds === [];
        if (!array_is_list($values) || !$matchesOutcome) {
            throw new InvalidFormatException('Payload key ' . self::fileIds . ' holds no file id for each published upload');
        }

        return new static(
            acceptKey: self::requireString($data, self::acceptKey),
            clientUploadIds: $clientUploadIds,
            fileIds: $fileIds,
            error: $error,
        );
    }
}

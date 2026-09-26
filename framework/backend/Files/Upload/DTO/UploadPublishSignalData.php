<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Files\Upload\UploadFrame;

/**
 * Project → uploads agent: hand these complete uploads of one connection over to the files
 * registry (HIL-136).
 *
 * Built by {@see HilosFiles::publishUploads()}, which reads its own frame back through
 * {@see self::fromArray()} so that a malformed request is refused at the door rather than by
 * the agent. The answer is a {@see FilesPublishedSignalData} under {@see self::$replySignal}.
 */
final class UploadPublishSignalData extends BaseDTO implements SignalDataInterface
{
    public const string acceptKey = 'acceptKey';
    public const string target = 'target';
    public const string clientUploadIds = 'clientUploadIds';
    public const string visibility = 'visibility';
    public const string replySignal = 'replySignal';

    /**
     * @param string $acceptKey Accept key of the connection the uploads belong to
     * @param string $target Upload target the uploads must have been declared for
     * @param list<string> $clientUploadIds Ids the client gave the uploads; the answer keeps their order
     * @param string $visibility Who may be given the published files, a {@see FileVisibility} value
     * @param string $replySignal Name of the agent signal the answer comes under
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $target,
        public readonly array $clientUploadIds,
        public readonly string $visibility,
        public readonly string $replySignal,
    ) {
    }

    /**
     * Reads a list of upload ids to publish: not empty, no id twice, every id one the wire allows.
     *
     * Shared with the frame the uploads agent sends on to the files library, which carries the
     * same list.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the list
     * @return list<string> Upload ids in the order the sender wrote them
     * @throws InvalidFormatException When the list is absent, empty, repeats an id, or holds an id the wire does not allow
     */
    public static function requireClientUploadIds(array $data, string $key): array
    {
        $values = self::requireArray($data, $key);
        if ($values === [] || !array_is_list($values)) {
            throw new InvalidFormatException('Payload carries no non-empty list under key ' . $key);
        }

        $ids = [];
        foreach ($values as $value) {
            if (!is_string($value) || !UploadFrame::isValidId($value)) {
                throw new InvalidFormatException('Payload key ' . $key . ' holds a value that is not an upload id');
            }
            if (in_array($value, $ids, true)) {
                throw new InvalidFormatException('Payload key ' . $key . ' names upload ' . $value . ' twice');
            }
            $ids[] = $value;
        }

        return $ids;
    }

    /**
     * Reads the visibility of the files to publish.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the visibility
     * @return string A {@see FileVisibility} value
     * @throws InvalidFormatException When the key is absent or holds no visibility the registry knows
     */
    public static function requireVisibility(array $data, string $key): string
    {
        $visibility = self::requireString($data, $key);
        if (FileVisibility::tryFrom($visibility) === null) {
            throw new InvalidFormatException('Payload key ' . $key . ' holds no file visibility');
        }

        return $visibility;
    }

    /**
     * Reads a name the payload cannot do without, refusing an empty one.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the name
     * @return string The name
     * @throws InvalidFormatException When the key is absent or holds an empty string
     */
    public static function requireName(array $data, string $key): string
    {
        $name = self::requireString($data, $key);
        if ($name === '') {
            throw new InvalidFormatException('Payload carries an empty string under key ' . $key);
        }

        return $name;
    }

    /**
     * @return array{acceptKey: string, target: string, clientUploadIds: list<string>, visibility: string, replySignal: string}
     *     DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::target => $this->target,
            self::clientUploadIds => $this->clientUploadIds,
            self::visibility => $this->visibility,
            self::replySignal => $this->replySignal,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a field is absent, the id list is malformed, the visibility is unknown,
     *     or the target or the reply name is empty
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, self::acceptKey),
            target: self::requireName($data, self::target),
            clientUploadIds: self::requireClientUploadIds($data, self::clientUploadIds),
            visibility: self::requireVisibility($data, self::visibility),
            replySignal: self::requireName($data, self::replySignal),
        );
    }
}

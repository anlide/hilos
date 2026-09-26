<?php

declare(strict_types=1);

namespace Hilos\Files\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Project → files library: these files are linked by the project now (HIL-336).
 *
 * An empty list is a valid payload on reading; the door that sends it never sends one.
 */
final class FileBindSignalData extends BaseDTO implements SignalDataInterface
{
    public const string fileIds = 'fileIds';

    /**
     * @param list<int> $fileIds Ids of the registry rows the project linked
     */
    public function __construct(public readonly array $fileIds)
    {
    }

    /**
     * @return array{fileIds: list<int>} DTO payload for transport
     */
    public function toArray(): array
    {
        return [self::fileIds => $this->fileIds];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no list of positive integers under fileIds
     */
    public static function fromArray(array $data): static
    {
        $values = self::requireArray($data, self::fileIds);
        if (!array_is_list($values)) {
            throw new InvalidFormatException('Payload carries no list under key ' . self::fileIds);
        }

        $fileIds = [];
        foreach ($values as $value) {
            if (!is_int($value) || $value <= 0) {
                throw new InvalidFormatException('Payload key ' . self::fileIds . ' holds a value that is not a positive integer');
            }
            $fileIds[] = $value;
        }

        return new static(fileIds: $fileIds);
    }
}

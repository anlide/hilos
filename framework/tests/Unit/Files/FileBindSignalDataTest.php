<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\DTO\FileBindSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the payload of the files registry bind frame (HIL-336).
 *
 * The list crosses a process boundary, so it is read strictly: anything but a list of positive
 * integers is a broken frame, not a list with a few ids to skip.
 */
final class FileBindSignalDataTest extends TestCase
{
    public function testTheIdsSurviveTheTrip(): void
    {
        $carried = FileBindSignalData::fromArray(new FileBindSignalData([3, 1, 2])->toArray());

        self::assertSame([3, 1, 2], $carried->fileIds);
    }

    public function testAnEmptyListIsReadable(): void
    {
        self::assertSame([], FileBindSignalData::fromArray([FileBindSignalData::fileIds => []])->fileIds);
    }

    /**
     * @param array<string, mixed> $payload Broken payload
     */
    #[DataProvider('brokenPayloads')]
    public function testABrokenPayloadIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);

        FileBindSignalData::fromArray($payload);
    }

    /**
     * @return array<string, array{array<string, mixed>}> Payloads the reader must refuse
     */
    public static function brokenPayloads(): array
    {
        return [
            'no key' => [[]],
            'not a list' => [[FileBindSignalData::fileIds => 7]],
            'keyed map' => [[FileBindSignalData::fileIds => ['a' => 1]]],
            'string id' => [[FileBindSignalData::fileIds => ['1']]],
            'zero id' => [[FileBindSignalData::fileIds => [1, 0]]],
            'negative id' => [[FileBindSignalData::fileIds => [-4]]],
        ];
    }
}

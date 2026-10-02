<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\DTO\FileRemoveSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the payload of the files registry remove frame (HIL-144).
 *
 * The list crosses a process boundary, so it is read strictly: anything but a list of positive
 * integers is a broken frame, not a list with a few ids to skip.
 */
final class FileRemoveSignalDataTest extends TestCase
{
    public function testTheIdsSurviveTheTrip(): void
    {
        $carried = FileRemoveSignalData::fromArray(new FileRemoveSignalData([3, 1, 2])->toArray());

        self::assertSame([3, 1, 2], $carried->fileIds);
    }

    public function testAnEmptyListIsReadable(): void
    {
        self::assertSame([], FileRemoveSignalData::fromArray([FileRemoveSignalData::fileIds => []])->fileIds);
    }

    /**
     * @param array<string, mixed> $payload Broken payload
     */
    #[DataProvider('brokenPayloads')]
    public function testABrokenPayloadIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);

        FileRemoveSignalData::fromArray($payload);
    }

    /**
     * @return array<string, array{array<string, mixed>}> Payloads the reader must refuse
     */
    public static function brokenPayloads(): array
    {
        return [
            'no key' => [[]],
            'not a list' => [[FileRemoveSignalData::fileIds => 7]],
            'keyed map' => [[FileRemoveSignalData::fileIds => ['a' => 1]]],
            'string id' => [[FileRemoveSignalData::fileIds => ['1']]],
            'zero id' => [[FileRemoveSignalData::fileIds => [1, 0]]],
            'negative id' => [[FileRemoveSignalData::fileIds => [-4]]],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\StepUp;

use Hilos\Auth\StepUp\DTO\StepUpConfirmedSignalData;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the frame that tells the tabs of a session which operations it has a live confirmation of (HIL-1330).
 */
final class StepUpConfirmedSignalDataTest extends TestCase
{
    public function testRoundtripKeepsTheKeysInTheirOrder(): void
    {
        $frame = new StepUpConfirmedSignalData([StepUpOperationKey::CHANGE_EMAIL, StepUpOperationKey::EXPORT_DATA]);

        self::assertSame(
            ['operations' => [StepUpOperationKey::CHANGE_EMAIL, StepUpOperationKey::EXPORT_DATA]],
            $frame->toArray(),
        );
        self::assertSame(
            [StepUpOperationKey::CHANGE_EMAIL, StepUpOperationKey::EXPORT_DATA],
            StepUpConfirmedSignalData::fromArray($frame->toArray())->operations,
        );
    }

    public function testMissingListIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        StepUpConfirmedSignalData::fromArray([]);
    }

    public function testAKeyThatIsNoStringRefusesTheWholeList(): void
    {
        $this->expectException(InvalidFormatException::class);

        StepUpConfirmedSignalData::fromArray(['operations' => [StepUpOperationKey::CHANGE_EMAIL, 7]]);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeSettingsDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;

/** Worker settings travel as a required boolean field on the existing worker link. */
final class WorkerProtectedModeSettingsMessageTest extends TestCase
{
    public function testTheFrameRoundTripsThroughTheWorkerFactory(): void
    {
        $restored = WorkerDTO::factoryWorkerDTO(new WorkerProtectedModeSettingsDTO(false)->toJson());

        $this->assertInstanceOf(WorkerProtectedModeSettingsDTO::class, $restored);
        $this->assertFalse($restored->manualRestartIsNormal);
        $this->assertSame(WorkerConstants::MESSAGE_PROTECTED_MODE_SETTINGS, $restored->getType());
    }

    public function testAFrameWithoutTheValueIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        WorkerProtectedModeSettingsDTO::fromArray([
            WorkerProtectedModeSettingsDTO::TYPE => WorkerProtectedModeSettingsDTO::MESSAGE_TYPE,
        ]);
    }
}

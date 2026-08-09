<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Communications\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\Communications\DTO\HilosChannelSettingResetActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelSettingUpdateActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelTestActionDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the communications channel action DTOs (HIL-200).
 *
 * Locks payload parsing for the three channel-config actions: FIELD_DATA-wrapped and
 * flat payloads both parse, channel/field are trimmed to strings, the set value passes
 * through untyped, a payload naming no setting is refused, and each DTO reports its own
 * action name.
 */
final class ChannelActionDTOTest extends TestCase
{
    public function testSetParsesWrappedPayloadAndKeepsValueUntyped(): void
    {
        $dto = HilosChannelSettingUpdateActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [
                HilosChannelSettingUpdateActionDTO::channel => ' email ',
                HilosChannelSettingUpdateActionDTO::field => ' smtp_port ',
                HilosChannelSettingUpdateActionDTO::value => 2525,
            ],
        ]);

        self::assertSame('email', $dto->channel);
        self::assertSame('smtp_port', $dto->field);
        self::assertSame(2525, $dto->value);
        self::assertSame(HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET, $dto->getAction());
    }

    public function testSetParsesFlatPayloadAndRoundTripsToArray(): void
    {
        $dto = HilosChannelSettingUpdateActionDTO::fromArray([
            HilosChannelSettingUpdateActionDTO::channel => 'email',
            HilosChannelSettingUpdateActionDTO::field => 'enabled',
            HilosChannelSettingUpdateActionDTO::value => true,
        ]);

        self::assertSame([
            HilosChannelSettingUpdateActionDTO::channel => 'email',
            HilosChannelSettingUpdateActionDTO::field => 'enabled',
            HilosChannelSettingUpdateActionDTO::value => true,
        ], $dto->toArray());
    }

    public function testSetKeepsAnOmittedValueNull(): void
    {
        $dto = HilosChannelSettingUpdateActionDTO::fromArray([
            HilosChannelSettingUpdateActionDTO::channel => 'email',
            HilosChannelSettingUpdateActionDTO::field => 'smtp_host',
        ]);

        self::assertNull($dto->value);
    }

    public function testSetRefusesAPayloadThatNamesNoSetting(): void
    {
        $this->expectException(InvalidFormatException::class);

        HilosChannelSettingUpdateActionDTO::fromArray([]);
    }

    public function testResetParsesAndReportsAction(): void
    {
        $dto = HilosChannelSettingResetActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [
                HilosChannelSettingResetActionDTO::channel => 'email',
                HilosChannelSettingResetActionDTO::field => 'smtp_host',
            ],
        ]);

        self::assertSame('email', $dto->channel);
        self::assertSame('smtp_host', $dto->field);
        self::assertSame(HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET, $dto->getAction());
    }

    public function testTestParsesAndReportsAction(): void
    {
        $dto = HilosChannelTestActionDTO::fromArray([
            HilosChannelTestActionDTO::channel => ' email ',
        ]);

        self::assertSame('email', $dto->channel);
        self::assertSame(HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST, $dto->getAction());
        self::assertSame([HilosChannelTestActionDTO::channel => 'email'], $dto->toArray());
    }
}

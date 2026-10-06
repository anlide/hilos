<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\AdminViewMode;

use Closure;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\ViewerFields;
use Hilos\Auth\Method\AuthMethodSettings;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Pages\Logs\DTO\HilosLogsOverviewSignalData;
use Hilos\Pages\Logs\DTO\HilosLogsRotationsSignalData;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTableRow;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTableRow;
use Hilos\Tables\Security\HilosSecurityStepUpTable;
use Hilos\Tables\Security\HilosSecurityStepUpTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Setting-derived fields use their catalog keys even when the wire field is a computed value.
 */
final class SettingOriginFieldsTest extends TestCase
{
    public function testChannelAndSignInSwitchesFollowTheirOwnSettingKeys(): void
    {
        $channels = (new HilosCommunicationsChannelsTable())->wireFields();
        $channel = [
            HilosCommunicationsChannelsTableRow::channel => 'mail',
            HilosCommunicationsChannelsTableRow::enabled => true,
        ];
        $enabledKey = DeliveryChannelSettings::enabledKey('mail');

        $this->assertSame(
            true,
            ViewerFields::hide($channel, $channels, self::columnsShown(), static fn(string $key): bool => $key === $enabledKey)[
                HilosCommunicationsChannelsTableRow::enabled
            ],
        );
        $this->assertSame(
            HiddenValue::mark(),
            ViewerFields::hide($channel, $channels, self::columnsShown(), static fn(string $key): bool => false)[
                HilosCommunicationsChannelsTableRow::enabled
            ],
        );

        $methods = (new HilosSecuritySignInMethodsTable())->wireFields();
        $method = [HilosSecuritySignInMethodsTableRow::enabled => true];
        $this->assertSame(
            true,
            ViewerFields::hide(
                $method,
                $methods,
                self::columnsShown(),
                static fn(string $key): bool => $key === AuthMethodSettings::DISABLED_KEY,
            )[HilosSecuritySignInMethodsTableRow::enabled],
        );
    }

    public function testStepUpSwitchNeedsBothSettingsOpen(): void
    {
        $fields = (new HilosSecurityStepUpTable())->wireFields();
        $row = [HilosSecurityStepUpTableRow::enabled => true];

        $this->assertSame(
            HiddenValue::mark(),
            ViewerFields::hide(
                $row,
                $fields,
                self::columnsShown(),
                static fn(string $key): bool => $key === StepUpSettings::DISABLED_KEY,
            )[HilosSecurityStepUpTableRow::enabled],
        );
        $this->assertSame(
            true,
            ViewerFields::hide($row, $fields, self::columnsShown(), static fn(string $key): bool => true)[
                HilosSecurityStepUpTableRow::enabled
            ],
        );
    }

    public function testLogSettingMapsJudgeEveryDepth(): void
    {
        $rotation = [
            HilosLogsRotationsSignalData::rotationCron => '0 3 * * *',
            HilosLogsRotationsSignalData::rotationMaxAgeSeconds => 3600,
            HilosLogsRotationsSignalData::rotationMaxLiveSizeBytes => 1000,
            HilosLogsRotationsSignalData::retentionKeepBatches => 5,
            HilosLogsRotationsSignalData::retentionMaxAgeSeconds => 86400,
        ];
        $fields = HilosLogsRotationsSignalData::wireFields();
        $open = static fn(string $key): bool => $key === LogSettingsCatalog::ROTATION_CRON;
        $viewer = ViewerFields::hide($rotation, $fields, self::columnsShown(), $open);
        $this->assertSame('0 3 * * *', $viewer[HilosLogsRotationsSignalData::rotationCron]);
        foreach (array_keys($rotation) as $name) {
            if ($name !== HilosLogsRotationsSignalData::rotationCron) {
                $this->assertSame(HiddenValue::mark(), $viewer[$name]);
            }
        }
        $overview = [
            HilosLogsOverviewSignalData::freeSpaceThresholdPercent => 20,
            HilosLogsOverviewSignalData::nodes => [[
                HilosLogsOverviewSignalData::nodeId => 'node-a',
                HilosLogsOverviewSignalData::freeSpaceThresholdPercent => 20,
            ]],
        ];
        $shown = ViewerFields::hide(
            $overview,
            HilosLogsOverviewSignalData::wireFields(),
            self::columnsShown(),
            static fn(string $key): bool => $key === LogSettingsCatalog::FREE_SPACE_THRESHOLD_PERCENT,
        );
        $this->assertSame(20, $shown[HilosLogsOverviewSignalData::freeSpaceThresholdPercent]);
        $this->assertSame(
            20,
            $shown[HilosLogsOverviewSignalData::nodes][0][HilosLogsOverviewSignalData::freeSpaceThresholdPercent],
        );
        $closed = ViewerFields::hide(
            $overview,
            HilosLogsOverviewSignalData::wireFields(),
            self::columnsShown(),
            static fn(string $key): bool => false,
        );
        $this->assertSame(HiddenValue::mark(), $closed[HilosLogsOverviewSignalData::freeSpaceThresholdPercent]);
        $this->assertSame(
            HiddenValue::mark(),
            $closed[HilosLogsOverviewSignalData::nodes][0][HilosLogsOverviewSignalData::freeSpaceThresholdPercent],
        );
    }

    /** @return Closure(string, string): bool Column verdict used by computed values here */
    private static function columnsShown(): Closure
    {
        return static fn(string $collection, string $field): bool => true;
    }
}

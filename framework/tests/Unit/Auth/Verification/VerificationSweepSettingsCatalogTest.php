<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Verification;

use Hilos\Auth\Verification\VerificationRetentionRule;
use Hilos\Auth\Verification\VerificationSweepCronRule;
use Hilos\Auth\Verification\VerificationSweepSettings;
use Hilos\Auth\Verification\VerificationSweepSettingsCatalog;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** The two catalog defaults pass their own write rules. */
final class VerificationSweepSettingsCatalogTest extends TestCase
{
    public function testCatalogTypesDefaultsAndRulesAgree(): void
    {
        $previousEnv = Hilos::$env;
        Hilos::$env = null;
        try {
            $entries = VerificationSweepSettingsCatalog::getCatalog();
            $retention = $entries[VerificationSweepSettings::RETENTION_SECONDS_KEY];
            $cron = $entries[VerificationSweepSettings::SWEEP_CRON_KEY];
            self::assertSame(SettingsCatalogConstants::TYPE_INTEGER, $retention[SettingsCatalogConstants::CATALOG_ENTRY_TYPE]);
            self::assertSame(3600, $retention[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
            self::assertSame(VerificationRetentionRule::class, $retention[SettingsCatalogConstants::CATALOG_ENTRY_RULE]);
            self::assertNull(VerificationRetentionRule::validate($retention[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]));
            self::assertSame(SettingsCatalogConstants::TYPE_STRING, $cron[SettingsCatalogConstants::CATALOG_ENTRY_TYPE]);
            self::assertSame(VerificationSweepSettings::DEFAULT_SWEEP_CRON, $cron[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
            self::assertSame(VerificationSweepCronRule::class, $cron[SettingsCatalogConstants::CATALOG_ENTRY_RULE]);
            self::assertNull(VerificationSweepCronRule::validate($cron[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]));
        } finally {
            Hilos::$env = $previousEnv;
        }
    }
}

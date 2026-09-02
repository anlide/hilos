<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Hilos;
use Hilos\Log\LogPresetNameRule;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogSettingsPresets;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the recipe of the three logging modes (HIL-762).
 *
 * Two promises, and both of them are about the recipe rather than about the mechanism above it.
 *
 * Every mode names the same keys. A mode that left one of them out would apply as a partial
 * statement: the axis it forgot would keep whatever the previous mode set, and the card would
 * describe a machine that is not the one running.
 *
 * Every value passes the rule of its own key. A recipe is not checked when it is declared — only
 * when it is applied — so a value its key refuses would turn into a refusal on the administrator's
 * screen for something they never typed. It is the same argument
 * {@see LogSettingsCatalog::pushIntervalDefault()} makes about a default the key's own rule would
 * not accept, one layer out.
 */
final class LogSettingsPresetsTest extends TestCase
{
    private ?SettingsAccessor $previousSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSettings = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(LogSettingsCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSettings;

        parent::tearDown();
    }

    public function testEveryModeNamesTheSameKeys(): void
    {
        $expected = LogSettingsPresets::presetGroup()->memberKeys();
        sort($expected);

        $this->assertSame([
            LogSettingsCatalog::ARCHIVE_RETENTION_MAX_AGE_SECONDS,
            LogSettingsCatalog::ROTATION_CRON,
            LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS,
            LogSettingsCatalog::ROTATION_MAX_LIVE_SIZE_BYTES,
            LogSettingsCatalog::WRITE_LEVEL,
        ], $expected);

        foreach (LogSettingsPresets::presetGroup()->presets as $preset) {
            $keys = array_keys($preset->values);
            sort($keys);
            $this->assertSame($expected, $keys, "mode {$preset->name} names a different set of keys");
        }
    }

    public function testEveryValueOfEveryModePassesTheRuleOfItsKey(): void
    {
        foreach (LogSettingsPresets::presetGroup()->presets as $preset) {
            foreach ($preset->values as $key => $value) {
                SettingValueRules::assertValid($key, $value);
            }
        }

        $this->expectNotToPerformAssertions();
    }

    public function testTheNameOfEveryModePassesTheRuleOfTheSelectionKey(): void
    {
        $group = LogSettingsPresets::presetGroup();

        $this->assertSame(LogSettingsCatalog::PRESET, $group->selectionSettingKey);

        foreach ($group->presets as $preset) {
            $this->assertNull(LogPresetNameRule::validate($preset->name));
        }
    }

    public function testTheAgeAxisOfRotationIsHeldOffByEveryMode(): void
    {
        foreach (LogSettingsPresets::presetGroup()->presets as $preset) {
            $this->assertSame(
                0,
                $preset->values[LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS],
                "mode {$preset->name} leaves the age axis of rotation on",
            );
        }
    }

    public function testTheTwoKeysOutsideEveryModeStayOutside(): void
    {
        $members = LogSettingsPresets::presetGroup()->memberKeys();

        $this->assertNotContains(LogSettingsCatalog::ARCHIVE_RETENTION_KEEP_BATCHES, $members);
        $this->assertNotContains(LogSettingsCatalog::INDEX_PUSH_INTERVAL_MS, $members);
    }
}

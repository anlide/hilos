<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Log\LogPresetNameRule;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogSettingsPresets;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the recipe of the three logging modes (HIL-762).
 *
 * Three promises, and all of them are about the recipe rather than about the mechanism above it.
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
 *
 * The mode chosen by default matches the defaults of its own keys. Otherwise a fresh installation
 * opens the screen with differences nobody made (HIL-906). It is checked with the environment
 * absent — the catalog's own fallbacks answer — and with an environment that names none of the
 * keys — the framework env catalog's defaults answer.
 */
final class LogSettingsPresetsTest extends TestCase
{
    /** Process environment keys behind the members of a mode, cleared around every test */
    private const array MEMBER_ENV_KEYS = [
        EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS,
        EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES,
        EnvConstants::LOG_ROTATION_CRON,
        EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS,
        EnvConstants::LOG_WRITE_LEVEL,
    ];

    private ?SettingsAccessor $previousSettings = null;

    /** @var ?EnvAccessor Accessor to put back on the facade after a test replaced it */
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSettings = Hilos::$setting;
        $this->previousEnv = Hilos::$env;
        Hilos::$setting = new SettingsAccessor(LogSettingsCatalog::class);
        $this->clearMemberEnvironment();
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSettings;
        Hilos::$env = $this->previousEnv;
        $this->clearMemberEnvironment();

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

    public function testAFreshInstallationStandsOnItsDefaultModeWithNoDifference(): void
    {
        foreach (['no environment' => null, 'an environment naming no key' => new EnvAccessor()] as $position => $env) {
            Hilos::$env = $env;
            $catalog = LogSettingsCatalog::getCatalog();
            $name = $catalog[LogSettingsCatalog::PRESET][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE];
            $preset = LogSettingsPresets::presetGroup()->presetByName($name);

            $this->assertNotNull($preset, "the default mode {$name} is not declared");
            foreach ($preset->values as $key => $value) {
                $this->assertSame(
                    $value,
                    $catalog[$key][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE],
                    "the default of {$key} differs from mode {$name} with {$position}",
                );
            }
        }
    }

    /**
     * Removes every member key from the process environment, which the accessor reads first.
     */
    private function clearMemberEnvironment(): void
    {
        foreach (self::MEMBER_ENV_KEYS as $key) {
            putenv($key->name);
        }
    }
}

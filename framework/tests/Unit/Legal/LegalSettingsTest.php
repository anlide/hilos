<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal;

use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Legal\LegalConsentFormRule;
use Hilos\Legal\LegalRefusalRule;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSettingsCatalog;
use PHPUnit\Framework\TestCase;

/** Legal settings resolve defaults and restrict writes to the approved choices. */
final class LegalSettingsTest extends TestCase
{
    private ?SettingsAccessor $previousSetting;

    /** Saves the process's settings accessor. */
    protected function setUp(): void
    {
        $this->previousSetting = Hilos::$setting;
    }

    /** Restores the process's settings accessor. */
    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSetting;
        parent::tearDown();
    }

    public function testDefaultsWithNoAccessorWithoutTheKeysAndWithTheCatalog(): void
    {
        foreach ([null, new SettingsAccessor(), new SettingsAccessor(LegalSettingsCatalog::class)] as $accessor) {
            Hilos::$setting = $accessor;
            self::assertSame('checkbox', LegalSettings::consentForm());
            self::assertSame('freeze', LegalSettings::refusal());
        }
        self::assertSame(LegalSettings::KEYS, array_keys(LegalSettingsCatalog::getCatalog()));
    }

    public function testStoredChoicesAreRead(): void
    {
        Hilos::$setting = new class (LegalSettingsCatalog::class) extends SettingsAccessor {
            /**
             * @param string $key Setting key
             * @return mixed Scripted value at the persistence seam
             */
            public function effectiveValueFor(string $key): mixed
            {
                return match ($key) {
                    LegalSettings::CONSENT_FORM_KEY => 'line',
                    LegalSettings::REFUSAL_KEY => 'remind',
                };
            }
        };
        self::assertSame('line', LegalSettings::consentForm());
        self::assertSame('remind', LegalSettings::refusal());
    }

    public function testRulesAcceptOnlyTheirOwnTwoStringChoices(): void
    {
        foreach (['checkbox', 'line'] as $value) {
            self::assertNull(LegalConsentFormRule::validate($value));
            self::assertNotNull(LegalRefusalRule::validate($value));
        }
        foreach (['freeze', 'remind'] as $value) {
            self::assertNull(LegalRefusalRule::validate($value));
            self::assertNotNull(LegalConsentFormRule::validate($value));
        }
        foreach ([null, true, false, 0, 1, [], '', 'CHECKBOX', 'forever'] as $value) {
            self::assertNotNull(LegalConsentFormRule::validate($value));
            self::assertNotNull(LegalRefusalRule::validate($value));
        }
    }
}

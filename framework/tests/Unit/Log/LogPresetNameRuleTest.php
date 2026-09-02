<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Log\LogPresetNameRule;
use Hilos\Log\LogSettingsPresets;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rule under the key remembering which logging mode was applied (HIL-762).
 *
 * The one thing worth locking is the pair of answers it gives to two things that look alike: the
 * empty string is a state — no mode applied, no card lit — while a name nobody declared is a typo
 * and is refused where it is typed, rather than quietly unlighting every card.
 */
final class LogPresetNameRuleTest extends TestCase
{
    public function testEveryDeclaredModeIsAccepted(): void
    {
        foreach (LogSettingsPresets::presetGroup()->presets as $preset) {
            $this->assertNull(LogPresetNameRule::validate($preset->name), "refused {$preset->name}");
        }
    }

    public function testTheEmptyStringIsAcceptedAsNoModeApplied(): void
    {
        $this->assertNull(LogPresetNameRule::validate(''));
    }

    public function testANameNoModeCarriesIsRefused(): void
    {
        $this->assertNotNull(LogPresetNameRule::validate('quiet'));
        $this->assertNotNull(LogPresetNameRule::validate('NORMAL'));
    }

    public function testAValueThatIsNotTextIsRefused(): void
    {
        $this->assertNotNull(LogPresetNameRule::validate(1));
        $this->assertNotNull(LogPresetNameRule::validate(null));
        $this->assertNotNull(LogPresetNameRule::validate(['normal']));
    }
}

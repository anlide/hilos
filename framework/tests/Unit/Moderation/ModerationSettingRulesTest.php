<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Moderation;

use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Hilos;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Moderation\Validation\ModerationModelRule;
use Hilos\Moderation\Validation\ModerationProviderRule;
use Hilos\Moderation\Validation\ModerationTimeoutRule;
use Hilos\Moderation\Validation\ModerationUrlRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the four rules a moderation role setting is written through (HIL-1625).
 *
 * What is pinned is each condition the rule states: the provider is exactly one of the two names,
 * the model says something, the address is empty or an absolute http(s) one as it stands, and the
 * timeout is a finite number above zero. The last cases show the settings write gate refusing with
 * the rule's own words.
 */
final class ModerationSettingRulesTest extends TestCase
{
    private ?SettingsAccessor $previousSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSettings = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(ModerationSettingsCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSettings;

        parent::tearDown();
    }

    /** @return iterable<string, array{mixed}> */
    public static function acceptedProviders(): iterable
    {
        yield 'local' => ['local'];
        yield 'external' => ['external'];
    }

    /** @return iterable<string, array{mixed}> */
    public static function refusedProviders(): iterable
    {
        yield 'upper case' => ['Local'];
        yield 'padded' => ['external '];
        yield 'empty' => [''];
        yield 'another name' => ['ollama'];
        yield 'null' => [null];
        yield 'number' => [1];
        yield 'boolean' => [true];
    }

    /** @return iterable<string, array{mixed}> */
    public static function acceptedModels(): iterable
    {
        yield 'local text model' => ['qwen2.5:0.5b'];
        yield 'local vision model' => ['qwen2.5vl:3b'];
        yield 'external model' => ['gpt-4o-mini'];
    }

    /** @return iterable<string, array{mixed}> */
    public static function refusedModels(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'line break' => ["\n"];
        yield 'null' => [null];
        yield 'number' => [3];
        yield 'list' => [['qwen2.5:3b']];
    }

    /** @return iterable<string, array{mixed}> */
    public static function acceptedUrls(): iterable
    {
        yield 'empty is no override' => [''];
        yield 'http with port' => ['http://127.0.0.1:11434'];
        yield 'https with path' => ['https://llm.internal:8443/ollama'];
        yield 'trailing slash' => ['http://model/'];
        yield 'upper case scheme' => ['HTTP://model:8080'];
    }

    /** @return iterable<string, array{mixed}> */
    public static function refusedUrls(): iterable
    {
        yield 'spaces only' => [' '];
        yield 'no scheme' => ['model:8080'];
        yield 'bare host' => ['localhost'];
        yield 'scheme relative' => ['//model:8080'];
        yield 'another scheme' => ['ftp://model/'];
        yield 'script scheme' => ['javascript:alert(1)'];
        yield 'no host' => ['http://'];
        yield 'padded left' => [' http://model'];
        yield 'padded right' => ['http://model '];
        yield 'null' => [null];
        yield 'number' => [11434];
    }

    /** @return iterable<string, array{mixed}> */
    public static function acceptedTimeouts(): iterable
    {
        yield 'whole seconds' => [20];
        yield 'default float' => [20.0];
        yield 'fraction' => [0.5];
        yield 'long wait' => [3600.0];
        yield 'numeric string' => ['30'];
        yield 'fraction string' => ['0.25'];
    }

    /** @return iterable<string, array{mixed}> */
    public static function refusedTimeouts(): iterable
    {
        yield 'zero' => [0];
        yield 'zero float' => [0.0];
        yield 'negative' => [-1];
        yield 'negative float' => [-0.5];
        yield 'zero string' => ['0'];
        yield 'negative string' => ['-5'];
        yield 'not a number' => [NAN];
        yield 'infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'huge string' => ['1e999'];
        yield 'empty' => [''];
        yield 'words' => ['soon'];
        yield 'null' => [null];
        yield 'boolean' => [true];
        yield 'list' => [[20]];
    }

    #[DataProvider('acceptedProviders')]
    public function testProviderRuleAcceptsTheTwoProviderNames(mixed $value): void
    {
        $this->assertNull(ModerationProviderRule::validate($value));
    }

    #[DataProvider('refusedProviders')]
    public function testProviderRuleRefusesAnythingElse(mixed $value): void
    {
        $this->assertSame('Value must be "local" or "external"', ModerationProviderRule::validate($value));
    }

    #[DataProvider('acceptedModels')]
    public function testModelRuleAcceptsAModelName(mixed $value): void
    {
        $this->assertNull(ModerationModelRule::validate($value));
    }

    #[DataProvider('refusedModels')]
    public function testModelRuleRefusesABlankOrNonTextValue(mixed $value): void
    {
        $this->assertSame('Value must be a model name', ModerationModelRule::validate($value));
    }

    #[DataProvider('acceptedUrls')]
    public function testUrlRuleAcceptsEmptyOrAnAbsoluteHttpAddress(mixed $value): void
    {
        $this->assertNull(ModerationUrlRule::validate($value));
    }

    #[DataProvider('refusedUrls')]
    public function testUrlRuleRefusesAnythingThatIsNotOne(mixed $value): void
    {
        $this->assertSame('Value must be empty or an absolute http or https URL', ModerationUrlRule::validate($value));
    }

    #[DataProvider('acceptedTimeouts')]
    public function testTimeoutRuleAcceptsAFiniteNumberAboveZero(mixed $value): void
    {
        $this->assertNull(ModerationTimeoutRule::validate($value));
    }

    #[DataProvider('refusedTimeouts')]
    public function testTimeoutRuleRefusesZeroNegativesAndNonFiniteValues(mixed $value): void
    {
        $this->assertSame('Value must be a number of seconds above 0', ModerationTimeoutRule::validate($value));
    }

    public function testTheWriteGateRefusesARoleValueWithTheRulesOwnWords(): void
    {
        $refused = [
            ModerationSettingsCatalog::TEXT_PROVIDER => ['ollama', 'Value must be "local" or "external"'],
            ModerationSettingsCatalog::IMAGE_MODEL => [' ', 'Value must be a model name'],
            ModerationSettingsCatalog::TEXT_URL => ['model:8080', 'Value must be empty or an absolute http or https URL'],
            ModerationSettingsCatalog::IMAGE_TIMEOUT_SEC => [0.0, 'Value must be a number of seconds above 0'],
            ModerationSettingsCatalog::CHECK_TIMEOUT_SEC => [0, 'Value must be a positive integer'],
        ];

        foreach ($refused as $key => [$value, $refusal]) {
            try {
                SettingValueRules::assertValid($key, $value);
                $this->fail("{$key} must refuse " . var_export($value, true));
            } catch (SettingValueRefusedException $exception) {
                $this->assertSame($refusal, $exception->getMessage(), $key);
            }
        }
    }

    public function testTheWriteGateTakesAnEmptyUrl(): void
    {
        foreach ([ModerationSettingsCatalog::TEXT_URL, ModerationSettingsCatalog::IMAGE_URL] as $key) {
            SettingValueRules::assertValid($key, '');
            $this->assertNull(Hilos::$setting->ruleFor($key)::validate(''), $key);
        }
    }

    public function testEveryKeyDefaultPassesItsOwnRule(): void
    {
        foreach (ModerationSettingsCatalog::getCatalog() as $key => $entry) {
            $rule = Hilos::$setting->ruleFor($key);

            $this->assertNotNull($rule, $key);
            $this->assertNull($rule::validate($entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]), $key);
        }
    }
}

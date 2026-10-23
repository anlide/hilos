<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Moderation;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\PositiveIntegerRule;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Moderation\ModerationLlmConstants;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Moderation\Validation\ModerationModelRule;
use Hilos\Moderation\Validation\ModerationProviderRule;
use Hilos\Moderation\Validation\ModerationTimeoutRule;
use Hilos\Moderation\Validation\ModerationUrlRule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework moderation settings fragment and the env its defaults come from (HIL-1625).
 *
 * What is pinned is the declared shape: nine keys, each open to an admin view mode viewer, the role
 * defaults read from the framework env with the literals under them, an empty address that means no
 * override, no key a secret could be written to, and the fragment present under any project catalog.
 */
final class ModerationSettingsCatalogTest extends TestCase
{
    /** Env variables of the two roles, restored after every case. */
    private const array ROLE_ENV = [
        EnvConstants::HILOS_MODERATION_TEXT_PROVIDER,
        EnvConstants::HILOS_MODERATION_TEXT_MODEL,
        EnvConstants::HILOS_MODERATION_TEXT_URL,
        EnvConstants::HILOS_MODERATION_TEXT_TIMEOUT_SEC,
        EnvConstants::HILOS_MODERATION_IMAGE_PROVIDER,
        EnvConstants::HILOS_MODERATION_IMAGE_MODEL,
        EnvConstants::HILOS_MODERATION_IMAGE_URL,
        EnvConstants::HILOS_MODERATION_IMAGE_TIMEOUT_SEC,
    ];

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnv = Hilos::$env;
        Hilos::$env = null;
        foreach (self::ROLE_ENV as $key) {
            putenv($key->name);
        }
        ModerationProjectTestCatalog::$catalog = [];
    }

    protected function tearDown(): void
    {
        foreach (self::ROLE_ENV as $key) {
            putenv($key->name);
        }
        Hilos::$env = $this->previousEnv;
        ModerationProjectTestCatalog::$catalog = [];

        parent::tearDown();
    }

    public function testTheLiteralsStandWhenThereIsNoEnvironment(): void
    {
        $this->assertSame($this->expectedCatalog(), ModerationSettingsCatalog::getCatalog());
    }

    public function testTheKeyNamesAreExact(): void
    {
        $this->assertSame([
            'moderation.text.provider',
            'moderation.text.model',
            'moderation.text.url',
            'moderation.text.timeout_sec',
            'moderation.image.provider',
            'moderation.image.model',
            'moderation.image.url',
            'moderation.image.timeout_sec',
            'moderation.check_timeout_sec',
        ], array_keys(ModerationSettingsCatalog::getCatalog()));
    }

    public function testTheProfileNamesAndModelDefaultsAreTheChatModelsOfToday(): void
    {
        $this->assertSame('hilos.moderation.text', ModerationLlmConstants::PROFILE_TEXT);
        $this->assertSame('hilos.moderation.image', ModerationLlmConstants::PROFILE_IMAGE);
        $this->assertSame('qwen2.5:0.5b', ModerationLlmConstants::DEFAULT_TEXT_MODEL);
        $this->assertSame('qwen2.5vl:3b', ModerationLlmConstants::DEFAULT_IMAGE_MODEL);
    }

    public function testTheFrameworkEnvDeclaresTheRoleDefaults(): void
    {
        $env = new EnvAccessor(EnvCatalogStub::class);

        $this->assertSame(LLMConstants::PROVIDER_LOCAL, $env[EnvConstants::HILOS_MODERATION_TEXT_PROVIDER]->string());
        $this->assertSame('qwen2.5:0.5b', $env[EnvConstants::HILOS_MODERATION_TEXT_MODEL]->string());
        $this->assertSame('', $env[EnvConstants::HILOS_MODERATION_TEXT_URL]->string());
        $this->assertSame(LLMConstants::DEFAULT_TIMEOUT_SEC, $env[EnvConstants::HILOS_MODERATION_TEXT_TIMEOUT_SEC]->float());
        $this->assertSame(LLMConstants::PROVIDER_LOCAL, $env[EnvConstants::HILOS_MODERATION_IMAGE_PROVIDER]->string());
        $this->assertSame('qwen2.5vl:3b', $env[EnvConstants::HILOS_MODERATION_IMAGE_MODEL]->string());
        $this->assertSame('', $env[EnvConstants::HILOS_MODERATION_IMAGE_URL]->string());
        $this->assertSame(LLMConstants::DEFAULT_TIMEOUT_SEC, $env[EnvConstants::HILOS_MODERATION_IMAGE_TIMEOUT_SEC]->float());
    }

    public function testRoleDefaultsComeFromTheFrameworkEnv(): void
    {
        putenv('HILOS_MODERATION_TEXT_PROVIDER=external');
        putenv('HILOS_MODERATION_TEXT_MODEL=gpt-4o-mini');
        putenv('HILOS_MODERATION_TEXT_URL=http://text-model:11434');
        putenv('HILOS_MODERATION_TEXT_TIMEOUT_SEC=7.5');
        putenv('HILOS_MODERATION_IMAGE_PROVIDER=local');
        putenv('HILOS_MODERATION_IMAGE_MODEL=llava:7b');
        putenv('HILOS_MODERATION_IMAGE_URL=http://image-model:11434');
        putenv('HILOS_MODERATION_IMAGE_TIMEOUT_SEC=45');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);

        $catalog = ModerationSettingsCatalog::getCatalog();

        $this->assertSame('external', $this->defaultOf($catalog, ModerationSettingsCatalog::TEXT_PROVIDER));
        $this->assertSame('gpt-4o-mini', $this->defaultOf($catalog, ModerationSettingsCatalog::TEXT_MODEL));
        $this->assertSame('http://text-model:11434', $this->defaultOf($catalog, ModerationSettingsCatalog::TEXT_URL));
        $this->assertSame(7.5, $this->defaultOf($catalog, ModerationSettingsCatalog::TEXT_TIMEOUT_SEC));
        $this->assertSame('local', $this->defaultOf($catalog, ModerationSettingsCatalog::IMAGE_PROVIDER));
        $this->assertSame('llava:7b', $this->defaultOf($catalog, ModerationSettingsCatalog::IMAGE_MODEL));
        $this->assertSame('http://image-model:11434', $this->defaultOf($catalog, ModerationSettingsCatalog::IMAGE_URL));
        $this->assertSame(45.0, $this->defaultOf($catalog, ModerationSettingsCatalog::IMAGE_TIMEOUT_SEC));
        $this->assertSame(
            ModerationSettingsCatalog::DEFAULT_CHECK_TIMEOUT_SEC,
            $this->defaultOf($catalog, ModerationSettingsCatalog::CHECK_TIMEOUT_SEC),
        );
    }

    public function testTheFrameworkEnvWithNothingSetGivesTheLiterals(): void
    {
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);

        $this->assertSame($this->expectedCatalog(), ModerationSettingsCatalog::getCatalog());
    }

    public function testAProjectEnvWithoutTheRoleKeysFallsBackToTheLiterals(): void
    {
        Hilos::$env = new EnvAccessor(ModerationEmptyEnvTestCatalog::class);

        $this->assertSame($this->expectedCatalog(), ModerationSettingsCatalog::getCatalog());
    }

    public function testAnEmptyUrlIsAnAbsentOverrideAndNotTheGlobalLocalAddress(): void
    {
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        $catalog = ModerationSettingsCatalog::getCatalog();

        $this->assertSame('', $this->defaultOf($catalog, ModerationSettingsCatalog::TEXT_URL));
        $this->assertSame('', $this->defaultOf($catalog, ModerationSettingsCatalog::IMAGE_URL));
        $this->assertNotSame(
            LLMConstants::DEFAULT_LOCAL_URL,
            $this->defaultOf($catalog, ModerationSettingsCatalog::TEXT_URL),
        );
    }

    public function testNoKeyCarriesASecret(): void
    {
        foreach (array_keys(ModerationSettingsCatalog::getCatalog()) as $key) {
            $this->assertDoesNotMatchRegularExpression('/key$|secret|token|password/', $key);
        }
    }

    public function testTheAccessorHoldsTheNineKeysUnderAnyProjectCatalog(): void
    {
        $bare = new SettingsAccessor(ModerationProjectTestCatalog::class);
        ModerationProjectTestCatalog::$catalog = [
            'project.own' => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'own',
            ],
        ];
        $withOwn = new SettingsAccessor(ModerationProjectTestCatalog::class);

        foreach ([$bare, $withOwn] as $accessor) {
            foreach (array_keys($this->expectedCatalog()) as $key) {
                $this->assertTrue(isset($accessor[$key]), $key);
                $this->assertTrue($accessor->visibleInAdminViewMode($key), $key);
            }
            $accessor->validateAdminViewVisibility();
        }
        $this->assertTrue(isset($withOwn['project.own']));
    }

    public function testEveryKeyNamesItsOwnRuleAndType(): void
    {
        $settings = new SettingsAccessor(ModerationProjectTestCatalog::class);

        $this->assertSame(ModerationProviderRule::class, $settings->ruleFor(ModerationSettingsCatalog::TEXT_PROVIDER));
        $this->assertSame(ModerationModelRule::class, $settings->ruleFor(ModerationSettingsCatalog::TEXT_MODEL));
        $this->assertSame(ModerationUrlRule::class, $settings->ruleFor(ModerationSettingsCatalog::TEXT_URL));
        $this->assertSame(ModerationTimeoutRule::class, $settings->ruleFor(ModerationSettingsCatalog::TEXT_TIMEOUT_SEC));
        $this->assertSame(ModerationProviderRule::class, $settings->ruleFor(ModerationSettingsCatalog::IMAGE_PROVIDER));
        $this->assertSame(ModerationModelRule::class, $settings->ruleFor(ModerationSettingsCatalog::IMAGE_MODEL));
        $this->assertSame(ModerationUrlRule::class, $settings->ruleFor(ModerationSettingsCatalog::IMAGE_URL));
        $this->assertSame(ModerationTimeoutRule::class, $settings->ruleFor(ModerationSettingsCatalog::IMAGE_TIMEOUT_SEC));
        $this->assertSame(PositiveIntegerRule::class, $settings->ruleFor(ModerationSettingsCatalog::CHECK_TIMEOUT_SEC));
        $this->assertSame(SettingsCatalogConstants::TYPE_FLOAT, $settings->typeFor(ModerationSettingsCatalog::TEXT_TIMEOUT_SEC));
        $this->assertSame(SettingsCatalogConstants::TYPE_INTEGER, $settings->typeFor(ModerationSettingsCatalog::CHECK_TIMEOUT_SEC));
    }

    public function testAProjectEntryOverridesTheFragmentKeyOfTheSameName(): void
    {
        ModerationProjectTestCatalog::$catalog = [
            ModerationSettingsCatalog::TEXT_MODEL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'project-model',
            ],
        ];
        $settings = new SettingsAccessor(ModerationProjectTestCatalog::class);

        $this->assertSame('project-model', $settings->defaultValueFor(ModerationSettingsCatalog::TEXT_MODEL));
        $this->assertSame('qwen2.5vl:3b', $settings->defaultValueFor(ModerationSettingsCatalog::IMAGE_MODEL));
    }

    /**
     * @param array<string, array<string, mixed>> $catalog Catalog under test
     * @param string $key Setting key
     */
    private function defaultOf(array $catalog, string $key): mixed
    {
        return $catalog[$key][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE];
    }

    /** @return array<string, array<string, mixed>> The exact fragment when the environment adds nothing */
    private function expectedCatalog(): array
    {
        return [
            'moderation.text.provider' => $this->entry('string', 'local', ModerationProviderRule::class),
            'moderation.text.model' => $this->entry('string', 'qwen2.5:0.5b', ModerationModelRule::class),
            'moderation.text.url' => $this->entry('string', '', ModerationUrlRule::class),
            'moderation.text.timeout_sec' => $this->entry('float', 20.0, ModerationTimeoutRule::class),
            'moderation.image.provider' => $this->entry('string', 'local', ModerationProviderRule::class),
            'moderation.image.model' => $this->entry('string', 'qwen2.5vl:3b', ModerationModelRule::class),
            'moderation.image.url' => $this->entry('string', '', ModerationUrlRule::class),
            'moderation.image.timeout_sec' => $this->entry('float', 20.0, ModerationTimeoutRule::class),
            'moderation.check_timeout_sec' => $this->entry('integer', 90, PositiveIntegerRule::class),
        ];
    }

    /**
     * @param string $type Setting type
     * @param string|int|float $default Default value
     * @param class-string $rule Rule class
     * @return array<string, mixed> Catalog entry
     */
    private function entry(string $type, string|int|float $default, string $rule): array
    {
        return [
            'type' => $type,
            'admin_view_visible' => true,
            'default_value' => $default,
            'rule' => $rule,
        ];
    }
}

/**
 * Project settings catalog for ModerationSettingsCatalogTest.
 */
final class ModerationProjectTestCatalog implements CatalogProviderInterface
{
    /** @var array<string, array<string, mixed>> Settings catalog */
    public static array $catalog = [];

    /**
     * Returns the current test catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return self::$catalog;
    }
}

/**
 * Env catalog of a project that declares none of the framework's variables.
 */
final class ModerationEmptyEnvTestCatalog implements CatalogProviderInterface
{
    /**
     * Returns an empty catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return [];
    }
}

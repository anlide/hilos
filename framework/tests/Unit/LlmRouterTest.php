<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\LLM\External\Chat\AsyncOpenAIChatProvider;
use Hilos\LLM\Local\Chat\AsyncOllamaChatProvider;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProfileCatalogConstants;
use Hilos\LLM\Routing\LlmProfileOverrideSource;
use Hilos\LLM\Routing\LlmProvider;
use Hilos\LLM\Routing\LlmRouter;
use Hilos\Moderation\ModerationLlmConstants;
use Hilos\Moderation\ModerationSettingsCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the LLM profile router (HIL-257) and its built-in moderator profiles (HIL-1625).
 */
final class LlmRouterTest extends TestCase
{
    private const string ROLE_TEXT_URL = 'http://role-text.test:11434';

    private const string GLOBAL_LOCAL_URL = 'http://global-local.test:11434';

    private const string EXTERNAL_URL = 'https://external.test/v1';

    private const string API_KEY = 'sk-test';

    private ?EnvAccessor $previousEnv = null;
    private ?SettingsAccessor $previousSetting = null;
    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousSetting = Hilos::$setting;
        $this->previousDb = Hilos::$db;
        Hilos::$env = new EnvAccessor();
        // No database: a setting reads its saved value from the test accessor, or else its catalog default.
        Hilos::$db = null;
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$setting = $this->previousSetting;
        Hilos::$db = $this->previousDb;
        foreach ([
            'LLM_CHAT_PROVIDER', 'LLM_EXTERNAL_API_KEY', 'LLM_EXTERNAL_URL', 'LLM_LOCAL_URL',
            'HILOS_MODERATION_TEXT_PROVIDER', 'HILOS_MODERATION_TEXT_URL',
            'HILOS_MODERATION_IMAGE_PROVIDER', 'HILOS_MODERATION_IMAGE_URL',
        ] as $key) {
            putenv($key);
        }
    }

    public function testResolvesLocalDefaultProfile(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_LOCAL);

        $profile = new LlmRouter()->resolve('default');

        self::assertSame('default', $profile->key);
        self::assertSame(LlmProvider::LOCAL, $profile->provider);
        self::assertSame(LLMConstants::DEFAULT_LOCAL_URL, $profile->url);
        self::assertSame(LLMConstants::DEFAULT_LOCAL_CHAT_MODEL, $profile->model);
        self::assertNull($profile->apiKey);
        self::assertSame(LLMConstants::DEFAULT_TIMEOUT_SEC, $profile->timeoutSec);
        self::assertNull($profile->placement);
    }

    public function testResolvesExternalProfileWithApiKey(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('LLM_EXTERNAL_API_KEY=sk-test');

        $profile = new LlmRouter()->resolve('default');

        self::assertSame(LlmProvider::EXTERNAL, $profile->provider);
        self::assertSame('sk-test', $profile->apiKey);
        self::assertSame(LLMConstants::DEFAULT_EXTERNAL_URL, $profile->url);
        self::assertSame(LLMConstants::DEFAULT_EXTERNAL_CHAT_MODEL, $profile->model);
    }

    public function testExternalWithoutApiKeyIsConfigurationError(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('LLM_EXTERNAL_API_KEY=');

        $this->expectException(LLMConfigurationException::class);

        new LlmRouter()->resolve('default');
    }

    public function testUnknownProfileIsConfigurationError(): void
    {
        $this->expectException(LLMConfigurationException::class);

        new LlmRouter()->resolve('does-not-exist');
    }

    public function testUnknownProviderIsConfigurationError(): void
    {
        putenv('LLM_CHAT_PROVIDER=frontier');

        $this->expectException(LLMConfigurationException::class);

        new LlmRouter()->resolve('default');
    }

    public function testChatClientForLocalBuildsOllamaProvider(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_LOCAL);

        self::assertInstanceOf(AsyncOllamaChatProvider::class, new LlmRouter()->chatClientFor('default'));
    }

    public function testChatClientForExternalBuildsOpenAiProvider(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('LLM_EXTERNAL_API_KEY=sk-test');

        self::assertInstanceOf(AsyncOpenAIChatProvider::class, new LlmRouter()->chatClientFor('default'));
    }

    public function testOverrideSourceReplacesResolvedProfile(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_LOCAL);

        $override = new class implements LlmProfileOverrideSource {
            public function override(LlmProfile $profile): LlmProfile
            {
                return new LlmProfile(
                    $profile->key,
                    $profile->provider,
                    $profile->url,
                    'overridden-model',
                    $profile->apiKey,
                    $profile->timeoutSec,
                    $profile->placement,
                );
            }
        };

        $profile = new LlmRouter(overrides: $override)->resolve('default');

        self::assertSame('overridden-model', $profile->model);
    }

    public function testResolveBaseIgnoresOverrideWhileResolveApplies(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_LOCAL);

        $override = new class implements LlmProfileOverrideSource {
            public function override(LlmProfile $profile): LlmProfile
            {
                return new LlmProfile(
                    $profile->key,
                    $profile->provider,
                    $profile->url,
                    'overridden-model',
                    $profile->apiKey,
                    $profile->timeoutSec,
                    $profile->placement,
                );
            }
        };

        $router = new LlmRouter(overrides: $override);

        self::assertSame(LLMConstants::DEFAULT_LOCAL_CHAT_MODEL, $router->resolveBase('default')->model);
        self::assertSame('overridden-model', $router->resolve('default')->model);
    }

    public function testResolveReadsChangedOverrideOnNextCall(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_LOCAL);

        $override = new class implements LlmProfileOverrideSource {
            public string $model = 'first-model';

            public function override(LlmProfile $profile): LlmProfile
            {
                return new LlmProfile(
                    $profile->key,
                    $profile->provider,
                    $profile->url,
                    $this->model,
                    $profile->apiKey,
                    $profile->timeoutSec,
                    $profile->placement,
                );
            }
        };
        $router = new LlmRouter(overrides: $override);

        self::assertSame('first-model', $router->resolve('default')->model);
        $override->model = 'second-model';
        self::assertSame('second-model', $router->resolve('default')->model);
    }

    public function testModerationProfilesResolveFromTheirRoleEnvWithNoSavedSetting(): void
    {
        $this->savedSettings([]);

        $text = new LlmRouter()->resolve(ModerationLlmConstants::PROFILE_TEXT);
        $image = new LlmRouter()->resolve(ModerationLlmConstants::PROFILE_IMAGE);

        self::assertSame(ModerationLlmConstants::PROFILE_TEXT, $text->key);
        self::assertSame(LlmProvider::LOCAL, $text->provider);
        self::assertSame(LLMConstants::DEFAULT_LOCAL_URL, $text->url);
        self::assertSame(ModerationLlmConstants::DEFAULT_TEXT_MODEL, $text->model);
        self::assertNull($text->apiKey);
        self::assertSame(LLMConstants::DEFAULT_TIMEOUT_SEC, $text->timeoutSec);
        self::assertSame(ModerationLlmConstants::PROFILE_IMAGE, $image->key);
        self::assertSame(LlmProvider::LOCAL, $image->provider);
        self::assertSame(LLMConstants::DEFAULT_LOCAL_URL, $image->url);
        self::assertSame(ModerationLlmConstants::DEFAULT_IMAGE_MODEL, $image->model);
        self::assertSame(LLMConstants::DEFAULT_TIMEOUT_SEC, $image->timeoutSec);
    }

    public function testSavedRoleSettingsApplyInResolveWhileResolveBaseKeepsEnv(): void
    {
        $this->savedSettings([
            ModerationSettingsCatalog::TEXT_PROVIDER => LLMConstants::PROVIDER_LOCAL,
            ModerationSettingsCatalog::TEXT_MODEL => 'text-saved',
            ModerationSettingsCatalog::TEXT_URL => 'http://text-saved.test:11434',
            ModerationSettingsCatalog::TEXT_TIMEOUT_SEC => 7.5,
            ModerationSettingsCatalog::IMAGE_PROVIDER => LLMConstants::PROVIDER_LOCAL,
            ModerationSettingsCatalog::IMAGE_MODEL => 'image-saved',
            ModerationSettingsCatalog::IMAGE_URL => 'http://image-saved.test:11434',
            ModerationSettingsCatalog::IMAGE_TIMEOUT_SEC => 12.0,
        ]);
        $router = new LlmRouter();

        $text = $router->resolve(ModerationLlmConstants::PROFILE_TEXT);
        $image = $router->resolve(ModerationLlmConstants::PROFILE_IMAGE);

        self::assertSame(LlmProvider::LOCAL, $text->provider);
        self::assertSame('text-saved', $text->model);
        self::assertSame('http://text-saved.test:11434', $text->url);
        self::assertSame(7.5, $text->timeoutSec);
        self::assertSame(LlmProvider::LOCAL, $image->provider);
        self::assertSame('image-saved', $image->model);
        self::assertSame('http://image-saved.test:11434', $image->url);
        self::assertSame(12.0, $image->timeoutSec);
        self::assertSame(ModerationLlmConstants::DEFAULT_TEXT_MODEL, $router->resolveBase(ModerationLlmConstants::PROFILE_TEXT)->model);
    }

    public function testAnEmptyUrlFallsBackToTheRoleUrlThenToTheGlobalLocalUrl(): void
    {
        putenv('HILOS_MODERATION_TEXT_URL=' . self::ROLE_TEXT_URL);
        putenv('LLM_LOCAL_URL=' . self::GLOBAL_LOCAL_URL);
        $this->savedSettings([
            ModerationSettingsCatalog::TEXT_URL => '',
            ModerationSettingsCatalog::IMAGE_URL => '',
        ]);

        self::assertSame(self::ROLE_TEXT_URL, new LlmRouter()->resolve(ModerationLlmConstants::PROFILE_TEXT)->url);
        self::assertSame(self::GLOBAL_LOCAL_URL, new LlmRouter()->resolve(ModerationLlmConstants::PROFILE_IMAGE)->url);
    }

    public function testAnEmptyUrlFallsBackToTheRoleUrlAfterASwitchFromExternalToLocal(): void
    {
        putenv('HILOS_MODERATION_TEXT_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('HILOS_MODERATION_IMAGE_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('HILOS_MODERATION_TEXT_URL=' . self::ROLE_TEXT_URL);
        putenv('LLM_LOCAL_URL=' . self::GLOBAL_LOCAL_URL);
        putenv('LLM_EXTERNAL_API_KEY=' . self::API_KEY);
        $this->savedSettings([
            ModerationSettingsCatalog::TEXT_PROVIDER => LLMConstants::PROVIDER_LOCAL,
            ModerationSettingsCatalog::TEXT_URL => '',
            ModerationSettingsCatalog::IMAGE_PROVIDER => LLMConstants::PROVIDER_LOCAL,
            ModerationSettingsCatalog::IMAGE_URL => '',
        ]);
        $router = new LlmRouter();

        self::assertSame(LlmProvider::EXTERNAL, $router->resolveBase(ModerationLlmConstants::PROFILE_TEXT)->provider);
        $text = $router->resolve(ModerationLlmConstants::PROFILE_TEXT);
        $image = $router->resolve(ModerationLlmConstants::PROFILE_IMAGE);

        self::assertSame(LlmProvider::LOCAL, $text->provider);
        self::assertSame(self::ROLE_TEXT_URL, $text->url);
        self::assertNull($text->apiKey);
        self::assertSame(LlmProvider::LOCAL, $image->provider);
        self::assertSame(self::GLOBAL_LOCAL_URL, $image->url);
        self::assertNull($image->apiKey);
    }

    public function testASavedExternalProviderTakesTheEndpointAndTheKeyFromEnv(): void
    {
        putenv('LLM_EXTERNAL_URL=' . self::EXTERNAL_URL);
        putenv('LLM_EXTERNAL_API_KEY=' . self::API_KEY);
        $this->savedSettings([
            ModerationSettingsCatalog::IMAGE_PROVIDER => LLMConstants::PROVIDER_EXTERNAL,
            ModerationSettingsCatalog::IMAGE_MODEL => 'vision-external',
            ModerationSettingsCatalog::IMAGE_URL => 'http://ignored-for-external.test:11434',
        ]);

        $image = new LlmRouter()->resolve(ModerationLlmConstants::PROFILE_IMAGE);

        self::assertSame(LlmProvider::EXTERNAL, $image->provider);
        self::assertSame(self::EXTERNAL_URL, $image->url);
        self::assertSame(self::API_KEY, $image->apiKey);
        self::assertSame('vision-external', $image->model);
    }

    public function testASavedExternalProviderWithoutAnApiKeyIsAnErrorWithoutFallback(): void
    {
        putenv('LLM_EXTERNAL_API_KEY=');
        $this->savedSettings([ModerationSettingsCatalog::TEXT_PROVIDER => LLMConstants::PROVIDER_EXTERNAL]);

        $this->expectException(LLMConfigurationException::class);

        new LlmRouter()->resolve(ModerationLlmConstants::PROFILE_TEXT);
    }

    public function testASavedUnknownProviderIsAConfigurationError(): void
    {
        $this->savedSettings([ModerationSettingsCatalog::IMAGE_PROVIDER => 'frontier']);

        $this->expectException(LLMConfigurationException::class);

        new LlmRouter()->resolve(ModerationLlmConstants::PROFILE_IMAGE);
    }

    public function testTheNextResolveSeesASavedChange(): void
    {
        $settings = $this->savedSettings([ModerationSettingsCatalog::TEXT_MODEL => 'first-saved']);
        $router = new LlmRouter();

        self::assertSame('first-saved', $router->resolve(ModerationLlmConstants::PROFILE_TEXT)->model);
        $settings->saved[ModerationSettingsCatalog::TEXT_MODEL] = 'second-saved';
        self::assertSame('second-saved', $router->resolve(ModerationLlmConstants::PROFILE_TEXT)->model);
    }

    public function testBuiltInProfilesAreDeclaredUnderAProjectCatalogOfItsOwnKeys(): void
    {
        $this->savedSettings([]);
        $router = new LlmRouter(LlmRouterProjectTestCatalog::class);

        self::assertSame(
            [
                LlmProfileCatalogConstants::DEFAULT_PROFILE,
                ModerationLlmConstants::PROFILE_TEXT,
                ModerationLlmConstants::PROFILE_IMAGE,
                LlmRouterProjectTestCatalog::PROJECT_PROFILE,
            ],
            array_keys($router->catalog()),
        );
        self::assertSame(ModerationLlmConstants::DEFAULT_IMAGE_MODEL, $router->resolve(ModerationLlmConstants::PROFILE_IMAGE)->model);
        self::assertSame(
            ModerationLlmConstants::DEFAULT_TEXT_MODEL,
            $router->resolve(LlmRouterProjectTestCatalog::PROJECT_PROFILE)->model,
        );
    }

    public function testAProjectEntryUnderABuiltInKeyReplacesTheFrameworkEntry(): void
    {
        $router = new LlmRouter(LlmRouterProjectTestCatalog::class);

        self::assertSame(
            ModerationLlmConstants::DEFAULT_TEXT_MODEL,
            $router->resolveBase(LlmProfileCatalogConstants::DEFAULT_PROFILE)->model,
        );
    }

    public function testAProjectOverrideKeepsItsKeysAndTheFrameworkOverrideHasTheLastWordOnBuiltInKeys(): void
    {
        $this->savedSettings([
            ModerationSettingsCatalog::TEXT_MODEL => 'text-saved',
            ModerationSettingsCatalog::IMAGE_MODEL => 'image-saved',
        ]);
        $projectOverride = new class implements LlmProfileOverrideSource {
            public function override(LlmProfile $profile): LlmProfile
            {
                return new LlmProfile(
                    $profile->key,
                    $profile->provider,
                    $profile->url,
                    'project-model',
                    $profile->apiKey,
                    $profile->timeoutSec,
                    $profile->placement,
                );
            }
        };
        $router = new LlmRouter(LlmRouterProjectTestCatalog::class, $projectOverride);

        self::assertSame('project-model', $router->resolve(LlmRouterProjectTestCatalog::PROJECT_PROFILE)->model);
        self::assertSame('project-model', $router->resolve(LlmProfileCatalogConstants::DEFAULT_PROFILE)->model);
        self::assertSame('text-saved', $router->resolve(ModerationLlmConstants::PROFILE_TEXT)->model);
        self::assertSame('image-saved', $router->resolve(ModerationLlmConstants::PROFILE_IMAGE)->model);
    }

    public function testNoSettingHoldsTheExternalSecret(): void
    {
        putenv('LLM_EXTERNAL_API_KEY=sk-secret-only-in-env');
        $settings = $this->savedSettings([]);
        // The premise: env does carry the key the settings must not.
        self::assertSame('sk-secret-only-in-env', Hilos::$env[EnvConstants::LLM_EXTERNAL_API_KEY]->string());

        foreach (array_keys($settings->catalog()) as $key) {
            self::assertNotSame('sk-secret-only-in-env', $settings->effectiveValueFor($key), $key);
        }
    }

    /**
     * Installs a settings accessor answering the given saved values, the catalog default otherwise.
     *
     * Built after the case's env is set: the moderation defaults are read from env when the catalog is.
     *
     * @param array<string, mixed> $saved Saved setting values keyed by setting key
     * @return LlmRouterSavedSettings The installed accessor, whose saved values a case may change
     */
    private function savedSettings(array $saved): LlmRouterSavedSettings
    {
        $settings = new LlmRouterSavedSettings();
        $settings->saved = $saved;
        Hilos::$setting = $settings;

        return $settings;
    }
}

/**
 * Settings accessor for LlmRouterTest: a saved value where the case wrote one, else the catalog default.
 */
final class LlmRouterSavedSettings extends SettingsAccessor
{
    /** @var array<string, mixed> Saved setting values keyed by setting key */
    public array $saved = [];

    /**
     * @param string $key Setting key
     * @return mixed Saved value, or the catalog default when the case saved none
     */
    public function effectiveValueFor(string $key): mixed
    {
        return array_key_exists($key, $this->saved) ? $this->saved[$key] : parent::effectiveValueFor($key);
    }
}

/**
 * Project profile catalog for LlmRouterTest: its own key, and an entry under the built-in `default`.
 */
final class LlmRouterProjectTestCatalog implements CatalogProviderInterface
{
    public const string PROJECT_PROFILE = 'project.role';

    /**
     * @return array<string, array<string, mixed>> Profiles keyed by profile key
     */
    public static function getCatalog(): array
    {
        $entry = [
            LlmProfileCatalogConstants::PROVIDER_ENV => EnvConstants::LLM_CHAT_PROVIDER->name,
            LlmProfileCatalogConstants::LOCAL_URL_ENV => EnvConstants::LLM_LOCAL_URL->name,
            LlmProfileCatalogConstants::LOCAL_URL_FALLBACK_ENV => null,
            LlmProfileCatalogConstants::LOCAL_MODEL_ENV => EnvConstants::HILOS_MODERATION_TEXT_MODEL->name,
            LlmProfileCatalogConstants::EXTERNAL_URL_ENV => EnvConstants::LLM_EXTERNAL_URL->name,
            LlmProfileCatalogConstants::EXTERNAL_MODEL_ENV => EnvConstants::HILOS_MODERATION_TEXT_MODEL->name,
            LlmProfileCatalogConstants::API_KEY_ENV => EnvConstants::LLM_EXTERNAL_API_KEY->name,
            LlmProfileCatalogConstants::TIMEOUT_ENV => null,
            LlmProfileCatalogConstants::PLACEMENT => null,
        ];

        return [
            LlmProfileCatalogConstants::DEFAULT_PROFILE => $entry,
            self::PROJECT_PROFILE => $entry,
        ];
    }
}

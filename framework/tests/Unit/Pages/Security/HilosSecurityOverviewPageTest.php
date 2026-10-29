<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Security;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\Method\AuthMethodDirectory;
use Hilos\Auth\Method\AuthMethodReadiness;
use Hilos\Auth\Method\AuthMethodSettings;
use Hilos\Auth\Method\AuthMethodSettingsCatalog;
use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\Auth\OAuth\OAuthConfigResolver;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\StepUp\StepUpOperation;
use Hilos\Auth\StepUp\StepUpOperationDirectory;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Collection\OAuthProviders;
use Hilos\Database\View\Collection\Settings;
use Hilos\Database\View\Item\OAuthProvider;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Pages\Security\AbstractHilosSecurityPage;
use Hilos\Pages\Security\DTO\HilosSecurityMethodWarning;
use Hilos\Pages\Security\DTO\HilosSecurityOAuthWarning;
use Hilos\Pages\Security\DTO\HilosSecurityOverviewData;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** Exercises domain composition and the real first-answer viewer bridge without a database. */
final class HilosSecurityOverviewPageTest extends TestCase
{
    private ?HilosDbContext $previousDb;
    private ?EnvAccessor $previousEnv;
    private ?SettingsAccessor $previousSettings;
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private string $previousHilosClass;
    /** @var array<string, string|false> Environment values restored after each test */
    private array $previousEnvironment = [];
    /** @var array<string, OAuthProvider> Scripted persisted provider rows */
    private array $providerRows = [];
    private SecurityOverviewTestSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousEnv = Hilos::$env;
        $this->previousSettings = Hilos::$setting;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousHilosClass = Hilos::appClass();
        foreach ([
            EnvConstants::MAIL_SMTP_USERNAME, EnvConstants::MAIL_SMTP_PASSWORD,
            EnvConstants::MAIL_TRANSPORT, EnvConstants::MAIL_SMTP_HOST, EnvConstants::MAIL_FILE_DIR,
        ] as $key) {
            $this->previousEnvironment[$key->name] = getenv($key->name);
            putenv($key->name . '=');
        }
        Hilos::$env = new EnvAccessor();
        Hilos::$setting = $this->settings = new SecurityOverviewTestSettings();
        Hilos::$sr = new SecurityOverviewTestRouter();
        SecurityOverviewTestHilos::initBrowser(new SecurityOverviewTestBrowser());

        $providers = $this->createStub(OAuthProviders::class);
        $providers->method('offsetExists')->willReturnCallback(fn(mixed $key): bool => isset($this->providerRows[$key]));
        $providers->method('offsetGet')->willReturnCallback(fn(mixed $key): OAuthProvider => $this->providerRows[$key]);
        $settings = Settings::init();
        $settings->setObjectCollection(ObjectSettings::initEmpty());
        Hilos::$db = $this->createStub(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturnMap([
            [HilosDbContext::oauthProviders, $providers],
            [HilosDbContext::settings, $settings],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        Hilos::$db = $this->previousDb;
        Hilos::$env = $this->previousEnv;
        Hilos::$setting = $this->previousSettings;
        Hilos::$sr = $this->previousRouter;
        $this->previousHilosClass::initBrowser($this->previousBrowser);
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        }
        parent::tearDown();
    }

    public function testEmptyDirectoriesKeepACompleteZeroOverview(): void
    {
        SecurityOverviewEmptyTestHilos::initBrowser(new SecurityOverviewTestBrowser());

        self::assertSame([
            'twoFactor' => ['enabled' => true, 'required' => 'none'],
            'stepUp' => ['enabledCount' => 0, 'totalCount' => 0],
            'oauth' => ['configuredCount' => 0, 'totalCount' => 0, 'unconfigured' => []],
            'signInMethods' => ['enabledCount' => 0, 'totalCount' => 0, 'unready' => []],
            'impersonation' => ['allowed' => true, 'scope' => 'act'],
        ], HilosSecurityOverviewData::fromSources()->toArray());
    }

    public function testFullCustomDirectoriesProduceTypedWarningsInDirectoryOrder(): void
    {
        $overview = HilosSecurityOverviewData::fromSources();

        self::assertSame($this->expectedOverview(), $overview->toArray());
        self::assertContainsOnlyInstancesOf(HilosSecurityOAuthWarning::class, $overview->oauthUnconfigured);
        self::assertContainsOnlyInstancesOf(HilosSecurityMethodWarning::class, $overview->signInMethodsUnready);
        self::assertSame(count(EnabledAuthMethods::keys()), $overview->signInMethodsEnabledCount);
        self::assertFalse(AuthMethodReadiness::isReady(AuthMethodKey::SMS));
        self::assertTrue(EnabledAuthMethods::isEnabled(AuthMethodKey::SMS));
    }

    public function testTwoFactorKeepsThePolicyRequiredValueEvenWhenDisabled(): void
    {
        foreach ([true, false] as $enabled) {
            foreach (SecondFactorSettings::REQUIRED_VALUES as $required) {
                $this->settings->values[SecondFactorSettings::ENABLED_KEY] = $enabled;
                $this->settings->values[SecondFactorSettings::REQUIRED_KEY] = $required;
                $policy = SecondFactorPolicy::current();

                self::assertSame(['enabled' => $enabled, 'required' => $required], HilosSecurityOverviewData::fromSources()->toArray()['twoFactor']);
                self::assertSame($policy->enabled, HilosSecurityOverviewData::fromSources()->twoFactorEnabled);
                self::assertSame($policy->required, HilosSecurityOverviewData::fromSources()->twoFactorRequired);
            }
        }
    }

    public function testStepUpCountsBothDepartureListsAndDeclaredDefaultOff(): void
    {
        foreach ([
            ['', '', 2],
            ['sensitive', '', 1],
            ['', 'quiet', 3],
            ['sensitive,project', 'quiet', 1],
            ['quiet,unknown', 'sensitive,unknown', 2],
        ] as [$disabled, $enabled, $count]) {
            $this->settings->values[StepUpSettings::DISABLED_KEY] = $disabled;
            $this->settings->values[StepUpSettings::ENABLED_KEY] = $enabled;
            $overview = HilosSecurityOverviewData::fromSources();

            self::assertSame(['enabledCount' => $count, 'totalCount' => 3], $overview->toArray()['stepUp']);
            self::assertSame(count(array_filter(SecurityOverviewTestOperations::keys(), StepUpSettings::isEnabled(...))), $count);
        }
    }

    public function testProviderReadinessUsesRecipeEnvAndStoredClientPairsWithoutReadingSecrets(): void
    {
        $resolver = new OAuthConfigResolver();
        self::assertSame(1, HilosSecurityOverviewData::fromSources()->oauthConfiguredCount);
        putenv(EnvConstants::MAIL_SMTP_USERNAME->name . '=env-client');
        self::assertSame(1, HilosSecurityOverviewData::fromSources()->oauthUnconfigured[2]->missingFields);
        putenv(EnvConstants::MAIL_SMTP_PASSWORD->name . '=env-secret');
        self::assertSame(2, HilosSecurityOverviewData::fromSources()->oauthConfiguredCount);

        $this->storeProvider('oauth:stored', 'stored-client', true);
        self::assertSame(3, HilosSecurityOverviewData::fromSources()->oauthConfiguredCount);
        // A saved empty client id leaves the env layer in force.
        $this->storeProvider('oauth:github', '', true);
        self::assertSame(3, HilosSecurityOverviewData::fromSources()->oauthConfiguredCount);
        putenv(EnvConstants::MAIL_SMTP_USERNAME->name . '=');
        $overview = HilosSecurityOverviewData::fromSources();
        self::assertSame([
            ['providerKey' => 'oauth:google', 'label' => 'Empty preset', 'missingFields' => 2],
            ['providerKey' => 'oauth:partial', 'label' => 'Partial recipe', 'missingFields' => 1],
            ['providerKey' => 'oauth:github', 'label' => 'Env provider', 'missingFields' => 1],
        ], $overview->toArray()['oauth']['unconfigured']);
        self::assertSame(
            count(array_filter(SecurityOverviewTestProviders::all(), $resolver->isReady(...))),
            $overview->oauthConfiguredCount,
        );
        $serialized = json_encode($overview->toArray(), JSON_THROW_ON_ERROR);
        foreach (['env-client', 'env-secret', 'stored-client', 'recipe-client', 'recipe-secret', 'clientSecret', 'clientId'] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    public function testOnlyEnabledNonOAuthUnreadyMethodsWarnAndReadinessIsRecomputed(): void
    {
        $this->settings->values[AuthMethodSettings::DISABLED_KEY] = AuthMethodKey::SMS;
        self::assertSame([
            'enabledCount' => 9, 'totalCount' => 10,
            'unready' => [['methodKey' => 'magic_link', 'label' => 'Email link']],
        ], HilosSecurityOverviewData::fromSources()->toArray()['signInMethods']);
        putenv(EnvConstants::MAIL_TRANSPORT->name . '=smtp');
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=relay.example.invalid');
        self::assertSame([], HilosSecurityOverviewData::fromSources()->signInMethodsUnready);
        self::assertSame(9, HilosSecurityOverviewData::fromSources()->signInMethodsEnabledCount);

        $this->settings->values[AuthMethodSettings::DISABLED_KEY] = implode(',', SecurityOverviewTestMethods::keys());
        self::assertSame([
            'enabledCount' => 0, 'totalCount' => 10, 'unready' => [],
        ], HilosSecurityOverviewData::fromSources()->toArray()['signInMethods']);
    }

    public function testImpersonationReadsAllowedAndBothScopesIndependently(): void
    {
        foreach ([true, false] as $allowed) {
            foreach (ImpersonationSettings::SCOPE_VALUES as $scope) {
                $this->settings->values[ImpersonationSettings::ALLOWED_KEY] = $allowed;
                $this->settings->values[ImpersonationSettings::SCOPE_KEY] = $scope;

                self::assertSame(['allowed' => $allowed, 'scope' => $scope], HilosSecurityOverviewData::fromSources()->toArray()['impersonation']);
            }
        }
    }

    public function testFirstAnswerCarriesTheExactOverviewAndNoTableWindows(): void
    {
        self::assertSame([HilosDbContext::settings, HilosDbContext::oauthProviders], SecurityOverviewTestPage::READS_DB);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY,
        ], SecurityOverviewTestPage::BROWSER);
        $this->assertPageAnswer($this->expectedOverview());
        // Table-style params cannot narrow an overview built from the directories.
        $this->assertPageAnswer($this->expectedOverview(), params: new PageRouteParams(['search' => 'no-match', 'limit' => 1]));
    }

    public function testViewerMasksEachSettingOnInitialAndRepeatedDelivery(): void
    {
        SecurityOverviewTestHilos::initBrowser(new SecurityOverviewTestBrowser(viewer: true));
        foreach ([
            SecondFactorSettings::ENABLED_KEY => [['twoFactor', 'enabled']],
            SecondFactorSettings::REQUIRED_KEY => [['twoFactor', 'required']],
            StepUpSettings::DISABLED_KEY => [['stepUp', 'enabledCount']],
            StepUpSettings::ENABLED_KEY => [['stepUp', 'enabledCount']],
            AuthMethodSettings::DISABLED_KEY => [['signInMethods', 'enabledCount'], ['signInMethods', 'unready']],
            ImpersonationSettings::ALLOWED_KEY => [['impersonation', 'allowed']],
            ImpersonationSettings::SCOPE_KEY => [['impersonation', 'scope']],
        ] as $closedKey => $paths) {
            $this->settings->closedKeys = [$closedKey];
            $expected = $this->expectedOverview();
            foreach ($paths as [$group, $field]) {
                $expected[$group][$field] = HiddenValue::mark();
            }
            $this->assertPageAnswer($expected, viewer: true);
        }
        $this->settings->closedKeys = [];
        $this->assertPageAnswer($this->expectedOverview(), viewer: true);

        $this->settings->closedKeys = [SecondFactorSettings::REQUIRED_KEY, AuthMethodSettings::DISABLED_KEY];
        $this->settings->values[SecondFactorSettings::REQUIRED_KEY] = 'everyone';
        $this->settings->values[AuthMethodSettings::DISABLED_KEY] = 'sms,magic_link';
        $expected = $this->expectedOverview();
        $expected['twoFactor']['required'] = HiddenValue::mark();
        $expected['signInMethods']['enabledCount'] = HiddenValue::mark();
        $expected['signInMethods']['unready'] = HiddenValue::mark();
        $this->assertPageAnswer($expected, viewer: true);
    }

    public function testAProviderReadFailureDoesNotQueueAnIncompleteFirstAnswer(): void
    {
        $row = $this->createStub(OAuthProvider::class);
        $row->method('hasClientSecret')->willThrowException(new DatabaseException('Provider unavailable'));
        $this->providerRows['oauth:stored'] = $row;

        try {
            $this->page()->onSubscribe('security-reader', new PageRouteParams([]));
            self::fail('A provider read failure must refuse the complete answer.');
        } catch (DatabaseException) {
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    /** @return array<string, mixed> Full expected overview with no table filtering */
    private function expectedOverview(): array
    {
        return [
            'twoFactor' => ['enabled' => true, 'required' => 'none'],
            'stepUp' => ['enabledCount' => 2, 'totalCount' => 3],
            'oauth' => ['configuredCount' => 1, 'totalCount' => 5, 'unconfigured' => [
                ['providerKey' => 'oauth:google', 'label' => 'Empty preset', 'missingFields' => 2],
                ['providerKey' => 'oauth:partial', 'label' => 'Partial recipe', 'missingFields' => 1],
                ['providerKey' => 'oauth:github', 'label' => 'Env provider', 'missingFields' => 2],
                ['providerKey' => 'oauth:stored', 'label' => 'Stored provider', 'missingFields' => 2],
            ]],
            'signInMethods' => ['enabledCount' => 10, 'totalCount' => 10, 'unready' => [
                ['methodKey' => 'sms', 'label' => 'Phone code'],
                ['methodKey' => 'magic_link', 'label' => 'Email link'],
            ]],
            'impersonation' => ['allowed' => true, 'scope' => 'act'],
        ];
    }

    /**
     * @param array<string, mixed> $overview Expected security overview
     * @param bool $viewer Whether the viewer bridge puts identity before page data
     * @param ?PageRouteParams $params Subscription params, or the empty params
     */
    private function assertPageAnswer(array $overview, bool $viewer = false, ?PageRouteParams $params = null): void
    {
        $this->page()->onSubscribe('security-reader', $params ?? new PageRouteParams([]));
        $frame = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($frame);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frame->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $frame->data);
        self::assertSame('security-reader', $frame->data->targetAcceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $frame->data->data);
        $identity = [
            'pageLabel' => 'Security Center',
            'pageLead' => 'Sign-in methods, two-factor authentication and OAuth login providers.',
            'pageBreadcrumb' => [
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_SECURITY, 'label' => 'Security Center'],
            ],
            'pageChildren' => [],
        ];
        $data = ['securityOverview' => $overview];
        self::assertSame([
            'page' => HilosPageConstants::HILOS_SECURITY,
            'payload' => ['data' => $viewer ? $identity + $data : $data + $identity],
        ], $frame->data->data->toArray());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return SecurityOverviewTestPage Real page served by a minimal agent double */
    private function page(): SecurityOverviewTestPage
    {
        $agent = $this->createStub(PageAgentInterface::class);
        $agent->method('getId')->willReturn('security-agent');
        $agent->method('getAgentSignalSource')->willReturn(new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_SETTINGS_LIBRARY));
        return new SecurityOverviewTestPage($agent);
    }

    /**
     * @param string $key Provider directory key
     * @param string $clientId Persisted client id
     * @param bool $secretSet Persisted secret state
     */
    private function storeProvider(string $key, string $clientId, bool $secretSet): void
    {
        $row = $this->createMock(OAuthProvider::class);
        $row->method('__get')->willReturnMap([['clientId', $clientId]]);
        $row->method('hasClientSecret')->willReturn($secretSet);
        $row->expects(self::never())->method('readClientSecret');
        $this->providerRows[$key] = $row;
    }
}

abstract class SecurityOverviewTestHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_SECURITY => SecurityOverviewTestPage::class];
    protected const string AUTH_METHOD_DIRECTORY = SecurityOverviewTestMethods::class;
    protected const string OAUTH_PROVIDER_DIRECTORY = SecurityOverviewTestProviders::class;
    protected const string STEP_UP_OPERATION_DIRECTORY = SecurityOverviewTestOperations::class;
}

abstract class SecurityOverviewEmptyTestHilos extends SecurityOverviewTestHilos
{
    protected const string AUTH_METHOD_DIRECTORY = AuthMethodDirectory::class;
    protected const string OAUTH_PROVIDER_DIRECTORY = OAuthProviderDirectory::class;
    protected const string STEP_UP_OPERATION_DIRECTORY = SecurityOverviewEmptyOperations::class;
}

final class SecurityOverviewTestMethods extends AuthMethodDirectory
{
    /** @return list<string> Wired methods in deliberate nonalphabetical order */
    protected static function methods(): array
    {
        return [
            AuthMethodKey::SMS, AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::PASSKEY, 'project_method', ...array_keys(SecurityOverviewTestProviders::all()),
        ];
    }
}

final class SecurityOverviewTestProviders extends OAuthProviderDirectory
{
    /** @return array<string, OAuthProviderDescriptor> Complete test provider directory */
    protected static function providers(): array
    {
        return [
            'oauth:google' => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GOOGLE, 'Empty preset'),
            'oauth:partial' => self::recipe('oauth:partial', 'Partial recipe', 'recipe-client', ''),
            'oauth:github' => OAuthProviderDescriptor::fromPreset(
                OAuthProviderPreset::GITHUB, 'Env provider', EnvConstants::MAIL_SMTP_USERNAME, EnvConstants::MAIL_SMTP_PASSWORD,
            ),
            'oauth:stored' => self::recipe('oauth:stored', 'Stored provider', '', ''),
            'oauth:custom' => self::recipe('oauth:custom', 'Custom recipe', 'recipe-client', 'recipe-secret'),
        ];
    }

    /**
     * @param string $key Provider key
     * @param string $label Provider label
     * @param string $clientId Recipe client id
     * @param string $secret Recipe client secret
     * @return OAuthProviderDescriptor Hand-built provider descriptor
     */
    private static function recipe(string $key, string $label, string $clientId, string $secret): OAuthProviderDescriptor
    {
        return OAuthProviderDescriptor::fromRecipe(new OAuthProviderConfig(
            key: $key, clientId: $clientId, clientSecret: $secret,
            authorizeUrl: 'https://provider.example/authorize', tokenUrl: 'https://provider.example/token',
            userInfoUrl: 'https://provider.example/userinfo', scope: 'profile', redirectUri: '',
            subjectKey: 'sub', emailKey: 'email', nameKey: 'name',
        ), $label);
    }
}

final class SecurityOverviewTestOperations extends StepUpOperationDirectory
{
    /** @return array<string, StepUpOperation> Both declared positions and a custom operation */
    protected static function operations(): array
    {
        return [
            'sensitive' => new StepUpOperation('sensitive', 'Sensitive', 'change security', false),
            'quiet' => new StepUpOperation('quiet', 'Quiet', 'change a flag', false, enabledByDefault: false),
            'project' => new StepUpOperation('project', 'Project', 'run a project operation', false),
        ];
    }
}

final class SecurityOverviewEmptyOperations extends StepUpOperationDirectory
{
    /** @return array<string, StepUpOperation> Empty operation directory */
    protected static function operations(): array
    {
        return [];
    }
}

final class SecurityOverviewTestSettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Real domain catalog fragments */
    public static function getCatalog(): array
    {
        return array_replace(
            SecondFactorSettingsCatalog::getCatalog(), StepUpSettingsCatalog::getCatalog(),
            AuthMethodSettingsCatalog::getCatalog(), ImpersonationSettingsCatalog::getCatalog(),
        );
    }
}

/** Real typed readers over domain catalogs with a scripted persisted layer and visibility. */
final class SecurityOverviewTestSettings extends SettingsAccessor
{
    /** @var array<string, bool|string> Scripted stored values */
    public array $values = [];
    /** @var list<string> Keys the test closes to viewers */
    public array $closedKeys = [];

    public function __construct()
    {
        parent::__construct(SecurityOverviewTestSettingsCatalog::class);
    }

    /**
     * @param string $key Setting catalog key
     * @return mixed Scripted stored value or real catalog default
     */
    public function effectiveValueFor(string $key): mixed
    {
        return $this->values[$key] ?? parent::effectiveValueFor($key);
    }

    /**
     * @param string $key Setting catalog key
     * @return bool Scripted catalog visibility verdict
     */
    public function visibleInAdminViewMode(string $key): bool
    {
        return !in_array($key, $this->closedKeys, true) && parent::visibleInAdminViewMode($key);
    }
}

final class SecurityOverviewTestPage extends AbstractHilosSecurityPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_SETTINGS_LIBRARY;
}

final class SecurityOverviewTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Facade bound by this test */
    protected function hilosClass(): string
    {
        return Hilos::appClass();
    }
}

final class SecurityOverviewTestBrowser extends IdentityTestBrowser
{
    /** @param bool $viewer Whether this connection is an admin-view-mode viewer */
    public function __construct(private readonly bool $viewer = false)
    {
        parent::__construct(1, !$viewer);
    }

    /**
     * @param string $pageClass Subscribed page class
     * @param string $acceptKey Connection receiving the frame
     * @return bool Scripted viewer verdict
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }
}

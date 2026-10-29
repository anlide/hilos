<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Security;

use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\OAuthProviders;
use Hilos\Database\View\Item\OAuthProvider;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Pages\Security\AbstractHilosSecurityOAuthProviderPage;
use Hilos\Pages\Security\DTO\HilosOAuthProviderHeadData;
use Hilos\Pages\Security\DTO\HilosOAuthProviderResetActionDTO;
use Hilos\Pages\Security\DTO\HilosOAuthProviderSetActionDTO;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the provider's complete first answer and its action refusals (HIL-286).
 *
 * Every refusal here is a domain phrase the screen shows in a toast, and every one of them
 * is decided without a database: a provider the project does not declare, a field that is
 * not a provider field, an empty value, and a value wider than its column.
 */
final class HilosSecurityOAuthProviderPageTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-oauth-provider-page';

    private ?HilosDbContext $previousDb;
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private string $previousHilosClass;
    /** @var array<string, string|false> Environment values restored after each test */
    private array $previousEnvironment = [];
    /** @var array<string, OAuthProvider> Scripted persisted provider rows */
    private array $providerRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousHilosClass = Hilos::appClass();
        foreach ([EnvConstants::MAIL_SMTP_USERNAME, EnvConstants::MAIL_SMTP_PASSWORD] as $key) {
            $this->previousEnvironment[$key->name] = getenv($key->name);
            putenv($key->name . '=');
        }
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new ProviderPageTestRouter();
        ProviderPageTestHilos::initBrowser(new ProviderPageTestBrowser());

        $providers = $this->createStub(OAuthProviders::class);
        $providers->method('offsetExists')->willReturnCallback(fn(mixed $key): bool => isset($this->providerRows[$key]));
        $providers->method('offsetGet')->willReturnCallback(fn(mixed $key): OAuthProvider => $this->providerRows[$key]);
        Hilos::$db = $this->createStub(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturnMap([[HilosDbContext::oauthProviders, $providers]]);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        Hilos::$db = $this->previousDb;
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        $this->previousHilosClass::initBrowser($this->previousBrowser);
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        }
        parent::tearDown();
    }

    public function testFirstAnswerCarriesTheCompletePresetHeadWithoutATableWindow(): void
    {
        self::assertSame([HilosDbContext::oauthProviders], ProviderPageTestPage::READS_DB);
        $this->assertFirstHead(['providerId' => 'oauth:github'], [
            'providerKey' => 'oauth:github', 'label' => 'GitHub', 'configured' => false, 'missingFields' => 2,
        ]);
        $this->assertFirstHead(['providerId' => 'oauth:google'], [
            'providerKey' => 'oauth:google', 'label' => 'Company sign-in', 'configured' => false, 'missingFields' => 2,
        ]);
    }

    public function testCustomRecipeUsesThePageDirectorySeamAndIgnoresTableStyleParams(): void
    {
        self::assertNull(Hilos::oauthProviderDirectoryClass()::get('oauth:custom'));
        $this->assertFirstHead(['providerId' => 'oauth:custom', 'search' => 'no-match', 'limit' => '1'], [
            'providerKey' => 'oauth:custom', 'label' => 'Project identity', 'configured' => true, 'missingFields' => 0,
        ]);
    }

    public function testReadinessFollowsTheEffectiveEnvAndStoredClientPairWithoutReadingSecrets(): void
    {
        $params = ['providerId' => 'oauth:google'];
        $head = ['providerKey' => 'oauth:google', 'label' => 'Company sign-in', 'configured' => false, 'missingFields' => 2];
        $this->assertFirstHead($params, $head);

        putenv(EnvConstants::MAIL_SMTP_USERNAME->name . '=env-client');
        $this->assertFirstHead($params, array_replace($head, ['missingFields' => 1]));
        putenv(EnvConstants::MAIL_SMTP_PASSWORD->name . '=env-secret');
        $this->assertFirstHead($params, array_replace($head, ['configured' => true, 'missingFields' => 0]));

        // Empty stored fields leave the environment pair in force.
        $this->storeProvider('oauth:google', '', false);
        $this->assertFirstHead($params, array_replace($head, ['configured' => true, 'missingFields' => 0]));
        putenv(EnvConstants::MAIL_SMTP_USERNAME->name . '=');
        putenv(EnvConstants::MAIL_SMTP_PASSWORD->name . '=');
        $this->assertFirstHead($params, $head);

        $this->storeProvider('oauth:google', 'stored-client', true);
        $this->assertFirstHead($params, array_replace($head, ['configured' => true, 'missingFields' => 0]));
        $this->storeProvider('oauth:google', '', true);
        $this->assertFirstHead($params, array_replace($head, ['missingFields' => 1]));
        $this->storeProvider('oauth:google', 'stored-client', false);
        $this->assertFirstHead($params, array_replace($head, ['missingFields' => 1]));
    }

    public function testRecipeHeadRecomputesMissingFieldsWhenAStoredOverrideCompletesThePair(): void
    {
        $params = ['providerId' => 'oauth:partial'];
        $head = ['providerKey' => 'oauth:partial', 'label' => 'Partial recipe', 'configured' => false, 'missingFields' => 1];
        $this->assertFirstHead($params, $head);
        $this->storeProvider('oauth:partial', '', true);
        $this->assertFirstHead($params, array_replace($head, ['configured' => true, 'missingFields' => 0]));
    }

    public function testUnknownMissingAndEmptyProviderIdsKeepANullHeadInTheFirstAnswer(): void
    {
        foreach ([['providerId' => 'oauth:gitlab'], [], ['providerId' => '']] as $params) {
            $this->assertFirstHead($params, null);
        }
    }

    public function testViewerReceivesTheSameNonpersonalHeadAndNullForAnUnknownProvider(): void
    {
        ProviderPageTestHilos::initBrowser(new ProviderPageTestBrowser(viewer: true));
        $this->assertFirstHead(['providerId' => 'oauth:custom'], [
            'providerKey' => 'oauth:custom', 'label' => 'Project identity', 'configured' => true, 'missingFields' => 0,
        ]);
        $this->assertFirstHead(['providerId' => 'oauth:gitlab'], null);
    }

    public function testAProviderReadFailureDoesNotQueueAnIncompleteFirstAnswer(): void
    {
        $row = $this->createStub(OAuthProvider::class);
        $row->method('hasClientSecret')->willThrowException(new DatabaseException('Provider unavailable'));
        $this->providerRows['oauth:github'] = $row;

        try {
            new ProviderPageTestPage(new ProviderPageTestAgent())->onSubscribe(
                self::ACCEPT_KEY,
                new PageRouteParams(['providerId' => 'oauth:github']),
            );
            self::fail('A provider read failure must refuse the complete answer.');
        } catch (DatabaseException) {
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    public function testAProviderTheProjectDoesNotDeclareIsRefused(): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown OAuth provider: oauth:gitlab');

        $this->set('oauth:gitlab', 'client_id', 'x');
    }

    public function testAFieldThatIsNotAProviderFieldIsRefused(): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown field: authorize_url');

        $this->set(OAuthProviderPreset::GITHUB->value, 'authorize_url', 'https://evil.example');
    }

    public function testAnEmptyValueIsRefusedWithTheWayBack(): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Client secret cannot be empty. Reset it to fall back to the environment value.');

        $this->set(OAuthProviderPreset::GITHUB->value, 'client_secret', "  \n");
    }

    public function testAValueWiderThanItsColumnIsRefused(): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Client ID cannot be longer than 255 characters.');

        $this->set(OAuthProviderPreset::GITHUB->value, 'client_id', str_repeat('a', 256));
    }

    public function testAResetOfAnUnknownFieldIsRefused(): void
    {
        $this->expectException(TableActionException::class);

        new ProviderPageTestPage(new ProviderPageTestAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::SECURITY_OAUTH_PROVIDER_RESET,
            new HilosOAuthProviderResetActionDTO(OAuthProviderPreset::GITHUB->value, 'redirect_uri'),
        );
    }

    /**
     * @param array<string, string> $params Subscription route params
     * @param ?array{providerKey: string, label: string, configured: bool, missingFields: int} $head Expected complete head
     */
    private function assertFirstHead(array $params, ?array $head): void
    {
        new ProviderPageTestPage(new ProviderPageTestAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams($params));
        $frame = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($frame);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frame->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $frame->data);
        self::assertSame(self::ACCEPT_KEY, $frame->data->targetAcceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $frame->data->data);
        self::assertSame(HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER, $frame->data->data->pageKey);
        self::assertArrayHasKey(HilosOAuthProviderHeadData::DATA, $frame->data->data->payload->data);
        self::assertSame($head, $frame->data->data->payload->data[HilosOAuthProviderHeadData::DATA]);
        self::assertSame([], $frame->data->data->payload->windows);
        self::assertSame([], $frame->data->data->payload->tables);
        $wire = $frame->data->data->toArray();
        self::assertSame($wire, PageResponseSignalData::fromArray($wire)->toArray());
        $serialized = json_encode($wire, JSON_THROW_ON_ERROR);
        foreach (['env-client', 'env-secret', 'stored-client', 'recipe-client', 'recipe-secret', 'clientId', 'clientSecret'] as $credential) {
            self::assertStringNotContainsString($credential, $serialized);
        }
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
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

    /**
     * Sends one set action to the page.
     *
     * @param string $providerKey Provider key
     * @param string $field Field name
     * @param string $value Value as sent
     * @throws TableActionException When the page refuses it
     */
    private function set(string $providerKey, string $field, string $value): void
    {
        new ProviderPageTestPage(new ProviderPageTestAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::SECURITY_OAUTH_PROVIDER_SET,
            new HilosOAuthProviderSetActionDTO($providerKey, $field, $value),
        );
    }
}

/**
 * Concrete provider page over the test directory: the abstract one carries the whole behavior.
 */
final class ProviderPageTestPage extends AbstractHilosSecurityOAuthProviderPage
{
    /**
     * @return class-string<OAuthProviderDirectory> The test directory
     */
    protected function providerDirectoryClass(): string
    {
        return ProviderPageTestDirectory::class;
    }
}

/**
 * Preset and custom providers, including complete and partial recipe defaults.
 */
final class ProviderPageTestDirectory extends OAuthProviderDirectory
{
    /**
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    protected static function providers(): array
    {
        return array_replace(parent::providers(), [
            OAuthProviderPreset::GITHUB->value => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GITHUB, 'GitHub'),
            OAuthProviderPreset::GOOGLE->value => OAuthProviderDescriptor::fromPreset(
                OAuthProviderPreset::GOOGLE, 'Company sign-in', EnvConstants::MAIL_SMTP_USERNAME, EnvConstants::MAIL_SMTP_PASSWORD,
            ),
            'oauth:custom' => self::recipe('oauth:custom', 'Project identity', 'recipe-secret'),
            'oauth:partial' => self::recipe('oauth:partial', 'Partial recipe', ''),
        ]);
    }

    /**
     * @param string $key Provider key
     * @param string $label Provider label
     * @param string $secret Recipe secret, empty for an incomplete pair
     * @return OAuthProviderDescriptor Hand-built provider with a default client id
     */
    private static function recipe(string $key, string $label, string $secret): OAuthProviderDescriptor
    {
        return OAuthProviderDescriptor::fromRecipe(new OAuthProviderConfig(
            key: $key, clientId: 'recipe-client', clientSecret: $secret,
            authorizeUrl: 'https://provider.example/authorize', tokenUrl: 'https://provider.example/token',
            userInfoUrl: 'https://provider.example/userinfo', scope: 'profile', redirectUri: '',
            subjectKey: 'sub', emailKey: 'email', nameKey: 'name',
        ), $label);
    }
}

/**
 * Page agent carrying only what a page may reach for: its id and its signal source.
 */
final class ProviderPageTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_index';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}

/** The facade deliberately keeps its provider directory empty to exercise the page's seam. */
abstract class ProviderPageTestHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER => ProviderPageTestPage::class];
}

final class ProviderPageTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Facade bound by this test */
    protected function hilosClass(): string
    {
        return Hilos::appClass();
    }
}

final class ProviderPageTestBrowser extends IdentityTestBrowser
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

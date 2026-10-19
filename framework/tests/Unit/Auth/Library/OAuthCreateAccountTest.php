<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Library;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\ActingSession;
use Hilos\Auth\Library\Command\OAuthCommands;
use Hilos\Auth\Library\DTO\OAuthCreateAccountActionDTO;
use Hilos\Auth\OAuth\OAuthAccountTokenSigner;
use Hilos\Auth\OAuth\OAuthLinkTokenSigner;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Collection\Identities;
use Hilos\Database\View\Item\Identity;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** The request's provider names an expired proof but never supplies any of its authority. */
final class OAuthCreateAccountTest extends TestCase
{
    private const string SECRET = 'oauth-create-test-secret';
    private const string SESSION = 'oauth-create-test-session';

    private ?RtContext $previousRt;
    private ?HilosDbContext $previousDb;

    /** @var class-string<Hilos> Facade to restore after each test */
    private string $previousApp;

    protected function setUp(): void
    {
        $this->previousRt = Hilos::$rt;
        $this->previousDb = Hilos::$db;
        $this->previousApp = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, OAuthCreateAccountTestHilos::class);
        Hilos::$rt = new OAuthCreateAccountTestRtContext();
        Hilos::$rt->configure();
        Hilos::$db = null;
    }

    protected function tearDown(): void
    {
        Hilos::$rt = $this->previousRt;
        Hilos::$db = $this->previousDb;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
    }

    /** @return list<array{?string, string}> Untrusted hints and independently written expected sentences */
    public static function expiredHints(): array
    {
        return [
            ['oauth:github', 'Your sign-in with Catalog GitHub has expired. Continue with Catalog GitHub again.'],
            ['oauth:google', 'Your sign-in with Catalog Google has expired. Continue with Catalog Google again.'],
            [null, 'Your sign-in has expired. Continue with your provider again.'],
            ['untrusted label', 'Your sign-in has expired. Continue with your provider again.'],
            ['', 'Your sign-in has expired. Continue with your provider again.'],
        ];
    }

    /**
     * @param ?string $provider Request hint
     * @param string $message Expected safe refusal
     */
    #[DataProvider('expiredHints')]
    public function testExpiredProofUsesOnlyTheCatalogLabel(?string $provider, string $message): void
    {
        $library = $this->createMock(AbstractUsersLibraryAgent::class);
        $library->method('oauthService')->willReturn($this->service());
        $library->expects(self::never())->method('grantSession');
        $token = new OAuthAccountTokenSigner(self::SECRET)->issue(
            'oauth:github', 'signed-subject', null, 'Signed Person', self::SESSION, -1,
        );

        $outcome = new OAuthCommands($library)->createAccount(
            'accept-test',
            new OAuthCreateAccountActionDTO($token, null, provider: $provider),
        );

        self::assertNotNull($outcome);
        self::assertSame([
            'ok' => false,
            'next' => ['step' => 'identifier', 'intent' => 'login'],
            'code' => 'oauth_sign_in_expired',
            'message' => $message,
        ], $outcome->toArray());
    }

    /** @return list<array{?string}> Hints that must never replace the signed provider */
    public static function untrustedHints(): array
    {
        return [[null], ['oauth:google'], ['fake-provider']];
    }

    /** @param ?string $provider Untrusted provider hint */
    #[DataProvider('untrustedHints')]
    public function testValidProofKeepsItsSignedIdentityRegardlessOfTheHint(?string $provider): void
    {
        $service = $this->service();
        $token = $service->issueAccountToken('oauth:github', 'signed-subject', null, 'Signed Person', self::SESSION);
        $identity = $this->createMock(Identity::class);
        $identity->method('__get')->with('userId')->willReturn(42);
        $identities = $this->createMock(Identities::class);
        $identities->expects(self::once())->method('findByIdentity')->with('oauth', 'oauth:github:signed-subject')->willReturn($identity);
        $db = $this->createMock(HilosDbContext::class);
        $db->method('__get')->with('identities')->willReturn($identities);
        Hilos::$db = $db;
        $library = $this->createMock(AbstractUsersLibraryAgent::class);
        $library->method('oauthService')->willReturn($service);
        $library->expects(self::once())->method('grantSession')->with(
            self::callback(static fn(ActingSession $acting): bool => $acting->sessionToken === self::SESSION),
            42,
        );

        self::assertNull(new OAuthCommands($library)->createAccount(
            'accept-test',
            new OAuthCreateAccountActionDTO($token, null, provider: $provider),
        ));
    }

    /** @param ?string $provider Untrusted provider hint */
    #[DataProvider('untrustedHints')]
    public function testAProviderHintCannotAuthorizeAForeignOrTamperedProof(?string $provider): void
    {
        $service = $this->service();
        $library = $this->createMock(AbstractUsersLibraryAgent::class);
        $library->method('oauthService')->willReturn($service);
        $library->expects(self::never())->method('grantSession');
        $foreign = $service->issueAccountToken('oauth:github', 'signed-subject', null, 'Person', 'another-session');
        $valid = $service->issueAccountToken('oauth:github', 'signed-subject', null, 'Person', self::SESSION);

        foreach ([$foreign, $valid . 'tampered', 'bad-token'] as $token) {
            $outcome = new OAuthCommands($library)->createAccount(
                'accept-test',
                new OAuthCreateAccountActionDTO($token, null, provider: $provider),
            );
            self::assertNotNull($outcome);
            self::assertFalse($outcome->ok);
            self::assertSame('oauth_sign_in_expired', $outcome->code);
        }
    }

    /** @return OAuthService Real signers, no provider network */
    private function service(): OAuthService
    {
        return new OAuthService(
            new OAuthProviderRegistry([]),
            new OAuthStateSigner(self::SECRET),
            600,
            new OAuthLinkTokenSigner(self::SECRET),
            600,
            new OAuthAccountTokenSigner(self::SECRET),
            600,
        );
    }
}

abstract class OAuthCreateAccountTestHilos extends Hilos
{
    protected const string OAUTH_PROVIDER_DIRECTORY = OAuthCreateAccountTestDirectory::class;
}

final class OAuthCreateAccountTestDirectory extends OAuthProviderDirectory
{
    /** @return array<string, OAuthProviderDescriptor> Two declared display names */
    protected static function providers(): array
    {
        return [
            'oauth:github' => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GITHUB, 'Catalog GitHub'),
            'oauth:google' => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GOOGLE, 'Catalog Google'),
        ];
    }
}

final class OAuthCreateAccountTestRtContext extends RtContext
{
    /** Mounts one browser connection without a database. */
    public function configure(): void
    {
        $connections = OAuthCreateAccountTestConnections::init();
        $connections->add(OAuthCreateAccountTestConnection::create('accept-test', null, 'oauth-create-test-session'));
        $this->_stateCollections['oauthCreateTestConnections'] = $connections;
    }
}

final class OAuthCreateAccountTestConnections extends HilosSessionConnections
{
    public const string STATE_CLASS = OAuthCreateAccountTestConnection::class;
}

final class OAuthCreateAccountTestConnection extends HilosSessionConnection
{
    /** Adds no fields to the connection. */
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Serialized runtime row */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No own fields */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Incoming field changes */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

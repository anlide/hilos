<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\OAuth\OAuthConfigField;
use Hilos\Auth\OAuth\OAuthConfigResolver;
use Hilos\Auth\OAuth\OAuthConfigSource;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\OAuth\OAuthSettingsCatalog;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Item\OAuthProvider;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Integration tests for the stored layer of an OAuth provider's configuration (HIL-286).
 *
 * What the administrator entered is a row in hilos_oauth_provider, and it wins over env.
 * The client secret in that row is written and erased through the provider layer's own
 * primitive and is never read back into the object: the resolver reports it by source and
 * state, and only the configuration the exchange runs on carries its value. Because no
 * mapped column moves when it is written, the write announces itself on the source bus, so
 * the admin screen drawn off the row redraws.
 */
final class OAuthProviderConfigIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs; the settings table is loaded eagerly by the context */
    private const array TABLES = ['hilos_oauth_provider', 'hilos_setting'];

    /** Truth-source id this case writes the provider rows under. */
    private const string WRITER_ID = 'oauth-provider-config-test';

    /** Env variable standing in for the provider's client id. */
    public const EnvConstants CLIENT_ID_ENV = EnvConstants::MAIL_SMTP_USERNAME;

    /** Env variable standing in for the provider's client secret. */
    public const EnvConstants CLIENT_SECRET_ENV = EnvConstants::MAIL_SMTP_PASSWORD;

    /** Env variable standing in for the shared return address. */
    public const EnvConstants REDIRECT_ENV = EnvConstants::MAIL_FROM_NAME;

    /** The return address the env carries in this case. */
    private const string ENV_REDIRECT = 'https://env.example/auth/callback';

    private ?DbContext $previousDb = null;

    private ?EnvAccessor $previousEnv = null;

    private ?SettingsAccessor $previousSetting = null;

    /** @var list<SourceChange> Changes announced during the case, oldest first */
    private array $announced = [];

    /**
     * @throws DatabaseException When a stub statement fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        TruthSourceRegistry::register(HilosDbContext::oauthProviders, TruthSourceKeys::all(), self::WRITER_ID);
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::WRITER_ID);

        $this->previousDb = Hilos::$db;
        $this->previousEnv = Hilos::$env;
        $this->previousSetting = Hilos::$setting;

        $db = new OAuthProviderConfigTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        Hilos::$setting = new SettingsAccessor(OAuthSettingsCatalog::class);

        putenv(self::CLIENT_ID_ENV->name . '=env-client');
        putenv(self::CLIENT_SECRET_ENV->name . '=env-secret');
        putenv(self::REDIRECT_ENV->name . '=' . self::ENV_REDIRECT);

        SourceChangeBus::reset();
        $announced = &$this->announced;
        SourceChangeBus::subscribe(new class ($announced) implements SourceChangeSubscriberInterface {
            /**
             * @param list<SourceChange> $announced Sink the case reads
             */
            public function __construct(private array &$announced)
            {
            }

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                $this->announced[] = $change;
            }
        });
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        putenv(self::CLIENT_ID_ENV->name);
        putenv(self::CLIENT_SECRET_ENV->name);
        putenv(self::REDIRECT_ENV->name);
        TruthSourceRegistry::unregister(HilosDbContext::oauthProviders, self::WRITER_ID);
        TruthSourceRegistry::unregister(HilosDbContext::settings, self::WRITER_ID);

        Hilos::$env = $this->previousEnv;
        Hilos::$setting = $this->previousSetting;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    public function testAStoredClientIdWinsOverEnv(): void
    {
        $this->row()->actions->updateClientId('admin-client');

        $resolved = $this->resolver()->resolve(self::github(), OAuthConfigField::CLIENT_ID);

        $this->assertSame(OAuthConfigSource::DB, $resolved->source);
        $this->assertSame('admin-client', $resolved->value);
    }

    public function testClearingTheStoredClientIdFallsBackToEnv(): void
    {
        $row = $this->row();
        $row->actions->updateClientId('admin-client');
        $row->actions->updateClientId(null);

        $resolved = $this->resolver()->resolve(self::github(), OAuthConfigField::CLIENT_ID);

        $this->assertSame(OAuthConfigSource::ENV, $resolved->source);
        $this->assertSame('env-client', $resolved->value);
    }

    public function testAStoredSecretIsReportedAndNeverReadBack(): void
    {
        $row = $this->row();
        $row->actions->writeClientSecret('admin-secret');

        $resolved = $this->resolver()->resolve(self::github(), OAuthConfigField::CLIENT_SECRET);
        $this->assertSame(OAuthConfigSource::DB, $resolved->source);
        $this->assertNull($resolved->value);
        $this->assertTrue($resolved->isSet);

        $reread = Hilos::$db->oauthProviders[OAuthProviderPreset::GITHUB->value];
        $this->assertNotNull($reread);
        $this->assertNotContains('admin-secret', $reread->toArray());
    }

    public function testTheExchangeRunsOnTheStoredSecret(): void
    {
        $this->row()->actions->writeClientSecret('admin-secret');

        $config = $this->resolver()->providerConfig(self::github());

        $this->assertNotNull($config);
        $this->assertSame('env-client', $config->clientId);
        $this->assertSame('admin-secret', $config->clientSecret);
    }

    public function testErasingTheStoredSecretFallsBackToEnv(): void
    {
        $row = $this->row();
        $row->actions->writeClientSecret('admin-secret');
        $row->actions->writeClientSecret(null);

        $resolved = $this->resolver()->resolve(self::github(), OAuthConfigField::CLIENT_SECRET);
        $this->assertSame(OAuthConfigSource::ENV, $resolved->source);

        $config = $this->resolver()->providerConfig(self::github());
        $this->assertNotNull($config);
        $this->assertSame('env-secret', $config->clientSecret);
    }

    public function testWritingTheSecretAnnouncesTheRowWithoutTheValue(): void
    {
        $row = $this->row();
        $this->announced = [];

        $row->actions->writeClientSecret('admin-secret');

        $this->assertCount(1, $this->announced);
        $change = $this->announced[0];
        $this->assertSame(HilosDbContext::oauthProviders, $change->sourceKey);
        $this->assertSame((string)$row->id, $change->sourceId);
        $this->assertSame([], $change->row);
    }

    public function testAStoredReturnAddressWinsOverEnv(): void
    {
        $this->storeReturnAddress('https://admin.example/auth/callback');

        $resolved = $this->resolver()->resolveRedirectUri();

        $this->assertSame(OAuthConfigSource::DB, $resolved->source);
        $this->assertSame('https://admin.example/auth/callback', $resolved->value);
    }

    public function testAnEmptyStoredReturnAddressFallsBackToEnv(): void
    {
        $this->storeReturnAddress('');

        $resolved = $this->resolver()->resolveRedirectUri();

        $this->assertSame(OAuthConfigSource::ENV, $resolved->source);
        $this->assertSame(self::ENV_REDIRECT, $resolved->value);
    }

    /**
     * Stores the return address the way the general settings screen would.
     *
     * @param string $value Address to store, empty included
     * @throws HilosException When the settings row cannot be written
     */
    private function storeReturnAddress(string $value): void
    {
        Hilos::$db->settings->actions->add(OAuthSettingsCatalog::REDIRECT_URI_KEY, $value, OAuthSettingsCatalog::getCatalog());
    }

    /**
     * Brings the GitHub row into being, empty.
     *
     * @return OAuthProvider The new row
     * @throws HilosException When the row cannot be created
     */
    private function row(): OAuthProvider
    {
        return Hilos::$db->oauthProviders->actions->add(OAuthProviderPreset::GITHUB->value);
    }

    /**
     * @return OAuthConfigResolver Resolver over the directory below
     */
    private function resolver(): OAuthConfigResolver
    {
        return new OAuthConfigResolver(OAuthProviderConfigTestDirectory::class);
    }

    /**
     * @return OAuthProviderDescriptor The GitHub preset with its pair in the stand-in env variables
     */
    public static function github(): OAuthProviderDescriptor
    {
        return OAuthProviderDescriptor::fromPreset(
            OAuthProviderPreset::GITHUB,
            'GitHub',
            self::CLIENT_ID_ENV,
            self::CLIENT_SECRET_ENV,
        );
    }

    /**
     * Applies or reverts the framework table stubs this case needs.
     *
     * @param bool $down Whether to drop instead of create
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach (self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 */
final class OAuthProviderConfigTestDbContext extends HilosDbContext
{
}

/**
 * A directory with the one provider the case configures.
 */
final class OAuthProviderConfigTestDirectory extends OAuthProviderDirectory
{
    /**
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    protected static function providers(): array
    {
        return array_replace(parent::providers(), [
            OAuthProviderPreset::GITHUB->value => OAuthProviderConfigIntegrationTest::github(),
        ]);
    }

    /**
     * @return EnvConstants Env variable standing in for the shared return address
     */
    public static function redirectUriEnvKey(): EnvConstants
    {
        return OAuthProviderConfigIntegrationTest::REDIRECT_ENV;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Security;

use Hilos\Auth\OAuth\OAuthConfigField;
use Hilos\Auth\OAuth\OAuthConfigSource;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\OAuth\OAuthSettingsCatalog;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\OAuthProvider as EntityOAuthProvider;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTableRow;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTableRow;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the three OAuth admin tables (HIL-286).
 *
 * The provider directory is bound by test subclasses through the tables' seam; with no
 * database every provider stands on env and its recipe. The assertions cover the row
 * projection from the directory and the resolver, that the client secret's value is in no
 * payload even when one is in force, the narrowing to one provider, and which change of
 * the provider rows redraws which row.
 */
final class HilosSecurityOAuthTablesTest extends TestCase
{
    /** Env variable standing in for GitHub's client id. */
    private const EnvConstants CLIENT_ID_ENV = EnvConstants::MAIL_SMTP_USERNAME;

    /** Env variable standing in for GitHub's client secret. */
    private const EnvConstants CLIENT_SECRET_ENV = EnvConstants::MAIL_SMTP_PASSWORD;

    /** The secret the env holds in these cases, which no payload may carry. */
    private const string SECRET = 'env-secret-value';

    private ?EnvAccessor $previousEnv = null;

    private ?DbContext $previousDb = null;

    private ?SettingsAccessor $previousSetting = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        Hilos::$db = null;
        Hilos::$setting = null;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv(self::CLIENT_ID_ENV->name . '=env-client');
        putenv(self::CLIENT_SECRET_ENV->name . '=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        putenv(self::CLIENT_ID_ENV->name);
        putenv(self::CLIENT_SECRET_ENV->name);
        Hilos::$env = $this->previousEnv;
        Hilos::$db = $this->previousDb;
        Hilos::$setting = $this->previousSetting;
        parent::tearDown();
    }

    public function testProvidersTableProjectsEveryDeclaredProviderInDirectoryOrder(): void
    {
        $rows = $this->providersTable()->getFullSnapshot()->rows;

        self::assertCount(2, $rows);
        [$github, $google] = $rows;
        self::assertInstanceOf(HilosSecurityOAuthProvidersTableRow::class, $github);
        self::assertInstanceOf(HilosSecurityOAuthProvidersTableRow::class, $google);
        self::assertSame(OAuthProviderPreset::GITHUB->value, $github->providerKey);
        self::assertSame(OAuthProviderPreset::GOOGLE->value, $google->providerKey);
    }

    public function testAProviderWithItsPairInEnvIsConfigured(): void
    {
        $github = $this->providerRow(OAuthProviderPreset::GITHUB->value);

        self::assertSame('GitHub', $github->label);
        self::assertTrue($github->builtIn);
        self::assertTrue($github->configured);
        self::assertSame(0, $github->missingFields);
        self::assertTrue($github->secretSet);
        self::assertSame(OAuthConfigSource::ENV->value, $github->clientIdSource);
        self::assertSame('https://github.com/login/oauth/authorize', $github->authorizeUrl);
        self::assertSame('login', $github->nameKey);
    }

    public function testAProviderWithNothingSetCountsBothRequiredFieldsMissing(): void
    {
        $google = $this->providerRow(OAuthProviderPreset::GOOGLE->value);

        self::assertFalse($google->configured);
        self::assertSame(2, $google->missingFields);
        self::assertFalse($google->secretSet);
        self::assertSame(OAuthConfigSource::DEFAULT->value, $google->clientIdSource);
    }

    public function testNoProviderPayloadCarriesTheSecret(): void
    {
        foreach ($this->providersTable()->getFullSnapshot()->rows as $row) {
            self::assertNotContains(self::SECRET, $row->toArray());
        }
        foreach ($this->fieldsTable()->getFullSnapshot()->rows as $row) {
            self::assertNotContains(self::SECRET, $row->toArray());
            self::assertArrayNotHasKey('id', $row->toArray());
        }
    }

    public function testFieldsTableHasOneRowPerFieldOfEveryProvider(): void
    {
        self::assertCount(2 * count(OAuthConfigField::cases()), $this->fieldsTable()->getFullSnapshot()->rows);
    }

    public function testTheSecretRowSaysItIsSetAndNothingMore(): void
    {
        $secret = $this->fieldRow(OAuthProviderPreset::GITHUB->value, OAuthConfigField::CLIENT_SECRET);

        self::assertTrue($secret->secret);
        self::assertNull($secret->value);
        self::assertTrue($secret->setState);
        self::assertSame(OAuthConfigSource::ENV->value, $secret->source);
    }

    public function testAnOrdinaryFieldCarriesItsValueAndSource(): void
    {
        $clientId = $this->fieldRow(OAuthProviderPreset::GITHUB->value, OAuthConfigField::CLIENT_ID);
        $scope = $this->fieldRow(OAuthProviderPreset::GITHUB->value, OAuthConfigField::SCOPE);

        self::assertSame('env-client', $clientId->value);
        self::assertSame(OAuthConfigSource::ENV->value, $clientId->source);
        self::assertSame('read:user user:email', $scope->value);
        self::assertSame(OAuthConfigSource::DEFAULT->value, $scope->source);
    }

    public function testTheProviderFilterNarrowsBothTablesToOneProvider(): void
    {
        $query = new TableQueryDTO(filter: [HilosSecurityOAuthProvidersTable::FILTER_PROVIDER => OAuthProviderPreset::GITHUB->value]);

        self::assertCount(1, $this->providersTable()->getPage($query)->rows);
        self::assertCount(count(OAuthConfigField::cases()), $this->fieldsTable()->getPage($query)->rows);
    }

    public function testAProviderTheProjectDoesNotDeclareNarrowsToNothing(): void
    {
        $query = new TableQueryDTO(filter: [HilosSecurityOAuthProvidersTable::FILTER_PROVIDER => 'oauth:gitlab']);

        self::assertCount(0, $this->providersTable()->getPage($query)->rows);
        self::assertCount(0, $this->fieldsTable()->getPage($query)->rows);
    }

    public function testAClientIdChangeRedrawsTheProviderAndItsClientIdRow(): void
    {
        $change = SourceChange::dbUpdated(HilosDbContext::oauthProviders, '3', [
            EntityOAuthProvider::provider_key => OAuthProviderPreset::GITHUB->value,
            EntityOAuthProvider::client_id => 'admin-client',
        ]);

        $providerMutation = $this->providersTable()->buildMutationForSourceEvent($change);
        self::assertNotNull($providerMutation);
        self::assertSame(TableMutationType::Update, $providerMutation->type);
        self::assertSame(OAuthProviderPreset::GITHUB->value, $providerMutation->rowKey);

        $fieldMutation = $this->fieldsTable()->buildMutationForSourceEvent($change);
        self::assertNotNull($fieldMutation);
        self::assertInstanceOf(HilosSecurityOAuthProviderFieldsTableRow::class, $fieldMutation->row);
        self::assertSame(OAuthConfigField::CLIENT_ID->value, $fieldMutation->row->field);
    }

    public function testAFreshProviderRowCreatedForItsSecretRedrawsTheSecretRow(): void
    {
        $change = SourceChange::dbCreated(HilosDbContext::oauthProviders, '3', [
            EntityOAuthProvider::provider_key => OAuthProviderPreset::GITHUB->value,
            EntityOAuthProvider::client_id => null,
            EntityOAuthProvider::scope => null,
        ]);

        $mutation = $this->fieldsTable()->buildMutationForSourceEvent($change);
        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        self::assertInstanceOf(HilosSecurityOAuthProviderFieldsTableRow::class, $mutation->row);
        self::assertSame(OAuthConfigField::CLIENT_SECRET->value, $mutation->row->field);
    }

    public function testAFreshProviderRowCreatedWithAClientIdRedrawsTheClientIdRow(): void
    {
        $change = SourceChange::dbCreated(HilosDbContext::oauthProviders, '3', [
            EntityOAuthProvider::provider_key => OAuthProviderPreset::GITHUB->value,
            EntityOAuthProvider::client_id => 'admin-client',
            EntityOAuthProvider::scope => null,
        ]);

        $mutation = $this->fieldsTable()->buildMutationForSourceEvent($change);
        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        self::assertInstanceOf(HilosSecurityOAuthProviderFieldsTableRow::class, $mutation->row);
        self::assertSame(OAuthConfigField::CLIENT_ID->value, $mutation->row->field);
    }

    public function testADeletedProviderRowRedrawsNoField(): void
    {
        $change = SourceChange::dbDeleted(HilosDbContext::oauthProviders, '3', [
            EntityOAuthProvider::provider_key => OAuthProviderPreset::GITHUB->value,
        ]);

        self::assertNull($this->fieldsTable()->buildMutationForSourceEvent($change));
    }

    public function testAChangeOfAnotherCollectionIsIgnored(): void
    {
        $change = SourceChange::dbUpdated(HilosDbContext::settings, '3', [ObjectSetting::key => 'unrelated']);

        self::assertNull($this->providersTable()->buildMutationForSourceEvent($change));
        self::assertNull($this->fieldsTable()->buildMutationForSourceEvent($change));
        self::assertNull(new HilosSecurityOAuthRedirectTable()->buildMutationForSourceEvent($change));
    }

    public function testTheReturnAddressTableHasItsOneRow(): void
    {
        $rows = new HilosSecurityOAuthRedirectTable()->getFullSnapshot()->rows;

        self::assertCount(1, $rows);
        self::assertInstanceOf(HilosSecurityOAuthRedirectTableRow::class, $rows[0]);
        self::assertSame(OAuthSettingsCatalog::REDIRECT_URI_KEY, $rows[0]->rowKey);
        self::assertSame(OAuthConfigSource::DEFAULT->value, $rows[0]->source);
        self::assertFalse($rows[0]->setState);
    }

    public function testAChangeOfTheReturnAddressSettingRedrawsItsRow(): void
    {
        $change = SourceChange::dbCreated(HilosDbContext::settings, '9', [ObjectSetting::key => OAuthSettingsCatalog::REDIRECT_URI_KEY]);

        $mutation = new HilosSecurityOAuthRedirectTable()->buildMutationForSourceEvent($change);

        self::assertNotNull($mutation);
        self::assertSame(OAuthSettingsCatalog::REDIRECT_URI_KEY, $mutation->rowKey);
    }

    /**
     * @param string $providerKey Provider key
     * @return HilosSecurityOAuthProvidersTableRow The provider's row
     */
    private function providerRow(string $providerKey): HilosSecurityOAuthProvidersTableRow
    {
        foreach ($this->providersTable()->getFullSnapshot()->rows as $row) {
            if ($row instanceof HilosSecurityOAuthProvidersTableRow && $row->providerKey === $providerKey) {
                return $row;
            }
        }

        self::fail("No providers-table row for {$providerKey}");
    }

    /**
     * @param string $providerKey Provider key
     * @param OAuthConfigField $field Field
     * @return HilosSecurityOAuthProviderFieldsTableRow The field's row
     */
    private function fieldRow(string $providerKey, OAuthConfigField $field): HilosSecurityOAuthProviderFieldsTableRow
    {
        foreach ($this->fieldsTable()->getFullSnapshot()->rows as $row) {
            if ($row instanceof HilosSecurityOAuthProviderFieldsTableRow && $row->providerKey === $providerKey && $row->field === $field->value) {
                return $row;
            }
        }

        self::fail("No fields-table row for {$providerKey} {$field->value}");
    }

    /**
     * @return HilosSecurityOAuthProvidersTable Providers table over the two test providers
     */
    private function providersTable(): HilosSecurityOAuthProvidersTable
    {
        return new class (self::descriptors()) extends HilosSecurityOAuthProvidersTable {
            /** @param array<string, OAuthProviderDescriptor> $fixture */
            public function __construct(private readonly array $fixture)
            {
                parent::__construct();
            }

            protected function providers(): array
            {
                return $this->fixture;
            }
        };
    }

    /**
     * @return HilosSecurityOAuthProviderFieldsTable Fields table over the two test providers
     */
    private function fieldsTable(): HilosSecurityOAuthProviderFieldsTable
    {
        return new class (self::descriptors()) extends HilosSecurityOAuthProviderFieldsTable {
            /** @param array<string, OAuthProviderDescriptor> $fixture */
            public function __construct(private readonly array $fixture)
            {
                parent::__construct();
            }

            protected function providers(): array
            {
                return $this->fixture;
            }
        };
    }

    /**
     * @return array<string, OAuthProviderDescriptor> GitHub over the stand-in env variables, Google over nothing
     */
    private static function descriptors(): array
    {
        return [
            OAuthProviderPreset::GITHUB->value => OAuthProviderDescriptor::fromPreset(
                OAuthProviderPreset::GITHUB,
                'GitHub',
                self::CLIENT_ID_ENV,
                self::CLIENT_SECRET_ENV,
            ),
            OAuthProviderPreset::GOOGLE->value => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GOOGLE, 'Google'),
        ];
    }
}

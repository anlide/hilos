<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractSessionsLibraryAgentDaemon;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgentDaemon;
use Hilos\Auth\Method\AuthMethodDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Context\HilosDbContext;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\Hilos as HilosFacade;
use Hilos\Pages\AbstractHilosProfileDataPage;
use Hilos\Pages\AbstractHilosProfilePage;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestClosedDirectory;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestProviderDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Pins the real Auth feature definition and its startup refusal contract.
 *
 * Among the refusals, a closed registration beside a method whose sign-in opens an account by
 * itself (HIL-2185): the directories are read through the captured project facade, so each
 * such case mounts its facade and the tear-down puts the base one back.
 */
final class AuthFeatureActivationTest extends TestCase
{
    /**
     * Restores the base facade so a later test sees the process as it found it.
     */
    protected function tearDown(): void
    {
        HilosFacade::initBrowser();
        HilosFacade::resetBrowser();

        parent::tearDown();
    }

    public function testDefinitionRequiresProfileAndProfileDataPages(): void
    {
        $definition = (new FeatureRegistry())->definition(HilosFeature::AUTH);

        self::assertInstanceOf(AuthFeature::class, $definition);
        self::assertSame([
            AbstractHilosProfilePage::class,
            AbstractHilosProfileDataPage::class,
        ], $definition->requirements()->requiredPages);
    }

    public function testMissingBothPagesRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::AUTH is declared but no page in PAGES extends '
            . AbstractHilosProfilePage::class);

        AuthFeatureMissingBothPagesHilos::validateFeatureActivation();
    }

    public function testMissingProfilePageRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::AUTH is declared but no page in PAGES extends '
            . AbstractHilosProfilePage::class);

        AuthFeatureMissingProfilePageHilos::validateFeatureActivation();
    }

    public function testMissingProfileDataPageRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::AUTH is declared but no page in PAGES extends '
            . AbstractHilosProfileDataPage::class);

        AuthFeatureMissingProfileDataPageHilos::validateFeatureActivation();
    }

    public function testCompleteAuthFeaturePassesStartupValidation(): void
    {
        AuthFeatureCompleteHilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    /** An SMS code opens the account of a number it does not find, which a closed registration does not cover. */
    public function testClosedRegistrationWithSmsRefusesStartup(): void
    {
        AuthFeatureClosedWithSmsHilos::initBrowser();

        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('Registration is closed, but the method directory wires ' . AuthMethodKey::SMS
            . ', whose sign-in opens an account by itself');

        AuthFeatureClosedWithSmsHilos::checkClosedRegistration();
    }

    /** A provider sign-in opens the account of a person it does not find, just as an SMS code does. */
    public function testClosedRegistrationWithAProviderRefusesStartup(): void
    {
        AuthFeatureClosedWithProviderHilos::initBrowser();

        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('Registration is closed, but the method directory wires ' . OAuthProviderPreset::GITHUB->value
            . ', whose sign-in opens an account by itself');

        AuthFeatureClosedWithProviderHilos::checkClosedRegistration();
    }

    /** A password opens no account by itself; providers the project declares and does not wire do not count. */
    public function testClosedRegistrationWithAPasswordAlonePassesStartup(): void
    {
        AuthFeatureClosedWithPasswordHilos::initBrowser();

        AuthFeatureClosedWithPasswordHilos::checkClosedRegistration();

        $this->addToAssertionCount(1);
    }
}

/** Complete, otherwise empty application declaration for the real auth feature. */
class AuthFeatureCompleteHilos extends HilosFacade
{
    protected const array FEATURES = [HilosFeature::AUTH];

    public const array PAGES = [
        AuthFeatureTestProfilePage::PAGE => AuthFeatureTestProfilePage::class,
        AuthFeatureTestProfileDataPage::PAGE => AuthFeatureTestProfileDataPage::class,
    ];

    public const array AGENTS = [
        HilosAgentType::HILOS_USERS_LIBRARY => [
            AgentRegistryKey::WORKER => AuthFeatureTestUsersLibraryAgent::class,
            AgentRegistryKey::DAEMON => AuthFeatureTestUsersLibraryAgentDaemon::class,
        ],
        HilosAgentType::HILOS_DATA_EXPORT => [
            AgentRegistryKey::WORKER => AuthFeatureTestDataExportAgent::class,
            AgentRegistryKey::DAEMON => DataExportAgentDaemon::class,
        ],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [
            AgentRegistryKey::WORKER => AuthFeatureTestSessionsLibraryAgent::class,
            AgentRegistryKey::DAEMON => AuthFeatureTestSessionsLibraryAgentDaemon::class,
        ],
    ];

    public const array TABLES = [];

    public const array PAGE_TABLES = [];

    /** @return HilosDbContext Unused database context required by the facade contract */
    protected static function createDb(): HilosDbContext
    {
        return new AuthFeatureTestDbContext();
    }
}

final class AuthFeatureTestDbContext extends HilosDbContext
{
    /** No DB collections are needed for this activation test. */
    public function configure(): void
    {
    }
}

final class AuthFeatureTestProfilePage extends AbstractHilosProfilePage
{
}

final class AuthFeatureTestProfileDataPage extends AbstractHilosProfileDataPage
{
}

final class AuthFeatureTestUsersLibraryAgent extends AbstractUsersLibraryAgent
{
}

final class AuthFeatureTestUsersLibraryAgentDaemon extends AbstractUsersLibraryAgentDaemon
{
}

final class AuthFeatureTestDataExportAgent extends AbstractDataExportAgent
{
}

final class AuthFeatureTestSessionsLibraryAgent extends AbstractSessionsLibraryAgent
{
}

final class AuthFeatureTestSessionsLibraryAgentDaemon extends AbstractSessionsLibraryAgentDaemon
{
}

final class AuthFeatureMissingBothPagesHilos extends AuthFeatureCompleteHilos
{
    public const array PAGES = [];
}

final class AuthFeatureMissingProfilePageHilos extends AuthFeatureCompleteHilos
{
    public const array PAGES = [
        AuthFeatureTestProfileDataPage::PAGE => AuthFeatureTestProfileDataPage::class,
    ];
}

final class AuthFeatureMissingProfileDataPageHilos extends AuthFeatureCompleteHilos
{
    public const array PAGES = [
        AuthFeatureTestProfilePage::PAGE => AuthFeatureTestProfilePage::class,
    ];
}

/** Opens the start check of a closed registration to the fixtures below. */
abstract class AuthFeatureClosedRegistrationHilos extends HilosFacade
{
    protected const array FEATURES = [HilosFeature::AUTH];

    protected const string OAUTH_PROVIDER_DIRECTORY = AuthMethodTestProviderDirectory::class;

    /**
     * @throws IncompleteFeatureActivationException When the fixture closes registration and wires SMS or a provider
     */
    public static function checkClosedRegistration(): void
    {
        static::refuseClosedRegistrationWithSelfRegisteringMethods();
    }
}

/** Closed registration with a password and an SMS code. */
abstract class AuthFeatureClosedWithSmsHilos extends AuthFeatureClosedRegistrationHilos
{
    protected const string AUTH_METHOD_DIRECTORY = AuthFeatureClosedWithSmsDirectory::class;
}

/** Closed registration with a password and the GitHub provider. */
abstract class AuthFeatureClosedWithProviderHilos extends AuthFeatureClosedRegistrationHilos
{
    protected const string AUTH_METHOD_DIRECTORY = AuthFeatureClosedWithProviderDirectory::class;
}

/** Closed registration with a password alone. */
abstract class AuthFeatureClosedWithPasswordHilos extends AuthFeatureClosedRegistrationHilos
{
    protected const string AUTH_METHOD_DIRECTORY = AuthMethodTestClosedDirectory::class;
}

/** Registers nobody, and wires an SMS code beside the password. */
final class AuthFeatureClosedWithSmsDirectory extends AuthMethodDirectory
{
    /** @return list<string> Method keys in button order */
    protected static function methods(): array
    {
        return [...parent::methods(), AuthMethodKey::PASSWORD, AuthMethodKey::SMS];
    }

    /** @return bool Always false: this project registers nobody */
    protected static function registers(): bool
    {
        return false;
    }
}

/** Registers nobody, and wires one declared provider beside the password. */
final class AuthFeatureClosedWithProviderDirectory extends AuthMethodDirectory
{
    /** @return list<string> Method keys in button order */
    protected static function methods(): array
    {
        return [...parent::methods(), AuthMethodKey::PASSWORD, OAuthProviderPreset::GITHUB->value];
    }

    /** @return bool Always false: this project registers nobody */
    protected static function registers(): bool
    {
        return false;
    }
}

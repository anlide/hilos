<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractSessionsLibraryAgentDaemon;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgentDaemon;
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
use PHPUnit\Framework\TestCase;

/** Pins the real Auth feature definition and its startup refusal contract. */
final class AuthFeatureActivationTest extends TestCase
{
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

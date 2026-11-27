<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Feature\Definition\I18nFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Country;
use Hilos\Database\Entity\Item\CountryName;
use Hilos\Database\Entity\Item\I18nReflow;
use Hilos\Database\Entity\Item\Language;
use Hilos\Database\Entity\Item\LanguageName;
use Hilos\Database\Entity\Item\Locale;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Groups\AbstractHilosLanguagesGroup;
use Hilos\Hilos as HilosFacade;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\Library\I18nLibraryAgentDaemon;
use Hilos\Pages\AbstractHilosI18nPage;
use Hilos\Pages\I18n\Lists\AbstractHilosI18nCountriesListPage;
use Hilos\Pages\I18n\Lists\AbstractHilosI18nLanguagesListPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pins the framework i18n feature definition and its activation refusals. */
final class I18nFeatureActivationTest extends TestCase
{
    public function testDefinitionRequiresThreePagesOneAgentOneGroupAndSixTables(): void
    {
        $definition = (new FeatureRegistry())->definition(HilosFeature::I18N);
        $requirements = $definition->requirements();

        self::assertInstanceOf(I18nFeature::class, $definition);
        self::assertSame([
            AbstractHilosI18nPage::class,
            AbstractHilosI18nLanguagesListPage::class,
            AbstractHilosI18nCountriesListPage::class,
        ], $requirements->requiredPages);
        self::assertSame([HilosAgentType::HILOS_I18N_LIBRARY], $requirements->requiredAgents);
        self::assertSame([AbstractHilosLanguagesGroup::class], $requirements->requiredGroups);
        self::assertSame([
            Language::_table,
            Country::_table,
            Locale::_table,
            LanguageName::_table,
            CountryName::_table,
            I18nReflow::_table,
        ], $requirements->requiredDbTables);
        self::assertSame([], $requirements->requiredTables);
        self::assertSame([], $requirements->requiredPageTables);
    }

    public function testCompleteFeaturePassesStartupValidation(): void
    {
        I18nFeatureCompleteHilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testMissingPageRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::I18N is declared but no page in PAGES extends '
            . AbstractHilosI18nCountriesListPage::class);

        I18nFeatureMissingPageHilos::validateFeatureActivation();
    }

    public function testMissingAgentRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::I18N is declared but agent '
            . HilosAgentType::HILOS_I18N_LIBRARY . ' is not registered in AGENTS');

        I18nFeatureMissingAgentHilos::validateFeatureActivation();
    }

    public function testMissingGroupRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(AbstractHilosLanguagesGroup::class);

        I18nFeatureMissingGroupHilos::validateFeatureActivation();
    }

    public function testRegistrationWithoutFeatureRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('AGENTS registers ' . HilosAgentType::HILOS_I18N_LIBRARY
            . ' but HilosFeature::I18N is not declared in FEATURES');

        I18nFeatureUndeclaredHilos::validateFeatureActivation();
    }

    /** @return iterable<string, array{?FsContext}> Filesystem states without an ip_country directory */
    public static function missingIpCountryDirectory(): iterable
    {
        yield 'no filesystem' => [null];
        yield 'no ip_country directory' => [new I18nFeatureFsContext()];
    }

    /** @param ?FsContext $context Filesystem without the ip_country directory */
    #[DataProvider('missingIpCountryDirectory')]
    public function testMissingIpCountryDirectoryIsRefusedOnlyWhenDeclared(?FsContext $context): void
    {
        $previousFs = HilosFacade::$fs;
        HilosFacade::$fs = $context;
        try {
            I18nFeatureUndeclaredHilos::checkIpCountryDirectory();
            $this->expectException(IncompleteFeatureActivationException::class);
            $this->expectExceptionMessage('HilosFeature::I18N reads the country of a connection from the ip_country directory');
            I18nFeatureCompleteHilos::checkIpCountryDirectory();
        } finally {
            HilosFacade::$fs = $previousFs;
        }
    }

    public function testRegisteredIpCountryDirectoryPassesWithoutCreatingIt(): void
    {
        $previousFs = HilosFacade::$fs;
        HilosFacade::$fs = new I18nFeatureFsContext();
        HilosFacade::$fs->configure();
        try {
            I18nFeatureCompleteHilos::checkIpCountryDirectory();
            self::assertSame('/uncreated/ip_country', HilosFacade::$fs->ip_country->getPath());
            self::assertSame([], HilosFacade::$fs->declarationErrors());
            self::assertDirectoryDoesNotExist('/uncreated/ip_country');
        } finally {
            HilosFacade::$fs = $previousFs;
        }
    }
}

/** Complete test-only registration of the i18n feature. */
class I18nFeatureCompleteHilos extends HilosFacade
{
    protected const array FEATURES = [HilosFeature::I18N];

    public const array PAGES = [
        I18nFeatureRootPage::PAGE => I18nFeatureRootPage::class,
        I18nFeatureLanguagesPage::PAGE => I18nFeatureLanguagesPage::class,
        I18nFeatureCountriesPage::PAGE => I18nFeatureCountriesPage::class,
    ];

    public const array GROUPS = [
        I18nFeatureLanguagesGroup::GROUP => I18nFeatureLanguagesGroup::class,
    ];

    public const array AGENTS = [
        HilosAgentType::HILOS_I18N_LIBRARY => [
            AgentRegistryKey::WORKER => I18nLibraryAgent::class,
            AgentRegistryKey::DAEMON => I18nLibraryAgentDaemon::class,
        ],
    ];

    /** @return HilosDbContext Unused context required by the facade contract */
    protected static function createDb(): HilosDbContext
    {
        return new I18nFeatureTestDbContext();
    }

    /** Checks the ip_country directory against the fixture context. */
    public static function checkIpCountryDirectory(): void
    {
        static::refuseI18nWithoutIpCountryDirectory();
    }
}

final class I18nFeatureTestDbContext extends HilosDbContext
{
    /** No collections are read by the activation test. */
    public function configure(): void
    {
    }
}

final class I18nFeatureFsContext extends FsContext
{
    /** Registers an intentionally uncreated shared ip_country path. */
    public function configure(): void
    {
        $this->registerDirectory(self::IP_COUNTRY, '/uncreated/ip_country', DirectoryScope::CLUSTER);
    }
}

final class I18nFeatureRootPage extends AbstractHilosI18nPage
{
}

final class I18nFeatureLanguagesPage extends AbstractHilosI18nLanguagesListPage
{
}

final class I18nFeatureCountriesPage extends AbstractHilosI18nCountriesListPage
{
}

final class I18nFeatureMissingPageHilos extends I18nFeatureCompleteHilos
{
    public const array PAGES = [
        I18nFeatureRootPage::PAGE => I18nFeatureRootPage::class,
        I18nFeatureLanguagesPage::PAGE => I18nFeatureLanguagesPage::class,
    ];
}

final class I18nFeatureMissingAgentHilos extends I18nFeatureCompleteHilos
{
    public const array AGENTS = [];
}

final class I18nFeatureUndeclaredHilos extends I18nFeatureCompleteHilos
{
    protected const array FEATURES = [];
}

final class I18nFeatureMissingGroupHilos extends I18nFeatureCompleteHilos
{
    public const array GROUPS = [];
}

final class I18nFeatureLanguagesGroup extends AbstractHilosLanguagesGroup
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_I18N_LIBRARY;
}

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
use Hilos\Database\Entity\Item\Language;
use Hilos\Database\Entity\Item\LanguageName;
use Hilos\Database\Entity\Item\Locale;
use Hilos\Hilos as HilosFacade;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\Library\I18nLibraryAgentDaemon;
use Hilos\Pages\AbstractHilosI18nPage;
use Hilos\Pages\I18n\Lists\AbstractHilosI18nCountriesListPage;
use Hilos\Pages\I18n\Lists\AbstractHilosI18nLanguagesListPage;
use PHPUnit\Framework\TestCase;

/** Pins the framework i18n feature definition and its activation refusals. */
final class I18nFeatureActivationTest extends TestCase
{
    public function testDefinitionRequiresThreePagesOneAgentAndFiveTables(): void
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
        self::assertSame([
            Language::_table,
            Country::_table,
            Locale::_table,
            LanguageName::_table,
            CountryName::_table,
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

    public function testRegistrationWithoutFeatureRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('AGENTS registers ' . HilosAgentType::HILOS_I18N_LIBRARY
            . ' but HilosFeature::I18N is not declared in FEATURES');

        I18nFeatureUndeclaredHilos::validateFeatureActivation();
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
}

final class I18nFeatureTestDbContext extends HilosDbContext
{
    /** No collections are read by the activation test. */
    public function configure(): void
    {
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

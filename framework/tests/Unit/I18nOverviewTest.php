<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Object\Collection\Countries as ObjectCountries;
use Hilos\Database\Object\Collection\Languages as ObjectLanguages;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\Object\Item\Language as ObjectLanguage;
use Hilos\Database\View\Collection\Countries;
use Hilos\Database\View\Collection\Languages;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\I18n\DTO\CountryOverviewTally;
use Hilos\I18n\DTO\I18nOverviewData;
use Hilos\I18n\DTO\LanguageOverviewTally;
use Hilos\Pages\AbstractHilosI18nPage;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** Exercises the real read collections, DTO and first page answer without a database. */
final class I18nOverviewTest extends TestCase
{
    private ?HilosDbContext $previousDb;
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private string $previousHilosClass;
    private string|false $previousDefaultLanguage;
    private ObjectLanguages $languageObjects;
    private ObjectCountries $countryObjects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousHilosClass = Hilos::appClass();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new I18nOverviewTestRouter();
        I18nOverviewTestHilos::initBrowser(new I18nOverviewTestBrowser());

        $this->languageObjects = ObjectLanguages::initEmpty();
        $this->countryObjects = ObjectCountries::initEmpty();
        $languages = Languages::init();
        $languages->setObjectCollection($this->languageObjects);
        $countries = Countries::init();
        $countries->setObjectCollection($this->countryObjects);
        Hilos::$db = $this->createStub(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturnMap([
            [HilosDbContext::languages, $languages],
            [HilosDbContext::countries, $countries],
        ]);

        $this->addLanguage(11, 'en', 'English in this project', true);
        $this->addLanguage(12, 'fr', 'Français', false);
        $this->addLanguage(13, 'qx', 'Project language', true);
        $this->addLanguage(14, 'ru', 'Русский', false);
        $this->addCountry(21, 'us', false);
        $this->addCountry(22, 'gb', true);
        $this->addCountry(23, 'qx', true);
    }

    protected function tearDown(): void
    {
        if ($this->previousDefaultLanguage === false) {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        } else {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
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

    public function testTalliesCountInstalledRowsAndUseTheDefaultRowsCurrentNativeName(): void
    {
        $overview = I18nOverviewData::fromCollections();

        self::assertInstanceOf(LanguageOverviewTally::class, $overview->languages);
        self::assertInstanceOf(CountryOverviewTally::class, $overview->countries);
        self::assertSame($this->expectedOverview(), $overview->toArray());
        self::assertGreaterThanOrEqual(0, $overview->languages->enabledCount);
        self::assertLessThanOrEqual($overview->languages->totalCount, $overview->languages->enabledCount);
        self::assertGreaterThanOrEqual(0, $overview->countries->enabledCount);
        self::assertLessThanOrEqual($overview->countries->totalCount, $overview->countries->enabledCount);
        self::assertSame($overview->countries->totalCount, $overview->countries->builtInCount + $overview->countries->ownCount);
    }

    public function testEveryReadRecomputesSwitchesMembershipAndTheCurrentDefaultName(): void
    {
        $before = I18nOverviewData::fromCollections();
        $this->languageObjects[11]->nativeName = 'Current English';
        $this->languageObjects[12]->enabled = true;
        $this->countryObjects[21]->enabled = true;
        $this->addCountry(24, 'qz', false);

        $after = I18nOverviewData::fromCollections();

        self::assertSame($this->expectedOverview(), $before->toArray());
        self::assertSame([
            'enabledCount' => 3, 'totalCount' => 4,
            'defaultLanguageCode' => 'en', 'defaultLanguageName' => 'Current English',
        ], $after->languages->toArray());
        self::assertSame([
            'enabledCount' => 3, 'totalCount' => 4, 'builtInCount' => 2, 'ownCount' => 2,
        ], $after->countries->toArray());
    }

    public function testTheConfiguredDefaultCodeSelectsItsInstalledRow(): void
    {
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=ru');
        Hilos::$env = new EnvAccessor();
        $this->languageObjects[14]->enabled = true;
        $this->languageObjects[14]->nativeName = 'Русский проекта';

        self::assertSame([
            'enabledCount' => 3, 'totalCount' => 4,
            'defaultLanguageCode' => 'ru', 'defaultLanguageName' => 'Русский проекта',
        ], I18nOverviewData::fromCollections()->languages->toArray());
    }

    public function testClearedCountriesProduceACompleteZeroTally(): void
    {
        I18nOverviewData::fromCollections();
        $this->countryObjects->clearInMemory();

        self::assertSame([
            'enabledCount' => 0, 'totalCount' => 0, 'builtInCount' => 0, 'ownCount' => 0,
        ], I18nOverviewData::fromCollections()->countries->toArray());
    }

    public function testClearedLanguagesRefuseTheMissingRequiredDefault(): void
    {
        I18nOverviewData::fromCollections();
        $this->languageObjects->clearInMemory();

        $this->expectException(ItemNotFoundException::class);
        I18nOverviewData::fromCollections();
    }

    public function testOtherLanguagesDoNotReplaceAMissingDefaultRow(): void
    {
        unset($this->languageObjects[11]);

        $this->expectException(ItemNotFoundException::class);
        I18nOverviewData::fromCollections();
    }

    public function testTheFirstSubscribeAnswersOnceWithTheWholeOverviewAndIdentity(): void
    {
        self::assertSame([HilosDbContext::languages, HilosDbContext::countries], I18nOverviewTestPage::READS_DB);
        $this->assertFirstAnswer();
    }

    public function testTheViewerReceivesTheSameCompleteNonpersonalOverview(): void
    {
        I18nOverviewTestHilos::initBrowser(new I18nOverviewTestBrowser(viewer: true));

        $this->assertFirstAnswer(viewer: true);
    }

    public function testTheFirstSubscribeQueuesNoIncompleteAnswerWhenDefaultIsMissing(): void
    {
        $this->languageObjects->clearInMemory();
        try {
            $this->page()->onSubscribe('overview-reader', new PageRouteParams([]));
            self::fail('A missing default language must refuse delivery.');
        } catch (ItemNotFoundException) {
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    /** @return array<string, array<string, int|string>> Exact installation fixture summary */
    private function expectedOverview(): array
    {
        return [
            'languages' => [
                'enabledCount' => 2, 'totalCount' => 4,
                'defaultLanguageCode' => 'en', 'defaultLanguageName' => 'English in this project',
            ],
            'countries' => ['enabledCount' => 2, 'totalCount' => 3, 'builtInCount' => 2, 'ownCount' => 1],
        ];
    }

    /** @param bool $viewer Whether identity precedes page data in the viewer answer */
    private function assertFirstAnswer(bool $viewer = false): void
    {
        $this->page()->onSubscribe('overview-reader', new PageRouteParams([]));
        $frame = Hilos::$sr->getNextQueuedSignal();

        self::assertNotNull($frame);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frame->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $frame->data);
        self::assertSame('overview-reader', $frame->data->targetAcceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $frame->data->data);
        $identity = [
            'pageLabel' => 'Internationalization',
            'pageLead' => 'Languages, countries, locales, and translation screens.',
            'pageBreadcrumb' => [
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Internationalization'],
            ],
            'pageChildren' => [],
            'pageHeadOwner' => [
                'page' => HilosPageConstants::HILOS_I18N,
                'label' => 'Internationalization',
                'lead' => 'Languages, countries, locales, and translation screens.',
                'icon' => 'bi-translate',
            ],
            'pageTabs' => [
                ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_I18N_LANGUAGES, 'label' => 'Languages'],
                ['page' => HilosPageConstants::HILOS_I18N_COUNTRIES, 'label' => 'Countries'],
            ],
        ];
        $overview = [I18nOverviewData::DATA => $this->expectedOverview()];
        self::assertSame([
            'page' => HilosPageConstants::HILOS_I18N,
            'payload' => ['data' => $viewer ? $identity + $overview : $overview + $identity],
        ], $frame->data->data->toArray());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return I18nOverviewTestPage Real page served by a minimal agent double */
    private function page(): I18nOverviewTestPage
    {
        $agent = $this->createStub(PageAgentInterface::class);
        $agent->method('getId')->willReturn('overview-agent');
        $agent->method('getAgentSignalSource')->willReturn(new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_I18N_LIBRARY));
        return new I18nOverviewTestPage($agent);
    }

    /**
     * @param int $id Fixture primary id
     * @param string $code Language code
     * @param string $nativeName Current row name
     * @param bool $enabled Language switch
     */
    private function addLanguage(int $id, string $code, string $nativeName, bool $enabled): void
    {
        $entity = new EntityLanguage();
        $entity->id = $id;
        $entity->code = $code;
        $entity->native_name = $nativeName;
        $entity->enabled = $enabled;
        $this->languageObjects[$id] = ObjectLanguage::fromEntity($entity);
    }

    /**
     * @param int $id Fixture primary id
     * @param string $code Country code
     * @param bool $enabled Country switch
     */
    private function addCountry(int $id, string $code, bool $enabled): void
    {
        $entity = new EntityCountry();
        $entity->id = $id;
        $entity->code = $code;
        $entity->currency_symbol = '¤';
        $entity->currency_code = 'XXX';
        $entity->enabled = $enabled;
        $this->countryObjects[$id] = ObjectCountry::fromEntity($entity);
    }
}

/** Registers exactly the implemented section tabs for the first-answer test. */
abstract class I18nOverviewTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_I18N => I18nOverviewTestPage::class,
        HilosPageConstants::HILOS_I18N_LANGUAGES => I18nOverviewTestPage::class,
        HilosPageConstants::HILOS_I18N_COUNTRIES => I18nOverviewTestPage::class,
    ];
}

final class I18nOverviewTestPage extends AbstractHilosI18nPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_I18N_LIBRARY;
}

/** Uses the test facade for normal served-page decisions. */
final class I18nOverviewTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Facade bound by this test */
    protected function hilosClass(): string
    {
        return Hilos::appClass();
    }
}

/** Makes the viewer verdict explicit without starting runtime state. */
final class I18nOverviewTestBrowser extends IdentityTestBrowser
{
    /** @param bool $viewer Whether the answer passes through the viewer field filter */
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

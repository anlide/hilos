<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nLanguageAddActionDTO;

/** The languages list adds a switched-off language and its catalog country names atomically. */
final class I18nLanguageAddTest extends IntegrationTestCase
{
    private I18nLibraryAgent $agent;

    /** @var list<string> Countries created only for these cases */
    private array $createdCountryCodes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $this->agent = new I18nLibraryAgent();
        foreach ([
            HilosDbContext::languages, HilosDbContext::countries, HilosDbContext::locales,
            HilosDbContext::languageNames, HilosDbContext::countryNames,
        ] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $this->agent->getId());
        }
        $this->clearFixtures();
        $this->underAgent($this->agent, function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            foreach (['us', 'pl'] as $code) {
                if (!isset(Hilos::$db->countries[$code])) {
                    $definition = BuiltInI18nCatalog::country($code);
                    self::assertNotNull($definition);
                    Hilos::$db->countries->actions->create($code, $definition->currencySymbol, $definition->currencyCode);
                    $this->createdCountryCodes[] = $code;
                }
            }
            if (!isset(Hilos::$db->countries['gg'])) {
                Hilos::$db->countries->actions->create('gg', '£', 'GBP');
                $this->createdCountryCodes[] = 'gg';
            }
        });
    }

    protected function tearDown(): void
    {
        $this->clearFixtures();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testKnownLanguageKeepsTypedValuesAndGetsUnlockedCatalogCountryNames(): void
    {
        $success = $this->addThroughPage('pt', 'Português editado', true);
        self::assertSame('Language Português editado (pt) added and switched off for now.', $success->message);
        self::assertArrayHasKey('pt', Hilos::$db->languages);
        self::assertFalse(Hilos::$db->languages['pt']->enabled);
        self::assertSame('Português editado', Hilos::$db->languages['pt']->nativeName);
        self::assertTrue(Hilos::$db->languages['pt']->rtl);

        $expectedNames = 0;
        foreach (BuiltInI18nCatalog::countries() as $definition) {
            $expectedName = BuiltInI18nCatalog::countryName($definition->code, 'pt');
            if (!isset(Hilos::$db->countries[$definition->code]) || $expectedName === null) {
                continue;
            }
            $name = Hilos::$db->countryNames->findBase(Hilos::$db->countries[$definition->code]->id, Hilos::$db->languages['pt']->id);
            self::assertNotNull($name, $definition->code);
            self::assertSame($expectedName, $name->name);
            self::assertFalse($name->locked);
            self::assertNull($name->localeId);
            $expectedNames++;
        }
        self::assertGreaterThanOrEqual(2, $expectedNames);
        Database::sql('SELECT COUNT(*) AS `count` FROM hilos_country_name WHERE language_id = ?', [Hilos::$db->languages['pt']->id]);
        self::assertSame($expectedNames, (int) Database::field('count'));
        self::assertNull(Hilos::$db->countryNames->findBase(Hilos::$db->countries['gg']->id, Hilos::$db->languages['pt']->id));
    }

    public function testCustomLanguageStartsSwitchedOffWithoutCountryNames(): void
    {
        self::assertNull(BuiltInI18nCatalog::language('cy'));
        $success = $this->addThroughPage('cy', 'Cymraeg', false);
        self::assertSame('Custom language Cymraeg (cy) added and switched off for now.', $success->message);
        self::assertArrayHasKey('cy', Hilos::$db->languages);
        self::assertSame('Cymraeg', Hilos::$db->languages['cy']->nativeName);
        self::assertFalse(Hilos::$db->languages['cy']->rtl);
        self::assertFalse(Hilos::$db->languages['cy']->enabled);
        Database::sql('SELECT COUNT(*) AS `count` FROM hilos_country_name WHERE language_id = ?', [Hilos::$db->languages['cy']->id]);
        self::assertSame(0, (int) Database::field('count'));
    }

    public function testDuplicateLanguageIsRefusedWithoutWritingAnything(): void
    {
        $languageId = Hilos::$db->languages['en']->id;
        Database::sql('SELECT COUNT(*) AS `count` FROM hilos_country_name');
        $nameCount = (int) Database::field('count');
        Database::sql('SELECT COUNT(*) AS `count` FROM hilos_language');
        $languageCount = (int) Database::field('count');
        $page = new LanguagesListPage($this->agent);
        try {
            $this->underAgent($this->agent, static function () use ($page): void {
                $page->onAction('language-ak', HilosSignalConstants::HILOS_I18N_LANGUAGE_ADD,
                    new HilosI18nLanguageAddActionDTO('en', 'Changed English', true));
            });
            self::fail('A language code may be added only once');
        } catch (ValidationException $error) {
            self::assertSame("Language 'en' already exists.", $error->getMessage());
        }
        self::assertSame($languageId, Hilos::$db->languages['en']->id);
        self::assertSame('English', Hilos::$db->languages['en']->nativeName);
        self::assertFalse(Hilos::$db->languages['en']->rtl);
        Database::sql('SELECT COUNT(*) AS `count` FROM hilos_country_name');
        self::assertSame($nameCount, (int) Database::field('count'));
        Database::sql('SELECT COUNT(*) AS `count` FROM hilos_language');
        self::assertSame($languageCount, (int) Database::field('count'));
    }

    public function testCountryNameFailureRollsBackTheLanguageAndNamesAndClosesTheTransaction(): void
    {
        $allowedCountryId = null;
        foreach (BuiltInI18nCatalog::countries() as $definition) {
            if (isset(Hilos::$db->countries[$definition->code])) {
                $allowedCountryId = Hilos::$db->countries[$definition->code]->id;
                break;
            }
        }
        self::assertNotNull($allowedCountryId);
        Database::sql('SELECT COUNT(*) AS `count` FROM hilos_country_name');
        $nameCount = (int) Database::field('count');
        TruthSourceRegistry::register(HilosDbContext::countryNames, TruthSourceKeys::set((string) $allowedCountryId), $this->agent->getId());
        $page = new LanguagesListPage($this->agent);
        try {
            $this->underAgent($this->agent, static function () use ($page): void {
                $page->onAction('language-ak', HilosSignalConstants::HILOS_I18N_LANGUAGE_ADD,
                    new HilosI18nLanguageAddActionDTO('pt', 'Português', false));
            });
            self::fail('A refused country-name write must fail the whole addition');
        } catch (CreateNotAllowedException) {
            self::assertArrayNotHasKey('pt', Hilos::$db->languages);
            Database::sql('SELECT COUNT(*) AS `count` FROM hilos_language WHERE code = ?', ['pt']);
            self::assertSame(0, (int) Database::field('count'));
            Database::sql('SELECT COUNT(*) AS `count` FROM hilos_country_name');
            self::assertSame($nameCount, (int) Database::field('count'));
        } finally {
            TruthSourceRegistry::register(HilosDbContext::countryNames, TruthSourceKeys::all(), $this->agent->getId());
        }

        self::assertSame('Language Português (pt) added and switched off for now.', $this->addThroughPage('pt', 'Português', false)->message);
    }

    /**
     * @param string $code Language code
     * @param string $nativeName Name typed by the person
     * @param bool $rtl Typed writing direction
     * @return PageActionSuccessSignalData Tracked action acknowledgement
     */
    private function addThroughPage(string $code, string $nativeName, bool $rtl): PageActionSuccessSignalData
    {
        $page = new LanguagesListPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $code, $nativeName, $rtl): void {
            $page->beginActionDispatch('language-req');
            self::assertNull($page->onAction('language-ak', HilosSignalConstants::HILOS_I18N_LANGUAGE_ADD,
                new HilosI18nLanguageAddActionDTO($code, $nativeName, $rtl)));
            $page->sendActionSuccess('language-ak', HilosSignalConstants::HILOS_I18N_LANGUAGE_ADD, 'language-req');
            $page->endActionDispatch();
        });
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === SignalConstants::ACTION_SUCCESS) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame('language-ak', $signal->data->targetAcceptKey);
                self::assertInstanceOf(PageActionSuccessSignalData::class, $signal->data->data);
                $replies[] = $signal->data->data;
            }
        }
        self::assertCount(1, $replies);
        self::assertSame('language-req', $replies[0]->requestId);
        self::assertSame(HilosSignalConstants::HILOS_I18N_LANGUAGE_ADD, $replies[0]->action);
        self::assertNull($replies[0]->reply);

        return $replies[0];
    }

    private function clearFixtures(): void
    {
        Database::sqlRun("DELETE FROM hilos_country_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'pt', 'cy'))");
        Database::sqlRun("DELETE FROM hilos_language_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'pt', 'cy'))"
            . " OR in_language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'pt', 'cy'))");
        Database::sqlRun("DELETE FROM hilos_locale WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'pt', 'cy'))");
        Database::sqlRun("DELETE FROM hilos_language WHERE code IN ('en', 'pt', 'cy')");
        foreach ($this->createdCountryCodes as $code) {
            Database::sqlRun('DELETE FROM hilos_country WHERE code = ?', [$code]);
        }
        $this->createdCountryCodes = [];
        foreach ([
            Hilos::$db->countryNames, Hilos::$db->languageNames, Hilos::$db->locales,
            Hilos::$db->languages, Hilos::$db->countries,
        ] as $collection) {
            $collection->getObjectCollection()?->reHydrate();
            $collection->clearCache();
        }
    }
}

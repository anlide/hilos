<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageUpdateActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nLanguageRowUpdateActionDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** The card and row actions share the i18n library's language write door. */
final class I18nLanguageEditTest extends IntegrationTestCase
{
    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->agent = new I18nLibraryAgent();
        foreach ([
            HilosDbContext::languages,
            HilosDbContext::countries,
            HilosDbContext::locales,
            HilosDbContext::languageNames,
            HilosDbContext::countryNames,
        ] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $this->agent->getId());
        }
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), $this->agent->getId());
        Hilos::$rt->connections->actions->clear();
        $this->clearFixtures();
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('uk', 'Українська', false);
        });
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent($this->agent->getId());
        $this->clearFixtures();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        putenv($this->previousDefaultLanguage === false
            ? EnvConstants::HILOS_DEFAULT_LANGUAGE->name
            : EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testCardAndListSaveNativeNameAndDirectionWithoutEnabling(): void
    {
        $this->saveLanguage(false, 'uk', 'Українська мова', true);
        self::assertSame('Українська мова', Hilos::$db->languages['uk']->nativeName);
        self::assertTrue(Hilos::$db->languages['uk']->rtl);
        self::assertFalse(Hilos::$db->languages['uk']->enabled);

        $this->saveLanguage(true, 'uk', 'Українська', false);
        $this->assertUnchangedLanguage(false);
    }

    /** @return iterable<string, array{bool}> The two language edit entrances */
    public static function doors(): iterable
    {
        yield 'card' => [false];
        yield 'row' => [true];
    }

    /** @param bool $fromList Whether to use the languages list page */
    #[DataProvider('doors')]
    public function testSwitchedOnLanguageIsRefusedWithoutChangingTheRow(bool $fromList): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages['uk']->actions->switchOn();
        });
        try {
            $this->saveLanguage($fromList, 'uk', 'Українська мова', true);
            self::fail('A switched-on language must refuse edits');
        } catch (I18nRowFrozenException $error) {
            self::assertSame(
                "Language 'uk' is switched on: switch it off, edit it, switch it on",
                $error->getMessage(),
            );
        }
        $this->assertUnchangedLanguage(true);
    }

    /** @return iterable<string, array{bool, string}> Invalid native names through both entrances */
    public static function invalidNames(): iterable
    {
        foreach (self::doors() as $door => [$fromList]) {
            yield $door . ' spaces' => [$fromList, '   '];
            yield $door . ' too long' => [$fromList, str_repeat('ї', 65)];
        }
    }

    /**
     * @param bool $fromList Whether to use the languages list page
     * @param string $nativeName Invalid name
     */
    #[DataProvider('invalidNames')]
    public function testInvalidNameIsRefusedWithoutChangingTheRow(bool $fromList, string $nativeName): void
    {
        try {
            $this->saveLanguage($fromList, 'uk', $nativeName, true);
            self::fail('An invalid native name must refuse the whole edit');
        } catch (ValidationException $error) {
            self::assertSame('Language native name must have 1..64 characters', $error->getMessage());
        }
        $this->assertUnchangedLanguage(false);
    }

    /** @param bool $fromList Whether to use the languages list page */
    #[DataProvider('doors')]
    public function testUnknownLanguageIsRefused(bool $fromList): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown language: zz');
        $this->saveLanguage($fromList, 'zz', 'Unknown', true);
    }

    /** @param bool $fromList Whether to use the languages list page */
    #[DataProvider('doors')]
    public function testTheOtherDoorsDTOIsRefused(bool $fromList): void
    {
        $page = $fromList ? new LanguagesListPage($this->agent) : new LanguageDetailPage($this->agent);
        $action = $fromList
            ? HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_UPDATE
            : HilosSignalConstants::HILOS_I18N_LANGUAGE_UPDATE;
        $dto = $fromList
            ? new HilosI18nLanguageUpdateActionDTO('uk', 'Українська мова', true)
            : new HilosI18nLanguageRowUpdateActionDTO('uk', 'Українська мова', true);

        try {
            $this->underAgent($this->agent, static function () use ($page, $action, $dto): void {
                $page->onAction('language-edit-ak', $action, $dto);
            });
            self::fail('Each entrance must accept only its own DTO');
        } catch (InvalidActionPayloadException) {
            $this->assertUnchangedLanguage(false);
        }
    }

    /**
     * @param bool $fromList Whether to use the languages list page
     * @param string $code Language code
     * @param string $nativeName New native name
     * @param bool $rtl New writing direction
     */
    private function saveLanguage(bool $fromList, string $code, string $nativeName, bool $rtl): void
    {
        $page = $fromList ? new LanguagesListPage($this->agent) : new LanguageDetailPage($this->agent);
        $dto = $fromList
            ? new HilosI18nLanguageRowUpdateActionDTO($code, $nativeName, $rtl)
            : new HilosI18nLanguageUpdateActionDTO($code, $nativeName, $rtl);
        $this->underAgent($this->agent, static function () use ($page, $dto): void {
            self::assertNull($page->onAction('language-edit-ak', $dto->getAction(), $dto));
        });
    }

    /** @param bool $enabled Expected availability */
    private function assertUnchangedLanguage(bool $enabled): void
    {
        self::assertSame('uk', Hilos::$db->languages['uk']->code);
        self::assertSame('Українська', Hilos::$db->languages['uk']->nativeName);
        self::assertFalse(Hilos::$db->languages['uk']->rtl);
        self::assertSame($enabled, Hilos::$db->languages['uk']->enabled);
    }

    private function clearFixtures(): void
    {
        Database::sqlRun("DELETE FROM hilos_country_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('uk', 'zz'))");
        Database::sqlRun("DELETE FROM hilos_language_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('uk', 'zz'))"
            . " OR in_language_id IN (SELECT id FROM hilos_language WHERE code IN ('uk', 'zz'))");
        Database::sqlRun("DELETE FROM hilos_locale WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('uk', 'zz'))");
        Database::sqlRun("DELETE FROM hilos_language WHERE code IN ('uk', 'zz')");
        foreach ([Hilos::$db->countryNames, Hilos::$db->languageNames, Hilos::$db->locales, Hilos::$db->languages] as $collection) {
            $collection->getObjectCollection()?->reHydrate();
            $collection->clearCache();
        }
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageDeleteActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nLanguageRowDeleteActionDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\CountryName;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\LanguageName;
use Hilos\Database\View\Item\Locale;
use Hilos\HilosException;
use Hilos\I18n\Exception\DefaultLanguageProtectedException;
use Hilos\I18n\I18nTestCommandConstants;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use PHPUnit\Framework\Attributes\DataProvider;

/** Language removal through the i18n library's database item actions. */
final class I18nLanguageDeleteTest extends IntegrationTestCase
{
    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;

    /** @throws HilosException When fixture cleanup or creation fails */
    protected function setUp(): void
    {
        parent::setUp();
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
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), $this->agent->getId());
        Hilos::$rt->connections->actions->clear();
        $this->clearFixtures();
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->languages->actions->create('fr', 'Français', false);
            Hilos::$db->countries->actions->create('gb', '£', 'GBP');
            Hilos::$db->countries->actions->create('us', '$', 'USD');
        });
    }

    /** @throws HilosException When fixture cleanup fails */
    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent($this->agent->getId());
        Hilos::$sr = null;
        $this->clearFixtures();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        putenv($this->previousDefaultLanguage === false
            ? EnvConstants::HILOS_DEFAULT_LANGUAGE->name
            : EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        parent::tearDown();
    }

    /** @return iterable<string, array{bool}> Both switch positions */
    public static function switchStates(): iterable
    {
        yield 'switched off' => [false];
        yield 'switched on' => [true];
    }

    /** @return iterable<string, array{bool, bool}> Locale switch positions with and without a country */
    public static function localeStates(): iterable
    {
        yield 'disabled countryless' => [false, false];
        yield 'enabled countryless' => [true, false];
        yield 'disabled regional' => [false, true];
        yield 'enabled regional' => [true, true];
    }

    /** @return iterable<string, array{bool, string}> Both roles of a locked language name, including empty text */
    public static function manualLanguageNames(): iterable
    {
        yield 'named language' => [true, 'French'];
        yield 'named language with empty text' => [true, ''];
        yield 'writing language' => [false, 'Anglais'];
        yield 'writing language with empty text' => [false, ''];
    }

    /** @return iterable<string, array{string}> Locked country names, including empty text */
    public static function lockedCountryNames(): iterable
    {
        yield 'nonempty name' => ['Royaume-Uni'];
        yield 'empty name' => [''];
    }

    /**
     * @param bool $enabled Default language switch position
     * @throws HilosException When fixture creation or lookup fails
     */
    #[DataProvider('switchStates')]
    public function testDefaultLanguageIsProtectedBeforeOtherDependencies(bool $enabled): void
    {
        $this->underAgent($this->agent, static function () use ($enabled): void {
            if ($enabled) {
                Hilos::$db->languages['en']->actions->switchOn();
            }
            self::createLocale(Hilos::$db->languages['en'], null);
            Hilos::$db->languageNames->actions->createManual(Hilos::$db->languages['en'], Hilos::$db->languages['fr'], null, '');
            Hilos::$db->countryNames->actions->createCatalogBase(Hilos::$db->countries['gb'], Hilos::$db->languages['en'], 'Britain');
        });

        try {
            $this->underAgent($this->agent, static fn () => Hilos::$db->languages['en']->actions->delete());
            self::fail('The configured default language must remain');
        } catch (DefaultLanguageProtectedException $failure) {
            self::assertSame("Default language 'en' cannot be deleted", $failure->getMessage());
        }
        self::assertSame($enabled, Hilos::$db->languages['en']->enabled);
        self::assertNotNull(Hilos::$db->locales['en']);
        self::assertNotNull(Hilos::$db->languageNames->findBase(Hilos::$db->languages['en']->id, Hilos::$db->languages['fr']->id));
        self::assertNotNull(Hilos::$db->countryNames->findBase(Hilos::$db->countries['gb']->id, Hilos::$db->languages['en']->id));
    }

    /**
     * @param bool $enabled Locale switch position
     * @param bool $regional Whether the locale has a country
     * @throws HilosException When fixture creation or lookup fails
     */
    #[DataProvider('localeStates')]
    public function testAnyLocalePreventsDeletionBeforeManualNames(bool $enabled, bool $regional): void
    {
        $locale = $this->underAgent($this->agent, static function () use ($enabled, $regional): Locale {
            $locale = self::createLocale(Hilos::$db->languages['fr'], $regional ? Hilos::$db->countries['gb'] : null);
            if ($enabled) {
                $locale->actions->switchOn();
            }
            Hilos::$db->languageNames->actions->createManual(Hilos::$db->languages['fr'], Hilos::$db->languages['en'], null, 'French');
            return $locale;
        });

        $this->assertFrenchDeletionRefused("Language 'fr' cannot be deleted: it has locales.");
        self::assertSame($enabled, Hilos::$db->locales[$locale->id]->enabled);
        self::assertNotNull(Hilos::$db->languageNames->findBase(Hilos::$db->languages['fr']->id, Hilos::$db->languages['en']->id));
    }

    /**
     * @param bool $namedLanguage Whether French is the named rather than the writing language
     * @param string $name Manual text, including empty text
     * @throws HilosException When fixture creation or lookup fails
     */
    #[DataProvider('manualLanguageNames')]
    public function testLockedLanguageNamesInEitherRolePreventDeletion(bool $namedLanguage, string $name): void
    {
        $manual = $this->underAgent($this->agent, static function () use ($namedLanguage, $name): LanguageName {
            Hilos::$db->countryNames->actions->createCatalogBase(Hilos::$db->countries['gb'], Hilos::$db->languages['fr'], 'Royaume-Uni');
            return Hilos::$db->languageNames->actions->createManual(
                Hilos::$db->languages[$namedLanguage ? 'fr' : 'en'],
                Hilos::$db->languages[$namedLanguage ? 'en' : 'fr'],
                null,
                $name,
            );
        });

        $this->assertFrenchDeletionRefused("Language 'fr' cannot be deleted: manual names reference it.");
        self::assertSame($name, Hilos::$db->languageNames[$manual->id]->name);
        self::assertTrue(Hilos::$db->languageNames[$manual->id]->locked);
        self::assertNotNull(Hilos::$db->countryNames->findBase(Hilos::$db->countries['gb']->id, Hilos::$db->languages['fr']->id));
    }

    /**
     * @param string $name Locked country name text
     * @throws HilosException When fixture creation or lookup fails
     */
    #[DataProvider('lockedCountryNames')]
    public function testLockedCountryNamesPreventDeletion(string $name): void
    {
        $manual = $this->underAgent($this->agent, static function () use ($name): CountryName {
            Hilos::$db->countryNames->actions->createCatalogBase(Hilos::$db->countries['us'], Hilos::$db->languages['fr'], 'États-Unis');
            return Hilos::$db->countryNames->actions->createManual(Hilos::$db->countries['gb'], Hilos::$db->languages['fr'], null, $name);
        });

        $this->assertFrenchDeletionRefused("Language 'fr' cannot be deleted: manual names reference it.");
        self::assertSame($name, Hilos::$db->countryNames[$manual->id]->name);
        self::assertNotNull(Hilos::$db->countryNames->findBase(Hilos::$db->countries['us']->id, Hilos::$db->languages['fr']->id));
    }

    /**
     * @param bool $enabled Empty language switch position
     * @throws HilosException When switching, removal or lookup fails
     */
    #[DataProvider('switchStates')]
    public function testAnEmptyLanguageCanBeDeletedInEitherSwitchPosition(bool $enabled): void
    {
        $languageId = Hilos::$db->languages['fr']->id;
        $this->underAgent($this->agent, static function () use ($enabled): void {
            if ($enabled) {
                Hilos::$db->languages['fr']->actions->switchOn();
            }
            Hilos::$db->languages['fr']->actions->delete();
        });

        self::assertNull(Hilos::$db->languages['fr']);
        self::assertNull(Hilos::$db->languages[$languageId]);
        self::assertSame(0, EntityLanguage::count([EntityLanguage::id => $languageId]));
        self::assertNotNull(Hilos::$db->languages['en']);
    }

    /** @throws HilosException When catalog fixture creation or deletion fails */
    public function testAllCatalogBaseNamesAreRemovedWithTheLanguage(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages['fr']->actions->delete();
            Hilos::$db->languages->actions->add('fr', 'Français', false);
            Hilos::$db->countryNames->actions->createCatalogBase(Hilos::$db->countries['gb'], Hilos::$db->languages['en'], 'Britain');
            Hilos::$db->languages['fr']->actions->switchOn();
        });
        $languageId = Hilos::$db->languages['fr']->id;
        $britainId = Hilos::$db->countryNames->findBase(Hilos::$db->countries['gb']->id, $languageId)->id;
        $statesId = Hilos::$db->countryNames->findBase(Hilos::$db->countries['us']->id, $languageId)->id;
        Hilos::$db->countryNames->getObjectCollection()->clearInMemory();
        Hilos::$db->countryNames->clearCache();

        $this->underAgent($this->agent, static fn () => Hilos::$db->languages['fr']->actions->delete());

        self::assertNull(Hilos::$db->languages['fr']);
        self::assertNull(Hilos::$db->languages[$languageId]);
        self::assertNull(Hilos::$db->countryNames[$britainId]);
        self::assertNull(Hilos::$db->countryNames[$statesId]);
        self::assertSame(0, EntityCountryName::count([EntityCountryName::language_id => $languageId]));
        self::assertSame('Britain', Hilos::$db->countryNames->findBase(
            Hilos::$db->countries['gb']->id, Hilos::$db->languages['en']->id,
        )->name);
        self::assertNotNull(Hilos::$db->countries['us']);
        self::assertNotNull(Hilos::$db->countries['gb']);
    }

    /** @throws HilosException When the existing command, its reply or a fixture write fails */
    public function testExistingCommandRemovesCatalogNamesAndAllowsRepeatedCleanup(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countryNames->actions->takeAllFromCatalog(Hilos::$db->languages['fr']);
        });
        $languageId = Hilos::$db->languages['fr']->id;
        self::assertSame(2, EntityCountryName::count([EntityCountryName::language_id => $languageId]));
        $previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        try {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $this->underAgent($this->agent, function (): void {
                    $this->agent->onSignalCommand(new CommandRequestDTO('language-delete-catalog', CliCommands::I18N_TEST_LANGUAGE_DELETE, [
                        I18nTestCommandConstants::FIELD_CODE => 'fr',
                    ]), '', '');
                });
                $reply = $this->drainCommandReply();
                self::assertTrue($reply->isOk());
                self::assertSame(['code' => 'fr'], $reply->payload);
                self::assertNull(Hilos::$db->languages['fr']);
                self::assertSame(0, EntityCountryName::count([EntityCountryName::language_id => $languageId]));
            }
        } finally {
            Hilos::$sr = $previousRouter;
        }
    }

    /** @throws HilosException When the fixture or filtered query fails */
    public function testUnlockedBaseReadExcludesLockedRowsOverridesAndOtherLanguages(): void
    {
        $base = $this->underAgent($this->agent, static function (): CountryName {
            $base = Hilos::$db->countryNames->actions->createCatalogBase(Hilos::$db->countries['gb'], Hilos::$db->languages['fr'], 'Royaume-Uni');
            Hilos::$db->countryNames->actions->createManual(Hilos::$db->countries['us'], Hilos::$db->languages['fr'], null, '');
            Hilos::$db->countryNames->actions->createCatalogBase(Hilos::$db->countries['gb'], Hilos::$db->languages['en'], 'Britain');
            $locale = self::createLocale(Hilos::$db->languages['fr'], null);
            $override = Hilos::$db->countryNames->actions->createManual(
                Hilos::$db->countries['gb'], Hilos::$db->languages['fr'], $locale, 'Grande-Bretagne',
            );
            Database::sqlRun('UPDATE hilos_country_name SET locked = 0 WHERE id = ?', [$override->id]);
            return $base;
        });
        Hilos::$db->countryNames->getObjectCollection()->clearInMemory();
        Hilos::$db->countryNames->clearCache();

        $names = Hilos::$db->countryNames->unlockedBaseNamesInLanguage(Hilos::$db->languages['fr']->id);

        self::assertCount(1, $names);
        self::assertSame($base->id, $names[0]->id);
        self::assertSame('Royaume-Uni', $names[0]->name);
        self::assertNull($names[0]->localeId);
        self::assertFalse($names[0]->locked);
    }

    /** @throws HilosException When the fixtures or transaction fails */
    public function testCountryNameTransactionDoorRefusesLockedBasesAndUnlockedOverrides(): void
    {
        $this->underAgent($this->agent, static function (): void {
            $base = Hilos::$db->countryNames->actions->createManual(Hilos::$db->countries['gb'], Hilos::$db->languages['fr'], null, '');
            $locale = self::createLocale(Hilos::$db->languages['fr'], null);
            $override = Hilos::$db->countryNames->actions->createManual(
                Hilos::$db->countries['gb'], Hilos::$db->languages['fr'], $locale, 'Grande-Bretagne',
            );
            Database::sqlRun('UPDATE hilos_country_name SET locked = 0 WHERE id = ?', [$override->id]);
            Hilos::$db->countryNames->getObjectCollection()->reHydrate();
            Hilos::$db->countryNames->clearCache();

            Database::transactionStart();
            try {
                foreach ([$base->id, $override->id] as $id) {
                    try {
                        Hilos::$db->countryNames[$id]->actions->deleteUnlockedBaseInTransaction();
                        self::fail('Only an unlocked base may join language removal');
                    } catch (ValidationException $failure) {
                        self::assertSame('Language removal can delete only unlocked base country names', $failure->getMessage());
                        self::assertNotNull(Hilos::$db->countryNames[$id]);
                    }
                }
            } finally {
                Database::transactionRollback();
            }
        });
        self::assertSame(2, EntityCountryName::count([EntityCountryName::language_id => Hilos::$db->languages['fr']->id]));
    }

    /** @throws HilosException When fixture creation or the owner guard fails */
    public function testCountryNameTransactionDoorRequiresRemovalOwnership(): void
    {
        $base = $this->underAgent($this->agent, static fn () => Hilos::$db->countryNames->actions->createCatalogBase(
            Hilos::$db->countries['gb'], Hilos::$db->languages['fr'], 'Royaume-Uni',
        ));
        TruthSourceRegistry::register(
            HilosDbContext::countryNames,
            TruthSourceKeys::all(),
            $this->agent->getId(),
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
        $this->underAgent($this->agent, static function () use ($base): void {
            Database::transactionStart();
            try {
                try {
                    $base->actions->deleteUnlockedBaseInTransaction();
                    self::fail('An update-only grant cannot remove a country name');
                } catch (WriteNotAllowedException $failure) {
                    self::assertStringContainsString('may not remove', $failure->getMessage());
                    self::assertNotNull(Hilos::$db->countryNames[$base->id]);
                }
            } finally {
                Database::transactionRollback();
            }
        });
    }

    /** @throws HilosException When fixture creation or rollback fails unexpectedly */
    public function testForeignKeyFailureRestoresAlreadyDeletedCatalogRowsAndOrmObjects(): void
    {
        $bases = $this->underAgent($this->agent, static function (): array {
            $bases = [
                Hilos::$db->countryNames->actions->createCatalogBase(Hilos::$db->countries['gb'], Hilos::$db->languages['fr'], 'Royaume-Uni'),
                Hilos::$db->countryNames->actions->createCatalogBase(Hilos::$db->countries['us'], Hilos::$db->languages['fr'], 'États-Unis'),
            ];
            // This inconsistent unlocked reference passes the manual-name verdict and leaves the FK as the final barrier.
            Database::sqlRun('INSERT INTO hilos_language_name (language_id, in_language_id, name, locked) VALUES (?, ?, ?, 0)', [
                Hilos::$db->languages['en']->id, Hilos::$db->languages['fr']->id, 'Anglais',
            ]);
            return $bases;
        });
        $languageId = Hilos::$db->languages['fr']->id;
        $languageObject = Hilos::$db->languages['fr']->getObject();
        self::assertTrue(Hilos::$db->languages->cardSummary($languageId)->canDelete);
        $mirror = new I18nLanguageDeleteMirror();
        SourceChangeBus::subscribe($mirror);
        try {
            try {
                $this->underAgent($this->agent, static fn () => Hilos::$db->languages['fr']->actions->delete());
                self::fail('The inconsistent reference must refuse the final language deletion');
            } catch (ForeignKeyConstraintException $failure) {
                self::assertStringContainsString('hilos_language_name', $failure->getMessage());
            }
        } finally {
            $mirror->active = false;
        }

        self::assertCount(2, $mirror->rowCountsAtDeletion);
        foreach ($bases as $base) {
            self::assertSame(0, $mirror->rowCountsAtDeletion[(string)$base->id]);
            self::assertSame(1, EntityCountryName::count([EntityCountryName::id => $base->id]));
            self::assertSame($base->getObject(), Hilos::$db->countryNames[$base->id]->getObject());
            self::assertTrue($base->getObject()->isRelated());
            self::assertSame($base->name, Hilos::$db->countryNames->findBase($base->countryId, $languageId)->name);
        }
        self::assertSame(1, EntityLanguage::count([EntityLanguage::id => $languageId]));
        self::assertSame($languageObject, Hilos::$db->languages['fr']->getObject());
        self::assertTrue($languageObject->isRelated());
        self::assertSame('Anglais', Hilos::$db->languageNames->findBase(Hilos::$db->languages['en']->id, $languageId)->name);

        $this->underAgent($this->agent, static function () use ($languageId): void {
            Hilos::$db->languageNames->findBase(Hilos::$db->languages['en']->id, $languageId)->actions->delete();
            Hilos::$db->languages['fr']->actions->delete();
        });
        self::assertNull(Hilos::$db->languages['fr']);
        self::assertSame(0, EntityCountryName::count([EntityCountryName::language_id => $languageId]));
    }

    /** @return iterable<string, array{bool}> Both page action doors */
    public static function pageDoors(): iterable
    {
        yield 'list' => [true];
        yield 'card' => [false];
    }

    /** @return iterable<string, array{bool, bool}> Each page with an empty language in both switch positions */
    public static function pageSwitchStates(): iterable
    {
        foreach (self::pageDoors() as $door => [$fromList]) {
            foreach (self::switchStates() as $state => [$enabled]) {
                yield $door . ' ' . $state => [$fromList, $enabled];
            }
        }
    }

    /**
     * @param bool $fromList Whether deletion is submitted from a table row
     * @param bool $enabled Language switch position
     */
    #[DataProvider('pageSwitchStates')]
    public function testTrackedPageDeletionSucceedsWithoutReplyData(bool $fromList, bool $enabled): void
    {
        $languageId = Hilos::$db->languages['fr']->id;
        $this->underAgent($this->agent, static function () use ($enabled): void {
            Hilos::$db->countryNames->actions->createCatalogBase(Hilos::$db->countries['gb'], Hilos::$db->languages['fr'], 'Royaume-Uni');
            if ($enabled) {
                Hilos::$db->languages['fr']->actions->switchOn();
            }
        });
        $success = $this->dispatchDelete($fromList, 'fr');
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        self::assertSame($fromList ? HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_DELETE
            : HilosSignalConstants::HILOS_I18N_LANGUAGE_DELETE, $success->action);
        self::assertSame('delete-tracked', $success->requestId);
        self::assertSame('Language deleted.', $success->message);
        self::assertNull($success->reply);
        self::assertArrayNotHasKey('reply', $success->toArray());
        self::assertNull(Hilos::$db->languages['fr']);
        self::assertSame(0, EntityCountryName::count([EntityCountryName::language_id => $languageId]));
    }

    /** @param bool $fromList Whether deletion is submitted from a table row */
    #[DataProvider('pageDoors')]
    public function testDeletePageHandlerReturnsNull(bool $fromList): void
    {
        $page = $fromList ? new LanguagesListPage($this->agent) : new LanguageDetailPage($this->agent);
        $dto = $fromList ? new HilosI18nLanguageRowDeleteActionDTO('fr') : new HilosI18nLanguageDeleteActionDTO('fr');
        $this->underAgent($this->agent, static function () use ($page, $dto): void {
            self::assertNull($page->onAction('delete-ak', $dto->getAction(), $dto));
        });
        self::assertNull(Hilos::$db->languages['fr']);
    }

    /** @return iterable<string, array{bool, string, string}> Refusals through both real page routers */
    public static function pageRefusals(): iterable
    {
        foreach (self::pageDoors() as $door => [$fromList]) {
            foreach ([
                'default' => "Default language 'en' cannot be deleted",
                'locale' => "Language 'fr' cannot be deleted: it has locales.",
                'named' => "Language 'fr' cannot be deleted: manual names reference it.",
                'writing' => "Language 'fr' cannot be deleted: manual names reference it.",
                'country' => "Language 'fr' cannot be deleted: manual names reference it.",
                'missing' => 'Unknown language: zz',
            ] as $scenario => $message) {
                yield $door . ' ' . $scenario => [$fromList, $scenario, $message];
            }
        }
    }

    /**
     * A free verdict was read before a new dependency arrived; confirmation asks the write door again.
     *
     * @param bool $fromList Whether deletion is submitted from a table row
     * @param string $scenario Dependency that appeared before confirmation
     * @param string $message Exact refusal sentence
     */
    #[DataProvider('pageRefusals')]
    public function testTrackedConfirmationChecksCurrentDependencies(bool $fromList, string $scenario, string $message): void
    {
        self::assertTrue(Hilos::$db->languages->cardSummary(Hilos::$db->languages['fr']->id)->canDelete);
        $this->underAgent($this->agent, static function () use ($scenario): void {
            Hilos::$db->countryNames->actions->createCatalogBase(Hilos::$db->countries['us'], Hilos::$db->languages['fr'], 'États-Unis');
            switch ($scenario) {
                case 'locale':
                    self::createLocale(Hilos::$db->languages['fr'], null);
                    break;
                case 'named':
                    Hilos::$db->languageNames->actions->createManual(Hilos::$db->languages['fr'], Hilos::$db->languages['en'], null, 'French');
                    break;
                case 'writing':
                    Hilos::$db->languageNames->actions->createManual(Hilos::$db->languages['en'], Hilos::$db->languages['fr'], null, 'Anglais');
                    break;
                case 'country':
                    Hilos::$db->countryNames->actions->createManual(Hilos::$db->countries['gb'], Hilos::$db->languages['fr'], null, 'Royaume-Uni');
                    break;
            }
        });
        $code = match ($scenario) {
            'default' => 'en',
            'missing' => 'zz',
            default => 'fr',
        };
        $failure = $this->dispatchDelete($fromList, $code);
        self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
        self::assertSame('fail', $failure->getEnvelopeOutcome());
        self::assertSame('delete-tracked', $failure->requestId);
        self::assertSame($message, $failure->reason);
        self::assertSame($fromList ? HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_DELETE
            : HilosSignalConstants::HILOS_I18N_LANGUAGE_DELETE, $failure->action);
        self::assertNotNull(Hilos::$db->languages['fr']);
        self::assertNotNull(Hilos::$db->languages['en']);
        self::assertNotNull(Hilos::$db->countryNames->findBase(Hilos::$db->countries['us']->id, Hilos::$db->languages['fr']->id));
    }

    /** @param bool $fromList Whether deletion is submitted from a table row */
    #[DataProvider('pageDoors')]
    public function testTrackedPageDeletionRollsBackCatalogCleanupOnForeignKeyFailure(bool $fromList): void
    {
        $base = $this->underAgent($this->agent, static function (): CountryName {
            $base = Hilos::$db->countryNames->actions->createCatalogBase(Hilos::$db->countries['gb'], Hilos::$db->languages['fr'], 'Royaume-Uni');
            Database::sqlRun('INSERT INTO hilos_language_name (language_id, in_language_id, name, locked) VALUES (?, ?, ?, 0)', [
                Hilos::$db->languages['en']->id, Hilos::$db->languages['fr']->id, 'Anglais',
            ]);
            return $base;
        });
        $failure = $this->dispatchDelete($fromList, 'fr');
        self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
        self::assertStringContainsString('hilos_language_name', $failure->errorDetail);
        self::assertSame(1, EntityCountryName::count([EntityCountryName::id => $base->id]));
        self::assertSame($base->getObject(), Hilos::$db->countryNames[$base->id]->getObject());
        self::assertNotNull(Hilos::$db->languages['fr']);
    }

    /** @param bool $fromList Whether deletion is submitted from a table row */
    #[DataProvider('pageDoors')]
    public function testEachDeleteDoorRejectsTheOtherPagesDto(bool $fromList): void
    {
        $page = $fromList ? new LanguagesListPage($this->agent) : new LanguageDetailPage($this->agent);
        $action = $fromList ? HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_DELETE : HilosSignalConstants::HILOS_I18N_LANGUAGE_DELETE;
        $dto = $fromList ? new HilosI18nLanguageDeleteActionDTO('fr') : new HilosI18nLanguageRowDeleteActionDTO('fr');
        $this->expectException(InvalidActionPayloadException::class);
        $page->onAction('delete-ak', $action, $dto);
    }

    /**
     * @param bool $fromList Whether deletion is submitted from a table row
     * @param string $code Requested language code
     * @return PageActionSuccessSignalData|PageActionErrorSignalData Correlated outcome from the real page dispatcher
     */
    private function dispatchDelete(bool $fromList, string $code): PageActionSuccessSignalData|PageActionErrorSignalData
    {
        $admin = Hilos::$db->users->actions->createWithName('Language delete admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__ . uniqid('', true)), 0, 32));
        $session->actions->bindUser($admin->id);
        Hilos::$rt->connections->actions->register('delete-ak', $admin->id, $session->token, $session->id);
        $page = $fromList ? LanguagesListPage::PAGE : LanguageDetailPage::PAGE;
        $action = $fromList ? HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_DELETE : HilosSignalConstants::HILOS_I18N_LANGUAGE_DELETE;
        $router = new PageSignalRouter(new HilosPageFactory($this->agent, Hilos::class), new ActionRouteConfig([$action => $page]));
        $this->underAgent($this->agent, static fn () => $router->dispatchAction(
            new WebSocketActionSignalDTO('delete-ak', $action, ['languageCode' => $code], 'delete-tracked'),
            'websocket',
        ));
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData
                && ($signal->data->data instanceof PageActionSuccessSignalData || $signal->data->data instanceof PageActionErrorSignalData)
            ) {
                return $signal->data->data;
            }
        }
        self::fail('The tracked delete did not answer');
    }

    /** @return CommandReplyDTO Reply from the existing library command */
    private function drainCommandReply(): CommandReplyDTO
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                return $signal->data;
            }
        }
        self::fail('Command reply was not queued');
    }

    /**
     * @param string $message Exact business refusal
     * @throws HilosException When deletion raises an unexpected failure
     */
    private function assertFrenchDeletionRefused(string $message): void
    {
        try {
            $this->underAgent($this->agent, static fn () => Hilos::$db->languages['fr']->actions->delete());
            self::fail('The referenced language must remain');
        } catch (ValidationException $failure) {
            self::assertSame($message, $failure->getMessage());
        }
        self::assertNotNull(Hilos::$db->languages['fr']);
        self::assertFalse(Hilos::$db->languages->cardSummary(Hilos::$db->languages['fr']->id)->canDelete);
    }

    /**
     * @param Language $language Locale's language
     * @param ?Country $country Its country, or null for a countryless locale
     * @return Locale New disabled locale
     * @throws HilosException When locale creation fails
     */
    private static function createLocale(Language $language, ?Country $country): Locale
    {
        return Hilos::$db->locales->actions->create(
            $language, $country, 'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
            'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
        );
    }

    /** @throws HilosException When fixture cleanup or cache hydration fails */
    private function clearFixtures(): void
    {
        Database::sqlRun('UPDATE hilos_country SET default_locale_id = NULL');
        Database::sqlRun('DELETE FROM hilos_country_name');
        Database::sqlRun('DELETE FROM hilos_language_name');
        Database::sqlRun('DELETE FROM hilos_locale');
        Database::sqlRun('DELETE FROM hilos_country');
        Database::sqlRun('DELETE FROM hilos_language');
        foreach ([Hilos::$db->countryNames, Hilos::$db->languageNames, Hilos::$db->locales,
            Hilos::$db->countries, Hilos::$db->languages] as $collection) {
            $collection->getObjectCollection()->reHydrate();
            $collection->clearCache();
        }
    }
}

/** Observes actual SQL deletions before the enclosing transaction rolls back. */
final class I18nLanguageDeleteMirror implements SourceMirrorSubscriberInterface
{
    public bool $active = true;

    /** @var array<string, int> Remaining SQL rows by deleted name id at the moment of removal */
    public array $rowCountsAtDeletion = [];

    /**
     * @param SourceChange $change Local collection mutation
     * @param SourceChangeProvenance $provenance Origin of the mutation
     * @throws HilosException When reading the deleted SQL row fails
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($this->active && $change->isDb() && $change->sourceKey === HilosDbContext::countryNames
            && $change->mutationType === TableMutationType::Delete) {
            $this->rowCountsAtDeletion[$change->sourceId] = EntityCountryName::count([EntityCountryName::id => (int)$change->sourceId]);
        }
    }
}

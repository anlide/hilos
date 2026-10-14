<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\CountryNamesActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\CountryName;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\LocaleTemplates;
use Hilos\I18n\MeasurementSystem;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameAddActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameUpdateActionDTO;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Writing through the real admin page dispatcher, owner, fixture commands and browser windows. */
final class I18nCountryNameWriteTest extends IntegrationTestCase
{
    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;

    /**
     * Set up only this test's rows through the existing reference owner.
     *
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->agent = new I18nLibraryAgent();
        foreach (array_keys(I18nLibraryAgent::OWNS_DB) as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $this->agent->getId());
        }
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), $this->agent->getId());
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        $this->clearTestRows();
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages['en'] ?? Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->countries->actions->create('qx', 'Other', 'QXX');
            Hilos::$db->countries['gb'] ?? Hilos::$db->countries->actions->create('gb', '£', 'GBP');
            Hilos::$db->countries['us'] ?? Hilos::$db->countries->actions->create('us', '$', 'USD');
            Hilos::$db->countries['fr'] ?? Hilos::$db->countries->actions->create('fr', '€', 'EUR');
        });
        $this->connect('write-ak', true);
        $this->seed();
        $this->underAgent($this->agent, fn () => $this->base()->actions->delete());
    }

    /**
     * Restore the environment and remove the rows even after a refused fixture cleanup.
     *
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    protected function tearDown(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent($this->agent->getId());
        $this->clearTestRows();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        Hilos::$sr = null;
        putenv($this->previousDefaultLanguage === false
            ? EnvConstants::HILOS_DEFAULT_LANGUAGE->name
            : EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        parent::tearDown();
    }

    /** @return iterable<string, array{string, bool}> Literal values and enabled state are independent */
    public static function nameStates(): iterable
    {
        foreach ([false, true] as $enabled) {
            foreach (['Name', '', "  \n", str_repeat('é', 255)] as $index => $name) {
                yield (int)$enabled . '-' . $index => [$name, $enabled];
            }
        }
    }

    /**
     * @param string $name Literal name to add and edit
     * @param bool $enabled Whether parents and locale are switched on
     * @throws HilosException When setup, dispatch or persistence fails
     */
    #[DataProvider('nameStates')]
    public function testCreateAndEditBaseAndCorrectionPreserveLiteralText(string $name, bool $enabled): void
    {
        if ($enabled) {
            $this->underAgent($this->agent, static function (): void {
                Hilos::$db->languages['qk']->actions->switchOn();
                Hilos::$db->countries['qj']->actions->switchOn();
                Hilos::$db->locales['qk-GB']->actions->switchOn();
            });
        }
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, $name), 'Country name added.');
        self::assertSame($name, $this->base()->name);
        self::assertTrue($this->base()->locked);
        $baseId = $this->base()->id;
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', 'qk-GB', $name), 'Locale correction added.');
        self::assertSame($name, $this->correction('qk-GB')->name);
        self::assertTrue($this->correction('qk-GB')->locked);
        $correctionId = $this->correction('qk-GB')->id;
        foreach ([null, 'qk-GB'] as $locale) {
            $message = $locale === null ? 'Country name saved.' : 'Locale correction saved.';
            $this->assertSuccess(new HilosI18nCountryNameUpdateActionDTO('qj', 'qk', $locale, 'Temporary', $name), $message);
            $this->assertSuccess(new HilosI18nCountryNameUpdateActionDTO('qj', 'qk', $locale, $name, 'Temporary'), $message);
        }
        self::assertSame($baseId, $this->base()->id);
        self::assertSame($correctionId, $this->correction('qk-GB')->id);
        self::assertSame($name, $this->base()->name);
        self::assertSame($name, $this->correction('qk-GB')->name);
        self::assertTrue($this->base()->locked);
        self::assertTrue($this->correction('qk-GB')->locked);
        self::assertSame($enabled, Hilos::$db->countries['qj']->enabled);
        self::assertSame($enabled, Hilos::$db->languages['qk']->enabled);
        self::assertSame($enabled, Hilos::$db->locales['qk-GB']->enabled);
    }

    /** @return iterable<string, array{?string, string}> Both rows, including a saved empty value */
    public static function storedNames(): iterable
    {
        foreach ([null, 'qk-GB'] as $locale) {
            foreach (['Name', '', '  '] as $index => $name) {
                yield ($locale ?? 'base') . '-' . $index => [$locale, $name];
            }
        }
    }

    /**
     * @param ?string $locale Row being tested
     * @param string $name Current literal value
     * @throws HilosException When setup, dispatch or persistence fails
     */
    #[DataProvider('storedNames')]
    public function testDuplicateAndStaleWritesAreRefusedWhileEqualExpectedTextSaves(?string $locale, string $name): void
    {
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, $name), 'Country name added.');
        if ($locale !== null) {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', $locale, $name), 'Locale correction added.');
        }
        $this->assertRefusal(
            new HilosI18nCountryNameAddActionDTO('qj', 'qk', $locale, 'Overwrite'),
            'A name already exists for this language or locale.',
        );
        $this->assertRefusal(
            new HilosI18nCountryNameUpdateActionDTO('qj', 'qk', $locale, 'Overwrite', 'Stale'),
            'The name changed elsewhere. Review the current value and save again.',
        );
        $this->assertSuccess(
            new HilosI18nCountryNameUpdateActionDTO('qj', 'qk', $locale, 'Saved', $name),
            $locale === null ? 'Country name saved.' : 'Locale correction saved.',
        );
    }

    /** @throws HilosException When setup, dispatch or persistence fails */
    public function testEqualTextReplacementIsAcceptedWithoutAnAbaVersion(): void
    {
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, 'Name'), 'Country name added.');
        $oldId = $this->base()->id;
        $this->underAgent($this->agent, fn () => $this->base()->actions->delete());
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, 'Name'), 'Country name added.');
        self::assertNotSame($oldId, $this->base()->id);
        $this->assertSuccess(new HilosI18nCountryNameUpdateActionDTO('qj', 'qk', null, 'New', 'Name'), 'Country name saved.');
    }

    /** @throws HilosException When setup, dispatch or persistence fails */
    public function testCountryAndWritingLanguageMayShareTheSameCode(): void
    {
        $this->underAgent($this->agent, static fn () => Hilos::$db->countries->actions->create('qk', 'Q', 'QKK'));
        try {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qk', 'qk', null, ''), 'Country name added.');
            $this->assertSuccess(new HilosI18nCountryNameUpdateActionDTO('qk', 'qk', null, 'Saved', ''), 'Country name saved.');
            self::assertSame('Saved', Hilos::$db->countryNames->findBase(
                Hilos::$db->countries['qk']->id, Hilos::$db->languages['qk']->id,
            )->name);
        } finally {
            $this->underAgent($this->agent, static function (): void {
                Hilos::$db->countryNames->findBase(Hilos::$db->countries['qk']->id, Hilos::$db->languages['qk']->id)?->actions->delete();
                Hilos::$db->countries['qk']->actions->delete();
            });
        }
    }

    /** @throws HilosException When setup, dispatch or persistence fails */
    public function testUsNameAllowsAnEnglishCorrectionForGreatBritain(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countryNames->findBase(Hilos::$db->countries['us']->id, Hilos::$db->languages['en']->id)?->actions->delete();
            Hilos::$db->locales['en-GB'] ?? Hilos::$db->locales->actions->create(
                Hilos::$db->languages['en'], Hilos::$db->countries['gb'], LocaleTemplates::DATE[0], LocaleTemplates::TIME[0],
                LocaleTemplates::NUMBER[0], LocaleTemplates::PHONE[0], LocaleTemplates::ADDRESS[0],
                MeasurementSystem::METRIC, LocaleTemplates::COLLATION[0],
            );
        });
        try {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('us', 'en', null, ''), 'Country name added.');
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('us', 'en', 'en-GB', '  '), 'Locale correction added.');
            $this->assertSuccess(new HilosI18nCountryNameUpdateActionDTO('us', 'en', 'en-GB', '', '  '), 'Locale correction saved.');
            $name = Hilos::$db->countryNames->findOverride(
                Hilos::$db->countries['us']->id, Hilos::$db->languages['en']->id, Hilos::$db->locales['en-GB']->id,
            );
            self::assertSame('', $name->name);
            self::assertTrue($name->locked);
        } finally {
            $this->underAgent($this->agent, static function (): void {
                Hilos::$db->countryNames->findBase(Hilos::$db->countries['us']->id, Hilos::$db->languages['en']->id)?->actions->delete();
                Hilos::$db->locales['en-GB']->actions->switchOff();
                Hilos::$db->locales['en-GB']->actions->delete();
            });
        }
    }

    /** @return iterable<string, array{bool}> Duplicate and unrelated database failures */
    public static function persistenceFailures(): iterable
    {
        yield 'duplicate' => [true];
        yield 'database failure' => [false];
    }

    /**
     * Inject the persistence failure at the existing action boundary, after the real page lookup.
     * Tracked dispatch bypasses onActionException, so the handler must translate the duplicate.
     *
     * @param bool $duplicate Whether the failing insert violated uniqueness
     * @throws HilosException When setup or dispatch fails
     */
    #[DataProvider('persistenceFailures')]
    public function testTrackedAddConvertsOnlyTheDuplicatePersistenceFailure(bool $duplicate): void
    {
        $collection = Hilos::$db->countryNames;
        $actions = $collection->actions;
        $property = new ReflectionProperty(DbCollection::class, '_actions');
        $refusedActions = $this->createMock(CountryNamesActions::class);
        $refusedActions->expects(self::once())->method('createManual')->willThrowException(
            $duplicate ? new DuplicateEntryException('SQL duplicate') : new DatabaseException('Database unavailable'),
        );
        $property->setValue($collection, $refusedActions);
        try {
            $before = $this->nameSnapshot();
            $failure = $this->dispatch(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, 'New'));
            self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
            self::assertSame('write-tracked', $failure->requestId);
            self::assertSame(HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_ADD, $failure->action);
            self::assertSame($duplicate
                ? 'A name already exists for this language or locale.' : SignalConstants::ACTION_FAILED_REASON, $failure->reason);
            self::assertSame($duplicate ? null : 'Database unavailable', $failure->errorDetail);
            self::assertSame($before, $this->nameSnapshot());
        } finally {
            $property->setValue($collection, $actions);
        }
    }

    /** @return iterable<string, array{bool, ?string}> Both actions and both row kinds */
    public static function doors(): iterable
    {
        foreach ([false, true] as $update) {
            foreach ([null, 'qk-GB'] as $locale) {
                yield ($update ? 'update ' : 'add ') . ($locale ?? 'base') => [$update, $locale];
            }
        }
    }

    /**
     * @param bool $update Whether an existing row is edited
     * @param ?string $locale Row being tested
     * @throws HilosException When setup, dispatch or persistence fails
     */
    #[DataProvider('doors')]
    public function testUnicodeWidthRefusalDoesNotMutate(bool $update, ?string $locale): void
    {
        if ($update || $locale !== null) {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, ''), 'Country name added.');
        }
        if ($update && $locale !== null) {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', $locale, ''), 'Locale correction added.');
        }
        $dto = $update
            ? new HilosI18nCountryNameUpdateActionDTO('qj', 'qk', $locale, str_repeat('é', 256), '')
            : new HilosI18nCountryNameAddActionDTO('qj', 'qk', $locale, str_repeat('é', 256));
        $this->assertRefusal($dto, 'Name exceeds its column width');
    }

    /** @return iterable<string, array{bool, string}> Missing coordinates and rows */
    public static function missingCoordinates(): iterable
    {
        foreach ([false, true] as $update) {
            foreach (['country', 'writing-language', 'locale', 'base'] as $scenario) {
                yield (int)$update . '-' . $scenario => [$update, $scenario];
            }
        }
        yield 'update absent correction' => [true, 'correction'];
        yield 'update absent base row' => [true, 'base-row'];
    }

    /**
     * @param bool $update Whether this is an edit request
     * @param string $scenario Missing coordinate or row
     * @throws HilosException When setup, dispatch or persistence fails
     */
    #[DataProvider('missingCoordinates')]
    public function testMissingCoordinatesNeverCreateOnUpdate(bool $update, string $scenario): void
    {
        if ($scenario !== 'base' && $scenario !== 'base-row') {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, ''), 'Country name added.');
        }
        $country = $scenario === 'country' ? 'zz' : 'qj';
        $writing = $scenario === 'writing-language' ? 'zz' : 'qk';
        $locale = match ($scenario) {
            'locale' => 'qk-DE',
            'base', 'correction' => 'qk-GB',
            default => null,
        };
        $dto = $update
            ? new HilosI18nCountryNameUpdateActionDTO($country, $writing, $locale, 'Write', '')
            : new HilosI18nCountryNameAddActionDTO($country, $writing, $locale, 'Write');
        $this->assertRefusal($dto, match ($scenario) {
            'country' => 'Unknown country: zz',
            'writing-language' => 'Unknown writing language: zz',
            'locale' => 'Unknown locale: qk-DE',
            default => 'The name no longer exists.',
        });
    }

    /** @return iterable<string, array{bool, bool}> Each action with both inconsistent locale relations */
    public static function invalidLocaleRelations(): iterable
    {
        foreach ([false, true] as $update) {
            foreach ([false, true] as $countryless) {
                yield (int)$update . '-' . (int)$countryless => [$update, $countryless];
            }
        }
    }

    /**
     * The ORM keeps locale coordinates immutable. SQL constructs an inconsistent stored relation
     * that its normal write doors cannot produce, to test the page's coordinate recheck.
     *
     * @param bool $update Whether to edit a correction
     * @param bool $countryless Whether the stored country, rather than language, is invalid
     * @throws HilosException When setup, dispatch or persistence fails
     */
    #[DataProvider('invalidLocaleRelations')]
    public function testBothActionsRecheckStoredLocaleRelations(bool $update, bool $countryless): void
    {
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, ''), 'Country name added.');
        if ($update) {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', 'qk-GB', ''), 'Locale correction added.');
        }
        $localeId = Hilos::$db->locales['qk-GB']->id;
        $writingId = Hilos::$db->languages['qk']->id;
        $countryId = Hilos::$db->countries['gb']->id;
        try {
            Database::sqlRun('UPDATE hilos_locale SET language_id = ?, country_id = ? WHERE id = ?', [
                $countryless ? $writingId : Hilos::$db->languages['en']->id,
                $countryless ? null : $countryId,
                $localeId,
            ]);
            Hilos::$db->locales->getObjectCollection()->reHydrate();
            Hilos::$db->locales->clearCache();
            $this->assertRefusal(
                $this->request($update, 'qk-GB'),
                'The correction must belong to a country locale of the writing language.',
            );
        } finally {
            Database::sqlRun('UPDATE hilos_locale SET language_id = ?, country_id = ? WHERE id = ?', [$writingId, $countryId, $localeId]);
            Hilos::$db->locales->getObjectCollection()->reHydrate();
            Hilos::$db->locales->clearCache();
        }
    }

    /**
     * @param bool $update Action receiving the other action's DTO
     * @param ?string $locale Row being tested
     * @throws HilosException When setup, dispatch or persistence fails
     */
    #[DataProvider('doors')]
    public function testWrongDtoAndUnknownActionRefuseBeforeMutation(bool $update, ?string $locale): void
    {
        $before = $this->nameSnapshot();
        foreach ([$this->request($update, $locale)->getAction(), 'unknown'] as $action) {
            try {
                new CountryNamesPage($this->agent)->onAction('write-ak', $action, $this->request(!$update, $locale));
                self::fail('A wrong DTO or unknown action must be refused');
            } catch (InvalidActionPayloadException|AgentUnknownActionException $failure) {
                self::assertInstanceOf(
                    $action === 'unknown' ? AgentUnknownActionException::class : InvalidActionPayloadException::class,
                    $failure,
                );
                self::assertSame($before, $this->nameSnapshot());
            }
        }
    }

    /**
     * @param bool $update Whether this is an edit request
     * @param ?string $locale Row being tested
     * @throws HilosException When setup, dispatch or persistence fails
     */
    #[DataProvider('doors')]
    public function testEveryWriteRequiresTheOwner(bool $update, ?string $locale): void
    {
        if ($update || $locale !== null) {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, ''), 'Country name added.');
        }
        if ($update && $locale !== null) {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', $locale, ''), 'Locale correction added.');
        }
        $before = $this->nameSnapshot();
        $dto = $this->request($update, $locale);
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: 'test-agent:outsider'), function () use ($dto): void {
                new CountryNamesPage($this->agent)->onAction('write-ak', $dto->getAction(), $dto);
            });
            self::fail('An unrelated agent cannot write names');
        } catch (CreateNotAllowedException|WriteNotAllowedException $failure) {
            self::assertInstanceOf($update ? WriteNotAllowedException::class : CreateNotAllowedException::class, $failure);
            self::assertSame($before, $this->nameSnapshot());
        }
    }

    /** @return iterable<string, array{bool, ?string, bool, bool}> Each door under guests and members, with and without view mode */
    public static function viewers(): iterable
    {
        foreach (self::doors() as $door => [$update, $locale]) {
            foreach ([false, true] as $signedIn) {
                foreach ([false, true] as $viewMode) {
                    yield $door . '-' . (int)$signedIn . '-' . (int)$viewMode => [$update, $locale, $signedIn, $viewMode];
                }
            }
        }
    }

    /**
     * @param bool $update Whether this is an edit request
     * @param ?string $locale Row being tested
     * @param bool $signedIn Whether the viewer has an account
     * @param bool $viewMode Whether admin view mode is enabled
     * @throws HilosException When setup, dispatch or persistence fails
     */
    #[DataProvider('viewers')]
    public function testNonAdminsCannotWriteThroughDispatcher(bool $update, ?string $locale, bool $signedIn, bool $viewMode): void
    {
        if ($update || $locale !== null) {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, ''), 'Country name added.');
        }
        if ($update && $locale !== null) {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', $locale, ''), 'Locale correction added.');
        }
        $this->connect('viewer', $signedIn ? false : null);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set($viewMode);
        $before = $this->nameSnapshot();
        $failure = $this->dispatch($this->request($update, $locale), 'viewer');
        self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
        self::assertSame($viewMode ? 'view_mode' : ($signedIn ? 'forbidden' : 'unauthorized'), $failure->errorCode);
        self::assertNull($failure->errorType);
        self::assertNull($failure->errorDetail);
        self::assertSame($before, $this->nameSnapshot());
    }

    /** @return iterable<string, array{bool, ?string, string}> Every mutation and literal count boundary */
    public static function fanoutWrites(): iterable
    {
        foreach (self::doors() as $door => [$update, $locale]) {
            foreach (['Live name', ''] as $index => $name) {
                yield $door . '-' . $index => [$update, $locale, $name];
            }
        }
    }

    /**
     * @param bool $update Whether this is an edit request
     * @param ?string $locale Row being tested
     * @param string $name New literal value
     * @throws HilosException When setup, dispatch or persistence fails
     */
    #[DataProvider('fanoutWrites')]
    public function testBothNamesWindowsReceiveUpdatedValuesAndCard(bool $update, ?string $locale, string $name): void
    {
        if ($update || $locale !== null) {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, 'Before'), 'Country name added.');
        }
        if ($update && $locale !== null) {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', $locale, 'Before'), 'Locale correction added.');
        }
        $this->connect('other-tab', true);
        foreach (['write-ak', 'other-tab'] as $acceptKey) {
            $this->subscribe($acceptKey);
        }
        $this->drain();
        $changes = new I18nCountryNameWriteChanges();
        SourceChangeBus::subscribe($changes);
        $dto = $update
            ? new HilosI18nCountryNameUpdateActionDTO('qj', 'qk', $locale, $name, 'Before')
            : new HilosI18nCountryNameAddActionDTO('qj', 'qk', $locale, $name);
        try {
            $this->assertSuccess($dto, ($locale === null ? 'Country name' : 'Locale correction') . ($update ? ' saved.' : ' added.'));
        } finally {
            $changes->active = false;
        }
        self::assertCount(1, $changes->changes);
        foreach ($changes->changes as $change) {
            Hilos::$browser->record($change);
        }
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $frames = $this->drain();
        foreach (['write-ak', 'other-tab'] as $acceptKey) {
            $deltas = [];
            $cards = [];
            foreach ($frames as $frame) {
                if ($frame->targetAcceptKey !== $acceptKey) {
                    continue;
                }
                self::assertNotInstanceOf(TableWindowSignalData::class, $frame->data);
                if ($frame->data instanceof TableViewportDeltaDTO) {
                    self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $frame->data->kind);
                    $deltas[] = $frame->data;
                }
                if ($frame->data instanceof PageResponseSignalData) {
                    $cards[] = $frame->data->payload->toArray()[PagePayload::data][CountryCardBrowserData::DATA];
                }
            }
            self::assertCount(1, $deltas);
            self::assertSame('qk', $deltas[0]->rowKey);
            $row = $deltas[0]->row[PagePayload::slots][HilosI18nCountryNamesTable::ROW_SLOT];
            self::assertSame($locale === null ? $name : 'Before', $row['name']);
            self::assertCount(3, $row['corrections']);
            $corrections = array_column($row['corrections'], 'name', 'localeCode');
            self::assertSame($locale === null ? null : $name, $corrections['qk-GB']);
            self::assertNull($corrections['qk-FR']);
            self::assertNull($corrections['qk-US']);
            self::assertCount(1, $cards);
            self::assertSame($locale === null && $name === '' ? 0 : 1, $cards[0][CountryCardBrowserData::FIELD_SUMMARY]['nameCount']);
        }
    }

    /** @return iterable<string, array{bool, string}> Both actions and the empty header fallback */
    public static function headerWrites(): iterable
    {
        foreach ([false, true] as $update) {
            foreach (['New English name', ''] as $name) {
                yield (int)$update . '-' . $name => [$update, $name];
            }
        }
    }

    /**
     * @param bool $update Whether the English name already exists
     * @param string $name New header name
     * @throws HilosException When setup, dispatch or browser projection fails
     */
    #[DataProvider('headerWrites')]
    public function testDefaultLanguageNameUpdatesBothHeadersWithoutApply(bool $update, string $name): void
    {
        if ($update) {
            $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'en', null, 'Before'), 'Country name added.');
        }
        $this->connect('other-tab', true);
        foreach (['write-ak', 'other-tab'] as $acceptKey) {
            $this->subscribe($acceptKey);
        }
        $this->drain();
        $changes = new I18nCountryNameWriteChanges();
        SourceChangeBus::subscribe($changes);
        try {
            $this->assertSuccess($update
                ? new HilosI18nCountryNameUpdateActionDTO('qj', 'en', null, $name, 'Before')
                : new HilosI18nCountryNameAddActionDTO('qj', 'en', null, $name),
                $update ? 'Country name saved.' : 'Country name added.',
            );
        } finally {
            $changes->active = false;
        }
        self::assertCount(1, $changes->changes);
        foreach ($changes->changes as $change) {
            Hilos::$browser->record($change);
        }
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $frames = $this->drain();
        foreach (['write-ak', 'other-tab'] as $acceptKey) {
            $deltas = [];
            $cards = [];
            foreach ($frames as $frame) {
                if ($frame->targetAcceptKey !== $acceptKey) {
                    continue;
                }
                self::assertNotInstanceOf(TableWindowSignalData::class, $frame->data);
                if ($frame->data instanceof TableViewportDeltaDTO) {
                    self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $frame->data->kind);
                    $deltas[] = $frame->data;
                }
                if ($frame->data instanceof PageResponseSignalData) {
                    $cards[] = $frame->data->payload->toArray()[PagePayload::data][CountryCardBrowserData::DATA];
                }
            }
            self::assertCount(1, $deltas);
            self::assertSame('en', $deltas[0]->rowKey);
            self::assertSame($name, $deltas[0]->row[PagePayload::slots][HilosI18nCountryNamesTable::ROW_SLOT]['name']);
            self::assertCount(1, $cards);
            self::assertSame($name === '' ? null : $name, $cards[0][CountryCardBrowserData::FIELD_SUMMARY]['name']);
            self::assertSame($name === '' ? 0 : 1, $cards[0][CountryCardBrowserData::FIELD_SUMMARY]['nameCount']);
        }
    }

    /** @throws HilosException When setup, dispatch or persistence fails */
    public function testExtendedSeedAndRepeatedClearPreserveOtherRows(): void
    {
        self::assertTrue($this->command(CliCommands::I18N_TEST_COUNTRY_NAMES_CLEAR)->isOk());
        $this->seed();
        $countryIds = [];
        foreach (['gb', 'us', 'fr', 'qx'] as $code) {
            $countryIds[$code] = Hilos::$db->countries[$code]->id;
        }
        $englishId = Hilos::$db->languages['en']->id;
        self::assertFalse(Hilos::$db->locales['qk-FR']->enabled);
        self::assertSame(Hilos::$db->languages['qk']->id, Hilos::$db->locales['qk-FR']->languageId);
        self::assertSame($countryIds['fr'], Hilos::$db->locales['qk-FR']->countryId);
        self::assertNull($this->correction('qk-FR'));
        self::assertNull(Hilos::$db->countryNames->findBase(Hilos::$db->countries['qj']->id, $englishId));
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'en', null, ''), 'Country name added.');
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', 'qk-FR', ''), 'Locale correction added.');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            self::assertTrue($this->command(CliCommands::I18N_TEST_COUNTRY_NAMES_CLEAR)->isOk());
            self::assertNull(Hilos::$db->countries['qj']);
            self::assertNull(Hilos::$db->languages['qk']);
            foreach (['qk-GB', 'qk-US', 'qk-FR'] as $code) {
                self::assertNull(Hilos::$db->locales[$code]);
            }
            self::assertSame([], $this->nameSnapshot());
        }
        foreach ($countryIds as $code => $id) {
            self::assertSame($id, Hilos::$db->countries[$code]->id);
        }
        self::assertSame($englishId, Hilos::$db->languages['en']->id);
    }

    /** @return iterable<string, array{string, string}> Both commands check both fixture markers */
    public static function foreignMarkers(): iterable
    {
        foreach ([CliCommands::I18N_TEST_COUNTRY_NAMES_SEED, CliCommands::I18N_TEST_COUNTRY_NAMES_CLEAR] as $command) {
            foreach (['qj', 'qk'] as $code) {
                yield $command . '-' . $code => [$command, $code];
            }
        }
    }

    /**
     * @param string $command Fixture command to refuse
     * @param string $code Foreign fixture address
     * @throws HilosException When setup, dispatch or persistence fails
     */
    #[DataProvider('foreignMarkers')]
    public function testForeignMarkerPreservesAddedNames(string $command, string $code): void
    {
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'en', null, ''), 'Country name added.');
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', null, ''), 'Country name added.');
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'qk', 'qk-FR', ''), 'Locale correction added.');
        $this->underAgent($this->agent, static function () use ($code): void {
            if ($code === 'qj') {
                Hilos::$db->countries['qj']->actions->update('Foreign', 'QJJ', null);
            } else {
                Hilos::$db->languages['qk']->actions->update('Foreign', false);
            }
        });
        $before = $this->nameSnapshot();
        $reply = $this->command($command);
        self::assertFalse($reply->isOk());
        self::assertStringContainsString($command === CliCommands::I18N_TEST_COUNTRY_NAMES_SEED
            ? 'Clear it before seeding again.' : 'is not the country names fixture.', $reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertSame($before, $this->nameSnapshot());
        foreach (['qk-GB', 'qk-US', 'qk-FR'] as $locale) {
            self::assertNotNull(Hilos::$db->locales[$locale]);
        }
        self::assertNotNull(Hilos::$db->countries['qj']);
        self::assertNotNull(Hilos::$db->languages['qk']);
    }

    /** @throws HilosException When setup, dispatch or persistence fails */
    public function testClearResumesAfterUnexpectedReferenceAndPreservesOtherRows(): void
    {
        $this->assertSuccess(new HilosI18nCountryNameAddActionDTO('qj', 'en', null, ''), 'Country name added.');
        $other = $this->underAgent($this->agent, static fn () => Hilos::$db->countryNames->actions->createManual(
            Hilos::$db->countries['qx'], Hilos::$db->languages['qk'], null, 'Foreign name',
        ));
        self::assertFalse($this->command(CliCommands::I18N_TEST_COUNTRY_NAMES_CLEAR)->isOk());
        self::assertNull(Hilos::$db->countryNames->findBase(Hilos::$db->countries['qj']->id, Hilos::$db->languages['en']->id));
        self::assertSame('Foreign name', Hilos::$db->countryNames[$other->id]->name);
        self::assertNotNull(Hilos::$db->languages['qk']);
        $this->underAgent($this->agent, static fn () => $other->actions->delete());
        self::assertTrue($this->command(CliCommands::I18N_TEST_COUNTRY_NAMES_CLEAR)->isOk());
        self::assertTrue($this->command(CliCommands::I18N_TEST_COUNTRY_NAMES_CLEAR)->isOk());
        self::assertNotNull(Hilos::$db->countries['qx']);
        self::assertNotNull(Hilos::$db->languages['en']);
    }

    /**
     * @param bool $update Whether to edit the fixture row
     * @param ?string $locale Country locale, or base
     * @return ActionPayloadDTO Name request with an empty expected value for updates
     * @throws InvalidFormatException When fixture coordinates violate the contract
     */
    private function request(bool $update, ?string $locale): ActionPayloadDTO
    {
        return $update
            ? new HilosI18nCountryNameUpdateActionDTO('qj', 'qk', $locale, 'Written', '')
            : new HilosI18nCountryNameAddActionDTO('qj', 'qk', $locale, 'Written');
    }

    /**
     * Create the declared fixture through its command handler.
     *
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function seed(): void
    {
        $reply = $this->command(CliCommands::I18N_TEST_COUNTRY_NAMES_SEED);
        self::assertTrue($reply->isOk(), json_encode($reply->payload));
        self::assertSame([], $reply->payload);
    }

    /**
     * @param string $command Test command name
     * @return CommandReplyDTO Single correlated reply
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function command(string $command): CommandReplyDTO
    {
        $this->drain();
        $this->underAgent($this->agent, fn () => $this->agent->onSignalCommand(
            new CommandRequestDTO('fixture-command', $command), 'test', 'fixture-command',
        ));
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        self::assertCount(1, $replies);
        self::assertSame('fixture-command', $replies[0]->correlationId);
        return $replies[0];
    }

    /**
     * @param ActionPayloadDTO $dto Name request to serialize into the real parser
     * @param string $acceptKey Calling connection
     * @return PageActionSuccessSignalData|PageActionErrorSignalData Correlated outcome
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function dispatch(ActionPayloadDTO $dto, string $acceptKey = 'write-ak'): PageActionSuccessSignalData|PageActionErrorSignalData
    {
        $this->drain();
        $router = new PageSignalRouter(new HilosPageFactory($this->agent, Hilos::class), new ActionRouteConfig(Hilos::getPageActionRoutes()));
        $this->underAgent($this->agent, static fn () => $router->dispatchAction(
            new WebSocketActionSignalDTO($acceptKey, $dto->getAction(), $dto->toArray(), 'write-tracked'), 'websocket',
        ));
        $outcomes = [];
        foreach ($this->drain() as $frame) {
            if ($frame->data instanceof PageActionSuccessSignalData || $frame->data instanceof PageActionErrorSignalData) {
                self::assertSame($acceptKey, $frame->targetAcceptKey);
                $outcomes[] = $frame->data;
            }
        }
        self::assertCount(1, $outcomes);
        return $outcomes[0];
    }

    /**
     * @param ActionPayloadDTO $dto Request whose acknowledgment is checked
     * @param string $message Exact success copy
     * @throws HilosException When dispatch fails
     */
    private function assertSuccess(ActionPayloadDTO $dto, string $message): void
    {
        $outcome = $this->dispatch($dto);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $outcome);
        self::assertSame('write-tracked', $outcome->requestId);
        self::assertSame($dto->getAction(), $outcome->action);
        self::assertSame($message, $outcome->message);
        self::assertNull($outcome->reply);
        self::assertArrayNotHasKey('reply', $outcome->toArray());
    }

    /**
     * @param ActionPayloadDTO $dto Refused request
     * @param string $reason Exact refusal copy
     * @throws HilosException When dispatch or a data read fails
     */
    private function assertRefusal(ActionPayloadDTO $dto, string $reason): void
    {
        $before = $this->nameSnapshot();
        $failure = $this->dispatch($dto);
        self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
        self::assertSame('write-tracked', $failure->requestId);
        self::assertSame($dto->getAction(), $failure->action);
        self::assertSame('fail', $failure->getEnvelopeOutcome());
        self::assertSame($reason, $failure->reason);
        self::assertSame($before, $this->nameSnapshot());
    }

    /**
     * @param string $acceptKey Connection to register
     * @param ?bool $admin Admin/member status, or null for an anonymous session
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function connect(string $acceptKey, ?bool $admin): void
    {
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', uniqid($acceptKey, true)), 0, 32));
        if ($admin !== null) {
            $user = Hilos::$db->users->actions->createWithName('Name writing reader');
            $user->actions->setAdmin($admin);
            $session->actions->bindUser($user->id);
        }
        Hilos::$rt->connections->actions->register($acceptKey, $session->userId, $session->token, $session->id);
    }

    /**
     * @param string $acceptKey Connection opening the names page
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function subscribe(string $acceptKey): void
    {
        $params = ['countryCode' => 'qj'];
        $windows = [ChatTableContext::hilosI18nCountryNames => new TableWindowDescriptorDTO(
            filter: [HilosI18nCountryNamesTable::FILTER_COUNTRY => 'qj'],
        )];
        Hilos::$sr->subscribeToPage(CountryNamesPage::PAGE, new WebSocketPageSubscribeSignalDTO(
            $acceptKey, CountryNamesPage::PAGE, $params, $windows,
        ));
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $params): void {
            new CountryNamesPage($this->agent)->onSubscribe($acceptKey, new PageRouteParams($params));
        });
    }

    /**
     * @return list<WebSocketSignalData> Frames since the previous drain
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function drain(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = $signal->data;
            }
        }
        return $frames;
    }

    /**
     * @return ?CountryName Fixture base
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function base(): ?CountryName
    {
        return Hilos::$db->countryNames->findBase(Hilos::$db->countries['qj']->id, Hilos::$db->languages['qk']->id);
    }

    /**
     * @param string $localeCode Fixture correction's locale
     * @return ?CountryName Fixture correction
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function correction(string $localeCode): ?CountryName
    {
        return Hilos::$db->countryNames->findOverride(
            Hilos::$db->countries['qj']->id, Hilos::$db->languages['qk']->id, Hilos::$db->locales[$localeCode]->id,
        );
    }

    /**
     * @return array<int, array<string, mixed>> Stored rows keyed by id, to detect writes on refused requests
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function nameSnapshot(): array
    {
        $names = [];
        foreach (Hilos::$db->countryNames as $name) {
            $names[$name->id] = $name->toArray();
        }
        return $names;
    }

    /**
     * Test teardown removes only its own country/name coordinates and intentionally foreign markers.
     *
     * @throws HilosException When a fixture write or cleanup fails
     */
    private function clearTestRows(): void
    {
        $this->underAgent($this->agent, static function (): void {
            foreach (['qj', 'qx'] as $code) {
                if (Hilos::$db->countries[$code] === null) {
                    continue;
                }
                foreach (Hilos::$db->countryNames as $name) {
                    if ($name->countryId === Hilos::$db->countries[$code]->id) {
                        $name->actions->delete();
                    }
                }
            }
            foreach (['qk-GB', 'qk-US', 'qk-FR'] as $code) {
                Hilos::$db->locales[$code]?->actions->switchOff();
                Hilos::$db->locales[$code]?->actions->delete();
            }
            Hilos::$db->languages['qk']?->actions->delete();
            foreach (['qj', 'qx'] as $code) {
                Hilos::$db->countries[$code]?->actions->delete();
            }
        });
    }
}

/** Captures real committed name facts; the test hands them to the browser at the worker's flush boundary. */
final class I18nCountryNameWriteChanges implements SourceChangeSubscriberInterface
{
    public bool $active = true;

    /** @var list<SourceChange> Name facts emitted by the write */
    public array $changes = [];

    /**
     * @param SourceChange $change Announced source change
     * @param SourceChangeProvenance $provenance Source origin (unused)
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($this->active && $change->sourceKey === HilosDbContext::countryNames) {
            $this->changes[] = $change;
        }
    }
}

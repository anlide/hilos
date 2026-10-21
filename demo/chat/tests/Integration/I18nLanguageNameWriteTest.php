<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
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
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\View\Item\LanguageName;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\HilosException;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameOverrideDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameAddActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameUpdateActionDTO;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** Writing through the real admin page dispatcher, owner, fixture commands and browser windows. */
final class I18nLanguageNameWriteTest extends IntegrationTestCase
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
            Hilos::$db->languages->actions->create('qx', 'Other named language', false);
            Hilos::$db->countries['gb'] ?? Hilos::$db->countries->actions->create('gb', '£', 'GBP');
            Hilos::$db->countries['us'] ?? Hilos::$db->countries->actions->create('us', '$', 'USD');
        });
        $this->connect('write-ak', true);
        $this->seed();
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
                Hilos::$db->languages['qv']->actions->switchOn();
                Hilos::$db->languages['qw']->actions->switchOn();
                Hilos::$db->locales['qv-GB']->actions->switchOn();
            });
        }
        $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, $name));
        self::assertSame($name, $this->base()->name);
        self::assertTrue($this->base()->locked);
        $baseId = $this->base()->id;
        $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', 'qv-GB', $name));
        self::assertSame($name, $this->correction('qv-GB')->name);
        self::assertTrue($this->correction('qv-GB')->locked);
        $correctionId = $this->correction('qv-GB')->id;
        foreach ([null, 'qv-GB'] as $locale) {
            $message = $locale === null
                ? 'Name of HIL-1490 writing (qw) in HIL-1490 target (qv) saved.'
                : 'Correction qv-GB of the name of HIL-1490 writing (qw) saved.';
            $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameUpdateActionDTO::class, 'qw', 'qv', $locale, 'Temporary', $name), $message);
            $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameUpdateActionDTO::class, 'qw', 'qv', $locale, $name, 'Temporary'), $message);
        }
        self::assertSame($baseId, $this->base()->id);
        self::assertSame($correctionId, $this->correction('qv-GB')->id);
        self::assertSame($name, $this->base()->name);
        self::assertSame($name, $this->correction('qv-GB')->name);
        self::assertTrue($this->base()->locked);
        self::assertTrue($this->correction('qv-GB')->locked);
        self::assertSame($enabled, Hilos::$db->languages['qw']->enabled);
        self::assertSame($enabled, Hilos::$db->languages['qv']->enabled);
        self::assertSame($enabled, Hilos::$db->locales['qv-GB']->enabled);
    }

    /** @return iterable<string, array{?string, string}> Both rows, including a saved empty value */
    public static function storedNames(): iterable
    {
        foreach ([null, 'qv-GB'] as $locale) {
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
        $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, $name));
        if ($locale !== null) {
            $this->assertSuccess($this->nameRequest(
                HilosI18nLanguageNameAddActionDTO::class,
                'qw', 'qv', $locale, $name
            ), 'Correction qv-GB of the name of HIL-1490 writing (qw) added.');
        }
        $this->assertRefusal(
            $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', $locale, 'Overwrite'),
            'A name already exists for this language or locale.',
        );
        $this->assertRefusal(
            $this->nameRequest(HilosI18nLanguageNameUpdateActionDTO::class, 'qw', 'qv', $locale, 'Overwrite', 'Stale'),
            'The name changed elsewhere. Review the current value and save again.',
        );
        $this->assertSuccess(
            $this->nameRequest(HilosI18nLanguageNameUpdateActionDTO::class, 'qw', 'qv', $locale, 'Saved', $name),
            $locale === null
                ? 'Name of HIL-1490 writing (qw) in HIL-1490 target (qv) saved.'
                : 'Correction qv-GB of the name of HIL-1490 writing (qw) saved.',
        );
    }

    /** @throws HilosException When setup, dispatch or persistence fails */
    public function testEqualTextReplacementRefusesCapturedIdAndAcceptsNewId(): void
    {
        $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, 'Name'));
        $oldId = $this->base()->id;
        $captured = $this->nameRequest(HilosI18nLanguageNameUpdateActionDTO::class, 'qw', 'qv', null, 'New', 'Name');
        $this->underAgent($this->agent, fn () => $this->base()->actions->delete());
        $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, 'Name'));
        self::assertNotSame($oldId, $this->base()->id);
        $newId = $this->base()->id;
        $this->assertRefusal($captured, 'The name no longer exists.');
        self::assertSame($newId, $this->base()->id);
        self::assertSame('Name', $this->base()->name);
        $this->assertSuccess($this->nameRequest(
            HilosI18nLanguageNameUpdateActionDTO::class,
            'qw', 'qv', null, 'New', 'Name'
        ), 'Name of HIL-1490 writing (qw) in HIL-1490 target (qv) saved.');
    }

    /** @throws HilosException When fixture recreation or dispatch fails */
    public function testCapturedSubjectDoesNotResolveItsCodeAfterRecreation(): void
    {
        $captured = $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, 'Name');
        $oldId = $captured->toArray()['languageId'];
        $this->clearTestRows();
        $this->seed();
        $fresh = $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, 'Name');
        self::assertNotSame($oldId, $fresh->toArray()['languageId']);
        $this->assertRefusal($captured, 'Unknown language: ' . $oldId);
        self::assertNull($this->base());
        $this->assertSuccess($fresh, 'Name of HIL-1490 writing (qw) in HIL-1490 target (qv) added.');
        self::assertSame('Name', $this->base()->name);
    }

    /** @throws HilosException When setup, dispatch or persistence fails */
    public function testCorrectionRequestsRefuseRecreatedBaseAndLocale(): void
    {
        $this->assertSuccess(
            $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, 'Name'),
            'Name of HIL-1490 writing (qw) in HIL-1490 target (qv) added.',
        );
        $add = $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', 'qv-GB', 'Correction');
        $this->assertSuccess($add, 'Correction qv-GB of the name of HIL-1490 writing (qw) added.');
        $edit = $this->nameRequest(HilosI18nLanguageNameUpdateActionDTO::class, 'qw', 'qv', 'qv-GB', 'New', 'Correction');
        $remove = $this->nameRequest(HilosI18nLanguageNameOverrideDeleteActionDTO::class, 'qw', 'qv', 'qv-GB', 'Correction');
        $removeBase = $this->nameRequest(HilosI18nLanguageNameDeleteActionDTO::class, 'qw', 'qv', null, 'Name');
        $this->underAgent($this->agent, fn () => $this->base()->actions->delete());
        $this->assertSuccess(
            $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, 'Name'),
            'Name of HIL-1490 writing (qw) in HIL-1490 target (qv) added.',
        );
        $this->assertRefusal($add, 'The name no longer exists.');
        $this->assertSuccess(
            $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', 'qv-GB', 'Correction'),
            'Correction qv-GB of the name of HIL-1490 writing (qw) added.',
        );
        $before = $this->nameSnapshot();
        $this->assertRefusal($edit, 'The name no longer exists.');
        $this->assertRefusal($remove, 'The name no longer exists.');
        $this->assertRefusal($removeBase, 'The name no longer exists.');
        self::assertSame($before, $this->nameSnapshot());
        $this->underAgent($this->agent, fn () => $this->correction('qv-GB')->actions->delete());
        $add = $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', 'qv-GB', 'Correction');
        $oldLocaleId = Hilos::$db->locales['qv-GB']->id;
        $this->underAgent($this->agent, static function (): void {
            $formats = LocaleFormats::ofLocale(Hilos::$db->locales['qv-GB']);
            Hilos::$db->locales['qv-GB']->actions->delete();
            Hilos::$db->locales->actions->create(
                Hilos::$db->languages['qv'], Hilos::$db->countries['gb'],
                $formats->date, $formats->time, $formats->number, $formats->phone,
                $formats->address, $formats->measurement, $formats->collation,
            );
        });
        self::assertNotSame($oldLocaleId, Hilos::$db->locales['qv-GB']->id);
        $this->assertRefusal($add, 'Unknown locale: ' . $oldLocaleId);
        self::assertNull($this->correction('qv-GB'));
        $this->assertSuccess(
            $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', 'qv-GB', 'Correction'),
            'Correction qv-GB of the name of HIL-1490 writing (qw) added.',
        );
    }

    /** @throws HilosException When setup, dispatch or persistence fails */
    public function testNameIdsCannotCrossBaseCorrectionOrWritingLanguageCoordinates(): void
    {
        $this->assertSuccess(
            $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, 'Name'),
            'Name of HIL-1490 writing (qw) in HIL-1490 target (qv) added.',
        );
        $this->assertSuccess(
            $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', 'qv-GB', 'Name'),
            'Correction qv-GB of the name of HIL-1490 writing (qw) added.',
        );
        $foreign = $this->underAgent($this->agent, static fn () => Hilos::$db->languageNames->actions->createManual(
            Hilos::$db->languages['qw'], Hilos::$db->languages['en'], null, 'Name',
        ));
        $baseEdit = $this->nameRequest(HilosI18nLanguageNameUpdateActionDTO::class, 'qw', 'qv', null, 'New', 'Name');
        $baseDelete = $this->nameRequest(HilosI18nLanguageNameDeleteActionDTO::class, 'qw', 'qv', null, 'Name');
        $overrideDelete = $this->nameRequest(
            HilosI18nLanguageNameOverrideDeleteActionDTO::class, 'qw', 'qv', 'qv-GB', 'Name',
        );
        $before = $this->nameSnapshot();
        foreach ([$baseEdit, $baseDelete] as $request) {
            foreach ([$foreign->id, $this->correction('qv-GB')->id] as $nameId) {
                $this->assertRefusal(
                    $request::fromArray([...$request->toArray(), 'nameId' => $nameId]),
                    'The name no longer exists at these coordinates.',
                );
            }
        }
        $this->assertRefusal(
            $overrideDelete::fromArray([...$overrideDelete->toArray(), 'nameId' => $this->base()->id]),
            'The name no longer exists at these coordinates.',
        );
        $add = $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', 'qv-GB', 'New');
        $this->assertRefusal(
            $add::fromArray([...$add->toArray(), 'baseNameId' => $foreign->id]),
            'The name no longer exists.',
        );
        self::assertSame($before, $this->nameSnapshot());
    }

    /** @return iterable<string, array{bool, ?string}> Both actions and both row kinds */
    public static function doors(): iterable
    {
        foreach ([false, true] as $update) {
            foreach ([null, 'qv-GB'] as $locale) {
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
            $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, ''));
        }
        if ($update && $locale !== null) {
            $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', $locale, ''));
        }
        $dto = $update
            ? $this->nameRequest(HilosI18nLanguageNameUpdateActionDTO::class, 'qw', 'qv', $locale, str_repeat('é', 256), '')
            : $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', $locale, str_repeat('é', 256));
        $this->assertRefusal($dto, 'Name exceeds its column width');
    }

    /** @return iterable<string, array{bool, string}> Missing coordinates and rows */
    public static function missingCoordinates(): iterable
    {
        foreach ([false, true] as $update) {
            foreach (['language', 'writing-language', 'locale', 'base'] as $scenario) {
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
            $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, ''));
        }
        $language = $scenario === 'language' ? 'zz' : 'qw';
        $writing = $scenario === 'writing-language' ? 'zz' : 'qv';
        $locale = match ($scenario) {
            'locale' => 'qv-FR',
            'base', 'correction' => 'qv-GB',
            default => null,
        };
        $dto = $update
            ? $this->nameRequest(HilosI18nLanguageNameUpdateActionDTO::class, $language, $writing, $locale, 'Write', '')
            : $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, $language, $writing, $locale, 'Write');
        $this->assertRefusal($dto, match ($scenario) {
            'language' => 'Unknown language: ' . PHP_INT_MAX,
            'writing-language' => 'Unknown writing language: ' . PHP_INT_MAX,
            'locale' => 'Unknown locale: ' . PHP_INT_MAX,
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
        $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, ''));
        if ($update) {
            $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', 'qv-GB', ''));
        }
        $localeId = Hilos::$db->locales['qv-GB']->id;
        $writingId = Hilos::$db->languages['qv']->id;
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
                $this->request($update, 'qv-GB'),
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
                new LanguageNamesPage($this->agent)->onAction('write-ak', $action, $this->request(!$update, $locale));
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
            $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, ''));
        }
        if ($update && $locale !== null) {
            $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', $locale, ''));
        }
        $before = $this->nameSnapshot();
        $dto = $this->request($update, $locale);
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: 'test-agent:outsider'), function () use ($dto): void {
                new LanguageNamesPage($this->agent)->onAction('write-ak', $dto->getAction(), $dto);
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
            $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, ''));
        }
        if ($update && $locale !== null) {
            $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', $locale, ''));
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
            $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, 'Before'));
        }
        if ($update && $locale !== null) {
            $this->assertSuccess($this->nameRequest(
                HilosI18nLanguageNameAddActionDTO::class,
                'qw', 'qv', $locale, 'Before'
            ), 'Correction qv-GB of the name of HIL-1490 writing (qw) added.');
        }
        $this->connect('other-tab', true);
        foreach (['write-ak', 'other-tab'] as $acceptKey) {
            $this->subscribe($acceptKey);
        }
        $this->drain();
        $changes = new I18nNameWriteChanges();
        SourceChangeBus::subscribe($changes);
        $dto = $update
            ? $this->nameRequest(HilosI18nLanguageNameUpdateActionDTO::class, 'qw', 'qv', $locale, $name, 'Before')
            : $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', $locale, $name);
        try {
            $this->assertSuccess(
                $dto,
                ($locale === null
                    ? 'Name of HIL-1490 writing (qw) in HIL-1490 target (qv)'
                    : 'Correction qv-GB of the name of HIL-1490 writing (qw)')
                . ($update ? ' saved.' : ' added.'),
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
                    $cards[] = $frame->data->payload->toArray()[PagePayload::data][LanguageCardBrowserData::DATA];
                }
            }
            self::assertCount(1, $deltas);
            self::assertSame((string) Hilos::$db->languages['qv']->id, $deltas[0]->rowKey);
            $row = $deltas[0]->row[PagePayload::slots][HilosI18nLanguageNamesTable::ROW_SLOT];
            self::assertSame($locale === null ? $name : 'Before', $row['name']);
            self::assertCount(1, $row['corrections']);
            self::assertSame('qv-GB', $row['corrections'][0]['localeCode']);
            self::assertSame($locale === null ? null : $name, $row['corrections'][0]['name']);
            self::assertCount(1, $cards);
            self::assertSame($locale === null && $name === '' ? 0 : 1, $cards[0][LanguageCardBrowserData::FIELD_SUMMARY]['nameCount']);
        }
    }

    /** @throws HilosException When setup, dispatch or persistence fails */
    public function testExtendedSeedAndRepeatedClearPreserveOtherRows(): void
    {
        $countryId = Hilos::$db->countries['gb']->id;
        self::assertFalse(Hilos::$db->locales['qv-GB']->enabled);
        self::assertSame(Hilos::$db->languages['qv']->id, Hilos::$db->locales['qv-GB']->languageId);
        self::assertSame($countryId, Hilos::$db->locales['qv-GB']->countryId);
        self::assertNull($this->base());
        $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, ''));
        $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', 'qv-GB', ''));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            self::assertTrue($this->command(CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR)->isOk());
            foreach (['qv', 'qw'] as $code) {
                self::assertArrayNotHasKey($code, Hilos::$db->languages);
            }
            foreach (['qv-GB', 'qw-GB', 'qw-US'] as $code) {
                self::assertArrayNotHasKey($code, Hilos::$db->locales);
            }
            self::assertSame([], $this->nameSnapshot());
        }
        self::assertSame($countryId, Hilos::$db->countries['gb']->id);
        self::assertSame('Other named language', Hilos::$db->languages['qx']->nativeName);
    }

    /** @return iterable<string, array{string, string}> Both fixture commands check both markers */
    public static function foreignMarkers(): iterable
    {
        foreach ([CliCommands::I18N_TEST_LANGUAGE_NAMES_SEED, CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR] as $command) {
            foreach (['qv', 'qw'] as $code) {
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
    public function testForeignMarkerPreservesTheReversePair(string $command, string $code): void
    {
        $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', null, ''));
        $this->assertSuccess($this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', 'qv-GB', ''));
        $this->underAgent($this->agent, static fn () => Hilos::$db->languages[$code]->actions->update('Foreign row', false));
        $before = $this->nameSnapshot();
        $reply = $this->command($command);
        self::assertFalse($reply->isOk());
        self::assertSame("Language '{$code}' is not the language names fixture.", $reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertSame($before, $this->nameSnapshot());
        foreach (['qv-GB', 'qw-GB', 'qw-US'] as $locale) {
            self::assertArrayHasKey($locale, Hilos::$db->locales);
        }
        self::assertArrayHasKey('qv', Hilos::$db->languages);
        self::assertArrayHasKey('qw', Hilos::$db->languages);
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
            ? $this->nameRequest(HilosI18nLanguageNameUpdateActionDTO::class, 'qw', 'qv', $locale, 'Written', '')
            : $this->nameRequest(HilosI18nLanguageNameAddActionDTO::class, 'qw', 'qv', $locale, 'Written');
    }

    /**
     * Create the declared fixture through its command handler.
     *
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function seed(): void
    {
        $reply = $this->command(CliCommands::I18N_TEST_LANGUAGE_NAMES_SEED);
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
     * @param ?string $message Exact success copy, or null for the standard fixture message
     * @throws HilosException When dispatch fails
     */
    private function assertSuccess(ActionPayloadDTO $dto, ?string $message = null): void
    {
        $outcome = $this->dispatch($dto);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $outcome);
        self::assertSame('write-tracked', $outcome->requestId);
        self::assertSame($dto->getAction(), $outcome->action);
        if ($message !== null) {
            self::assertSame($message, $outcome->message);
        } else {
            $isUpdate = $dto instanceof HilosI18nLanguageNameUpdateActionDTO;
            $locale = $dto->localeId === null
                ? null
                : (isset(Hilos::$db->locales[$dto->localeId]) ? Hilos::$db->locales[$dto->localeId]->code : 'qv-GB');
            $expected = ($locale === null
                ? 'Name of HIL-1490 writing (qw) in HIL-1490 target (qv)'
                : "Correction {$locale} of the name of HIL-1490 writing (qw)")
                . ($isUpdate ? ' saved.' : ' added.');
            self::assertSame($expected, $outcome->message);
        }
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
        $params = ['languageCode' => 'qw'];
        $windows = [ChatTableContext::hilosI18nLanguageNames => new TableWindowDescriptorDTO(
            filter: [
                HilosI18nLanguageNamesTable::FILTER_LANGUAGE => isset(Hilos::$db->languages['qw'])
                    ? Hilos::$db->languages['qw']->id
                    : 999999,
            ],
        )];
        Hilos::$sr->subscribeToPage(LanguageNamesPage::PAGE, new WebSocketPageSubscribeSignalDTO(
            $acceptKey, LanguageNamesPage::PAGE, $params, $windows,
        ));
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $params): void {
            new LanguageNamesPage($this->agent)->onSubscribe($acceptKey, new PageRouteParams($params));
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
     * @return ?LanguageName Fixture base
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function base(): ?LanguageName
    {
        return Hilos::$db->languageNames->findBase(Hilos::$db->languages['qw']->id, Hilos::$db->languages['qv']->id);
    }

    /**
     * @param string $localeCode Fixture correction's locale
     * @return ?LanguageName Fixture correction
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function correction(string $localeCode): ?LanguageName
    {
        return Hilos::$db->languageNames->findOverride(
            Hilos::$db->languages['qw']->id, Hilos::$db->languages['qv']->id, Hilos::$db->locales[$localeCode]->id,
        );
    }

    /**
     * @return array<int, array<string, mixed>> Stored rows keyed by id, to detect writes on refused requests
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function nameSnapshot(): array
    {
        $names = [];
        foreach (Hilos::$db->languageNames as $name) {
            $names[$name->id] = $name->toArray();
        }
        return $names;
    }

    /**
     * Test cleanup also handles the intentionally foreign marker and names used by refusal cases.
     *
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function clearTestRows(): void
    {
        $this->underAgent($this->agent, static function (): void {
            $ids = [];
            foreach (['qv', 'qw', 'qx'] as $code) {
                if (isset(Hilos::$db->languages[$code])) {
                    $ids[] = Hilos::$db->languages[$code]->id;
                }
            }
            // Reference collections are mounted whole; a walk keeps its keys when item actions remove rows.
            foreach (Hilos::$db->languageNames as $name) {
                if (in_array($name->languageId, $ids, true) || in_array($name->inLanguageId, $ids, true)) {
                    $name->actions->delete();
                }
            }
            foreach (Hilos::$db->locales as $locale) {
                if (in_array($locale->languageId, $ids, true)) {
                    $locale->actions->switchOff();
                    $locale->actions->delete();
                }
            }
            foreach (['qv', 'qw', 'qx'] as $code) {
                if (isset(Hilos::$db->languages[$code])) {
                    Hilos::$db->languages[$code]->actions->delete();
                }
            }
        });
    }
    /**
     * Capture fixture IDs at the point the client builds its request.
     *
     * @param class-string<ActionPayloadDTO> $class Action payload class
     * @param string $subjectCode Fixture subject code
     * @param string $writingCode Fixture writing language code
     * @param ?string $localeCode Fixture correction locale, or base
     * @param string $text New or confirmed text
     * @param ?string $expectedName Latest committed text for update
     * @return ActionPayloadDTO Request carrying captured primary IDs
     * @throws HilosException When fixture lookup or payload validation fails
     */
    private function nameRequest(
        string $class,
        string $subjectCode,
        string $writingCode,
        ?string $localeCode,
        string $text,
        ?string $expectedName = null,
    ): ActionPayloadDTO {
        $subjectId = isset(Hilos::$db->languages[$subjectCode]) ? Hilos::$db->languages[$subjectCode]->id : PHP_INT_MAX;
        $writingId = isset(Hilos::$db->languages[$writingCode]) ? Hilos::$db->languages[$writingCode]->id : PHP_INT_MAX;
        $localeId = $localeCode === null
            ? null
            : (isset(Hilos::$db->locales[$localeCode]) ? Hilos::$db->locales[$localeCode]->id : PHP_INT_MAX);
        $base = Hilos::$db->languageNames->findBase($subjectId, $writingId);
        if ($class === HilosI18nLanguageNameAddActionDTO::class) {
            return new HilosI18nLanguageNameAddActionDTO(
                $subjectId, $writingId, $localeId, $localeId === null ? null : ($base?->id ?? PHP_INT_MAX), $text,
            );
        }
        $nameId = ($localeId === null ? $base : Hilos::$db->languageNames->findOverride($subjectId, $writingId, $localeId))?->id
            ?? PHP_INT_MAX;
        if ($class === HilosI18nLanguageNameDeleteActionDTO::class) {
            return new HilosI18nLanguageNameDeleteActionDTO($subjectId, $writingId, $nameId, $text);
        }
        if ($class === HilosI18nLanguageNameOverrideDeleteActionDTO::class) {
            return new HilosI18nLanguageNameOverrideDeleteActionDTO($subjectId, $writingId, $localeId, $nameId, $text);
        }

        return new HilosI18nLanguageNameUpdateActionDTO($subjectId, $writingId, $localeId, $nameId, $text, $expectedName);
    }
}

/** Captures real committed name facts; the test hands them to the browser at the worker's flush boundary. */
final class I18nNameWriteChanges implements SourceChangeSubscriberInterface
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
        if ($this->active && $change->sourceKey === HilosDbContext::languageNames) {
            $this->changes[] = $change;
        }
    }

}

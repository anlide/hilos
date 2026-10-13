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
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\View\Item\LanguageName;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameOverrideDeleteActionDTO;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** Removal through the real admin page dispatcher, owner, fixture commands and browser windows. */
final class I18nLanguageNameDeleteTest extends IntegrationTestCase
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
        $this->connect('delete-ak', true);
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

    /** @return iterable<string, array{string, bool}> Empty and nonempty names in both switch positions */
    public static function nameStates(): iterable
    {
        yield 'nonempty disabled' => ['Fixture base', false];
        yield 'nonempty enabled' => ['Fixture base', true];
        yield 'empty disabled' => ['', false];
        yield 'empty enabled' => ['', true];
    }

    /**
     * @param string $expectedName Literal base to confirm
     * @param bool $enabled Whether parent and locale rows are enabled
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    #[DataProvider('nameStates')]
    public function testBaseDeletionRemovesAllCurrentCorrectionsAndKeepsOtherNames(string $expectedName, bool $enabled): void
    {
        $this->seed();
        $this->underAgent($this->agent, function () use ($expectedName, $enabled): void {
            $this->base()->actions->edit($expectedName);
            // Corrections may change while confirmation is open: the base deletion takes all current ones.
            $this->correction('qw-GB')->actions->edit('Changed after opening');
            Hilos::$db->languageNames->actions->createManual(Hilos::$db->languages['qv'], Hilos::$db->languages['en'], null, 'Other base');
            Hilos::$db->languageNames->actions->createManual(Hilos::$db->languages['qx'], Hilos::$db->languages['qw'], null, 'Other subject');
            Hilos::$db->languageNames->actions->createManual(
                Hilos::$db->languages['qx'], Hilos::$db->languages['qw'], Hilos::$db->locales['qw-GB'], 'Other correction',
            );
            if ($enabled) {
                Hilos::$db->languages['qv']->actions->switchOn();
                Hilos::$db->languages['qw']->actions->switchOn();
                Hilos::$db->locales['qw-GB']->actions->switchOn();
                Hilos::$db->locales['qw-US']->actions->switchOn();
            }
        });
        self::assertTrue($this->base()->locked);
        $this->assertSuccess($this->dispatch(new HilosI18nLanguageNameDeleteActionDTO('qv', 'qw', $expectedName)), false);
        self::assertNull($this->base());
        self::assertNull($this->correction('qw-GB'));
        self::assertNull($this->correction('qw-US'));
        self::assertSame('Other base', Hilos::$db->languageNames->findBase(Hilos::$db->languages['qv']->id, Hilos::$db->languages['en']->id)->name);
        self::assertSame('Other subject', Hilos::$db->languageNames->findBase(
            Hilos::$db->languages['qx']->id, Hilos::$db->languages['qw']->id,
        )->name);
        self::assertSame('Other correction', Hilos::$db->languageNames->findOverride(
            Hilos::$db->languages['qx']->id, Hilos::$db->languages['qw']->id, Hilos::$db->locales['qw-GB']->id,
        )->name);
        foreach (['qv', 'qw'] as $code) {
            self::assertSame($enabled, Hilos::$db->languages[$code]->enabled);
        }
        foreach (['qw-GB', 'qw-US'] as $code) {
            self::assertSame($enabled, Hilos::$db->locales[$code]->enabled);
        }
    }

    /**
     * @param string $expectedName Literal correction to confirm
     * @param bool $enabled Whether the parent and locale are enabled
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    #[DataProvider('nameStates')]
    public function testCorrectionDeletionKeepsTheBaseAndSibling(string $expectedName, bool $enabled): void
    {
        $this->seed();
        $this->underAgent($this->agent, function () use ($expectedName, $enabled): void {
            $this->correction('qw-GB')->actions->edit($expectedName);
            if ($enabled) {
                Hilos::$db->languages['qv']->actions->switchOn();
                Hilos::$db->languages['qw']->actions->switchOn();
                Hilos::$db->locales['qw-GB']->actions->switchOn();
            }
        });
        self::assertTrue($this->correction('qw-GB')->locked);
        $this->assertSuccess($this->dispatch(new HilosI18nLanguageNameOverrideDeleteActionDTO('qv', 'qw', 'qw-GB', $expectedName)), true);
        self::assertSame('Fixture base', $this->base()->name);
        self::assertNull($this->correction('qw-GB'));
        self::assertSame('', $this->correction('qw-US')->name);
        self::assertSame($enabled, Hilos::$db->locales['qw-GB']->enabled);
    }

    /** @return iterable<string, array{bool, string}> Each door's stale and missing coordinates */
    public static function refusals(): iterable
    {
        foreach ([false, true] as $override) {
            foreach (['changed', 'missing-name', 'missing-language', 'missing-writing-language'] as $scenario) {
                yield ($override ? 'correction ' : 'base ') . $scenario => [$override, $scenario];
            }
        }
        yield 'correction without base' => [true, 'missing-base'];
        yield 'correction without locale' => [true, 'missing-locale'];
    }

    /**
     * @param bool $override Whether this is the correction door
     * @param string $scenario Change made before confirmation
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    #[DataProvider('refusals')]
    public function testTrackedRefusalPreservesEveryRemainingName(bool $override, string $scenario): void
    {
        $this->seed();
        $this->underAgent($this->agent, function () use ($override, $scenario): void {
            if ($scenario === 'changed') {
                ($override ? $this->correction('qw-GB') : $this->base())->actions->edit('Changed');
            } elseif ($scenario === 'missing-name') {
                ($override ? $this->correction('qw-GB') : $this->base())->actions->delete();
            } elseif ($scenario === 'missing-base') {
                $this->base()->actions->delete();
            }
        });
        $before = $this->nameSnapshot();
        $language = $scenario === 'missing-language' ? 'zz' : 'qv';
        $writing = $scenario === 'missing-writing-language' ? 'zz' : 'qw';
        $dto = $override
            ? new HilosI18nLanguageNameOverrideDeleteActionDTO(
                $language, $writing, $writing . ($scenario === 'missing-locale' ? '-FR' : '-GB'), 'Fixture correction',
            )
            : new HilosI18nLanguageNameDeleteActionDTO($language, $writing, 'Fixture base');
        $failure = $this->dispatch($dto);
        self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
        self::assertSame('delete-tracked', $failure->requestId);
        self::assertSame($dto->getAction(), $failure->action);
        self::assertSame('fail', $failure->getEnvelopeOutcome());
        self::assertSame(match ($scenario) {
            'changed' => 'The name changed. Close this window and confirm again.',
            'missing-language' => 'Unknown language: zz',
            'missing-writing-language' => 'Unknown writing language: zz',
            'missing-locale' => 'Unknown locale: qw-FR',
            default => 'The name no longer exists.',
        }, $failure->reason);
        self::assertSame($before, $this->nameSnapshot());
    }

    /** @return iterable<string, array{bool}> Corrupted locale relations the DTO cannot see */
    public static function invalidLocaleRelations(): iterable
    {
        yield 'foreign language' => [false];
        yield 'countryless locale' => [true];
    }

    /**
     * The ORM makes a locale's coordinates immutable; SQL here constructs the inconsistent read
     * boundary to prove the page rechecks both relations instead of trusting a well-formed code.
     *
     * @param bool $countryless Whether the country, rather than language relation, is inconsistent
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    #[DataProvider('invalidLocaleRelations')]
    public function testCorrectionRechecksTheStoredLocaleRelations(bool $countryless): void
    {
        $this->seed();
        $localeId = Hilos::$db->locales['qw-GB']->id;
        $writingId = Hilos::$db->languages['qw']->id;
        $countryId = Hilos::$db->countries['gb']->id;
        $before = $this->nameSnapshot();
        try {
            Database::sqlRun('UPDATE hilos_locale SET language_id = ?, country_id = ? WHERE id = ?', [
                $countryless ? $writingId : Hilos::$db->languages['en']->id,
                $countryless ? null : $countryId,
                $localeId,
            ]);
            Hilos::$db->locales->getObjectCollection()->reHydrate();
            Hilos::$db->locales->clearCache();
            $failure = $this->dispatch($this->request(true));
            self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
            self::assertSame('The correction must belong to a country locale of the writing language.', $failure->reason);
            self::assertSame($before, $this->nameSnapshot());
        } finally {
            Database::sqlRun('UPDATE hilos_locale SET language_id = ?, country_id = ? WHERE id = ?', [$writingId, $countryId, $localeId]);
            Hilos::$db->locales->getObjectCollection()->reHydrate();
            Hilos::$db->locales->clearCache();
        }
    }

    /**
     * SQL constructs an orphan the normal cascading item action deliberately cannot leave.
     *
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    public function testCorrectionCannotBeDeletedAfterItsBaseIsMissing(): void
    {
        $this->seed();
        Database::sqlRun('DELETE FROM hilos_language_name WHERE id = ?', [$this->base()->id]);
        Hilos::$db->languageNames->getObjectCollection()->reHydrate();
        Hilos::$db->languageNames->clearCache();
        $before = $this->nameSnapshot();
        $failure = $this->dispatch($this->request(true));
        self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
        self::assertSame('The name no longer exists.', $failure->reason);
        self::assertSame($before, $this->nameSnapshot());
        self::assertSame('Fixture correction', $this->correction('qw-GB')->name);
    }

    /**
     * A refused locale write rolls back the languages and base created earlier in the seed transaction.
     *
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    public function testFailedSeedLeavesNoPartialFixtureOrSuccessReply(): void
    {
        TruthSourceRegistry::unregister(HilosDbContext::locales, $this->agent->getId());
        try {
            $reply = $this->command(CliCommands::I18N_TEST_LANGUAGE_NAMES_SEED);
            self::assertFalse($reply->isOk());
            self::assertNull(Hilos::$db->languages['qv']);
            self::assertNull(Hilos::$db->languages['qw']);
            self::assertNull(Hilos::$db->locales['qw-GB']);
            self::assertSame([], $this->nameSnapshot());
        } finally {
            TruthSourceRegistry::register(HilosDbContext::locales, TruthSourceKeys::all(), $this->agent->getId());
        }
        self::assertTrue($this->command(CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR)->isOk());
        $this->seed();
    }

    /** @return iterable<string, array{bool}> Both deletion doors */
    public static function doors(): iterable
    {
        yield 'base' => [false];
        yield 'correction' => [true];
    }

    /**
     * @param bool $override Door receiving the other's DTO
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    #[DataProvider('doors')]
    public function testWrongDtoIsRefusedBeforeMutation(bool $override): void
    {
        $this->seed();
        $before = $this->nameSnapshot();
        try {
            new LanguageNamesPage($this->agent)->onAction('delete-ak', $this->request($override)->getAction(), $this->request(!$override));
            self::fail('A mismatched DTO must be refused');
        } catch (InvalidActionPayloadException) {
            self::assertSame($before, $this->nameSnapshot());
        }
    }

    /**
     * An unknown action never reaches the write door.
     *
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    public function testUnknownActionIsRefusedBeforeMutation(): void
    {
        $this->seed();
        $before = $this->nameSnapshot();
        try {
            new LanguageNamesPage($this->agent)->onAction('delete-ak', 'unknown', $this->request(false));
            self::fail('An unknown action must be refused');
        } catch (AgentUnknownActionException) {
            self::assertSame($before, $this->nameSnapshot());
        }
    }

    /**
     * @param bool $override Door whose item write needs the library's claim
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    #[DataProvider('doors')]
    public function testDeletionRequiresTheOwningAgent(bool $override): void
    {
        $this->seed();
        $before = $this->nameSnapshot();
        $dto = $this->request($override);
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: 'test-agent:outsider'), function () use ($dto): void {
                new LanguageNamesPage($this->agent)->onAction('delete-ak', $dto->getAction(), $dto);
            });
            self::fail('An unrelated agent is not the i18n owner');
        } catch (WriteNotAllowedException) {
            self::assertSame($before, $this->nameSnapshot());
        }
    }

    /** @return iterable<string, array{bool, bool, bool}> Both doors under guests and members, with and without view mode */
    public static function viewers(): iterable
    {
        foreach ([false, true] as $override) {
            foreach ([false, true] as $signedIn) {
                foreach ([false, true] as $viewMode) {
                    yield implode('-', [(int)$override, (int)$signedIn, (int)$viewMode]) => [$override, $signedIn, $viewMode];
                }
            }
        }
    }

    /**
     * @param bool $override Door being called
     * @param bool $signedIn Whether the viewer has an account
     * @param bool $viewMode Whether the admin view mode is open
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    #[DataProvider('viewers')]
    public function testNonAdminsCannotDelete(bool $override, bool $signedIn, bool $viewMode): void
    {
        $this->seed();
        $this->connect('viewer', $signedIn ? false : null);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set($viewMode);
        if ($viewMode) {
            $this->subscribe('viewer');
            $frames = $this->drain();
            self::assertNotEmpty($frames);
            self::assertInstanceOf(PageResponseSignalData::class, $frames[0]->data);
            self::assertSame('HIL-1490 target', $frames[0]->data->payload->toArray()[PagePayload::data][LanguageCardBrowserData::DATA]['nativeName']);
        }
        $before = $this->nameSnapshot();
        $failure = $this->dispatch($this->request($override), 'viewer');
        self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
        self::assertSame($viewMode ? 'view_mode' : ($signedIn ? 'forbidden' : 'unauthorized'), $failure->errorCode);
        self::assertNull($failure->errorType);
        self::assertNull($failure->errorDetail);
        self::assertSame($before, $this->nameSnapshot());
    }

    /**
     * @param bool $override Whether only the correction is deleted
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    #[DataProvider('doors')]
    public function testBothNamesSubscribersReceiveValuesAndCardWithoutRowRemoval(bool $override): void
    {
        $this->seed();
        $this->connect('other-tab', true);
        foreach (['delete-ak', 'other-tab'] as $acceptKey) {
            $this->subscribe($acceptKey);
        }
        $this->drain();
        $changes = new I18nNameDeletionChanges();
        SourceChangeBus::subscribe($changes);
        try {
            $this->assertSuccess($this->dispatch($this->request($override)), $override);
        } finally {
            $changes->active = false;
        }
        self::assertCount($override ? 1 : 3, $changes->changes);
        foreach ($changes->changes as $change) {
            Hilos::$browser->record($change);
        }
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $frames = $this->drain();
        foreach (['delete-ak', 'other-tab'] as $acceptKey) {
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
            self::assertSame('qw', $deltas[0]->rowKey);
            $row = $deltas[0]->row[PagePayload::slots][HilosI18nLanguageNamesTable::ROW_SLOT];
            self::assertSame($override ? 'Fixture base' : null, $row['name']);
            if ($override) {
                self::assertCount(2, $row['corrections']);
                self::assertSame('qw-GB', $row['corrections'][0]['localeCode']);
                self::assertNull($row['corrections'][0]['name']);
                self::assertSame('', $row['corrections'][1]['name']);
            } else {
                self::assertSame([], $row['corrections']);
            }
            self::assertCount(1, $cards);
            self::assertSame($override ? 1 : 0, $cards[0][LanguageCardBrowserData::FIELD_SUMMARY]['nameCount']);
        }
    }

    /**
     * The fixed fixture is exact and repeated cleanup preserves unrelated rows.
     *
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    public function testSeedAndRepeatedClearUseTheRealAgentCommand(): void
    {
        $countries = [Hilos::$db->countries['gb']->id, Hilos::$db->countries['us']->id];
        $this->seed();
        self::assertSame('HIL-1490 target', Hilos::$db->languages['qv']->nativeName);
        self::assertSame('HIL-1490 writing', Hilos::$db->languages['qw']->nativeName);
        foreach (['qv', 'qw'] as $code) {
            self::assertFalse(Hilos::$db->languages[$code]->enabled);
        }
        foreach (['qw-GB', 'qw-US'] as $code) {
            self::assertFalse(Hilos::$db->locales[$code]->enabled);
        }
        self::assertSame('Fixture base', $this->base()->name);
        self::assertSame('Fixture correction', $this->correction('qw-GB')->name);
        self::assertSame('', $this->correction('qw-US')->name);
        $before = $this->nameSnapshot();
        self::assertFalse($this->command(CliCommands::I18N_TEST_LANGUAGE_NAMES_SEED)->isOk());
        self::assertSame($before, $this->nameSnapshot());
        foreach ([1, 2] as $attempt) {
            $reply = $this->command(CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR);
            self::assertTrue($reply->isOk());
            self::assertSame([], $reply->payload);
            self::assertNull(Hilos::$db->languages['qv']);
            self::assertNull(Hilos::$db->languages['qw']);
            self::assertNull(Hilos::$db->locales['qw-GB']);
            self::assertNull(Hilos::$db->locales['qw-US']);
        }
        self::assertSame($countries, [Hilos::$db->countries['gb']->id, Hilos::$db->countries['us']->id]);
        self::assertSame('Other named language', Hilos::$db->languages['qx']->nativeName);
        self::assertNotNull(Hilos::$db->languages['en']);
    }

    /** @return iterable<string, array{string, string}> Each command with either foreign marker */
    public static function foreignMarkers(): iterable
    {
        foreach ([CliCommands::I18N_TEST_LANGUAGE_NAMES_SEED, CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR] as $command) {
            foreach (['qv', 'qw'] as $code) {
                yield $command . ' ' . $code => [$command, $code];
            }
        }
    }

    /**
     * @param string $command Command to refuse
     * @param string $code Foreign fixture address
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    #[DataProvider('foreignMarkers')]
    public function testForeignMarkerRefusesBeforeAnyFixtureWrite(string $command, string $code): void
    {
        $this->seed();
        $this->underAgent($this->agent, static fn () => Hilos::$db->languages[$code]->actions->update('Foreign row', false));
        $before = $this->nameSnapshot();
        $reply = $this->command($command);
        self::assertFalse($reply->isOk());
        self::assertSame("Language '{$code}' is not the language names fixture.", $reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertSame('Foreign row', Hilos::$db->languages[$code]->nativeName);
        self::assertSame($before, $this->nameSnapshot());
        self::assertNotNull(Hilos::$db->languages['qv']);
        self::assertNotNull(Hilos::$db->languages['qw']);
        self::assertNotNull(Hilos::$db->locales['qw-GB']);
        self::assertNotNull(Hilos::$db->locales['qw-US']);
    }

    /**
     * A partial cleanup stays resumable without widening its coordinates.
     *
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    public function testClearCanResumeAfterARefusalWithoutRemovingUnrelatedRows(): void
    {
        $this->seed();
        $other = $this->underAgent($this->agent, static fn () => Hilos::$db->languageNames->actions->createManual(
            Hilos::$db->languages['qx'], Hilos::$db->languages['qw'], null, 'Do not clean this name',
        ));
        self::assertFalse($this->command(CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR)->isOk());
        self::assertNull(Hilos::$db->languages['qv']);
        self::assertNotNull(Hilos::$db->languages['qw']);
        self::assertSame('Do not clean this name', Hilos::$db->languageNames[$other->id]->name);
        $this->underAgent($this->agent, static fn () => $other->actions->delete());
        self::assertTrue($this->command(CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR)->isOk());
        self::assertNull(Hilos::$db->languages['qw']);
        self::assertNotNull(Hilos::$db->languages['qx']);
    }

    /**
     * @param bool $override Requested door
     * @return ActionPayloadDTO Valid confirmation for the fixture
     * @throws InvalidFormatException When fixture coordinates violate the DTO contract
     */
    private function request(bool $override): ActionPayloadDTO
    {
        return $override
            ? new HilosI18nLanguageNameOverrideDeleteActionDTO('qv', 'qw', 'qw-GB', 'Fixture correction')
            : new HilosI18nLanguageNameDeleteActionDTO('qv', 'qw', 'Fixture base');
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
     * @param ActionPayloadDTO $dto Confirmation to serialize into the real parser
     * @param string $acceptKey Calling connection
     * @return PageActionSuccessSignalData|PageActionErrorSignalData Correlated outcome
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function dispatch(ActionPayloadDTO $dto, string $acceptKey = 'delete-ak'): PageActionSuccessSignalData|PageActionErrorSignalData
    {
        $this->drain();
        $router = new PageSignalRouter(new HilosPageFactory($this->agent, Hilos::class), new ActionRouteConfig(Hilos::getPageActionRoutes()));
        $this->underAgent($this->agent, static fn () => $router->dispatchAction(
            new WebSocketActionSignalDTO($acceptKey, $dto->getAction(), $dto->toArray(), 'delete-tracked'), 'websocket',
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
     * @param PageActionSuccessSignalData|PageActionErrorSignalData $outcome Tracked result
     * @param bool $override Whether only a correction was removed
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function assertSuccess(PageActionSuccessSignalData|PageActionErrorSignalData $outcome, bool $override): void
    {
        self::assertInstanceOf(PageActionSuccessSignalData::class, $outcome);
        self::assertSame('delete-tracked', $outcome->requestId);
        self::assertSame($this->request($override)->getAction(), $outcome->action);
        self::assertSame($override ? 'Locale correction removed.' : 'Language name deleted.', $outcome->message);
        self::assertNull($outcome->reply);
        self::assertArrayNotHasKey('reply', $outcome->toArray());
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
            $user = Hilos::$db->users->actions->createWithName('Name deletion reader');
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
        $params = ['languageCode' => 'qv'];
        $windows = [ChatTableContext::hilosI18nLanguageNames => new TableWindowDescriptorDTO(
            filter: [HilosI18nLanguageNamesTable::FILTER_LANGUAGE => 'qv'],
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
        return Hilos::$db->languageNames->findBase(Hilos::$db->languages['qv']->id, Hilos::$db->languages['qw']->id);
    }

    /**
     * @param string $localeCode Fixture correction's locale
     * @return ?LanguageName Fixture correction
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function correction(string $localeCode): ?LanguageName
    {
        return Hilos::$db->languageNames->findOverride(
            Hilos::$db->languages['qv']->id, Hilos::$db->languages['qw']->id, Hilos::$db->locales[$localeCode]->id,
        );
    }

    /**
     * @return array<int, string> Literal values keyed by id, to detect writes on refused requests
     * @throws HilosException When fixture setup, dispatch or a data read fails
     */
    private function nameSnapshot(): array
    {
        $names = [];
        foreach (Hilos::$db->languageNames as $name) {
            $names[$name->id] = $name->name;
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
                if (Hilos::$db->languages[$code] !== null) {
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
                Hilos::$db->languages[$code]?->actions->delete();
            }
        });
    }
}

/** Captures real committed name facts; the test hands them to the browser at the worker's flush boundary. */
final class I18nNameDeletionChanges implements SourceChangeSubscriberInterface
{
    public bool $active = true;

    /** @var list<SourceChange> Name facts emitted by the deletion */
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

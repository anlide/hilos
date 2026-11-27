<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n;

use Generator;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\Languages;
use Hilos\Groups\AbstractHilosLanguagesGroup;
use Hilos\Hilos;
use Hilos\I18n\AvailableLanguagesAudience;
use Hilos\I18n\DTO\AvailableLanguageRow;
use Hilos\I18n\DTO\AvailableLanguagesSignalData;
use Hilos\I18n\DTO\AvailableLocaleRow;
use Hilos\I18n\Library\I18nLibraryAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises the process-local reaction and the queued frame without a database. */
final class AvailableLanguagesAudienceTest extends TestCase
{
    private ?HilosDbContext $previousDb;
    private ?SignalRouter $previousRouter;
    private I18nLibraryAgent $agent;
    private AvailableLanguagesSignalData $snapshot;

    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
        $this->previousRouter = Hilos::$sr;
        AvailableLanguagesAudience::reset();
        $this->agent = new I18nLibraryAgent();
        $this->snapshot = new AvailableLanguagesSignalData([
            new AvailableLanguageRow(1, 'en', 'English', [
                new AvailableLocaleRow(2, 'en', null, null),
                new AvailableLocaleRow(3, 'en-GB', 'gb', 'United Kingdom'),
            ]),
        ]);

        $languages = $this->createStub(Languages::class);
        $languages->method('available')->willReturn($this->snapshot);
        Hilos::$db = $this->createStub(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturnMap([[HilosDbContext::languages, $languages]]);
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        AvailableLanguagesAudience::reset();
        Hilos::$sr = $this->previousRouter;
        Hilos::$db = $this->previousDb;
    }

    #[DataProvider('sourceChanges')]
    public function testOnlyLocalCatalogWritesQueueOneGroupFrame(
        SourceChange $change,
        SourceChangeProvenance $provenance,
        bool $shouldSend,
    ): void {
        $audience = new AvailableLanguagesAudience();
        $audience->onSourceChange($change, $provenance);
        $audience->onSourceChange($change, $provenance);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        AvailableLanguagesAudience::onAgentTick($this->agent);
        $signal = Hilos::$sr->getNextQueuedSignal();

        if ($shouldSend) {
            self::assertNotNull($signal);
            $signal = SignalDTO::fromArray($signal->toArray());
            self::assertSame('hilos_languages_state', $signal->signalName->getName());
            self::assertSame(SignalTypeConstants::WS_GROUP, $signal->signalType->getType());
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertSame(AbstractHilosLanguagesGroup::GROUP, $signal->data->targetGroup);
            self::assertInstanceOf(AvailableLanguagesSignalData::class, $signal->data->data);
            self::assertSame($this->snapshot->toArray(), $signal->data->data->toArray());
        } else {
            self::assertNull($signal);
        }

        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        AvailableLanguagesAudience::onAgentTick($this->agent);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** A failed snapshot read leaves the committed change pending for the next tick. */
    public function testFailedSnapshotIsRetriedWithoutAnotherWrite(): void
    {
        $failure = new DatabaseException('Catalog read failed');
        $workingDb = Hilos::$db;
        $languages = $this->createStub(Languages::class);
        $languages->method('available')->willThrowException($failure);
        Hilos::$db = $this->createStub(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturnMap([[HilosDbContext::languages, $languages]]);

        new AvailableLanguagesAudience()->onSourceChange(
            SourceChange::dbUpdated(HilosDbContext::languages, '1', []),
            SourceChangeProvenance::LocalWrite,
        );

        try {
            AvailableLanguagesAudience::onAgentTick($this->agent);
            self::fail('The catalog read failure must escape the tick');
        } catch (DatabaseException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertNull(Hilos::$sr->getNextQueuedSignal());

        Hilos::$db = $workingDb;
        AvailableLanguagesAudience::onAgentTick($this->agent);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame('hilos_languages_state', $signal->signalName->getName());
        self::assertSame(SignalTypeConstants::WS_GROUP, $signal->signalType->getType());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        self::assertSame(AbstractHilosLanguagesGroup::GROUP, $signal->data->targetGroup);
        self::assertInstanceOf(AvailableLanguagesSignalData::class, $signal->data->data);
        self::assertSame($this->snapshot->toArray(), $signal->data->data->toArray());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());

        AvailableLanguagesAudience::onAgentTick($this->agent);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return Generator<string, array{SourceChange, SourceChangeProvenance, bool}> Changes and their delivery verdict */
    public static function sourceChanges(): Generator
    {
        foreach ([
            HilosDbContext::languages,
            HilosDbContext::locales,
            HilosDbContext::countries,
            HilosDbContext::countryNames,
        ] as $source) {
            yield "local $source" => [SourceChange::dbUpdated($source, '1', []), SourceChangeProvenance::LocalWrite, true];
            yield "remote $source" => [SourceChange::dbUpdated($source, '1', []), SourceChangeProvenance::AppliedRemote, false];
        }

        yield 'language name' => [SourceChange::dbUpdated(HilosDbContext::languageNames, '1', []), SourceChangeProvenance::LocalWrite, false];
        yield 'reflow' => [SourceChange::dbUpdated(HilosDbContext::i18nReflows, '1', []), SourceChangeProvenance::LocalWrite, false];
        yield 'runtime' => [SourceChange::rtUpdated(HilosDbContext::languages, '1', []), SourceChangeProvenance::LocalWrite, false];
    }

    public function testResetDiscardsPendingUpdate(): void
    {
        new AvailableLanguagesAudience()->onSourceChange(
            SourceChange::dbUpdated(HilosDbContext::languages, '1', []),
            SourceChangeProvenance::LocalWrite,
        );
        AvailableLanguagesAudience::reset();
        AvailableLanguagesAudience::onAgentTick($this->agent);

        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }
}

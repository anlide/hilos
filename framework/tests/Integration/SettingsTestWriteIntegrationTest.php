<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\CliCommands;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\SettingsCatalogStub;
use Hilos\Database\Settings\SettingsCommandConstants;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Testing\IntegrationTest;

/**
 * The test-only settings batch against the real settings table (HIL-1856).
 *
 * What a unit cannot show is pinned here: the pairs reach the table in the order they came, each
 * pair is one write that announces one change of the settings collection - the change the
 * settings screen's table turns into its row delta - and a refused pair ends the batch with the
 * pairs before it written. The protocol half, judged before any write, is pinned in
 * SettingsLibraryAgentTest.
 */
#[IntegrationTest]
final class SettingsTestWriteIntegrationTest extends SettingsTestWriteEnvironment
{
    /** @var string Correlation id the batch is answered under */
    private const string CORRELATION_ID = 'settings-test-write';

    public function testEveryPairIsOneWriteAndOneChangeInTheOrderGiven(): void
    {
        $writes = $this->write([
            [SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING, 'first'],
            [SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER, '2'],
            [SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING, 'second=with=equals'],
        ])->payload[SettingsCommandConstants::FIELD_WRITES];

        self::assertSame([
            [SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING, 'first'],
            [SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER, '2'],
            [SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING, 'second=with=equals'],
        ], array_map(static fn(array $write): array => [
            $write[SettingsCommandConstants::FIELD_KEY],
            $write[SettingsCommandConstants::FIELD_VALUE],
        ], $writes));
        $acceptedAt = array_column($writes, SettingsCommandConstants::FIELD_ACCEPTED_AT);
        self::assertContainsOnlyInt($acceptedAt);
        $ordered = $acceptedAt;
        sort($ordered);
        self::assertSame($ordered, $acceptedAt, 'Each pair is accepted after the one before it');

        $rows = self::persisted();
        self::assertSame([
            [SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING, 'second=with=equals'],
            [SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER, '2'],
        ], array_map(static fn(array $row): array => [$row['key'], $row['value']], $rows));
        // An update announces only the columns it changed, so a change is told by the id of its row.
        self::assertSame([
            [TableMutationType::Create, $rows[0]['id']],
            [TableMutationType::Create, $rows[1]['id']],
            [TableMutationType::Update, $rows[0]['id']],
        ], SettingsChangeRecorder::$changes);
    }

    public function testARefusedPairEndsTheBatchByItsNumberAndThePairsBeforeItStay(): void
    {
        $reply = $this->write([
            [SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING, 'kept'],
            [SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER, 'many'],
            [SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN, 'true'],
        ]);

        self::assertFalse($reply->isOk());
        self::assertSame(
            "Write 2 was refused: Setting 'example_integer' takes a whole number; 'many' is not one",
            $reply->payload['message'] ?? null,
        );
        $rows = self::persisted();
        self::assertSame(
            [[SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING, 'kept']],
            array_map(static fn(array $row): array => [$row['key'], $row['value']], $rows),
        );
        self::assertSame([[TableMutationType::Create, $rows[0]['id']]], SettingsChangeRecorder::$changes);
    }

    /**
     * Hands one batch to the library the way the command channel would, and reads its one reply.
     *
     * @param list<array{0: string, 1: string}> $pairs Keys and values in the order they are sent
     * @return CommandReplyDTO The reply, after which nothing else is queued
     */
    private function write(array $pairs): CommandReplyDTO
    {
        new SettingsLibraryAgent()->onSignalCommand(new CommandRequestDTO(
            correlationId: self::CORRELATION_ID,
            command: CliCommands::SETTINGS_TEST_WRITE,
            payload: [SettingsCommandConstants::FIELD_WRITES => array_map(static fn(array $pair): array => [
                SettingsCommandConstants::FIELD_KEY => $pair[0],
                SettingsCommandConstants::FIELD_VALUE => $pair[1],
            ], $pairs)],
        ), 'cli', CliCommands::SETTINGS_TEST_WRITE);

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalTypeConstants::COMMAND_REPLY, $signal->signalType->getType());
        self::assertInstanceOf(CommandReplyDTO::class, $signal->data);
        self::assertSame(self::CORRELATION_ID, $signal->data->correlationId);
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'The batch is answered once and sends nothing else');

        return $signal->data;
    }

    /**
     * Reads the stored overrides past the ORM under test.
     *
     * @return list<array{id: string, key: string, value: string}> Stored rows in the order they were created, the id as text
     */
    private static function persisted(): array
    {
        return array_map(
            static fn(array $row): array => ['id' => (string)$row['id'], 'key' => $row['key'], 'value' => $row['value']],
            Database::sql('SELECT id, `key`, `value` FROM hilos_setting ORDER BY id')->rows(),
        );
    }
}

/** Owns the facade bindings and the change recorder outside the managed rollback case's lifecycle. */
abstract class SettingsTestWriteEnvironment extends FrameworkRollbackIntegrationTestCase
{
    private ?SettingsAccessor $previousSetting = null;

    private ?TableContext $previousTable = null;

    private ?SignalRouter $previousRouter = null;

    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousSetting = Hilos::$setting;
        $this->previousTable = Hilos::$table;
        $this->previousRouter = Hilos::$sr;
        parent::prepareIntegrationEnvironment();
        Hilos::$setting = new SettingsAccessor(SettingsCatalogStub::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new class extends TableContext {
            public function configure(): void
            {
                $this->register(HilosSettingsTable::TABLE, new HilosSettingsTable());
            }
        };
        Hilos::$table->configure();
        SettingsChangeRecorder::$changes = [];
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new SettingsChangeRecorder());
    }

    protected function releaseIntegrationEnvironment(): void
    {
        try {
            parent::releaseIntegrationEnvironment();
        } finally {
            SourceChangeBus::reset();
            SettingsChangeRecorder::$changes = [];
            Hilos::$setting = $this->previousSetting;
            Hilos::$table = $this->previousTable;
            Hilos::$sr = $this->previousRouter;
        }
    }
}

/**
 * Writes down every change the settings collection announces, at the write.
 *
 * A mirror, because a mirror hears a change when it is made: a reaction would wait for a commit
 * that the rollback case never makes.
 */
final class SettingsChangeRecorder implements SourceMirrorSubscriberInterface
{
    /** @var list<array{0: TableMutationType, 1: string}> Mutation and row id of each announced settings change */
    public static array $changes = [];

    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->isDb() && $change->sourceKey === HilosDbContext::settings) {
            self::$changes[] = [$change->mutationType, $change->sourceId];
        }
    }
}

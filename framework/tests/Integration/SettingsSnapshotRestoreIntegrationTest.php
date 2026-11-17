<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\CliCommands;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Database\Database;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\SettingsCatalogStub;
use Hilos\Database\Settings\SettingsCommandConstants;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/SettingsTestWriteIntegrationTest.php';

/** Exact settings cleanup through the real owner and raw action doors, under managed rollback. */
#[IntegrationTest]
final class SettingsSnapshotRestoreIntegrationTest extends SettingsTestWriteEnvironment
{
    /** @return array<string, array{string, bool, string, ?string, string}> Original raw states */
    public static function rawStates(): array
    {
        return [
            'absent default' => ['example_string', false, 'string', null, 'default'],
            'absent reference' => ['snapshot_reference', false, 'string', null, 'reference'],
            'override equal to default' => ['example_integer', true, 'integer', '0', 'override'],
            'custom empty string' => ['example_string', true, 'string', '', 'override'],
            'raw numeric representation' => ['example_integer', true, 'integer', '0007', 'override'],
            'orphan' => ['snapshot_orphan', true, 'string', 'orphan-value', 'orphan'],
            'legacy nullable value and type' => ['example_string', true, '', null, 'default'],
        ];
    }

    /**
     * @param string $key Settings identity
     * @param bool $exists Original presence
     * @param string $type Original type
     * @param ?string $rawValue Original nullable text
     * @param string $source Original source verdict
     */
    #[DataProvider('rawStates')]
    public function testRestoresEveryRawFieldAndSource(string $key, bool $exists, string $type, ?string $rawValue, string $source): void
    {
        Hilos::$setting = new SettingsAccessor(SnapshotSettingsCatalog::class);
        if ($exists) {
            Hilos::$db->settings->actions->addRawForTest($key, $type, $rawValue);
        }
        $changesBeforeRead = SettingsChangeRecorder::$changes;
        $snapshot = $this->snapshot($key);
        self::assertSame($changesBeforeRead, SettingsChangeRecorder::$changes, 'Capturing a snapshot never writes');
        self::assertSame(['key' => $key, 'exists' => $exists, 'type' => $type, 'rawValue' => $rawValue, 'valueSource' => $source], $snapshot);
        if ($rawValue === '0007') {
            Hilos::$db->settings[$key]->actions->restoreRawForTest('string', 'temporary');
        } elseif ($exists) {
            Hilos::$db->settings[$key]->actions->delete();
        } else {
            Hilos::$db->settings->actions->addRawForTest($key, 'string', 'temporary');
        }
        $this->restore($snapshot);
        self::assertSame($snapshot, $this->snapshot($key));
        $rows = Database::sql('SELECT `type`, `value` FROM hilos_setting WHERE `key` = ?', [$key])->rows();
        self::assertSame($exists ? [['type' => $type, 'value' => $rawValue]] : [], $rows);
        // An equal snapshot is a no-op, including the original absence of a row.
        $changes = SettingsChangeRecorder::$changes;
        $this->restore($snapshot);
        self::assertSame($changes, SettingsChangeRecorder::$changes);
    }

    public function testFailedRestoreRollsBackRawWritesAndMemoryWithoutAPartialResult(): void
    {
        $key = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;
        Hilos::$db->settings->actions->addRawForTest($key, 'string', 'original');
        $original = $this->snapshot($key);
        Hilos::$db->settings[$key]->actions->restoreRawForTest('string', 'temporary');
        $before = $this->snapshot($key);
        $environment = Hilos::$env;
        Hilos::$env = null;
        try {
            try {
                Hilos::$db->settings[$key]->actions->restoreRawForTest('string', 'forbidden');
                self::fail('The raw item action must enforce its own test gate');
            } catch (SettingInvalidValueException) {
            }
            try {
                Hilos::$db->settings->actions->addRawForTest('forbidden_orphan', 'string', null);
                self::fail('The raw collection action must enforce its own test gate');
            } catch (SettingInvalidValueException) {
            }
        } finally {
            Hilos::$env = $environment;
        }
        self::assertSame($before, $this->snapshot($key));
        self::assertArrayNotHasKey('forbidden_orphan', Hilos::$db->settings);
        SourceChangeBus::subscribe(new class implements SourceMirrorSubscriberInterface {
            private bool $armed = true;

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($this->armed) {
                    $this->armed = false;
                    throw new InvalidArgumentException('Injected raw settings write failure');
                }
            }
        });
        $reply = $this->command(CliCommands::SETTINGS_TEST_RESTORE, [SettingsCommandConstants::FIELD_SNAPSHOT => $original]);
        self::assertFalse($reply->isOk());
        self::assertSame($before, $this->snapshot($key));
        self::assertSame([['type' => 'string', 'value' => 'temporary']], Database::sql(
            'SELECT `type`, `value` FROM hilos_setting WHERE `key` = ?',
            [$key],
        )->rows());
        // A changed catalog/source also refuses cleanup and undoes the attempted write.
        $wrongSource = array_replace($original, ['valueSource' => 'orphan']);
        self::assertFalse($this->command(CliCommands::SETTINGS_TEST_RESTORE, [SettingsCommandConstants::FIELD_SNAPSHOT => $wrongSource])->isOk());
        self::assertSame($before, $this->snapshot($key));
    }

    /**
     * @param string $key Settings identity
     * @return array<string, mixed> Captured state
     */
    private function snapshot(string $key): array
    {
        $reply = $this->command(CliCommands::SETTINGS_TEST_SNAPSHOT, [SettingsCommandConstants::FIELD_KEY => $key]);
        self::assertTrue($reply->isOk(), $reply->toJson());

        return $reply->payload[SettingsCommandConstants::FIELD_SNAPSHOT];
    }

    /** @param array<string, mixed> $snapshot Original state */
    private function restore(array $snapshot): void
    {
        $reply = $this->command(CliCommands::SETTINGS_TEST_RESTORE, [SettingsCommandConstants::FIELD_SNAPSHOT => $snapshot]);
        self::assertTrue($reply->isOk(), $reply->toJson());
        self::assertSame([SettingsCommandConstants::FIELD_RESTORED => true], $reply->payload);
    }

    /**
     * Drives the actual owner, including its local handover acknowledgement.
     *
     * @param string $command Command name
     * @param array<string, mixed> $payload Command payload
     * @return CommandReplyDTO Exactly one reply
     */
    private function command(string $command, array $payload): CommandReplyDTO
    {
        $owner = new SettingsLibraryAgent();
        $owner->onSignalCommand(new CommandRequestDTO('settings-snapshot-test', $command, $payload), 'cli', $command);
        $reply = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData) {
                $owner->onSignalAgent($signal->data, 'agent', $signal->signalName->getName());
                continue;
            }
            self::assertSame(SignalTypeConstants::COMMAND_REPLY, $signal->signalType->getType());
            self::assertInstanceOf(CommandReplyDTO::class, $signal->data);
            self::assertNull($reply, 'The owner must not reply twice');
            $reply = $signal->data;
        }
        self::assertNotNull($reply);

        return $reply;
    }
}

/** Adds a referenced default without changing the production settings catalog. */
final class SnapshotSettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Catalog with one reference fixture */
    public static function getCatalog(): array
    {
        $catalog = SettingsCatalogStub::getCatalog();
        $catalog[SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] = 'catalog-default';

        return $catalog + [
            'snapshot_reference' => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => [
                    SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING,
                ],
            ],
        ];
    }
}

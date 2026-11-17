<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\Method\AuthMethodDirectory;
use Hilos\Auth\Method\DTO\AuthMethodsSignalData;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\OAuth\RawOAuthProviderSnapshot;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use ReflectionProperty;

/** Real owner commits and deferred announcements require a case without an always-rollback root. */
final class OAuthProviderSnapshotRestoreIntegrationTest extends FrameworkIntegrationTestCase
{
    private ?HilosDbContext $previousDb;
    private ?EnvAccessor $previousEnv;
    private ?SettingsAccessor $previousSetting;
    private ?SignalRouter $previousRouter;
    private string $previousAppClass;
    private SnapshotRestoreIndexAgent $owner;

    /** @var list<SourceChange> Reactions released by real commits */
    private array $changes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousEnv = Hilos::$env;
        $this->previousSetting = Hilos::$setting;
        $this->previousRouter = Hilos::$sr;
        $this->previousAppClass = new ReflectionProperty(Hilos::class, 'appClass')->getValue();
        foreach (['hilos_oauth_provider', 'hilos_setting'] as $table) {
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}_down.sql"));
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}.sql"));
        }
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, SnapshotRestoreHilos::class);
        Hilos::$db = new SnapshotRestoreDbContext();
        Hilos::$db->configure();
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        Hilos::$setting = null;
        Hilos::$sr = new SignalRouter();
        putenv(EnvConstants::APP_ENV->name . '=test');
        $this->owner = new SnapshotRestoreIndexAgent();
        TruthSourceRegistry::register(HilosDbContext::oauthProviders, TruthSourceKeys::all(), $this->owner->getId());
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $changes = &$this->changes;
        SourceChangeBus::subscribe(new class ($changes) implements SourceChangeSubscriberInterface {
            /** @param list<SourceChange> $changes Committed source facts */
            public function __construct(private array &$changes)
            {
            }

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                self::assertCommitted();
                $this->changes[] = $change;
            }

            private static function assertCommitted(): void
            {
                OAuthProviderSnapshotRestoreIntegrationTest::assertFalse(Database::hasOpenTransactions());
            }
        });
    }

    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        TruthSourceRegistry::unregister(HilosDbContext::oauthProviders, $this->owner->getId());
        Hilos::$db = $this->previousDb;
        Hilos::$env = $this->previousEnv;
        Hilos::$setting = $this->previousSetting;
        Hilos::$sr = $this->previousRouter;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        foreach (['hilos_oauth_provider', 'hilos_setting'] as $table) {
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}_down.sql"));
        }
        parent::tearDown();
    }

    public function testCustomRawFieldsAreRestoredThroughTheRealOwner(): void
    {
        $this->seed(' raw client ', ' raw secret ', ' raw scope ');
        $original = $this->snapshot();
        $this->mutate();
        $this->restore($original);
        self::assertTrue($this->persistedMatches($original));
        self::assertTrue($this->snapshot() === $original);
    }

    public function testAbsentThenCreatedProviderReturnsToAbsence(): void
    {
        $original = $this->snapshot();
        self::assertNull($original['row']);
        $id = $this->write(fn() => Hilos::$db->oauthProviders->actions->add(OAuthProviderPreset::GITHUB->value)->id);
        $this->mutate();
        $this->restore($original);
        self::assertSame([], Database::sql('SELECT id FROM hilos_oauth_provider')->rows());
        self::assertArrayNotHasKey($id, Hilos::$db->oauthProviders);
        self::assertArrayNotHasKey(OAuthProviderPreset::GITHUB->value, Hilos::$db->oauthProviders);
        self::assertTrue($this->snapshot() === $original);
    }

    public function testEmptySecretAndNullStayDistinctWhileTheProductionReaderKeepsNormalizing(): void
    {
        $this->seed('', '', null);
        $empty = $this->snapshot();
        self::assertTrue($empty['row']['clientSecret'] === '');
        self::assertNull(Hilos::$db->oauthProviders[OAuthProviderPreset::GITHUB->value]->readClientSecret());
        $this->write(fn() => Hilos::$db->oauthProviders[OAuthProviderPreset::GITHUB->value]->actions->writeClientSecret(null));
        $null = $this->snapshot();
        self::assertTrue($null['row']['clientSecret'] === null);
        $this->restore($empty);
        self::assertTrue($this->persistedMatches($empty));
        $this->restore($null);
        self::assertTrue($this->persistedMatches($null));
    }

    public function testCapturedPrimaryIdAndBothOriginalMillisecondMomentsRemainExact(): void
    {
        $this->seed(null, null, '');
        $original = $this->snapshot();
        self::assertSame(-123456789, $original['row']['createdAt']);
        self::assertSame(0, $original['row']['updatedAt']);
        $this->mutate();
        $this->restore($original);
        self::assertTrue($this->persistedMatches($original));
        self::assertSame($original['row']['id'], $this->snapshot()['row']['id']);
    }

    public function testAlreadyRestoredSnapshotIsANoOpEvenWithAStaleCurrentId(): void
    {
        $this->seed('client', 'secret', null);
        $original = $this->snapshot();
        $this->drain();
        $changes = $this->changes;
        $reply = $this->command(CliCommands::OAUTH_PROVIDER_TEST_RESTORE, ['snapshot' => $original, 'currentId' => null]);
        self::assertTrue($reply->isOk());
        self::assertSame($changes, $this->changes);
        self::assertSame([], $this->drain());
        self::assertTrue($this->persistedMatches($original));
    }

    public function testDeleteRecreateRefusesTheOldIdentityAndLeavesTheReplacementAlone(): void
    {
        $absent = $this->snapshot();
        $this->write(fn() => Hilos::$db->oauthProviders->actions->add(OAuthProviderPreset::GITHUB->value));
        $original = $this->snapshot();
        $this->write(fn() => Hilos::$db->oauthProviders[$original['row']['id']]->actions->deleteForTest());
        self::assertFalse($this->command(CliCommands::OAUTH_PROVIDER_TEST_RESTORE, [
            'snapshot' => $original, 'currentId' => null,
        ])->isOk());
        $this->write(fn() => Hilos::$db->oauthProviders->actions->add(OAuthProviderPreset::GITHUB->value));
        $replacement = $this->snapshot();
        self::assertNotSame($original['row']['id'], $replacement['row']['id']);
        foreach ([$original, $absent] as $snapshot) {
            self::assertFalse($this->command(CliCommands::OAUTH_PROVIDER_TEST_RESTORE, [
                'snapshot' => $snapshot, 'currentId' => $original['row']['id'],
            ])->isOk());
            self::assertTrue($this->persistedMatches($replacement));
        }
    }

    public function testFailureAfterSecretWriteRollsBackDatabaseOrmMomentsAndSecretCache(): void
    {
        $this->seed('original-client', null, null);
        $original = $this->snapshot();
        $this->mutate();
        $before = $this->snapshot();
        self::assertTrue(Hilos::$db->oauthProviders[$before['row']['id']]->hasClientSecret());
        $this->drain();
        $changes = $this->changes;
        SourceChangeBus::subscribe(new class implements SourceMirrorSubscriberInterface {
            private bool $armed = true;

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($this->armed && $change->row === []) {
                    $this->armed = false;
                    throw new InvalidFormatException('Injected failure after the DB-only write');
                }
            }
        });
        self::assertFalse($this->command(CliCommands::OAUTH_PROVIDER_TEST_RESTORE, [
            'snapshot' => $original, 'currentId' => $before['row']['id'],
        ])->isOk());
        self::assertTrue($this->persistedMatches($before));
        self::assertTrue($this->snapshot() === $before);
        self::assertTrue(Hilos::$db->oauthProviders[$before['row']['id']]->hasClientSecret());
        self::assertSame($changes, $this->changes);
        self::assertSame([], $this->drain());
    }

    public function testCommitRestoresOfferedMethodsAndNeverAnnouncesTheRawSecret(): void
    {
        $this->seed('original-client', null, null);
        $original = $this->snapshot();
        $offered = AuthMethodsSignalData::current()->toArray();
        $this->mutate();
        self::assertNotSame($offered, AuthMethodsSignalData::current()->toArray());
        $this->drain();
        $this->changes = [];
        SourceChangeBus::subscribe(new class implements SourceMirrorSubscriberInterface {
            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if (Database::hasOpenTransactions()) {
                    OAuthProviderSnapshotRestoreIntegrationTest::assertNull(Hilos::$sr->getNextQueuedSignal());
                }
            }
        });
        $this->restore($original);
        self::assertSame($offered, AuthMethodsSignalData::current()->toArray());
        $signals = $this->drain();
        $methods = array_values(array_filter($signals, static fn(SignalDTO $signal): bool =>
            $signal->signalName->getName() === HilosSignalConstants::HILOS_AUTH_METHODS));
        self::assertCount(1, $methods);
        self::assertInstanceOf(WebSocketSignalData::class, $methods[0]->data);
        self::assertSame($offered, $methods[0]->data->data->toArray());
        self::assertNotEmpty($this->changes);
        $syncs = array_filter($signals, static fn(SignalDTO $signal): bool =>
            $signal->signalName->getName() === SignalConstants::DB_SYNC_UPDATED);
        self::assertNotEmpty($syncs);
        foreach ($signals as $signal) {
            self::assertFalse(str_contains($signal->data->toJson(), 'temporary-secret'));
            self::assertFalse(str_contains($signal->data->toJson(), 'client_secret'));
        }
        foreach ($this->changes as $change) {
            self::assertArrayNotHasKey('client_secret', $change->row);
            self::assertArrayNotHasKey('client_secret', $change->previous);
        }
        $database = Hilos::$db;
        Hilos::$db = null;
        try {
            self::assertFalse($this->command(CliCommands::OAUTH_PROVIDER_TEST_SNAPSHOT, [
                'providerKey' => OAuthProviderPreset::GITHUB->value,
            ])->isOk());
        } finally {
            Hilos::$db = $database;
        }
        $environment = Hilos::$env;
        Hilos::$env = null;
        try {
            foreach ([
                fn() => Hilos::$db->oauthProviders[$original['row']['id']]->rawSnapshotForTest(),
                fn() => Hilos::$db->oauthProviders[$original['row']['id']]->actions->restoreRawForTest(
                    RawOAuthProviderSnapshot::fromArray($original),
                ),
                fn() => Hilos::$db->oauthProviders[$original['row']['id']]->actions->deleteForTest(),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('The raw API must enforce its own non-production gate');
                } catch (InvalidFormatException) {
                }
            }
        } finally {
            Hilos::$env = $environment;
        }
    }

    /**
     * @param ?string $clientId Raw fixture field
     * @param ?string $secret Raw DB-only fixture field
     * @param ?string $scope Raw fixture field
     */
    private function seed(?string $clientId, ?string $secret, ?string $scope): void
    {
        Database::sql(
            'INSERT INTO hilos_oauth_provider (provider_key, client_id, client_secret, scope, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [OAuthProviderPreset::GITHUB->value, $clientId, $secret, $scope, -123456789, 0],
        );
    }

    private function mutate(): void
    {
        $this->write(static function (): void {
            Hilos::$db->oauthProviders[OAuthProviderPreset::GITHUB->value]->actions->updateClientId('temporary-client');
            Hilos::$db->oauthProviders[OAuthProviderPreset::GITHUB->value]->actions->updateScope('temporary-scope');
            Hilos::$db->oauthProviders[OAuthProviderPreset::GITHUB->value]->actions->writeClientSecret('temporary-secret');
        });
    }

    /** @return array<string, mixed> Raw command snapshot, without assertion diffs containing secrets */
    private function snapshot(): array
    {
        $reply = $this->command(CliCommands::OAUTH_PROVIDER_TEST_SNAPSHOT, ['providerKey' => OAuthProviderPreset::GITHUB->value]);
        self::assertTrue($reply->isOk(), 'Snapshot was refused');

        return $reply->payload['snapshot'];
    }

    /** @param array<string, mixed> $snapshot Original state */
    private function restore(array $snapshot): void
    {
        $current = $this->snapshot();
        $reply = $this->command(CliCommands::OAUTH_PROVIDER_TEST_RESTORE, [
            'snapshot' => $snapshot, 'currentId' => $current['row']['id'] ?? null,
        ]);
        self::assertTrue($reply->isOk(), 'Raw restoration was refused');
        self::assertSame(['restored' => true], $reply->payload);
    }

    /**
     * @param array<string, mixed> $snapshot Original raw fields
     * @return bool Independent SQL matches all captured fields
     */
    private function persistedMatches(array $snapshot): bool
    {
        $row = Database::sql('SELECT * FROM hilos_oauth_provider WHERE provider_key = ?', [$snapshot['providerKey']])->row();
        if ($snapshot['row'] === null) {
            return $row === null;
        }
        return $row !== null && (int)$row['id'] === $snapshot['row']['id']
            && $row['client_id'] === $snapshot['row']['clientId'] && $row['client_secret'] === $snapshot['row']['clientSecret']
            && $row['scope'] === $snapshot['row']['scope'] && (int)$row['created_at'] === $snapshot['row']['createdAt']
            && (int)$row['updated_at'] === $snapshot['row']['updatedAt'];
    }

    /**
     * @param string $command Channel-only command name
     * @param array<string, mixed> $payload Raw exchange
     * @return CommandReplyDTO The owner's single reply
     */
    private function command(string $command, array $payload): CommandReplyDTO
    {
        $this->owner->replies = [];
        $this->write(fn() => $this->owner->onSignalCommand(
            new CommandRequestDTO('oauth-snapshot-integration', $command, $payload), 'cli', $command,
        ));
        self::assertCount(1, $this->owner->replies);
        self::assertSame('oauth-snapshot-integration', $this->owner->replies[0]->correlationId);

        return $this->owner->replies[0];
    }

    /**
     * @param Closure $operation Work inside the real owner's execution frame
     * @return mixed Result of the operation
     */
    private function write(Closure $operation): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->owner->getId()), $operation);
    }

    /** @return list<SignalDTO> Ordinary sync and all-connections signals */
    private function drain(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }
        return $signals;
    }
}

final class SnapshotRestoreIndexAgent extends AbstractHilosIndexAgent
{
    /** @var list<CommandReplyDTO> Closed-channel replies, kept apart from browser/sync signals */
    public array $replies = [];

    public function replyToCommand(CommandReplyDTO $reply): void
    {
        $this->replies[] = $reply;
    }
}

final class SnapshotRestoreDbContext extends HilosDbContext
{
}

final class SnapshotRestoreHilos extends Hilos
{
    protected const string OAUTH_PROVIDER_DIRECTORY = SnapshotRestoreProviderDirectory::class;
    protected const string AUTH_METHOD_DIRECTORY = SnapshotRestoreMethodDirectory::class;

    protected static function createDb(): HilosDbContext
    {
        return new SnapshotRestoreDbContext();
    }
}

final class SnapshotRestoreProviderDirectory extends OAuthProviderDirectory
{
    protected static function providers(): array
    {
        return [OAuthProviderPreset::GITHUB->value => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GITHUB, 'GitHub')];
    }
}

final class SnapshotRestoreMethodDirectory extends AuthMethodDirectory
{
    protected static function methods(): array
    {
        return [OAuthProviderPreset::GITHUB->value];
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DataExportAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Analytics\AnalyticsPersonEvent;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\DataExport\DataExportState;
use Hilos\Database\Database;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\HilosException;
use JsonException;
use PharData;

/** The project seam includes only the author's messages and the rename target's history. */
final class DataExportTest extends IntegrationTestCase
{
    /** Only the analytics tables insertPersonEvent() writes, children before dictionaries. */
    private const array ANALYTICS_CLEANUP_ORDER = [
        'hilos_analytics_person_event', 'hilos_analytics_browser_session',
        'hilos_analytics_page', 'hilos_analytics_page_params', 'hilos_analytics_action_name',
    ];

    private string $directory;
    private ?FsContext $previousFs;
    private ?SignalRouter $previousRouter;
    private ?DataExportAgent $agent = null;

    /** @var array<string, int> Analytics high water marks owned by this test instance only */
    private array $analyticsBaseline = [];

    /** @var list<int> People created by this case */
    private array $userIds = [];

    /** @var list<int> Feed events created by this case */
    private array $eventIds = [];

    /** @var list<int> Published registry files created by this case */
    private array $fileIds = [];

    /** Creates separate attachment and archive storage for this case. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->directory = sys_get_temp_dir() . '/chat-export-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory . '/published');
        $this->previousFs = Hilos::$fs;
        Hilos::$fs = new ChatExportTestFs($this->directory);
        Hilos::$fs->configure();
        // AnalyticsStore's tables have no mounted DbCollection; retain pre-existing dictionary rows.
        foreach (self::ANALYTICS_CLEANUP_ORDER as $table) {
            Database::sql('SELECT COALESCE(MAX(`id`), 0) AS `id` FROM `' . $table . '`');
            $this->analyticsBaseline[$table] = (int)Database::field('id');
        }
    }

    /** Removes owned rows even after a failed assertion, then releases the agent, bindings and files. */
    protected function tearDown(): void
    {
        try {
            $this->removeFixtureRows();
        } finally {
            try {
                if ($this->agent !== null) {
                    $this->underAgent($this->agent, fn () => $this->agent->onStop());
                }
            } finally {
                if ($this->agent !== null) {
                    TruthSourceRegistry::unregisterAgent($this->agent->getId());
                    SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->agent->getId()));
                }
                Hilos::$fs = $this->previousFs;
                Hilos::$sr = $this->previousRouter;
                try {
                    foreach (['published', 'exports'] as $directory) {
                        foreach (glob($this->directory . '/' . $directory . '/*') as $file) {
                            unlink($file);
                        }
                        if (is_dir($this->directory . '/' . $directory)) {
                            rmdir($this->directory . '/' . $directory);
                        }
                    }
                    rmdir($this->directory);
                } finally {
                    parent::tearDown();
                }
            }
        }
    }

    /**
     * @throws HilosException When seeding or the real export agent fails
     * @throws JsonException When the archive's JSON cannot be decoded
     */
    public function testExportsOwnChatRowsAndAttachmentBytes(): void
    {
        $personId = $this->createUser('Exporting');
        $otherId = $this->createUser('Other');
        $this->eventIds[] = (int)Hilos::$db->events->actions->addUserRegistered($personId)->id;
        $this->eventIds[] = (int)Hilos::$db->events->actions->addUserRenamed(
            Hilos::$db->userRenames->actions->add($personId, $personId, 'Before', 'Exporting'),
        )->id;
        $this->eventIds[] = (int)Hilos::$db->events->actions->addUserRenamed(
            Hilos::$db->userRenames->actions->add($otherId, $personId, 'Other before', 'Other'),
        )->id;
        $file = $this->keepRegistryFile($personId, 'note.txt', "my attachment\n");
        $this->fileIds[] = (int)$file->id;
        $this->eventIds[] = (int)Hilos::$db->events->actions->addMessage('my message', userId: $personId, fileIds: [(int)$file->id])->id;
        $this->eventIds[] = (int)Hilos::$db->events->actions->addMessage('foreign message', userId: $otherId)->id;
        $agent = $this->agent = new DataExportAgent();
        $this->startAgent($agent);
        ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static function () use ($agent, $personId): void {
            Hilos::$db->dataExports->actions->order($personId, '2026-01-01 00:00:00');
            $agent->onTick();
        });

        $export = Hilos::$db->dataExports->ofUser($personId);
        self::assertNotNull($export);
        self::assertSame(DataExportState::READY, $export->state);
        $archive = new PharData($this->directory . '/exports/' . $export->storedName);
        self::assertSame(
            ['schemaVersion' => 1, 'attribution' => 'authenticated_event_actor', 'parts' => 0],
            json_decode($archive['analytics_person.json']->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
        $chat = json_decode($archive['chat.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['name' => 'Exporting'], $chat['profile']);
        self::assertCount(1, $chat['messages']);
        self::assertSame('my message', $chat['messages'][0]['text']);
        // The name and the bytes are the registry row's; the archive path is its stored name (HIL-144).
        self::assertSame([['filename' => 'note.txt', 'file' => 'files/' . $file->storedName]], $chat['messages'][0]['attachments']);
        self::assertSame("my attachment\n", $archive['files/' . $file->storedName]->getContent());
        self::assertNotNull($chat['registeredAt']);
        self::assertArrayNotHasKey('renames', $chat, 'The renames are the framework\'s section');
        $renames = json_decode($archive['renames.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $renames);
        self::assertSame('Before', $renames[0]['from']);
        self::assertSame('Exporting', $renames[0]['to']);
        self::assertStringNotContainsString('Other', $archive['chat.json']->getContent());
        self::assertStringNotContainsString('foreign', $archive['chat.json']->getContent());
    }

    /**
     * The last user of a shared browser does not own the earlier actor's facts.
     *
     * @throws HilosException When event storage or archive creation fails
     * @throws JsonException When an archive part cannot be decoded
     */
    public function testAnalyticsPartsUseTheExactActorAndIncludeFoldedAccounts(): void
    {
        $aliceId = $this->createUser('Alice');
        $bobId = $this->createUser('Bob');
        $foldedId = $this->createUser('Folded');
        $olderId = $this->createUser('Older');
        Hilos::$db->userMerges->actions->add($foldedId, $aliceId);
        Hilos::$db->userMerges->actions->add($olderId, $foldedId);

        $store = new AnalyticsStore();
        $browserToken = 'shared-browser-' . bin2hex(random_bytes(8));
        // Every Alice row has the same source moment: the second part needs the id tie-breaker.
        for ($row = 1; $row <= 501; $row++) {
            $store->insertPersonEvent(new AnalyticsPersonEvent(
                $browserToken, $aliceId, null, 11, AnalyticsPersonEvent::ACTION, 'alice_action',
                null, null, '127.0.0.1', 1,
            ));
        }
        $store->insertPersonEvent(new AnalyticsPersonEvent(
            $browserToken, $olderId, null, 12, AnalyticsPersonEvent::PAGE_OPEN,
            null, 'chat', ['room' => 'mine'], '2001:db8::1', 502,
        ));
        $store->insertPersonEvent(new AnalyticsPersonEvent(
            $browserToken, $bobId, $aliceId, 13, AnalyticsPersonEvent::ACTION, 'bob_action',
            null, null, '127.0.0.2', 503,
        ));
        Database::sql('UPDATE `hilos_analytics_browser_session` SET `user_identity_type` = ?, `user_identity_value` = ?
            WHERE `session_token` = ?', ['user_id', (string)$bobId, $browserToken]);

        $agent = $this->agent = new DataExportAgent();
        $this->startAgent($agent);
        ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static function () use ($agent, $aliceId, $bobId): void {
            Hilos::$db->dataExports->actions->order($aliceId, '2026-01-01 00:00:00');
            Hilos::$db->dataExports->actions->order($bobId, '2026-01-01 00:00:00');
            $agent->onTick();
            $agent->onTick();
        });

        $alice = Hilos::$db->dataExports->ofUser($aliceId);
        $bob = Hilos::$db->dataExports->ofUser($bobId);
        self::assertSame(DataExportState::READY, $alice?->state);
        self::assertSame(DataExportState::READY, $bob?->state);
        $aliceArchive = new PharData($this->directory . '/exports/' . $alice->storedName);
        $bobArchive = new PharData($this->directory . '/exports/' . $bob->storedName);
        $aliceIndex = json_decode($aliceArchive['analytics_person.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['schemaVersion' => 1, 'attribution' => 'authenticated_event_actor', 'parts' => 2], $aliceIndex);
        $first = json_decode($aliceArchive['analytics_person_000001.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode($aliceArchive['analytics_person_000002.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(500, $first);
        self::assertCount(2, $second);
        self::assertSame('alice_action', $first[0]['name']);
        self::assertSame('chat', $second[1]['page']);
        self::assertSame(['room' => 'mine'], $second[1]['params']);
        self::assertSame('2001:db8::1', $second[1]['address']);
        self::assertSame('1970-01-01T00:00:00.502Z', $second[1]['at']);
        self::assertStringNotContainsString('shared-browser', $aliceArchive['analytics_person_000001.json']->getContent());
        self::assertStringNotContainsString('bob_action', $aliceArchive['analytics_person_000002.json']->getContent());

        $bobIndex = json_decode($bobArchive['analytics_person.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $bobIndex['parts']);
        $bobPart = json_decode($bobArchive['analytics_person_000001.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $bobPart);
        self::assertSame('bob_action', $bobPart[0]['name']);
        self::assertSame($aliceId, $bobPart[0]['subjectUserId']);
        self::assertStringNotContainsString('alice_action', $bobArchive['analytics_person_000001.json']->getContent());
    }

    /**
     * @param string $name Fixture display name
     * @return int Created person, retained for cleanup before any assertion runs
     */
    private function createUser(string $name): int
    {
        $id = (int)Hilos::$db->users->actions->createWithName($name)->id;
        $this->userIds[] = $id;

        return $id;
    }

    /** Removes the case's dependent rows before its files and people, through existing mounted actions. */
    private function removeFixtureRows(): void
    {
        foreach ($this->analyticsBaseline as $table => $id) {
            Database::sqlRun('DELETE FROM `' . $table . '` WHERE `id` > ?', [$id]);
        }
        if ($this->agent !== null) {
            $this->underAgent($this->agent, function (): void {
                foreach ($this->userIds as $userId) {
                    Hilos::$db->dataExports->ofUser($userId)?->actions->delete();
                }
            });
        }
        Hilos::$db->eventAttachments->actions->deleteForMessages($this->eventIds);
        foreach ($this->userIds as $userId) {
            Hilos::$db->eventMessages->actions->deleteByAuthor($userId);
            Hilos::$db->eventUserRegistrations->actions->deleteByTarget($userId);
            Hilos::$db->userRenames->actions->deleteByUser($userId);
            Hilos::$db->userMerges->actions->deleteForUser($userId);
        }
        Hilos::$db->events->actions->deleteByIds($this->eventIds);
        foreach ($this->fileIds as $fileId) {
            Hilos::$db->files[$fileId]->actions->delete();
        }
        foreach ($this->userIds as $userId) {
            Hilos::$db->users[$userId]->actions->delete();
        }
    }
}

final class ChatExportTestFs extends FsContext
{
    /** @param string $directory Private test root */
    public function __construct(private readonly string $directory) { }

    /** Registers the files registry's directory and the export directory. */
    public function configure(): void
    {
        $this->registerDirectory(self::FILES, $this->directory . '/published', DirectoryScope::CLUSTER);
        $this->registerDirectory(self::DATA_EXPORT, $this->directory . '/exports', DirectoryScope::CLUSTER);
    }
}

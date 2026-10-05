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
    private string $directory;
    private ?FsContext $previousFs;

    /** Creates separate attachment and archive storage for this case. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->directory = sys_get_temp_dir() . '/chat-export-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory . '/published');
        $this->previousFs = Hilos::$fs;
        Hilos::$fs = new ChatExportTestFs($this->directory);
        Hilos::$fs->configure();
    }

    /** Restores the project's FS binding and drops this case's files and agent claim. */
    protected function tearDown(): void
    {
        Hilos::$fs = $this->previousFs;
        TruthSourceRegistry::unregisterAgent(DataExportAgent::AGENT_TYPE);
        foreach (['published', 'exports'] as $directory) {
            foreach (glob($this->directory . '/' . $directory . '/*') as $file) {
                unlink($file);
            }
            if (is_dir($this->directory . '/' . $directory)) {
                rmdir($this->directory . '/' . $directory);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    /**
     * @throws HilosException When seeding or the real export agent fails
     * @throws JsonException When the archive's JSON cannot be decoded
     */
    public function testExportsOwnChatRowsAndAttachmentBytes(): void
    {
        $personId = (int)Hilos::$db->users->actions->createWithName('Exporting')->id;
        $otherId = (int)Hilos::$db->users->actions->createWithName('Other')->id;
        Hilos::$db->events->actions->addUserRegistered($personId);
        Hilos::$db->events->actions->addUserRenamed(
            Hilos::$db->userRenames->actions->add($personId, $personId, 'Before', 'Exporting'),
        );
        Hilos::$db->events->actions->addUserRenamed(
            Hilos::$db->userRenames->actions->add($otherId, $personId, 'Other before', 'Other'),
        );
        $file = $this->keepRegistryFile($personId, 'note.txt', "my attachment\n");
        Hilos::$db->events->actions->addMessage('my message', userId: $personId, fileIds: [(int)$file->id]);
        Hilos::$db->events->actions->addMessage('foreign message', userId: $otherId);
        $agent = new DataExportAgent();
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
        $aliceId = (int)Hilos::$db->users->actions->createWithName('Alice')->id;
        $bobId = (int)Hilos::$db->users->actions->createWithName('Bob')->id;
        $foldedId = (int)Hilos::$db->users->actions->createWithName('Folded')->id;
        $olderId = (int)Hilos::$db->users->actions->createWithName('Older')->id;
        Database::sql('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`) VALUES (?, ?), (?, ?)',
            [$foldedId, $aliceId, $olderId, $foldedId]);

        $store = new AnalyticsStore();
        // Every Alice row has the same source moment: the second part needs the id tie-breaker.
        for ($row = 1; $row <= 501; $row++) {
            $store->insertPersonEvent(new AnalyticsPersonEvent(
                'shared-browser', $aliceId, null, 11, AnalyticsPersonEvent::ACTION, 'alice_action',
                null, null, '127.0.0.1', 1,
            ));
        }
        $store->insertPersonEvent(new AnalyticsPersonEvent(
            'shared-browser', $olderId, null, 12, AnalyticsPersonEvent::PAGE_OPEN,
            null, 'chat', ['room' => 'mine'], '2001:db8::1', 502,
        ));
        $store->insertPersonEvent(new AnalyticsPersonEvent(
            'shared-browser', $bobId, $aliceId, 13, AnalyticsPersonEvent::ACTION, 'bob_action',
            null, null, '127.0.0.2', 503,
        ));
        Database::sql('UPDATE `hilos_analytics_browser_session` SET `user_identity_type` = ?, `user_identity_value` = ?
            WHERE `session_token` = ?', ['user_id', (string)$bobId, 'shared-browser']);

        $agent = new DataExportAgent();
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

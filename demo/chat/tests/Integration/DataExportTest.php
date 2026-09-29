<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DataExportAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\DTO\PublishedAttachmentInput;
use Demo\Chat\Database\DTO\PublishedAttachmentInputs;
use Demo\Chat\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\DataExport\DataExportState;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\HilosException;
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
        Hilos::$fs->files->create('mine.txt')->append("my attachment\n");
        Hilos::$db->events->actions->addMessage(
            'my message',
            userId: $personId,
            attachments: new PublishedAttachmentInputs(new PublishedAttachmentInput('note.txt', 'text/plain', 'mine.txt')),
        );
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
        $chat = json_decode($archive['chat.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['name' => 'Exporting'], $chat['profile']);
        self::assertCount(1, $chat['messages']);
        self::assertSame('my message', $chat['messages'][0]['text']);
        self::assertSame([['filename' => 'note.txt', 'file' => 'files/mine.txt']], $chat['messages'][0]['attachments']);
        self::assertSame("my attachment\n", $archive['files/mine.txt']->getContent());
        self::assertNotNull($chat['registeredAt']);
        self::assertArrayNotHasKey('renames', $chat, 'The renames are the framework\'s section');
        $renames = json_decode($archive['renames.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $renames);
        self::assertSame('Before', $renames[0]['from']);
        self::assertSame('Exporting', $renames[0]['to']);
        self::assertStringNotContainsString('Other', $archive['chat.json']->getContent());
        self::assertStringNotContainsString('foreign', $archive['chat.json']->getContent());
    }
}

final class ChatExportTestFs extends FsContext
{
    /** @param string $directory Private test root */
    public function __construct(private readonly string $directory) { }

    /** Registers the existing attachment names and the export directory. */
    public function configure(): void
    {
        $this->registerDirectory(self::FILES, $this->directory . '/published', DirectoryScope::CLUSTER);
        $this->registerDirectory('published', $this->directory . '/published', DirectoryScope::CLUSTER);
        $this->registerDirectory(self::DATA_EXPORT, $this->directory . '/exports', DirectoryScope::CLUSTER);
    }
}

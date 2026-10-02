<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\HilosException;
use Hilos\Socket\Worker\DTO\CronSignalDTO;

/**
 * The history cleanup hands the files of the messages it deletes to the files library (HIL-144).
 *
 * Every half hour the chat deletes its whole history. The files those messages carried are
 * registry rows now: the cleanup names them in one remove frame after the events are gone, and
 * the library removes them at once - the chat no longer empties the files directory itself, which
 * is the registry's and may hold files the chat does not own.
 */
final class HistoryCleanupFilesTest extends IntegrationTestCase
{
    /** @var list<int> Registry rows the case created, removed once it ends */
    private array $fileIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::$db->events->actions->deleteAll();
        $this->drainSignals();
    }

    protected function tearDown(): void
    {
        Hilos::$db->events->actions->deleteAll();
        foreach ($this->fileIds as $fileId) {
            $file = Hilos::$db->files[$fileId];
            if ($file !== null) {
                $storedName = $file->storedName;
                $file->actions->delete();
                Hilos::$fs?->files[$storedName]->unlink();
            }
        }
        parent::tearDown();
    }

    /**
     * @throws HilosException When seeding or the cleanup fails
     */
    public function testTheCleanupNamesEveryAttachedFileToTheLibraryAndLeavesTheDirectory(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Cleaned')->id;
        $first = $this->keepRegistryFile($userId, 'first.txt', 'first');
        $second = $this->keepRegistryFile($userId, 'second.txt', 'second');
        $third = $this->keepRegistryFile($userId, 'third.txt', 'third');
        $this->fileIds = [(int)$first->id, (int)$second->id, (int)$third->id];
        Hilos::$db->events->actions->addMessage('two files', userId: $userId, fileIds: [(int)$first->id, (int)$second->id]);
        Hilos::$db->events->actions->addMessage('one file', userId: $userId, fileIds: [(int)$third->id]);
        Hilos::$db->events->actions->addMessage('no file', userId: $userId);
        $this->drainSignals();

        new ChatAgent()->onSignalCron(new CronSignalDTO(ChatCronConstants::CLEANUP_HISTORY), '', ChatCronConstants::CLEANUP_HISTORY);

        $removals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_FILE_REMOVE) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(FileRemoveSignalData::class, $signal->data->data);
                $removals[] = $signal->data->data->fileIds;
            }
        }
        self::assertCount(1, $removals, 'One remove frame names every file at once');
        $named = $removals[0];
        sort($named);
        self::assertSame($this->fileIds, $named);
        self::assertSame(0, count(Hilos::$db->eventAttachments->allFileIds()), 'No attachment survives the cleanup');
        foreach ([$first, $second, $third] as $file) {
            self::assertTrue(Hilos::$fs?->files[$file->storedName]->exists(), 'The chat no longer empties the files directory');
        }
    }
}

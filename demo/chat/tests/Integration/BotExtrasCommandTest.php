<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\LibraryAgent;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\View\Item\Bot;
use Demo\Chat\Hilos;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/** Test command preparation and cleanup through the library's real bot actions. */
final class BotExtrasCommandTest extends IntegrationTestCase
{
    /** Marker cleanup preserves active, ordinary and neighboring-marker bots across repeated requests. */
    public function testCreatesInactiveExtrasAndDeletesOnlyItsOwnInactiveMarker(): void
    {
        $sr = Hilos::$sr;
        Hilos::initSignalRouter(new ChatSignalRouter());
        $before = count(Hilos::$db->bots);
        $protectedBots = [];
        try {
            $this->assertSame(
                ['deleted' => 0],
                $this->dispatch(ChatCommandConstants::BOT_TEST_EXTRAS_DELETE, ['marker' => 'extras01'])->payload,
            );
            $this->assertCount($before, Hilos::$db->bots);
            $created = $this->dispatch(ChatCommandConstants::BOT_TEST_EXTRAS_CREATE, ['marker' => 'extras01', 'count' => 3]);
            $this->assertSame([ChatCommandConstants::botIds], array_keys($created->payload));
            $botIds = $created->payload[ChatCommandConstants::botIds];
            $this->assertIsArray($botIds);
            $this->assertTrue(array_is_list($botIds));
            $this->assertCount(3, $botIds);
            $this->assertCount(3, array_unique($botIds));
            $this->assertCount($before + 3, Hilos::$db->bots);
            foreach ($botIds as $index => $botId) {
                $this->assertIsInt($botId);
                $this->assertArrayHasKey($botId, Hilos::$db->bots);
                $this->assertSame('ZZ 1 extra extras01 ' . ($index + 1), Hilos::$db->bots[$botId]->name);
                $this->assertFalse(Hilos::$db->bots[$botId]->active);
                $this->assertSame('', Hilos::$db->bots[$botId]->description);
                $this->assertSame('', Hilos::$db->bots[$botId]->style);
                $this->assertSame('', Hilos::$db->bots[$botId]->topics);
                $this->assertSame('', Hilos::$db->bots[$botId]->personality);
            }

            $foreign = $this->dispatch(ChatCommandConstants::BOT_TEST_EXTRAS_CREATE, ['marker' => 'extras010', 'count' => 1]);
            $protectedBots[] = Hilos::$db->bots->actions->create('ZZ 1 extra extras01 active', active: true);
            $protectedBots[] = Hilos::$db->bots->actions->create('Ordinary inactive bot', active: false);
            $protectedBots[] = Hilos::$db->bots->actions->create('ZZ 1 extra extras01', active: false);

            $deleted = $this->dispatch(ChatCommandConstants::BOT_TEST_EXTRAS_DELETE, ['marker' => 'extras01']);
            $this->assertSame(['deleted' => 3], $deleted->payload);
            foreach ($botIds as $botId) {
                $this->assertArrayNotHasKey($botId, Hilos::$db->bots);
            }
            $this->assertCount($before + 4, Hilos::$db->bots);
            $this->assertArrayHasKey($foreign->payload[ChatCommandConstants::botIds][0], Hilos::$db->bots);
            foreach ($protectedBots as $bot) {
                $this->assertArrayHasKey($bot->id, Hilos::$db->bots);
            }
            $this->assertTrue(Hilos::$db->bots[$protectedBots[0]->id]->active);
            $this->assertSame(
                ['deleted' => 0],
                $this->dispatch(ChatCommandConstants::BOT_TEST_EXTRAS_DELETE, ['marker' => 'extras01'])->payload,
            );
            $this->assertCount($before + 4, Hilos::$db->bots);
        } finally {
            try {
                foreach (Hilos::$db->bots->filter(static fn (Bot $bot): bool =>
                    str_starts_with($bot->name, ChatCommandConstants::BOT_EXTRA_NAME_PREFIX . ' extras01 ')
                    || str_starts_with($bot->name, ChatCommandConstants::BOT_EXTRA_NAME_PREFIX . ' extras010 ')
                ) as $bot) {
                    $bot->actions->delete();
                }
                foreach ($protectedBots as $bot) {
                    if (isset(Hilos::$db->bots[$bot->id])) {
                        $bot->actions->delete();
                    }
                }
            } finally {
                Hilos::$sr = $sr;
            }
        }
    }

    /**
     * @param string $command Test command delivered to the library
     * @param array<string, mixed> $payload Command fields
     * @return CommandReplyDTO The single successful reply, with no bot-agent signal queued
     */
    private function dispatch(string $command, array $payload): CommandReplyDTO
    {
        $this->drainSignals();
        $request = new CommandRequestDTO('extras-command', $command, $payload);
        new LibraryAgent()->onSignalCommand(Hilos::$sr->createCommandPayloadDTO($command, $request), 'command', $command);
        $reply = null;
        $syncIds = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $this->assertNull($reply, 'The command answers exactly once');
                $reply = $signal->data;
                continue;
            }
            if ($command === ChatCommandConstants::BOT_TEST_EXTRAS_CREATE) {
                $this->assertInstanceOf(DbSyncCreatedSignalData::class, $signal->data, 'Extras queue DB sync and never start a bot agent');
            } else {
                $this->assertInstanceOf(DbSyncDeletedSignalData::class, $signal->data, 'Cleanup queues only DB sync beside its reply');
            }
            $this->assertSame(ChatDbContext::bots, $signal->data->collectionKey);
            $syncIds[] = $signal->data->idString;
        }
        $this->assertInstanceOf(CommandReplyDTO::class, $reply);
        $this->assertSame('extras-command', $reply->correlationId);
        $this->assertTrue($reply->isOk());
        if ($command === ChatCommandConstants::BOT_TEST_EXTRAS_CREATE) {
            $this->assertSame(array_map(strval(...), $reply->payload[ChatCommandConstants::botIds]), $syncIds);
        } else {
            $this->assertCount($reply->payload[ChatCommandConstants::deleted], $syncIds);
        }

        return $reply;
    }
}

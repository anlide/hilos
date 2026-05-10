<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration coverage for normalized event DbItem bridge properties.
 */
final class EventBridgePropertiesTest extends IntegrationTestCase
{
    public function testMessageEventExposesDetailAndAuthorBridges(): void
    {
        Hilos::$db->events->actions->deleteAll();
        $bot = null;

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            $bot = Hilos::$db->bots->actions->create('Bridge Bot', active: false);
            $this->assertNotNull($user->id);
            $this->assertNotNull($bot->id);

            $userEvent = Hilos::$db->events->actions->addMessage('user bridge', userId: $user->id);
            $botEvent = Hilos::$db->events->actions->addMessage('bot bridge', botId: $bot->id);

            $userMessage = $userEvent->eventMessage;
            $this->assertNotNull($userMessage);
            $this->assertSame($userEvent->id, $userMessage->event?->id);
            $this->assertSame($user->id, $userMessage->authorUser?->id);
            $this->assertNull($userMessage->authorBot);
            $this->assertSame(0, count($userMessage->attachments));

            $botMessage = $botEvent->eventMessage;
            $this->assertNotNull($botMessage);
            $this->assertSame($botEvent->id, $botMessage->event?->id);
            $this->assertSame($bot->id, $botMessage->authorBot?->id);
            $this->assertNull($botMessage->authorUser);
        } finally {
            Hilos::$db->events->actions->deleteAll();

            if ($bot?->id !== null && Hilos::$db->bots[$bot->id] !== null) {
                Hilos::$db->bots[$bot->id]->actions->delete();
            }
        }
    }

    public function testRegistrationEventExposesDetailAndUserBridges(): void
    {
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            $this->assertNotNull($user->id);
            $event = Hilos::$db->events->actions->addUserRegistered($user->id);

            $registration = $event->eventUserRegistration;
            $this->assertNotNull($registration);
            $this->assertSame($event->id, $registration->event?->id);
            $this->assertSame($user->id, $registration->targetUser?->id);
            $this->assertNull($event->eventMessage);
            $this->assertNull($event->eventUserRename);
        } finally {
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testRenameEventExposesDetailTargetAndActorBridges(): void
    {
        Hilos::$db->events->actions->deleteAll();

        try {
            $targetUser = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            $actorUser = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            $this->assertNotNull($targetUser->id);
            $this->assertNotNull($actorUser->id);
            $event = Hilos::$db->events->actions->addUserRenamedByAdmin(
                $targetUser->id,
                'Old Name',
                'New Name',
                $actorUser->id,
            );

            $rename = $event->eventUserRename;
            $this->assertNotNull($rename);
            $this->assertSame($event->id, $rename->event?->id);
            $this->assertSame($targetUser->id, $rename->targetUser?->id);
            $this->assertSame($actorUser->id, $rename->actorUser?->id);
            $this->assertNull($event->eventMessage);
            $this->assertNull($event->eventUserRegistration);
        } finally {
            Hilos::$db->events->actions->deleteAll();
        }
    }
}

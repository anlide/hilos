<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Constants\CliCommands;
use Hilos\Core\Router\SignalRouter;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AdminCommandConstants;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Proves admin:create works in this demo too: the framework's write over this demo's people (HIL-1197).
 *
 * Until HIL-1197 this demo refused the command, because it mints its people through its own
 * sign-in; the command is also the one way out of an installation whose every sign-in method
 * is switched off, so the owner had it work the same in all three demos. The framework owns the
 * command, the lookup, the bind and the write, and pins them over its own tables; what belongs
 * here is that the write lands in this demo's extended person chain - a session carrying a user
 * has THAT user flagged and no second one appears, and a session carrying none leaves with an
 * administrator bound to it whose chat column says it was never merged.
 *
 * Requires the test DB reset (composer run test:db-reset).
 */
final class AdminCreateCommandTest extends IntegrationTestCase
{
    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    /**
     * A session that already carries a user has that user flagged, and nothing is minted.
     *
     * @throws HilosException On database failure
     */
    public function testASessionCarryingAUserHasThatUserFlagged(): void
    {
        $sessionToken = RandomHelper::hex(16);
        $member = Hilos::$db->users->actions->createWithName('Member');
        Hilos::$db->sessions->actions->createAnonymous($sessionToken);
        Hilos::$db->sessions->findByToken($sessionToken)?->actions->bindUser((int)$member->id);
        $usersBefore = count(Hilos::$db->users->listAll());

        $reply = $this->sendAdminCreate($sessionToken);

        self::assertTrue($reply->isOk(), var_export($reply->payload, true));
        self::assertSame((int)$member->id, $reply->payload[AdminCommandConstants::FIELD_USER_ID]);
        self::assertFalse($reply->payload[AdminCommandConstants::FIELD_CREATED]);
        self::assertTrue(Hilos::$db->users[(int)$member->id]?->admin);
        self::assertCount($usersBefore, Hilos::$db->users->listAll(), 'Flagging a user mints none');
    }

    /**
     * A session with no user leaves with a minted administrator bound to it, in this demo's chain.
     *
     * @throws HilosException On database failure
     */
    public function testASessionCarryingNoUserGetsAMintedAdministrator(): void
    {
        $sessionToken = RandomHelper::hex(16);
        Hilos::$db->sessions->actions->createAnonymous($sessionToken);

        $reply = $this->sendAdminCreate($sessionToken);

        self::assertTrue($reply->isOk(), var_export($reply->payload, true));
        self::assertTrue($reply->payload[AdminCommandConstants::FIELD_CREATED]);

        $mintedId = $reply->payload[AdminCommandConstants::FIELD_USER_ID];
        self::assertIsInt($mintedId);
        $minted = Hilos::$db->users[$mintedId];
        self::assertTrue($minted?->admin);
        self::assertNull(Hilos::$db->userMerges[$mintedId]);
        // The bind is what makes the mint usable: without it the operator owns an
        // administrator and no browser that is one.
        self::assertSame($mintedId, Hilos::$db->sessions->findByToken($sessionToken)?->userId);
    }

    /**
     * Drives one admin:create through the library, the way the daemon routes it.
     *
     * @param string $sessionToken Session cookie token to send
     * @return CommandReplyDTO The reply the library queued
     * @throws HilosException When the library cannot be started
     */
    private function sendAdminCreate(string $sessionToken): CommandReplyDTO
    {
        $this->sessionsLibrary()->onSignalCommand(
            new CommandRequestDTO(
                correlationId: 'corr-admin-create',
                command: CliCommands::ADMIN_CREATE,
                payload: [AdminCommandConstants::FIELD_SESSION_TOKEN => $sessionToken],
            ),
            '',
            '',
        );

        return $this->consumeReply();
    }

    /**
     * Takes the one reply the library queued and fails the test when it queued none or two.
     *
     * The whole queue is drained rather than read once because the writes this command makes
     * are announced to the other workers as DB-sync signals, so the reply is not alone in
     * there on the paths that succeed.
     *
     * @return CommandReplyDTO The queued reply
     */
    private function consumeReply(): CommandReplyDTO
    {
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }

        self::assertCount(1, $replies, 'Every command branch answers exactly once');

        return $replies[0];
    }
}

<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Integration;

use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Hilos;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AdminCommandConstants;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Proves this demo's half of admin:create: the row it mints and the row it flags (HIL-609).
 *
 * The framework owns the command, the lookup and the session bind, and pins them over its
 * own tables; what belongs here is the project seam - that a session carrying a user has
 * THAT user flagged and no second one appears, and that a session carrying none leaves with
 * an administrator bound to it. Both go through the command the daemon really routes rather
 * than through the seam directly, because the mount is part of what is being proven.
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
     * This is today's visitor: the handshake registers everyone, so the operator pointing at
     * his own browser must not end up with a second account he never asked for.
     *
     * @throws HilosException On database failure
     */
    public function testASessionCarryingAUserHasThatUserFlagged(): void
    {
        $sessionToken = RandomHelper::hex(16);
        $visitor = Hilos::$db->users->actions->registerGuest();
        Hilos::$db->sessions->actions->createAnonymous($sessionToken);
        Hilos::$db->sessions->findByToken($sessionToken)?->actions->bindUser((int)$visitor->id);
        $usersBefore = count(Hilos::$db->users->listAll());

        $reply = $this->sendAdminCreate($sessionToken);

        self::assertTrue($reply->isOk(), var_export($reply->payload, true));
        self::assertSame((int)$visitor->id, $reply->payload[AdminCommandConstants::FIELD_USER_ID]);
        self::assertFalse($reply->payload[AdminCommandConstants::FIELD_CREATED]);
        self::assertTrue(Hilos::$db->users[(int)$visitor->id]?->admin);
        self::assertCount($usersBefore, Hilos::$db->users->listAll(), 'Flagging a user mints none');
    }

    /**
     * A session with no user leaves with a minted administrator bound to it.
     *
     * Unreachable from a browser in this demo until the visitor moves behind the session
     * (HIL-610/611), and the whole reason the command exists: on a fresh installation there
     * is no row to flag and no login to make one.
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
        self::assertTrue(Hilos::$db->users[$mintedId]?->admin);
        // The bind is what makes the mint usable: without it the operator owns an
        // administrator and no browser that is one.
        self::assertSame($mintedId, Hilos::$db->sessions->findByToken($sessionToken)?->userId);
    }

    /**
     * A wire name this agent does not mount is refused rather than met with silence.
     *
     * The command socket parks the caller until an answer comes back, so a handler that
     * ignored an unknown name would hang the CLI instead of failing it.
     */
    public function testAnUnknownCommandIsAnsweredWithAnError(): void
    {
        new PollAgent()->onSignalCommand(
            new CommandRequestDTO(correlationId: 'corr-unknown', command: 'admin:nonsense', payload: []),
            '',
            '',
        );

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('Unknown command', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    /**
     * Drives one admin:create through the agent, the way the daemon routes it.
     *
     * @param string $sessionToken Session cookie token to send
     * @return CommandReplyDTO The reply the agent queued
     */
    private function sendAdminCreate(string $sessionToken): CommandReplyDTO
    {
        new PollAgent()->onSignalCommand(
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
     * Takes the one reply the agent queued and fails the test when it queued none or two.
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

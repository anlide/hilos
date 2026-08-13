<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AdminCommandConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent side of the admin:grant / admin:revoke command route (HIL-553).
 *
 * The route is declared on the abstract index agent, so it exists in every project and is
 * answered by whatever reaches the unauthenticated command socket - the CLI class that
 * normally sends it is not on the path. What needs pinning here is everything the framework
 * owns: that both wire names land on the handler, that the payload is validated before the
 * project seam is called, and that the seam's outcome - refusal, failure, success - always
 * becomes exactly one reply. The write itself belongs to a project and is exercised by the
 * demo runs.
 */
final class AdminGrantCommandRouteTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testBothWireNamesDeclareTheRoute(): void
    {
        self::assertContains(CliCommands::ADMIN_GRANT, AbstractHilosIndexAgent::AGENT_COMMANDS);
        self::assertContains(CliCommands::ADMIN_REVOKE, AbstractHilosIndexAgent::AGENT_COMMANDS);
    }

    public function testGrantReachesTheProjectSeamAndAnswersWithTheFlag(): void
    {
        $agent = new AdminGrantRouteTestAgent();

        $this->sendCommand($agent, CliCommands::ADMIN_GRANT, [
            AdminCommandConstants::FIELD_USER_ID => 7,
            AdminCommandConstants::FIELD_ADMIN => true,
        ]);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk());
        self::assertSame([7, true], $agent->applied);
        self::assertSame(7, $reply->payload[AdminCommandConstants::FIELD_USER_ID]);
        self::assertTrue($reply->payload[AdminCommandConstants::FIELD_ADMIN]);
    }

    public function testRevokeCarriesItsFlagThroughTheSameHandler(): void
    {
        // The flag travels in the payload rather than in the wire name, so the revoke path
        // is only honest if a false flag survives the handler that both names share.
        $agent = new AdminGrantRouteTestAgent();

        $this->sendCommand($agent, CliCommands::ADMIN_REVOKE, [
            AdminCommandConstants::FIELD_USER_ID => 7,
            AdminCommandConstants::FIELD_ADMIN => false,
        ]);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk());
        self::assertSame([7, false], $agent->applied);
        self::assertFalse($reply->payload[AdminCommandConstants::FIELD_ADMIN]);
    }

    public function testAnUnwiredProjectRefusesAsAnErrorReply(): void
    {
        $this->sendCommand(new AdminGrantRouteTestUnwiredAgent(), CliCommands::ADMIN_GRANT, [
            AdminCommandConstants::FIELD_USER_ID => 7,
            AdminCommandConstants::FIELD_ADMIN => true,
        ]);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('not wired', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    public function testAnUnknownUserAnswersAsAnErrorReply(): void
    {
        $agent = new AdminGrantRouteTestAgent();
        $agent->refuseWith = new ItemNotFoundForUpdateException('No such user: 404');

        $this->sendCommand($agent, CliCommands::ADMIN_GRANT, [
            AdminCommandConstants::FIELD_USER_ID => 404,
            AdminCommandConstants::FIELD_ADMIN => true,
        ]);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('No such user', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    public function testANonPositiveUserIdIsRefusedBeforeTheSeamIsCalled(): void
    {
        $agent = new AdminGrantRouteTestAgent();

        $this->sendCommand($agent, CliCommands::ADMIN_GRANT, [
            AdminCommandConstants::FIELD_USER_ID => 0,
            AdminCommandConstants::FIELD_ADMIN => true,
        ]);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('positive userId', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertNull($agent->applied, 'A rejected payload never reaches the project write');
    }

    public function testAMissingUserIdIsRefusedRatherThanReadAsZero(): void
    {
        // The socket authenticates nobody, so a payload arrives as whatever was typed at it;
        // a missing id must not fall through to a user id of 0.
        $agent = new AdminGrantRouteTestAgent();

        $this->sendCommand($agent, CliCommands::ADMIN_GRANT, [
            AdminCommandConstants::FIELD_ADMIN => true,
        ]);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertNull($agent->applied);
    }

    /**
     * Drives one command through the agent under test.
     *
     * @param AbstractHilosIndexAgent $agent Agent under test
     * @param string $command Command-channel wire name to send
     * @param array<string, mixed> $payload Request payload
     */
    private function sendCommand(AbstractHilosIndexAgent $agent, string $command, array $payload): void
    {
        $agent->onSignalCommand(
            new CommandRequestDTO(correlationId: 'corr-1', command: $command, payload: $payload),
            '',
            '',
        );
    }

    /**
     * Takes the single reply the agent queued and fails the test when it queued none.
     *
     * @return CommandReplyDTO The queued reply
     */
    private function consumeReply(): CommandReplyDTO
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal, 'Every command branch answers exactly once');
        self::assertInstanceOf(CommandReplyDTO::class, $signal->data);
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'No branch answers twice');

        return $signal->data;
    }
}

/**
 * Index agent with the grant wired, standing in for a project binding: it records the call
 * instead of writing a row, and can be told to fail the way a project refuses an unknown user.
 */
final class AdminGrantRouteTestAgent extends AbstractHilosIndexAgent
{
    /** @var ?array{int, bool} Arguments the seam was called with, or null when it was not */
    public ?array $applied = null;

    /** @var ?ItemNotFoundForUpdateException Failure the seam raises instead of writing */
    public ?ItemNotFoundForUpdateException $refuseWith = null;

    public function onStop(): void
    {
    }

    /**
     * Records the grant, or fails the way a project refuses an unknown user.
     *
     * @param int $userId Target user id
     * @param bool $admin New admin flag
     * @throws ItemNotFoundForUpdateException When the test asked this seam to refuse
     */
    protected function applyAdminGrant(int $userId, bool $admin): void
    {
        if ($this->refuseWith !== null) {
            throw $this->refuseWith;
        }

        $this->applied = [$userId, $admin];
    }
}

/**
 * Index agent of a project that never wired the grant - the framework default, unchanged.
 */
final class AdminGrantRouteTestUnwiredAgent extends AbstractHilosIndexAgent
{
    public function onStop(): void
    {
    }
}

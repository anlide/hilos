<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * What an operator hears when the command handler they invoked threw (HIL-730).
 *
 * The worker caught {@see AgentException}, wrote the reason to the agent log and returned. The
 * request stayed parked in the master until the terminal gave up on it, so a handler that
 * failed loudly in the journal was indistinguishable from a daemon that was not running.
 *
 * The sibling catch one line above — a payload the command's DTO refused — already answered
 * with the exception's own text, and that precedent is the whole shape of the fix: the command
 * port is closed, and the installation owner is the only reader behind it.
 */
final class WorkerManagerCommandHandlerFailureTest extends TestCase
{
    private const string CORRELATION_ID = 'corr-730-worker';

    private const string COMMAND = 'protected-mode:open';

    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testAHandlerThatThrewIsAnsweredWithItsOwnReason(): void
    {
        $manager = new WorkerManagerCommandFailureTestManager();

        $this->handleCommand($manager);

        $reply = $this->queuedReply();
        $this->assertInstanceOf(CommandReplyDTO::class, $reply);
        $this->assertSame(CommandConstants::STATUS_ERROR, $reply->status);
        $this->assertSame(
            'protected-mode:open failed: the freeze file is held by another node',
            $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    /**
     * The reply is addressed by correlation id and named by it too, which is how the master
     * finds the connection still holding the request.
     */
    public function testTheReplyIsNamedAndAddressedByTheCorrelationId(): void
    {
        $manager = new WorkerManagerCommandFailureTestManager();

        $this->handleCommand($manager);

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::COMMAND_REPLY, $signal->signalType->getType());
        $this->assertSame(self::CORRELATION_ID, $signal->signalName->getName());
        $this->assertInstanceOf(CommandReplyDTO::class, $signal->data);
        $this->assertSame(self::CORRELATION_ID, $signal->data->correlationId);
    }

    /**
     * A handler that answered for itself must not be answered for a second time, or the
     * terminal reads whichever reply the master delivers first.
     */
    public function testAHandlerThatAnsweredForItselfIsNotAnsweredAgain(): void
    {
        $manager = new WorkerManagerCommandFailureTestManager();
        WorkerManagerCommandFailureTestAgent::$throwOnCommand = false;

        $this->handleCommand($manager);

        $reply = $this->queuedReply();
        $this->assertInstanceOf(CommandReplyDTO::class, $reply);
        $this->assertSame(CommandConstants::STATUS_OK, $reply->status);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Delivers one command request to the manager under test, the way the master does.
     *
     * @param WorkerManager $manager Manager under test
     */
    private function handleCommand(WorkerManager $manager): void
    {
        $handle = Closure::bind(
            static function (WorkerManager $manager, SignalDTO $signal): void {
                $manager->handleAgentMessage(new DaemonAgentMessageDTO(
                    WorkerManagerCommandFailureTestManager::AGENT_ID,
                    $signal,
                ));
            },
            null,
            WorkerManager::class,
        );

        $handle($manager, new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(self::COMMAND),
            new CommandRequestDTO(self::CORRELATION_ID, self::COMMAND),
        ));
    }

    /**
     * Takes the reply the worker queued for the master to carry back.
     *
     * @return ?CommandReplyDTO Queued reply, or null when the worker queued none
     */
    private function queuedReply(): ?CommandReplyDTO
    {
        $data = Hilos::$sr->getNextQueuedSignal()?->data;

        return $data instanceof CommandReplyDTO ? $data : null;
    }
}

final class WorkerManagerCommandFailureTestManager extends WorkerManager
{
    public const string AGENT_ID = 'unit_command_failure_agent';

    public function __construct()
    {
        parent::__construct(1);
        WorkerManagerCommandFailureTestAgent::$throwOnCommand = true;
        $this->agentManager->addAgent(self::AGENT_ID, new WorkerManagerCommandFailureTestAgent());
    }

    /**
     * @return SignalRouter Plain router: the command declares no payload DTO
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager Manager the test fills by hand
     */
    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerCommandFailureTestAgentManager();
    }
}

final class WorkerManagerCommandFailureTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Fixture agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new WorkerManagerCommandFailureTestAgent();
    }
}

/**
 * Fixture agent that owns the command and decides, per case, whether it fails or answers.
 */
final class WorkerManagerCommandFailureTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_command_failure';

    /** @var bool Whether the handler throws instead of answering, set by the case under test */
    public static bool $throwOnCommand = true;

    /**
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws AgentException When the case under test asks the handler to fail
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if (self::$throwOnCommand) {
            throw new AgentException('the freeze file is held by another node');
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId));
    }

    public function onStop(): void
    {
    }
}

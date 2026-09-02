<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Core\Agent\ProtectedModeTestDriverTrait;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/**
 * AbstractHilosIndexAgent - Abstract agent for Hilos dashboard, settings, i18n, and non-logs admin pages.
 *
 * Projects must extend this class to provide a concrete agent for Hilos index-scoped pages.
 * Logs overview uses {@see AbstractHilosLogsAgent} separately.
 *
 * It was also the periodic owner of the channel delivery journal until HIL-771. The prune
 * deletes rows from that journal, so it belongs to whoever owns it, and since HIL-771 that is
 * {@see AbstractNotificationsLibraryAgent} - which took the schedule, the idempotence and the
 * skip-when-unconfigured whole.
 */
abstract class AbstractHilosIndexAgent extends AbstractHilosAgent
{
    use ProtectedModeOperatorTrait;
    use ProtectedModeTestDriverTrait;

    public const string AGENT_TYPE = HilosAgentType::HILOS_INDEX;

    /**
     * The commands this agent answers, and the reason each of them is the index agent's.
     *
     * The test-only emit is NOT among them any more (HIL-771). It landed here because there
     * was no notification-owned agent to put it on - {@see HilosNotifier} was a worker seam
     * any process called - and {@see AbstractNotificationsLibraryAgent} is now that agent, so
     * the command sits beside the tables it writes.
     *
     * The protected-mode names (HIL-344, HIL-481, HIL-616, HIL-704) ride the same inheritance for the
     * same reason - chat, tasks and simple-poll get a freeze they can drive by extending this
     * class alone. The inspector is not among them: it is answered by the master, because a
     * freeze stops every agent but the initiator. The operator commands are not either: they
     * belong to the agent that runs real operations, and a command routes to exactly one agent
     * type. The fourth name is the test path's mint: it is answered by the operator trait this
     * class also carries, because a driven window otherwise has no way to produce the code its
     * maintenance screen now asks for - and the operator's own three names stay where they are.
     * The fifth (HIL-704) is that trait's other half, the test path's close: the window has two
     * exits, and without it nothing ever drove the one that freezes the node again instead of
     * opening it to everyone.
     *
     * The channel echo (HIL-729) rides it for the plainest reason of the lot: it proves the
     * command round-trip and nothing else, so the answer is the same wherever it is asked, and
     * an installation with no project agent of its own would otherwise have no way to ask.
     *
     * The admin grant pair is NOT here any more (HIL-729): what it writes ends in a person's
     * open tabs being told, and only the sessions library knows which sockets those are, so
     * {@see AbstractSessionsLibraryAgent} answers it now.
     */
    public const array AGENT_COMMANDS = [
        CliCommands::COMMAND_TEST_ECHO,
        CliCommands::PROTECTED_MODE_TEST_ENTER,
        CliCommands::PROTECTED_MODE_TEST_LEAVE,
        CliCommands::PROTECTED_MODE_TEST_OPEN,
        CliCommands::PROTECTED_MODE_TEST_PASS,
        CliCommands::PROTECTED_MODE_TEST_CLOSE,
    ];

    /**
     * Registers framework settings as the Hilos index DB truth source.
     */
    public function onStart(): void
    {
        $this->registerDbTruthSource(HilosDbContext::settings);
    }

    /**
     * Finishes any protected-mode drive in flight.
     *
     * @throws HilosException Whatever the concrete agent's tick raises
     */
    public function onTick(): void
    {
        parent::onTick();

        $this->tickProtectedModeTestDriver();
        $this->tickProtectedModeOperator();
    }

    /**
     * Routes the command-channel commands declared in {@see AGENT_COMMANDS}.
     *
     * Every path answers exactly once: a CLI parked on the command socket learns the outcome
     * instead of timing out.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused; the routing is on $data->command)
     * @throws InvalidArgumentException When the command reply carries an empty correlation id
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if ($this->isProtectedModeTestCommand($data->command)) {
            $this->handleProtectedModeTestCommand($data);

            return;
        }

        if ($this->isProtectedModeOperatorCommand($data->command)) {
            $this->handleProtectedModeOperatorCommand($data);

            return;
        }

        // The echo has no handler of its own on purpose: what it proves is that a request reached
        // an agent and a reply came back, so anything between the two would be the probe testing
        // itself instead of the channel.
        if ($data->command === CliCommands::COMMAND_TEST_ECHO) {
            $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, $data->payload));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
    }
}

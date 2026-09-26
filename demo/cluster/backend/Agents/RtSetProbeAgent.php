<?php

declare(strict_types=1);

namespace Demo\Cluster\Agents;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Hilos;
use Demo\Cluster\Runtime\View\Context\ClusterRtContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\TruthSource\Exception\ClaimedSetKeyMissingException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/**
 * RtSetProbeAgent - this node's owner of its own set of the probe notes (HIL-1116).
 *
 * The runtime set width holding across nodes is what it exists to show. The claim over a set
 * is laid in the worker that runs the agent, and "a write past the owner is refused" holds by
 * construction inside that one process; with the owners of the sets of one collection spread
 * over the nodes of a cluster, that has to be shown rather than assumed. So every node runs one
 * ({@see AgentScope::NODE}), and each owns the set named by its own node id: the scenario says
 * "s1 writes its set, s2 may not", and both names have to be literal - which a placed agent,
 * landing wherever the policy puts it, would not give.
 *
 * The write is driven over the command channel and goes through the ordinary runtime actions,
 * so the door that allows or refuses it is the truth-source door every application write
 * passes, not an imitation of it. A refusal is answered in that door's own words.
 */
final class RtSetProbeAgent extends AbstractAgent
{
    /**
     * The probe notes, by the set of this node.
     *
     * Every operation, so the node creates the notes of its set, rewrites them, and hands them
     * over as the whole truth about its set.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_RT_SET = [ClusterRtContext::probeNotes => TruthSourceOperation::BY_KIND];

    public const string AGENT_TYPE = AgentType::RT_SET_PROBE;

    /**
     * The one test-only command of the drill: write a note into a named set.
     *
     * No inner DTO: the payload is three strings, entered under the bare name, and the `test:`
     * prefix keeps the socket from ever parking it on a production-like node. The master has no
     * branch for it, so it is parked and routed here.
     */
    public const array AGENT_COMMANDS = [CliCommands::CLUSTER_TEST_RT_WRITE];

    /**
     * Names the set this replica owns: the node it runs on.
     *
     * Off a cluster there is no node and so no set, and the empty key refuses the start
     * ({@see ClaimedSetKeyMissingException}) - the stand is always a cluster.
     *
     * @param string $collection Collection the resolver is asking about, as named in the map
     * @return string Id of this node, empty off a cluster
     */
    public function ownedRtSetKey(string $collection): string
    {
        $cluster = Hilos::$cluster;
        if ($cluster === null || !$cluster->isEnabled()) {
            return '';
        }

        return $cluster->identity()->nodeId;
    }

    /**
     * Lets the replica go without clearing anything: the notes of its set stay on the nodes.
     *
     * The claim goes with the process, which is the correct lifetime for it - the next replica
     * on this node makes its own.
     */
    public function onStop(): void
    {
    }

    /**
     * Routes the command of {@see AGENT_COMMANDS}.
     *
     * Every path answers exactly once, so a CLI parked on the command socket learns the outcome
     * instead of timing out.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused; the routing is on $data->command)
     * @throws InvalidArgumentException When the reply cannot be named for the command
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        $reply = match ($data->command) {
            CliCommands::CLUSTER_TEST_RT_WRITE => $this->write($data),
            default => CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"),
        };

        $this->replyToCommand($reply);
    }

    /**
     * Writes the named note into the named set through the ordinary runtime actions.
     *
     * Nothing here checks the set against the node: the set is the command's to name, and the
     * door judges it - which is the whole of what the scenario watches.
     *
     * @param CommandRequestDTO $request Request naming the set, the note and its text
     * @return CommandReplyDTO Reply naming what was written, or why nothing was
     */
    private function write(CommandRequestDTO $request): CommandReplyDTO
    {
        $setKey = self::stringField($request, CommandConstants::FIELD_RT_SET_KEY);
        $noteId = self::stringField($request, CommandConstants::FIELD_RT_STATE_ID);
        $text = self::stringField($request, CommandConstants::FIELD_TEXT);
        if ($setKey === null || $noteId === null || $text === null) {
            return CommandReplyDTO::error(
                $request->correlationId,
                'Missing ' . CommandConstants::FIELD_RT_SET_KEY . ', ' . CommandConstants::FIELD_RT_STATE_ID
                . ' or ' . CommandConstants::FIELD_TEXT,
            );
        }

        try {
            Hilos::$rt->probeNotes->actions->write($noteId, $setKey, $text);
        // read-refusal-swallowed: this probe answers its caller with whatever failed, the refusal included
        } catch (HilosException $e) {
            return CommandReplyDTO::error($request->correlationId, $e->getMessage());
        }

        return CommandReplyDTO::ok($request->correlationId, [
            CommandConstants::FIELD_RT_SET_KEY => $setKey,
            CommandConstants::FIELD_RT_STATE_ID => $noteId,
            CommandConstants::FIELD_TEXT => $text,
        ]);
    }

    /**
     * Reads one non-empty string out of a request payload.
     *
     * @param CommandRequestDTO $request Request whose payload is being read
     * @param string $field Payload key to read
     * @return ?string The value, or null when it is absent, not a string, or empty
     */
    private static function stringField(CommandRequestDTO $request, string $field): ?string
    {
        // external-boundary: the harness's command line, arriving over the command socket
        $value = $request->payload[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

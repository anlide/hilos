<?php

declare(strict_types=1);

namespace Hilos\Cluster\Probe;

use Hilos\Constants\HilosAgentType;
use Hilos\Hilos;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\TruthSource\Exception\ClaimedSetKeyMissingException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Item\Object_;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/**
 * DbProbeAgent - this node's hand on the one database the stand shares (HIL-712).
 *
 * One replica on every node ({@see AgentScope::NODE}), and that is the whole reason it
 * exists rather than being a job for the fleet: the scenario says "node A writes, node B
 * reads", and both names have to be literal. A fleet member is placed wherever the policy
 * puts it and is left dead behind scenario 9 (P-152), so "node B" would be whichever node
 * happened to be holding one. A node replica is on each node, always.
 *
 * It is not the master's work either, which is the sharper half of the same question: the
 * cluster test commands the master answers itself all stay away from the database, and
 * blocking work in the master process is forbidden outright. Reading a row is blocking work.
 *
 * What this proves is not that MySQL stores what it is given. The read answers out of THIS
 * process's copy of the probe values collection and never with a fresh query, so a value written
 * on another node can only come back if the announcement crossed the mesh and the copy here
 * went stale on purpose (HIL-670). A query would pass the scenario while proving nothing
 * beyond both nodes being able to reach the same server.
 */
final class DbProbeAgent extends AbstractAgent
{
    /**
     * The probe values belonging to this node, with adding and updating alone.
     *
     * The set claim supplies reader interest in the whole collection, so this replica observes
     * other nodes' writes too. {@see AbstractAgent::READS_DB} would repeat that interest and is
     * refused beside a claim. The write door still permits changes only within this node's set.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB_SET = [
        HilosDbContext::probeValues => [TruthSourceOperation::Add, TruthSourceOperation::Update],
    ];

    public const string AGENT_TYPE = HilosAgentType::HILOS_PROBE_DB;

    /**
     * The write half and the read half of the drill, both test-only.
     *
     * Neither carries an inner DTO: the payload is two strings, so both are entered under
     * the bare name. The `test:` prefix on that name is what keeps the socket from ever
     * parking either of them on a production-like node.
     *
     * The master answers neither, and nothing had to be done to arrange that: it has no
     * branch for a name it does not know, so both are parked and routed here - which is
     * exactly the shape wanted, because the master must not touch a database.
     */
    public const array AGENT_COMMANDS = [
        CliCommands::CLUSTER_TEST_DB_WRITE,
        CliCommands::CLUSTER_TEST_DB_READ,
    ];

    /**
     * Names this replica's set by its node id; off a cluster the empty key would refuse the
     * claim ({@see ClaimedSetKeyMissingException}), but the probe's start gate excludes that case.
     *
     * @param string $collection Collection the resolver is asking about, as named in the map
     * @return string Id of this node, empty off a cluster
     */
    public function ownedDbSetKey(string $collection): string
    {
        $cluster = Hilos::$cluster;
        if ($cluster === null || !$cluster->isEnabled()) {
            return '';
        }

        return $cluster->identity()->nodeId;
    }

    /**
     * Lets the replica go without clearing anything: the row it wrote is in the database.
     *
     * The claim over this node's probe values goes with the process, which is the correct
     * lifetime for it - the next node replica to come up makes its own.
     */
    public function onStop(): void
    {
    }

    /**
     * Routes the two commands of {@see AGENT_COMMANDS}.
     *
     * Every path answers exactly once, so a CLI parked on the command socket learns the
     * outcome instead of timing out.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused; the routing is on $data->command)
     * @throws InvalidArgumentException When the reply cannot be named for the command
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        $reply = match ($data->command) {
            CliCommands::CLUSTER_TEST_DB_WRITE => $this->write($data),
            CliCommands::CLUSTER_TEST_DB_READ => $this->read($data),
            default => CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"),
        };

        $this->replyToCommand($reply);
    }

    /**
     * Writes the named probe value through the ordinary actions and lets them raise the fact.
     *
     * Nothing here announces anything: {@see Object_::sync()} emits DB_SYNC_CREATED or
     * DB_SYNC_UPDATED off the write itself. That is the point of driving the drill down the
     * production path rather than an imitation of it - what the scenario then watches is the
     * mechanism an application gets for free, not one this agent built for the occasion.
     *
     * The first write adds the row to this node's set and every later one updates it in place.
     * An existing row belonging to another node is refused by the ordinary set ownership door.
     *
     * @param CommandRequestDTO $request Request naming the probe key and its new value
     * @return CommandReplyDTO Reply naming what was written and which member took it, or why nothing was
     */
    private function write(CommandRequestDTO $request): CommandReplyDTO
    {
        $key = self::stringField($request, CommandConstants::FIELD_SETTING_KEY);
        $value = self::stringField($request, CommandConstants::FIELD_SETTING_VALUE);
        if ($key === null || $value === null) {
            return CommandReplyDTO::error(
                $request->correlationId,
                'Missing ' . CommandConstants::FIELD_SETTING_KEY . ' or ' . CommandConstants::FIELD_SETTING_VALUE,
            );
        }

        try {
            $probeValues = Hilos::$db->probeValues;
            if (!isset($probeValues[$key])) {
                $probeValues->actions->add($this->ownedDbSetKey(HilosDbContext::probeValues), $key, $value);
            } else {
                $probeValues[$key]->actions->updateValue($value);
            }
            Database::sql('SELECT @@hostname AS member');
            $member = Database::field('member');
        // read-refusal-swallowed: this probe answers its caller with whatever failed, the refusal included
        } catch (HilosException $e) {
            return CommandReplyDTO::error($request->correlationId, $e->getMessage());
        }

        return CommandReplyDTO::ok($request->correlationId, [
            CommandConstants::FIELD_SETTING_KEY => $key,
            CommandConstants::FIELD_SETTING_VALUE => $value,
            CommandConstants::FIELD_DATABASE_MEMBER => $member,
        ]);
    }

    /**
     * Answers with the value this process holds for the named probe row.
     *
     * Out of the cached copy on purpose, and null when no row is held for the key - see the
     * class docblock for why a fresh query would leave the scenario proving nothing.
     *
     * @param CommandRequestDTO $request Request naming the probe key
     * @return CommandReplyDTO Reply carrying the key and its value, null when there is no row
     */
    private function read(CommandRequestDTO $request): CommandReplyDTO
    {
        $key = self::stringField($request, CommandConstants::FIELD_SETTING_KEY);
        if ($key === null) {
            return CommandReplyDTO::error(
                $request->correlationId,
                'Missing ' . CommandConstants::FIELD_SETTING_KEY,
            );
        }

        try {
            $value = isset(Hilos::$db->probeValues[$key]) ? Hilos::$db->probeValues[$key]->value : null;
        // read-refusal-swallowed: this probe answers its caller with whatever failed, the refusal included
        } catch (HilosException $e) {
            return CommandReplyDTO::error($request->correlationId, $e->getMessage());
        }

        return CommandReplyDTO::ok($request->correlationId, [
            CommandConstants::FIELD_SETTING_KEY => $key,
            CommandConstants::FIELD_SETTING_VALUE => $value,
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

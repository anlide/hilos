<?php

declare(strict_types=1);

namespace Demo\Cluster\Agents;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Hilos;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentCommandConfigKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Settings\SettingsCatalogConstants;
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
 * process's copy of the settings collection and never with a fresh query, so a value written
 * on another node can only come back if the announcement crossed the mesh and the copy here
 * went stale on purpose (HIL-670). A query would pass the scenario while proving nothing
 * beyond both nodes being able to reach the same server.
 */
final class DbProbeAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::DB_PROBE;

    /**
     * The write half and the read half of the drill, both test-only.
     *
     * Neither carries an inner DTO: the payload is two strings, and the config entry exists
     * to hold {@see AgentCommandConfigKey::TEST_ONLY}, which is what keeps the socket from
     * ever parking either of them on a production-like node.
     *
     * The master answers neither, and nothing had to be done to arrange that: it has no
     * branch for a name it does not know, so both are parked and routed here - which is
     * exactly the shape wanted, because the master must not touch a database.
     */
    public const array AGENT_COMMANDS = [
        CliCommands::CLUSTER_TEST_DB_WRITE => [AgentCommandConfigKey::TEST_ONLY => true],
        CliCommands::CLUSTER_TEST_DB_READ => [AgentCommandConfigKey::TEST_ONLY => true],
    ];

    /**
     * Claims the settings collection, because a write needs a truth source to be allowed one.
     *
     * Claimed rather than listed as a read: {@see AbstractAgent::READS_DB} is for a collection
     * somebody else owns, and a claim is its own reader interest anyway (HIL-750), so
     * declaring both would be one fact kept in two places.
     *
     * Every node claims it and nothing objects. The cluster-wide two-owner guard is over
     * runtime collections, whose rows live in one process's memory; these rows are on a disk
     * all five nodes share, and any of them may legitimately write there.
     */
    public function onStart(): void
    {
        $this->registerDbTruthSource(HilosDbContext::settings);
    }

    /**
     * Lets the replica go without clearing anything: the row it wrote is in the database.
     *
     * The claim over the settings collection goes with the process, which is the correct
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
     * Writes the named setting through the ordinary actions and lets them raise the fact.
     *
     * Nothing here announces anything: {@see Object_::sync()} emits DB_SYNC_CREATED or
     * DB_SYNC_UPDATED off the write itself. That is the point of driving the drill down the
     * production path rather than an imitation of it - what the scenario then watches is the
     * mechanism an application gets for free, not one this agent built for the occasion.
     *
     * The key is an orphan by construction, since this demo registers no settings catalog at
     * all, so the first write adds the row and every later one updates it in place.
     *
     * @param CommandRequestDTO $request Request naming the setting key and its new value
     * @return CommandReplyDTO Reply naming what was written, or why nothing was
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
            $settings = Hilos::$db->settings;
            $existing = $settings[$key];
            if ($existing === null) {
                $settings->actions->addOrphan(
                    $key,
                    SettingsCatalogConstants::TYPE_STRING,
                    $value,
                    Hilos::$setting?->catalog() ?? [],
                );
            } else {
                $existing->actions->updateValue($value);
            }
        } catch (HilosException $e) {
            return CommandReplyDTO::error($request->correlationId, $e->getMessage());
        }

        return CommandReplyDTO::ok($request->correlationId, [
            CommandConstants::FIELD_SETTING_KEY => $key,
            CommandConstants::FIELD_SETTING_VALUE => $value,
        ]);
    }

    /**
     * Answers with the value this process holds for the named setting.
     *
     * Out of the cached copy on purpose, and null when no row is held for the key - see the
     * class docblock for why a fresh query would leave the scenario proving nothing.
     *
     * @param CommandRequestDTO $request Request naming the setting key
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
            $setting = Hilos::$db->settings[$key];
        } catch (HilosException $e) {
            return CommandReplyDTO::error($request->correlationId, $e->getMessage());
        }

        return CommandReplyDTO::ok($request->correlationId, [
            CommandConstants::FIELD_SETTING_KEY => $key,
            CommandConstants::FIELD_SETTING_VALUE => $setting?->value,
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

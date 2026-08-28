<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle\Agent;

use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleSuccessSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Auth\Throttle\ThrottleCommandConstants;
use Hilos\Auth\Throttle\ThrottlePolicy;
use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentCommandConfigKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Collection\AuthBlocks;
use Hilos\Database\View\Item\AuthBlock;
use Hilos\Hilos;
use Hilos\Log\LogRotationAgent;
use Hilos\Runtime\State\Item\AuthAttempt as StateAuthAttempt;
use Hilos\Runtime\View\Collection\AuthAttempts;
use Hilos\Runtime\View\Item\AuthAttempt as ViewAuthAttempt;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Throwable;

/**
 * Per-node owner of the anti-abuse attempt counters and blocks (HIL-420).
 *
 * The single truth source of the `hilosAuthAttempts` collection, which is what makes it an
 * agent at all: a worker may read its replica of the counters but not write it, so counting
 * has to happen in one process and be handed to the rest over runtime sync. It is per-node
 * and not monopolistic ({@see AuthThrottleAgentDaemon}), the same placement
 * {@see LogRotationAgent} has.
 *
 * It answers one question, over one signal: a worker that could not settle an attempt from
 * its own replica asks {@see HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK}, and the
 * verdict goes back over {@see HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT} to the
 * page agent that parked the action. All the arithmetic - the window, the ladder, the
 * durable write - lives here, so a worker never reaches the database on the auth path.
 *
 * On start it replays the blocks that have not lifted out of `hilos_auth_block` into the
 * counters. That replay is the whole point of the durable half: the counters die with the
 * process, and without it restarting the daemon would be a way to have a block forgotten.
 * Window counts are deliberately not replayed - an in-flight window is worth less than the
 * complexity of persisting it, and losing one costs an abuser a few attempts, not a block.
 */
final class AuthThrottleAgent extends AbstractAgent
{
    /**
     * @var list<string> The durable blocks it writes the ladder's verdicts into. Read rather than
     *     claimed: the table is written from more than this agent's process - a block outlives the
     *     node that set it - so the agent is one of its readers and says so.
     */
    public const array READS_DB = [HilosDbContext::authBlocks];

    public const string AGENT_TYPE = HilosAgentType::HILOS_AUTH_THROTTLE;

    /**
     * Worker → agent route for the verdict request. A singleton per node, so it maps
     * straight to its payload DTO with no index field.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => ThrottleCheckSignalData::class,
        HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => ThrottleSuccessSignalData::class,
    ];

    /**
     * The one thing this agent accepts from outside a running page: the test-only reset,
     * which empties both halves of the state. It travels the command channel rather than
     * being done in the CLI process because the counters are this agent's runtime
     * collection, which no other process holds. No inner DTO - the config entry exists only
     * to carry {@see AgentCommandConfigKey::TEST_ONLY}, which is what stops the socket from
     * ever parking the command on a production-like node.
     */
    public const array AGENT_COMMANDS = [
        CliCommands::THROTTLE_TEST_RESET => [AgentCommandConfigKey::TEST_ONLY => true],
    ];

    /** @var float Minimum seconds between counter sweeps, so onTick stays cheap */
    private const float SWEEP_INTERVAL_SECONDS = 60.0;

    /** Configured limits and ladder, read once on start. */
    private ThrottlePolicy $policy;

    /** @var float Timestamp of the last sweep, for throttling */
    private float $lastSweepAt = 0.0;

    /**
     * Claims the counters, reads the policy, and replays the blocks still in force.
     */
    public function onStart(): void
    {
        $this->policy = ThrottlePolicy::fromEnv();
        $this->registerRtTruthSource(StateAuthAttempt::RT_COLLECTION);
        $this->lastSweepAt = microtime(true);
        $this->replayDurableBlocks();
    }

    /**
     * Judges one attempt and answers the worker that asked.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the signal name is neither of the two it answers
     * @throws InvalidArgumentException When the verdict signal cannot be named or queued
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK:
                if ($data->data instanceof ThrottleCheckSignalData) {
                    $this->judge($data->data);

                    return;
                }
                break;

            case HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED:
                if ($data->data instanceof ThrottleSuccessSignalData) {
                    $this->forgive($data->data);

                    return;
                }
                break;

            default:
                throw new AgentUnknownSignalException($name);
        }

        $this->logAgentWarning("{$name} arrived with a payload it does not carry: " . get_class($data->data));
    }

    /**
     * Handles the test-only reset routed here over the command channel.
     *
     * @param CommandRequestDTO $data Command request (no payload fields consumed)
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused)
     * @throws InvalidArgumentException When the handler cannot name its reply to the command
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if ($data->command !== CliCommands::THROTTLE_TEST_RESET) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));

            return;
        }

        $this->handleReset($data);
    }

    /**
     * Retires the counters that have gone quiet, at most once a minute.
     */
    public function onTick(): void
    {
        $now = microtime(true);
        if ($now - $this->lastSweepAt < self::SWEEP_INTERVAL_SECONDS) {
            return;
        }
        $this->lastSweepAt = $now;

        $attempts = $this->attemptsView();
        $attempts?->actions->sweep($now, $this->policy->windowSeconds, $this->policy->cooldownSeconds());
        $this->dropServedBlocks($now);
    }

    /**
     * Nothing owned to release: the counters die with the worker, the blocks are in the database.
     */
    public function onStop(): void
    {
        // No-op.
    }

    /**
     * Empties both halves of the throttle state and reports what each of them gave up.
     *
     * Refused outright on a production-like environment, though not here: the command socket
     * turns the request away before it is parked, so by the time this runs the environment
     * has already been judged. What made that gate necessary is this handler's own reach -
     * it hands every blocked key its access back.
     *
     * @param CommandRequestDTO $data Command request being answered
     */
    private function handleReset(CommandRequestDTO $data): void
    {
        try {
            $countersCleared = $this->attemptsView()?->actions->clear() ?? 0;
            $blocksCleared = $this->blocksCollection()?->clearAll() ?? 0;
        } catch (Throwable $e) {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                'Auth throttle reset failed: ' . $e->getMessage(),
            ));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            ThrottleCommandConstants::FIELD_COUNTERS_CLEARED => $countersCleared,
            ThrottleCommandConstants::FIELD_BLOCKS_CLEARED => $blocksCleared,
        ]));
    }

    /**
     * Counts one attempt against its key and decides whether the action may run.
     *
     * The order is what keeps a blocked key from paying for the attempt twice: an active
     * block answers before anything is counted, so an abuser hammering a blocked key does
     * not push its window count up and cannot make the block escalate further by knocking.
     * Only an attempt that is actually judged is counted, and only a count past the limit
     * escalates.
     *
     * @param ThrottleCheckSignalData $check Attempt to judge
     */
    private function judge(ThrottleCheckSignalData $check): void
    {
        if (!$this->policy->enabled) {
            $this->answer($check, true, null);

            return;
        }

        $attempt = $this->counterFor($check);
        if ($attempt === null) {
            $this->answer($check, true, null);

            return;
        }

        $now = microtime(true);
        $blockedUntil = $attempt->blockedUntil;
        if ($blockedUntil !== null && $blockedUntil > $now) {
            $this->answer($check, false, (int)ceil($blockedUntil - $now));

            return;
        }

        $count = $attempt->actions->countAttempt($now, $this->policy->windowSeconds);
        if ($count <= $this->policy->maxFor($check->scope)) {
            $this->answer($check, true, null);

            return;
        }

        $this->answer($check, false, $this->escalate($check, $attempt, $now));
    }

    /**
     * Deletes the stored blocks that have been served and cooled, on the same sweep.
     *
     * A row is only ever read back by the replay on start, and only while it is still in
     * force ({@see replayDurableBlocks()}), so one whose block lifted long ago will never be
     * read again by anything. Without this the table would grow by one row per key ever
     * blocked and never shrink - unbounded in exactly the situation the layer exists for,
     * which is somebody hammering the sign-in from many addresses.
     *
     * The cooldown is what "long ago" means, and it is the same day the ladder cools over,
     * because that is the last moment the row could still have said something: after it, the
     * level it carries is one the counters have already forgotten.
     *
     * @param float $now Current unix seconds
     */
    private function dropServedBlocks(float $now): void
    {
        $blocks = $this->blocksCollection();
        if ($blocks === null) {
            return;
        }

        try {
            $blocks->clearServed(date('Y-m-d H:i:s', (int)($now - $this->policy->cooldownSeconds())));
        } catch (Throwable $e) {
            $this->logAgentError('Auth throttle: could not drop the served blocks: ' . $e->getMessage());
        }
    }

    /**
     * Clears everything counted against a session that has just proved who it is.
     *
     * Both halves of the state go, and in this order: a durable row surviving the runtime one
     * would come back at the next start and refuse a session that is now known to be legitimate.
     * A failed durable delete is logged rather than raised - the counters are already clear, so
     * the person is let through, and the worst the leftover row can do is outlive a restart.
     *
     * @param ThrottleSuccessSignalData $success Session that authenticated
     */
    private function forgive(ThrottleSuccessSignalData $success): void
    {
        $this->attemptsView()?->actions->forgetSession($success->identity);

        $blocks = $this->blocksCollection();
        if ($blocks === null) {
            return;
        }

        try {
            $blocks->clearIdentity(ThrottleScope::SESSION, $success->identity);
        } catch (Throwable $e) {
            $this->logAgentError('Auth throttle: could not drop the stored blocks of a session: ' . $e->getMessage());
        }
    }

    /**
     * Raises a breaching key one ladder step, durably, and reports how long it is out.
     *
     * The runtime row is written first and the database second, on purpose: the runtime
     * write is what every worker's fast path reads, so a database that is slow or down
     * delays the record of the block rather than the block itself. A failed durable write
     * is logged and swallowed for the same reason - it costs the block its survival across
     * a restart, which is worth far less than refusing every auth action while the database
     * is unwell.
     *
     * @param ThrottleCheckSignalData $check Attempt that breached its limit
     * @param ViewAuthAttempt $attempt Counter of the breaching key
     * @param float $now Current unix seconds
     * @return int Seconds until the block lifts
     */
    private function escalate(ThrottleCheckSignalData $check, ViewAuthAttempt $attempt, float $now): int
    {
        $level = $this->policy->escalate($attempt->level);
        $blockSeconds = $this->policy->blockSecondsFor($level);
        $liftsAt = $now + $blockSeconds;

        $attempt->actions->block($level, $liftsAt, $now);
        $this->logAgentWarning(
            "Auth throttle: blocked {$check->scope} key on '{$check->action}' for {$blockSeconds}s"
                . " at level {$level} (acceptKey={$check->acceptKey})",
        );

        $blocks = $this->blocksCollection();
        if ($blocks !== null) {
            try {
                $blocks->recordBlock(
                    $check->scope,
                    $check->identity,
                    $check->action,
                    $level,
                    date('Y-m-d H:i:s', (int)$liftsAt),
                );
            } catch (Throwable $e) {
                $this->logAgentError('Auth throttle: could not persist a block: ' . $e->getMessage());
            }
        }

        return $blockSeconds;
    }

    /**
     * Reads back every block still in force and puts it into the counters.
     *
     * A block whose datetime cannot be read is skipped rather than treated as blocking
     * forever: a row we cannot understand must not be able to lock a key out permanently.
     */
    private function replayDurableBlocks(): void
    {
        $blocks = $this->blocksCollection();
        $attempts = $this->attemptsView();
        if ($blocks === null || $attempts === null) {
            return;
        }

        $replayed = 0;
        $now = microtime(true);
        try {
            $active = $blocks->findActive(date('Y-m-d H:i:s'));
        } catch (Throwable $e) {
            $this->logAgentError('Auth throttle: could not read the stored blocks: ' . $e->getMessage());

            return;
        }

        foreach ($active as $block) {
            $liftsAt = $this->blockLiftsAt($block);
            if ($liftsAt === null) {
                continue;
            }

            $attempts->actions->open($block->scope, $block->identity, $block->action);
            $key = StateAuthAttempt::keyFor($block->scope, $block->identity, $block->action);
            $attempts[$key]?->actions->block($block->level, $liftsAt, $now);
            $replayed++;
        }

        if ($replayed > 0) {
            $this->logAgentInfo("Auth throttle: replayed {$replayed} stored block(s)");
        }
    }

    /**
     * Reads a stored block's lift moment as unix seconds.
     *
     * @param AuthBlock $block Stored block
     * @return ?float Unix seconds the block lifts, or null when the row names no readable moment
     */
    private function blockLiftsAt(AuthBlock $block): ?float
    {
        $blockedUntil = $block->blockedUntil;
        if ($blockedUntil === null) {
            return null;
        }

        $liftsAt = strtotime($blockedUntil);

        return $liftsAt === false ? null : (float)$liftsAt;
    }

    /**
     * Ensures a counter exists for the checked key and returns it.
     *
     * @param ThrottleCheckSignalData $check Attempt being judged
     * @return ?ViewAuthAttempt Counter for the key, or null when the counters are not mounted
     */
    private function counterFor(ThrottleCheckSignalData $check): ?ViewAuthAttempt
    {
        $attempts = $this->attemptsView();
        if ($attempts === null) {
            return null;
        }

        $attempts->actions->open($check->scope, $check->identity, $check->action);

        return $attempts[StateAuthAttempt::keyFor($check->scope, $check->identity, $check->action)];
    }

    /**
     * Sends the verdict back to the page agent that parked the action.
     *
     * @param ThrottleCheckSignalData $check Attempt being answered
     * @param bool $allowed Whether the action may run
     * @param ?int $retryAfter Seconds until the block lifts; null when allowed
     */
    private function answer(ThrottleCheckSignalData $check, bool $allowed, ?int $retryAfter): void
    {
        $this->sendToAgent(
            HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT,
            new ThrottleVerdictSignalData($check->requestKey, $allowed, $retryAfter, $check->agentIndex),
        );
    }

    /**
     * The runtime counters, or null with a line in the log when the feature was not activated.
     *
     * A project that registered this agent without declaring
     * {@see HilosFeature::AUTH_THROTTLE} has an agent with nothing to own; saying so is the
     * only way that mistake is visible, because everything downstream of it just lets
     * traffic through. Asked through isset(), because reading an unmounted runtime
     * collection throws - and that is precisely the case this is here to report.
     *
     * @return ?AuthAttempts Counters view, or null when not mounted
     */
    private function attemptsView(): ?AuthAttempts
    {
        $rt = Hilos::$rt;
        $attempts = $rt !== null && isset($rt->hilosAuthAttempts) ? $rt->hilosAuthAttempts : null;
        if ($attempts instanceof AuthAttempts) {
            return $attempts;
        }

        $this->logAgentError(
            'Auth throttle counters are not mounted: declaring HilosFeature::AUTH_THROTTLE mounts '
            . StateAuthAttempt::RT_COLLECTION
            . ' via AuthThrottleFeature::mount(); the project runtime context must not replace it',
        );

        return null;
    }

    /**
     * The durable block table, or null with a line in the log when the framework context is absent.
     *
     * @return ?AuthBlocks Blocks collection, or null when unavailable
     */
    private function blocksCollection(): ?AuthBlocks
    {
        $db = Hilos::$db;
        if ($db instanceof HilosDbContext) {
            return $db->authBlocks;
        }

        $this->logAgentError('Auth throttle: the framework database context is unavailable, blocks cannot be stored');

        return null;
    }
}

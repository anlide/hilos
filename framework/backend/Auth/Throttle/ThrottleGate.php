<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle;

use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleSuccessSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\AuthAttempt as StateAuthAttempt;
use Hilos\Runtime\View\Collection\AuthAttempts;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Utils\Logger;

/**
 * The worker's half of the anti-abuse guard: what it can decide alone, and what it must ask (HIL-420).
 *
 * {@see PageSignalRouter} holds the actions waiting on a verdict; this holds the knowledge
 * of what a throttle key is, which of them an action has, and how to reach the one process
 * allowed to count - {@see AuthThrottleAgent}. Keeping the two apart is what lets the page
 * dispatcher stay a page dispatcher: it asks whether this action is refused, parked or free,
 * and never learns what a scope or a ladder is.
 *
 * An action is keyed twice, once per scope: the IP a crowd shares and the session one browser
 * holds. Both are counted, so passing one limit does not excuse the other, and a refusal by
 * either refuses the action.
 *
 * The gate also remembers whether the agent is answering at all (HIL-1280). A verdict that does
 * not arrive in time is this server's failure, so within the grace of
 * {@see AuthThrottleSettings::outageGraceSeconds()} the action runs; past it the doors are
 * refused until the agent answers again. The silence is this process's own measure: it starts
 * when the first question that went unanswered was asked, and any verdict ends it, even one that
 * came too late for its action. It is never counted from the last answer - after a quiet night
 * the first sign-in of the morning would otherwise fall straight into a refusal.
 */
final class ThrottleGate
{
    /** The shortest wait a refusal for silence names, so a grace of zero still tells the client to come back. */
    private const int SHORTEST_SILENCE_REFUSAL_SECONDS = 1;

    /** Configured limits, read from the environment on first use in this worker. */
    private ?ThrottlePolicy $policy = null;

    /** Unix seconds when the oldest question still unanswered was asked, or null while the agent answers. */
    private ?float $silentSince = null;

    /** Whether the silence has outlasted the grace, so the doors refuse; logged once on each turn. */
    private bool $refusing = false;

    /**
     * Whether the layer refuses anything at all in this deployment.
     *
     * @return bool True when the guard is on
     */
    public function enabled(): bool
    {
        return $this->policy()->enabled;
    }

    /**
     * How long a parked action waits for its verdict before the silence of the agent is judged.
     *
     * @return float Seconds to wait
     */
    public function verdictTimeoutSeconds(): float
    {
        return $this->policy()->verdictTimeoutMs / 1000;
    }

    /**
     * Builds the checks one action owes, one per scope that can be keyed.
     *
     * A scope with nothing to key on is dropped rather than keyed on a placeholder: an
     * absent peer name and an absent session are different clients only by accident, so one
     * shared empty identity would let strangers spend each other's budget. In practice the
     * session is always there - the master mints a token for every connection on the 101 -
     * so dropping a scope costs the guard a key, never all of them.
     *
     * @param WebSocketActionSignalDTO $data Action being dispatched
     * @param string $requestKey Key the parked action is held under
     * @param SignalSourceInterface $pageAgent Signal source of the page agent that parks it
     * @return list<ThrottleCheckSignalData> Checks to send, empty when the action cannot be keyed at all
     */
    public function checksFor(
        WebSocketActionSignalDTO $data,
        string $requestKey,
        SignalSourceInterface $pageAgent,
    ): array {
        $agentType = $pageAgent->getType();
        if ($agentType === null) {
            Logger::error(
                "Auth throttle: the page agent has no type to address a verdict to, "
                    . "action={$data->action} runs unthrottled",
            );

            return [];
        }

        $checks = [];
        foreach ($this->identities($data) as $scope => $identity) {
            $checks[] = new ThrottleCheckSignalData(
                scope: $scope,
                identity: $identity,
                action: $data->action,
                acceptKey: $data->acceptKey,
                requestKey: $requestKey,
                agentType: $agentType,
                agentIndex: $pageAgent->getIndex(),
            );
        }

        return $checks;
    }

    /**
     * Answers a consummated block off this worker's own replica of the counters.
     *
     * The fast half of the guard, and the reason a blocked client costs the agent nothing:
     * the block was written to the runtime collection when it was decided, so every worker
     * already carries it and refuses without a signal, a wait, or a database read.
     *
     * @param ThrottleCheckSignalData $check Key to look up
     * @param float $now Current unix seconds
     * @return ?int Seconds until the block lifts, or null when this key is not blocked here
     */
    public function blockedSeconds(ThrottleCheckSignalData $check, float $now): ?int
    {
        $attempts = $this->counters();
        if ($attempts === null) {
            return null;
        }

        $attempt = $attempts[StateAuthAttempt::keyFor($check->scope, $check->identity, $check->action)];
        $blockedUntil = $attempt?->blockedUntil;
        if ($blockedUntil === null || $blockedUntil <= $now) {
            return null;
        }

        return (int)ceil($blockedUntil - $now);
    }

    /**
     * Asks the throttle agent to judge one attempt.
     *
     * Sent from the worker rather than from an agent, which an agent signal has accepted
     * since HIL-567: the dispatcher is not the counters' owner and has no business
     * borrowing its voice.
     *
     * @param ThrottleCheckSignalData $check Attempt to judge
     * @throws InvalidArgumentException When the throttle signal cannot be named or queued
     */
    public function requestVerdict(ThrottleCheckSignalData $check): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK),
            signalData: new AgentSignalData($check),
        );
    }

    /**
     * Tells the counters that a session has proved who it is.
     *
     * Sent from wherever a session is promoted to a user, and silent in three cases that are
     * not failures: the layer is off, the project never activated it, or the connection
     * carries no session to name. A worker cannot clear the counters itself for the same
     * reason it cannot count them - one process owns them.
     *
     * @param string $sessionToken Session token that authenticated, as the connection presented it
     * @throws InvalidArgumentException When the throttle signal cannot be named or queued
     */
    public function reportAuthenticated(string $sessionToken): void
    {
        $identity = ThrottleIdentity::forSession($sessionToken);
        if ($identity === null || !$this->enabled() || $this->counters() === null) {
            return;
        }

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED),
            signalData: new AgentSignalData(new ThrottleSuccessSignalData($identity)),
        );
    }

    /**
     * Reads a verdict as the wait it imposes, if any.
     *
     * A denial names how long it lasts. One that arrived without the number still denies -
     * the agent decided, and dropping its decision because a hint went missing would let a
     * blocked key through - so the client is told the shortest wait the ladder can impose,
     * which is the least it will really have to wait.
     *
     * @param ThrottleVerdictSignalData $verdict Verdict to read
     * @return ?int Seconds the caller must wait, or null when the action may run
     */
    public function refusalSeconds(ThrottleVerdictSignalData $verdict): ?int
    {
        if ($verdict->allowed) {
            return null;
        }

        return $verdict->retryAfter ?? $this->policy()->blockSecondsFor(1);
    }

    /**
     * Records that the agent answered, which ends any silence this process was measuring.
     *
     * @param float $now Current unix seconds
     */
    public function noteAnswered(float $now): void
    {
        if ($this->refusing) {
            $silence = round($now - ($this->silentSince ?? $now), 1);
            Logger::warning("Auth throttle answers again after {$silence} s of silence");
            $this->refusing = false;
        }

        $this->silentSince = null;
    }

    /**
     * Judges one action whose verdict did not arrive in time against the silence of the agent.
     *
     * The first such action opens the silence at the moment it asked, not at the moment it is
     * judged: the agent has been silent since the question, and the sweep that notices comes a
     * tick later.
     *
     * @param float $askedAt Unix seconds when this action asked the agent
     * @param float $now Current unix seconds
     * @param int $graceSeconds Seconds of silence the guarded doors still run through
     * @return ?int Seconds the caller must wait, or null when the silence is still within the grace and the action may run
     */
    public function silenceRefusal(float $askedAt, float $now, int $graceSeconds): ?int
    {
        $this->silentSince ??= $askedAt;
        $silence = $now - $this->silentSince;
        if ($silence < $graceSeconds) {
            return null;
        }

        if (!$this->refusing) {
            Logger::error(
                'Auth throttle silent for ' . round($silence, 1) . " s, past the grace of {$graceSeconds} s: "
                    . 'guarded actions are refused until it answers',
            );
            $this->refusing = true;
        }

        return max(self::SHORTEST_SILENCE_REFUSAL_SECONDS, $graceSeconds);
    }

    /**
     * The identity of each scope this action can be counted against.
     *
     * @param WebSocketActionSignalDTO $data Action being dispatched
     * @return array<string, string> Identity per {@see ThrottleScope}, keyless scopes omitted
     */
    private function identities(WebSocketActionSignalDTO $data): array
    {
        $identities = [];
        if ($data->clientIp !== null) {
            $identities[ThrottleScope::IP] = $data->clientIp;
        }
        if ($data->sessionIdentity !== null) {
            $identities[ThrottleScope::SESSION] = $data->sessionIdentity;
        }

        return $identities;
    }

    /**
     * This worker's replica of the attempt counters, or null when the feature is not activated.
     *
     * Asked through isset() rather than caught: reading an unmounted runtime collection
     * throws, and a project that never declared {@see HilosFeature::AUTH_THROTTLE} has not
     * made a mistake - it simply has no throttle, and every action goes straight through.
     *
     * @return ?AuthAttempts Counters view, or null when not mounted
     */
    private function counters(): ?AuthAttempts
    {
        $rt = Hilos::$rt;
        if ($rt === null || !isset($rt->hilosAuthAttempts)) {
            return null;
        }

        $attempts = $rt->hilosAuthAttempts;

        return $attempts instanceof AuthAttempts ? $attempts : null;
    }

    /**
     * The configured numbers, read once per worker.
     *
     * @return ThrottlePolicy Policy in force
     */
    private function policy(): ThrottlePolicy
    {
        return $this->policy ??= ThrottlePolicy::fromEnv();
    }
}

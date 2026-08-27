<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

/**
 * Worker-side record of when each local agent was last needed.
 *
 * The count lives on the agent's side rather than the master's because a browser may hang on one
 * node while the agent it talks to lives on another: the subscription record then sits in the
 * neighbour's registry, invisible to the host node's master, which would stop an agent that still
 * has a live remote subscriber. The worker sees every frame addressed to its own agent, wherever
 * it came from, so the two facts an idle verdict needs — silence and no subscribers — are both
 * here and nowhere else.
 *
 * Nothing in this class decides anything: it answers {@see self::isIdle()} for a window the caller
 * reads from the registry, and the caller owns the stop.
 */
final class AgentIdleTracker
{
    /** @var array<string, float> Agent id => microtime of the last event that reset its window */
    private array $lastEventAt = [];

    /** @var array<string, array<string, true>> Agent id => set of acceptKeys subscribed to it */
    private array $subscribers = [];

    /**
     * Starts the window for an agent that has just come up.
     *
     * @param string $agentId Agent that started
     * @param float $now Current microtime
     */
    public function noteStarted(string $agentId, float $now): void
    {
        $this->lastEventAt[$agentId] = $now;
        $this->subscribers[$agentId] = [];
    }

    /**
     * Restarts the window: a frame addressed to this agent has arrived.
     *
     * @param string $agentId Agent the frame was addressed to
     * @param float $now Current microtime
     */
    public function noteAddressed(string $agentId, float $now): void
    {
        $this->lastEventAt[$agentId] = $now;
    }

    /**
     * Records one live subscription held against this agent.
     *
     * A blank accept key names no connection, and the payload a subscribe is built from allows
     * one: the key is read with a require-string that refuses a non-string and not an empty one,
     * which is why the subscription record and both drop paths ignore a blank key already. Held
     * here it would be worse than ignored — a claim on the agent that no drop can ever name, so
     * the agent would never reach an idle moment again. Everywhere else a blank key costs a
     * dropped frame; here it costs a process nobody stops, and says nothing while it does. The
     * window still restarts, because the frame itself did address the agent.
     *
     * @param string $agentId Agent the page is subscribed to
     * @param string $acceptKey Connection holding the subscription
     * @param float $now Current microtime
     */
    public function noteSubscriber(string $agentId, string $acceptKey, float $now): void
    {
        $this->lastEventAt[$agentId] = $now;
        if ($acceptKey === '') {
            return;
        }

        $this->subscribers[$agentId][$acceptKey] = true;
    }

    /**
     * Forgets one subscription and restarts the window.
     *
     * The window restarts on the way out, not only on the way in, because the tab that has just
     * closed is exactly when the agent may still be finishing what it was asked for: counting the
     * silence from the last frame would kill an hour-old subscriber's agent in the same second the
     * tab went away.
     *
     * @param string $agentId Agent the page was subscribed to
     * @param string $acceptKey Connection that let go
     * @param float $now Current microtime
     */
    public function dropSubscriber(string $agentId, string $acceptKey, float $now): void
    {
        $this->lastEventAt[$agentId] = $now;
        unset($this->subscribers[$agentId][$acceptKey]);
    }

    /**
     * Drops everything remembered about an agent that is gone.
     *
     * @param string $agentId Agent that stopped
     */
    public function forget(string $agentId): void
    {
        unset($this->lastEventAt[$agentId], $this->subscribers[$agentId]);
    }

    /**
     * Whether the agent has been silent longer than its window with nobody subscribed to it.
     *
     * An agent this tracker has never heard of is not idle: the answer would otherwise be "yes"
     * for one that started before the tracker did, and the first tick after a deploy would take
     * down every agent on the worker.
     *
     * @param string $agentId Agent being judged
     * @param int $timeoutSec Declared idle window in seconds
     * @param float $now Current microtime
     * @return bool True when nobody holds the agent and the window has expired
     */
    public function isIdle(string $agentId, int $timeoutSec, float $now): bool
    {
        if (($this->subscribers[$agentId] ?? []) !== []) {
            return false;
        }

        $lastEventAt = $this->lastEventAt[$agentId] ?? null;

        return $lastEventAt !== null && $now - $lastEventAt > $timeoutSec;
    }
}

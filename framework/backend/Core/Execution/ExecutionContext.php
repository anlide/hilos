<?php

declare(strict_types=1);

namespace Hilos\Core\Execution;

use Hilos\Core\Execution\Exception\FramePopOrderException;

/**
 * Process-global record of the worker callback in flight: which agent is running
 * and which WebSocket accept key it serves.
 *
 * Two scoping idioms share one current frame. The worker event loop sets the
 * top-level scope imperatively with setCurrentAgentId() / setCurrentAcceptKey();
 * nested callbacks use run() / push() / pop(), which restore the previous frame
 * on return. Truth-source registries and runtime view contexts read
 * currentAgentId() and currentAcceptKey() to decide who may write or which
 * connection is "self".
 */
final class ExecutionContext
{
    /** @var list<FrameStackEntry> */
    private static array $frames = [];

    private static ?ExecutionFrame $ambientFrame = null;

    private static int $nextFrameToken = 1;

    /**
     * Runs a callback with a pushed execution frame and restores the previous frame afterwards.
     *
     * @template T
     * @param ExecutionFrame $frame Frame active during the callback
     * @param callable(): T $callback Work to execute in this frame
     * @return T Callback result
     * @throws FramePopOrderException When the callback leaves the frame stack imbalanced
     */
    public static function run(ExecutionFrame $frame, callable $callback): mixed
    {
        $token = self::push($frame);
        try {
            return $callback();
        } finally {
            self::pop($token);
        }
    }

    /**
     * Runs a callback with the given accept key stamped on a pushed frame, then restores the previous frame.
     *
     * Lets an agent acting on behalf of a connection (the backup initiator) stamp
     * that connection as the origin of the DB/RT writes it performs inside the
     * callback, so the initiator's own table deltas apply at once. The current
     * agent id is preserved; outside such a scope an agent write carries no accept
     * key and its origin stays null (nobody's own).
     *
     * @template T
     * @param ?string $acceptKey Accept key to scope writes to, or null for an unattended scope
     * @param callable(): T $callback Work to execute in this scope
     * @return T Callback result
     * @throws FramePopOrderException When the callback leaves the frame stack imbalanced
     */
    public static function withAcceptKey(?string $acceptKey, callable $callback): mixed
    {
        return self::run(self::currentFrame()->withAcceptKey($acceptKey), $callback);
    }

    /**
     * Pushes an execution frame and returns a token that must be popped in a finally block.
     *
     * @param ExecutionFrame $frame Frame to make current
     * @return int Stack token for {@see self::pop()}
     */
    public static function push(ExecutionFrame $frame): int
    {
        $token = self::$nextFrameToken++;
        self::$frames[] = new FrameStackEntry($token, $frame);

        return $token;
    }

    /**
     * Pops the current execution frame.
     *
     * @param int $token Token returned by {@see self::push()}
     * @throws FramePopOrderException When popping a non-current frame
     */
    public static function pop(int $token): void
    {
        $lastKey = array_key_last(self::$frames);
        if ($lastKey === null || self::$frames[$lastKey]->token !== $token) {
            throw new FramePopOrderException('Execution context frame pop order mismatch.');
        }

        array_pop(self::$frames);
    }

    /**
     * Sets the agent on the current frame for the worker event loop's top-level scope.
     *
     * @param ?string $agentId Agent to scope to, or null outside an agent callback
     */
    public static function setCurrentAgentId(?string $agentId): void
    {
        self::replaceCurrentFrame(self::currentFrame()->withAgentId($agentId));
    }

    /**
     * Sets the WebSocket accept key on the current frame for the top-level scope.
     *
     * @param ?string $acceptKey Accept key to scope to, or null outside a WebSocket-scoped signal
     */
    public static function setCurrentAcceptKey(?string $acceptKey): void
    {
        self::replaceCurrentFrame(self::currentFrame()->withAcceptKey($acceptKey));
    }

    /**
     * Clears the current agent only when it matches the stopped/unregistered agent.
     *
     * @param string $agentId Agent id that is being stopped or unregistered
     */
    public static function clearCurrentAgentIdIf(string $agentId): void
    {
        if (self::currentAgentId() === $agentId) {
            self::setCurrentAgentId(null);
        }
    }

    /**
     * @return ?string Agent whose callback is executing, or null when outside one
     */
    public static function currentAgentId(): ?string
    {
        return self::currentFrame()->agentId;
    }

    /**
     * @return ?string WebSocket accept key for the current callback, or null when none
     */
    public static function currentAcceptKey(): ?string
    {
        return self::currentFrame()->acceptKey;
    }

    /**
     * Reset all execution metadata.
     */
    public static function clear(): void
    {
        self::$frames = [];
        self::$ambientFrame = null;
        self::$nextFrameToken = 1;
    }

    /**
     * Resolves the active frame, falling back to the ambient frame and then an empty one.
     *
     * @return ExecutionFrame Top-of-stack frame, the ambient frame, or a fresh empty frame
     */
    private static function currentFrame(): ExecutionFrame
    {
        if (self::$frames !== []) {
            return self::$frames[array_key_last(self::$frames)]->frame;
        }

        return self::$ambientFrame ?? new ExecutionFrame();
    }

    /**
     * Replaces the top-of-stack frame, or the ambient frame when the stack is empty.
     *
     * @param ExecutionFrame $frame Frame to store as current
     */
    private static function replaceCurrentFrame(ExecutionFrame $frame): void
    {
        if (self::$frames !== []) {
            $lastKey = array_key_last(self::$frames);
            self::$frames[$lastKey] = new FrameStackEntry(self::$frames[$lastKey]->token, $frame);
            return;
        }

        self::$ambientFrame = $frame;
    }
}

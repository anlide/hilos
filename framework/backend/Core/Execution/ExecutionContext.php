<?php

declare(strict_types=1);

namespace Hilos\Core\Execution;

use LogicException;

/**
 * Stack-scoped metadata for the currently executing worker callback.
 */
final class ExecutionContext
{
    /** @var list<array{token: int, frame: ExecutionFrame}> */
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
     * Pushes an execution frame and returns a token that must be popped in a finally block.
     *
     * @param ExecutionFrame $frame Frame to make current
     * @return int Stack token for {@see self::pop()}
     */
    public static function push(ExecutionFrame $frame): int
    {
        $token = self::$nextFrameToken++;
        self::$frames[] = [
            'token' => $token,
            'frame' => $frame,
        ];

        return $token;
    }

    /**
     * Pops the current execution frame.
     *
     * @param int $token Token returned by {@see self::push()}
     * @throws LogicException When popping a non-current frame
     */
    public static function pop(int $token): void
    {
        $lastKey = array_key_last(self::$frames);
        if ($lastKey === null || self::$frames[$lastKey]['token'] !== $token) {
            throw new LogicException('Execution context frame pop order mismatch.');
        }

        array_pop(self::$frames);
    }

    /**
     * Compatibility setter for code that still scopes only the current agent.
     */
    public static function setCurrentAgentId(?string $agentId): void
    {
        self::replaceCurrentFrame(self::currentFrame()->withAgentId($agentId));
    }

    /**
     * Compatibility setter for code that still scopes only the current WebSocket connection.
     */
    public static function setCurrentAcceptKey(?string $acceptKey): void
    {
        self::replaceCurrentFrame(self::currentFrame()->withAcceptKey($acceptKey));
    }

    /**
     * Clears the current agent only when it matches the stopped/unregistered agent.
     */
    public static function clearCurrentAgentIdIf(string $agentId): void
    {
        if (self::currentAgentId() === $agentId) {
            self::setCurrentAgentId(null);
        }
    }

    public static function currentAgentId(): ?string
    {
        return self::currentFrame()->agentId;
    }

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

    private static function currentFrame(): ExecutionFrame
    {
        if (self::$frames !== []) {
            return self::$frames[array_key_last(self::$frames)]['frame'];
        }

        return self::$ambientFrame ?? new ExecutionFrame();
    }

    private static function replaceCurrentFrame(ExecutionFrame $frame): void
    {
        if (self::$frames !== []) {
            self::$frames[array_key_last(self::$frames)]['frame'] = $frame;
            return;
        }

        self::$ambientFrame = $frame;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

/** The attribution stored for one journaled operation. */
final readonly class JournalReceiptData
{
    private function __construct(
        public ?int $actorUserId,
        public ?int $subjectUserId,
        public ?int $sessionId,
        public string $channel,
        public string $action,
        public ?string $agent,
        public ?string $source,
    ) {
    }

    /**
     * @param ?int $actorUserId Person at the keyboard
     * @param ?int $subjectUserId Person impersonated by the actor
     * @param ?int $sessionId Browser session identifier
     * @param string $action Accepted server action name
     * @param ?string $agent Current agent identifier
     * @return self Web action attribution
     */
    public static function web(
        ?int $actorUserId,
        ?int $subjectUserId,
        ?int $sessionId,
        string $action,
        ?string $agent,
    ): self {
        return new self(
            $actorUserId,
            $subjectUserId,
            $sessionId,
            'web',
            $action,
            $agent,
            $sessionId === null ? null : 'session #' . $sessionId,
        );
    }

    /**
     * @param string $direction Migration direction, up or down
     * @param int $index Migration number
     * @param string $file Exact SQL file path
     * @return self Migration attribution
     */
    public static function migration(string $direction, int $index, string $file): self
    {
        return new self(null, null, null, 'migration', "migration.{$direction}.{$index}", null, basename($file));
    }
}

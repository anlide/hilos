<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

/** The attribution stored for one journaled operation. */
final readonly class JournalReceiptData
{
    public const int MAX_SOURCE_LENGTH = 512;

    /** Channel stored for a migration run. */
    public const string CHANNEL_MIGRATION = 'migration';

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
     * @param string $command Command name as it goes on the wire
     * @param ?string $agent Agent that took the command
     * @param list<string> $address Address field values captured in the receipt source
     * @return self CLI command attribution
     */
    public static function cli(string $command, ?string $agent, array $address = []): self
    {
        $source = $address === [] ? $command : $command . ' ' . implode(' ', $address);
        if (mb_strlen($source) > self::MAX_SOURCE_LENGTH) {
            $source = mb_substr($source, 0, self::MAX_SOURCE_LENGTH);
        }

        return new self(null, null, null, 'cli', $command, $agent, $source);
    }

    /**
     * @param string $direction Migration direction, up or down
     * @param int $index Migration number
     * @param string $file Exact SQL file path
     * @return self Migration attribution
     */
    public static function migration(string $direction, int $index, string $file): self
    {
        return new self(null, null, null, self::CHANNEL_MIGRATION, "migration.{$direction}.{$index}", null, basename($file));
    }

    /**
     * @param string $action Autonomous operation name
     * @param string $initiatorAgentId Agent that initiated the operation
     * @return self Autonomous agent attribution
     */
    public static function agent(string $action, string $initiatorAgentId): self
    {
        return new self(null, null, null, 'agent', $action, $initiatorAgentId, $initiatorAgentId);
    }

    /**
     * @param string $ruleName Scheduled rule name
     * @param ?string $cronExpression Schedule captured when this attempt started, or null for legacy signals
     * @param string $initiatorAgentId Agent that started the attempt
     * @return self Cron attempt attribution without job parameters or personal identifiers
     */
    public static function cron(string $ruleName, ?string $cronExpression, string $initiatorAgentId): self
    {
        return new self(
            null,
            null,
            null,
            'cron',
            $ruleName,
            $initiatorAgentId,
            $cronExpression === null ? $ruleName : $ruleName . ' (' . $cronExpression . ')',
        );
    }
}

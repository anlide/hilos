<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent;

use Demo\WebSocketTest\Constants\AgentType;
use Demo\WebSocketTest\Database\Idea;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Idea\TruthSourceRegistry;
use Hilos\Logging\Logger\Logger;

/**
 * UserAgent - Regular agent for user management
 *
 * Runs in regular worker process.
 */
class UserAgent extends AbstractAgent
{
    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::SESSION;

    /** @var string User ID */
    private string $userId;

    /**
     * UserAgent constructor
     *
     * @param string $userId User identifier
     * @param SignalRouter $signalRouter Signal router instance
     */
    public function __construct(string $userId, SignalRouter $signalRouter)
    {
        parent::__construct($signalRouter);
        $this->userId = $userId;
    }

    /**
     * Get agent type
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return self::AGENT_TYPE;
    }

    /**
     * Get agent index
     *
     * User agent index is the user ID
     *
     * @return ?string Agent index (user ID)
     */
    public function getIndex(): ?string
    {
        return $this->userId;
    }

    /**
     * Get user ID
     *
     * @return string User ID
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Called when agent is started
     */
    public function onStart(): void
    {
        Logger::logAgentStart($this->getId(), $this->getType());

        // Register this agent as truth source for user_settings table
        // Get all UserSettings keys for this user
        $userId = (int)$this->userId;
        if ($userId > 0 && Idea::$storage !== null) {
            $userSettings = Idea::$storage->userSettings;
            
            // Get all UserSettings for this user from ObjectCollection
            // For now, collect keys from already loaded objects
            // TODO: Load all UserSettings for this user if needed
            $keys = [];
            $userSettings->backupIndex();
            try {
                foreach ($userSettings as $key => $setting) {
                    if ($setting->userId === $userId) {
                        $keys[] = $key;
                    }
                }
            } finally {
                $userSettings->restoreIndex();
            }

            // Register as truth source if we have keys
            if (!empty($keys)) {
                TruthSourceRegistry::register('user_setting', $keys, $this->getId());
            }
        }
    }

    /**
     * Called when agent is stopped
     */
    public function onStop(): void
    {
        // Unregister as truth source
        TruthSourceRegistry::unregister('user_setting', $this->getId());
        
        Logger::logAgentStop($this->getId(), $this->getType());
    }

    /**
     * Agent-specific tick implementation
     */
    public function onTick(): void
    {
        // TODO: Add user-specific logic here
    }
}

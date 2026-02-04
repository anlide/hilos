<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Router\SignalSourceInterface;

/**
 * PageAgentInterface - Interface for Agent methods accessible from Page
 *
 * Defines the contract for Agent methods that Page handlers can use.
 * This is a subset of AgentInterface to limit Page access to Agent.
 *
 * Agents must implement this interface to be used with PageFactory.
 */
interface PageAgentInterface
{
    /**
     * Get agent unique identifier
     *
     * @return string Agent ID
     */
    public function getId(): string;

    /**
     * Get agent signal source for broadcasting
     *
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface;
}

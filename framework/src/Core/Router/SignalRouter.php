<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Utils\DTO\SignalDTO;
use Hilos\Utils\DTO\SignalDataDTO;
use Hilos\Logging\Logger\Logger;
use Hilos\Exception\Router\PageSubscriptionNotFoundException;
use Hilos\Exception\Router\PageSubscriptionMismatchException;
use Hilos\Exception\Router\GroupSubscriptionNotFoundException;

/**
 * SignalRouter - Base class for routing signals from sources to agents
 *
 * Routes signals based on configuration stored in protected $config array.
 * Can be used directly with empty config, or extended to provide custom routing rules.
 * Manages signal queueing.
 */
class SignalRouter
{
    /**
     * Signal routing configuration
     *
     * Can be overridden in child classes or set via constructor.
     * Format: [
     *     'source_name' => [
     *         'signal_type' => ['agentType' => 'type', 'agentIndex' => 'index'|null]
     *     ]
     * ]
     *
     * @var array
     */
    protected array $config;

    /** @var SignalDTO[] Queued signals to dispatch */
    private array $queuedSignals = [];

    /**
     * User page subscriptions storage
     * Format: [clientId => ['page' => string, 'params' => array]]
     *
     * @var array
     */
    private array $subscriptions = [];

    /**
     * User group subscriptions storage
     * Format: [clientId => [groupName => params, ...]]
     *
     * @var array
     */
    private array $subscriptionGroups = [];

    /**
     * Constructor
     *
     * Initializes signal router with empty configuration.
     * Child classes should override constructor and call parent::__construct(),
     * then set $this->config with custom routing rules.
     */
    public function __construct()
    {
        $this->config = [];
    }

    /**
     * Route signal to target
     *
     * Returns routing information for signal, or null if no route found.
     * Supports routing by page, group, or direct routing.
     *
     * @param SignalSourceInterface $signalSource Signal source identifier
     * @param string $signalType Signal type (e.g., 'frame', 'handshake', 'close', 'subscribe', 'action')
     * @param SignalDataDTO $dto Signal data DTO
     * @return ?array Routing info ['agentType' => string, 'agentIndex' => ?string] or null if no route
     */
    public function route(SignalSourceInterface $signalSource, string $signalType, SignalDataDTO $dto): ?array
    {
        $source = $signalSource->getSource();

        // Support both old config structure (direct) and new structure (with 'signals' key)
        $signalsConfig = $this->config['signals'] ?? $this->config;

        // Check if source exists in config
        if (!isset($signalsConfig[$source])) {
            return null;
        }

        $sourceConfig = $signalsConfig[$source];

        // Check if signal type exists in source config
        if (!isset($sourceConfig[$signalType])) {
            return null;
        }

        $routeConfig = $sourceConfig[$signalType];

        // Get routeBy method (default to 'direct' for backward compatibility)
        $routeBy = $routeConfig['routeBy'] ?? 'direct';

        // Route by page subscription
        if ($routeBy === 'page') {
            return $this->routeByPage($dto, $routeConfig, $signalType);
        }

        // Route by group subscription
        if ($routeBy === 'group') {
            return $this->routeByGroup($dto, $routeConfig, $signalType);
        }

        // Direct routing (backward compatibility)
        if (!isset($routeConfig['agentType'])) {
            return null;
        }

        return [
            'agentType' => $routeConfig['agentType'],
            'agentIndex' => $routeConfig['agentIndex'] ?? null,
        ];
    }

    /**
     * Route signal by page subscription
     *
     * @param SignalDataDTO $dto Signal DTO
     * @param array $routeConfig Route configuration
     * @param string $signalType Signal type
     * @return ?array Routing info or null
     */
    private function routeByPage(SignalDataDTO $dto, array $routeConfig, string $signalType): ?array
    {
        $dtoData = $dto->toArray();
        $clientId = $dtoData['clientId'] ?? '';

        if ($clientId === '') {
            return null;
        }

        // For subscribe/unsubscribe signals, get page from DTO
        if (in_array($signalType, ['subscribe', 'unsubscribe', 'update_subscription'], true)) {
            $page = $dtoData['page'] ?? null;
            if ($page === null) {
                return null;
            }

            // Get route from pages config
            if (!isset($this->config['pages'][$page])) {
                return null;
            }

            $pageConfig = $this->config['pages'][$page];
            return [
                'agentType' => $pageConfig['agentType'] ?? '',
                'agentIndex' => $pageConfig['agentIndex'] ?? null,
            ];
        }

        // For action signals, get page from user subscription or DTO
        if ($signalType === 'action') {
            $page = $dtoData['page'] ?? $this->getUserPage($clientId);
            if ($page === null) {
                return null;
            }

            // Get route from pages config
            if (!isset($this->config['pages'][$page])) {
                return null;
            }

            $pageConfig = $this->config['pages'][$page];
            return [
                'agentType' => $pageConfig['agentType'] ?? '',
                'agentIndex' => $pageConfig['agentIndex'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Route signal by group subscription
     *
     * @param SignalDataDTO $dto Signal DTO
     * @param array $routeConfig Route configuration
     * @param string $signalType Signal type
     * @return ?array Routing info or null (returns first matching group route)
     */
    private function routeByGroup(SignalDataDTO $dto, array $routeConfig, string $signalType): ?array
    {
        $dtoData = $dto->toArray();
        $clientId = $dtoData['clientId'] ?? '';

        if ($clientId === '') {
            return null;
        }

        // For subscribe/unsubscribe signals, get groups from DTO
        if (in_array($signalType, ['subscribe', 'unsubscribe', 'update_subscription'], true)) {
            $groups = $dtoData['groups'] ?? [];
            if (empty($groups)) {
                return null;
            }

            // Get route from first group in config
            foreach ($groups as $group) {
                if (isset($this->config['groups'][$group])) {
                    $groupConfig = $this->config['groups'][$group];
                    return [
                        'agentType' => $groupConfig['agentType'] ?? '',
                        'agentIndex' => $groupConfig['agentIndex'] ?? null,
                    ];
                }
            }

            return null;
        }

        // For action signals, get group from DTO or user subscriptions
        if ($signalType === 'action') {
            $group = $dtoData['group'] ?? null;
            if ($group === null) {
                // Try to get first group from user subscriptions
                $userGroups = $this->getUserGroups($clientId);
                if (empty($userGroups)) {
                    return null;
                }
                // Get first group name from array keys
                $group = array_key_first($userGroups);
            }

            // Get route from groups config
            if (!isset($this->config['groups'][$group])) {
                return null;
            }

            $groupConfig = $this->config['groups'][$group];
            return [
                'agentType' => $groupConfig['agentType'] ?? '',
                'agentIndex' => $groupConfig['agentIndex'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Queue signal for dispatch
     *
     * Called by servers to queue signals for later dispatch.
     * Signals are accumulated during tick and dispatched at the end of loop iteration.
     * Routing is performed during dispatch, not during queue.
     *
     * @param SignalSourceInterface $signalSource Signal source identifier
     * @param string $signalType Signal type (e.g., 'frame', 'handshake', 'close')
     * @param string $signalName Signal name
     * @param SignalDataDTO $data Signal data DTO
     */
    public function queueSignal(SignalSourceInterface $signalSource, string $signalType, string $signalName, SignalDataDTO $data): void
    {
        // Create SignalDTO and queue it
        $signal = new SignalDTO($signalSource, $signalType, $signalName, $data);
        $this->queuedSignals[] = $signal;

        // Log signal queued
        $source = $signalSource->getSource();
        Logger::info("Signal queued: {$source}/{$signalType}/{$signalName}");
    }

    /**
     * Get next queued signal
     *
     * Returns one signal from queue and removes it.
     * Returns null if queue is empty.
     * Used by DaemonManager to dispatch signals one by one.
     *
     * @return ?SignalDTO Queued signal DTO or null
     */
    public function getNextQueuedSignal(): ?SignalDTO
    {
        if (empty($this->queuedSignals)) {
            return null;
        }

        return array_shift($this->queuedSignals);
    }

    /**
     * Subscribe user to page
     *
     * @param string $clientId Client identifier
     * @param string $page Page identifier
     * @param array $params Additional parameters
     */
    public function subscribeToPage(string $clientId, string $page, array $params = []): void
    {
        $this->subscriptions[$clientId] = [
            'page' => $page,
            'params' => $params,
        ];
    }

    /**
     * Update user page subscription
     *
     * Updates parameters of existing page subscription.
     * Throws exception if current page doesn't match the page being updated.
     *
     * @param string $clientId Client identifier
     * @param string $page Page identifier
     * @param array $params Additional parameters to update
     * @throws PageSubscriptionNotFoundException If no subscription found
     * @throws PageSubscriptionMismatchException If current page doesn't match the page being updated
     */
    public function updatePageSubscription(string $clientId, string $page, array $params): void
    {
        if (!isset($this->subscriptions[$clientId])) {
            throw new PageSubscriptionNotFoundException($clientId);
        }

        $currentPage = $this->subscriptions[$clientId]['page'] ?? null;
        if ($currentPage !== $page) {
            throw new PageSubscriptionMismatchException($currentPage ?? '', $page);
        }

        // Merge new params with existing params
        $existingParams = $this->subscriptions[$clientId]['params'] ?? [];
        $this->subscriptions[$clientId]['params'] = array_merge($existingParams, $params);
    }

    /**
     * Subscribe user to group
     *
     * @param string $clientId Client identifier
     * @param string $group Group identifier
     * @param array $params Additional parameters
     */
    public function subscribeToGroup(string $clientId, string $group, array $params = []): void
    {
        if (!isset($this->subscriptionGroups[$clientId])) {
            $this->subscriptionGroups[$clientId] = [];
        }

        $this->subscriptionGroups[$clientId][$group] = $params;
    }

    /**
     * Update user group subscription
     *
     * Updates parameters of existing group subscription.
     * Throws exception if group is not currently subscribed.
     *
     * @param string $clientId Client identifier
     * @param string $group Group identifier
     * @param array $params Additional parameters to update
     * @throws GroupSubscriptionNotFoundException If group is not currently subscribed
     */
    public function updateGroupSubscription(string $clientId, string $group, array $params): void
    {
        if (!isset($this->subscriptionGroups[$clientId]) || !isset($this->subscriptionGroups[$clientId][$group])) {
            throw new GroupSubscriptionNotFoundException($clientId, $group);
        }

        // Merge new params with existing params
        $existingParams = $this->subscriptionGroups[$clientId][$group];
        $this->subscriptionGroups[$clientId][$group] = array_merge($existingParams, $params);
    }


    /**
     * Unsubscribe user from page
     *
     * @param string $clientId Client identifier
     */
    public function unsubscribeFromPage(string $clientId): void
    {
        unset($this->subscriptions[$clientId]);
    }

    /**
     * Unsubscribe user from group
     *
     * @param string $clientId Client identifier
     * @param string $group Group identifier
     */
    public function unsubscribeFromGroup(string $clientId, string $group): void
    {
        if (isset($this->subscriptionGroups[$clientId])) {
            unset($this->subscriptionGroups[$clientId][$group]);
            
            // Clean up empty client entry
            if (empty($this->subscriptionGroups[$clientId])) {
                unset($this->subscriptionGroups[$clientId]);
            }
        }
    }


    /**
     * Unsubscribe user from all subscriptions
     *
     * @param string $clientId Client identifier
     */
    public function unsubscribeFromAll(string $clientId): void
    {
        unset($this->subscriptions[$clientId]);
        unset($this->subscriptionGroups[$clientId]);
    }

    /**
     * Get user's page subscription
     *
     * @param string $clientId Client identifier
     * @return ?string Page identifier or null if not subscribed
     */
    public function getUserPage(string $clientId): ?string
    {
        return $this->subscriptions[$clientId]['page'] ?? null;
    }

    /**
     * Get user's page subscription params
     *
     * @param string $clientId Client identifier
     * @return array Page subscription parameters
     */
    public function getUserPageParams(string $clientId): array
    {
        return $this->subscriptions[$clientId]['params'] ?? [];
    }

    /**
     * Get user's group subscriptions
     *
     * @param string $clientId Client identifier
     * @return array Array of group names (keys) with their params (values)
     */
    public function getUserGroups(string $clientId): array
    {
        return $this->subscriptionGroups[$clientId] ?? [];
    }

    /**
     * Get user's group subscription params
     *
     * @param string $clientId Client identifier
     * @param string $group Group identifier
     * @return array Group subscription parameters or empty array if not subscribed
     */
    public function getUserGroupParams(string $clientId, string $group): array
    {
        return $this->subscriptionGroups[$clientId][$group] ?? [];
    }

    /**
     * Check if user has page subscription
     *
     * @param string $clientId Client identifier
     * @return bool True if user is subscribed to a page
     */
    public function hasPageSubscription(string $clientId): bool
    {
        return isset($this->subscriptions[$clientId]['page']) && $this->subscriptions[$clientId]['page'] !== null;
    }

    /**
     * Check if user has group subscription
     *
     * @param string $clientId Client identifier
     * @param string $group Group identifier
     * @return bool True if user is subscribed to the group
     */
    public function hasGroupSubscription(string $clientId, string $group): bool
    {
        return isset($this->subscriptionGroups[$clientId][$group]);
    }

    /**
     * Get destinations for signal
     *
     * Returns array of destinations for signal based on routing configuration.
     * Each destination is an array with 'type', 'agentType' and 'agentIndex'.
     * Can return multiple destinations for signals that need to be delivered to multiple agents.
     *
     * @param SignalDTO $signal Signal DTO
     * @return array Array of destinations [['type' => string, 'agentType' => string, 'agentIndex' => ?string], ...]
     */
    public function getDestinations(SignalDTO $signal): array
    {
        // Get route using existing route method
        $route = $this->route($signal->signalSource, $signal->signalType, $signal->data);

        if ($route === null) {
            return [];
        }

        // Add destination type (for now only 'agent' is supported)
        $destination = array_merge(['type' => 'agent'], $route);

        // For now, return single destination
        // Can be extended in child classes to return multiple destinations
        return [$destination];
    }
}


<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\API\Router\Exception\GroupSubscriptionNotFoundException;
use Hilos\API\Router\Exception\PageSubscriptionMismatchException;
use Hilos\API\Router\Exception\PageSubscriptionNotFoundException;
use Hilos\Constants\SignalPayloadConstants;

/**
 * SubscriptionRegistry - Worker-local store of client page and group subscriptions.
 *
 * Owns the subscription state that SignalRouter delegates to. In a worker this is
 * the local mirror used by browser fan-out; in the daemon it is the global routing
 * registry used for broadcasts.
 */
final class SubscriptionRegistry
{
    /** @var array<string, PageSubscription> Page subscriptions keyed by accept key */
    private array $pages = [];

    /** @var array<string, array<string, array<string, mixed>>> Group params keyed by accept key, then group */
    private array $groups = [];

    /**
     * @param string $acceptKey Client accept key
     * @param string $page Page identifier
     * @param array<string, mixed> $params Route params
     */
    public function subscribeToPage(string $acceptKey, string $page, array $params): void
    {
        if ($acceptKey === '') {
            return;
        }

        $this->pages[$acceptKey] = new PageSubscription($page, $params);
    }

    /**
     * Merges new params into an existing page subscription.
     *
     * @param string $acceptKey Client accept key
     * @param string $page Page identifier being updated
     * @param array<string, mixed> $params Params to merge on top of the current ones
     * @throws PageSubscriptionNotFoundException If no subscription exists for the accept key
     * @throws PageSubscriptionMismatchException If the stored page differs from the page being updated
     */
    public function updatePageSubscription(string $acceptKey, string $page, array $params): void
    {
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->pages[$acceptKey])) {
            throw new PageSubscriptionNotFoundException($acceptKey);
        }

        $subscription = $this->pages[$acceptKey];
        if ($subscription->page !== $page) {
            throw new PageSubscriptionMismatchException($subscription->page, $page);
        }

        $this->pages[$acceptKey] = $subscription->withMergedParams($params);
    }

    /**
     * Removes a page subscription when the stored page matches.
     *
     * @param string $acceptKey Client accept key
     * @param string $page Page identifier that must match the stored page
     */
    public function unsubscribeFromPage(string $acceptKey, string $page): void
    {
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->pages[$acceptKey])) {
            return;
        }
        if ($this->pages[$acceptKey]->page !== $page) {
            return;
        }

        unset($this->pages[$acceptKey]);
    }

    /**
     * @param string $acceptKey Client accept key
     * @param string $group Group identifier
     * @param array<string, mixed> $params Route params
     */
    public function subscribeToGroup(string $acceptKey, string $group, array $params): void
    {
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->groups[$acceptKey])) {
            $this->groups[$acceptKey] = [];
        }

        $this->groups[$acceptKey][$group] = $params;
    }

    /**
     * Merges new params into an existing group subscription.
     *
     * @param string $acceptKey Client accept key
     * @param string $group Group identifier
     * @param array<string, mixed> $params Params to merge on top of the current ones
     * @throws GroupSubscriptionNotFoundException If the group is not currently subscribed
     */
    public function updateGroupSubscription(string $acceptKey, string $group, array $params): void
    {
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->groups[$acceptKey][$group])) {
            throw new GroupSubscriptionNotFoundException($acceptKey, $group);
        }

        $this->groups[$acceptKey][$group] = array_merge($this->groups[$acceptKey][$group], $params);
    }

    /**
     * Removes a group subscription, dropping the client entry when it becomes empty.
     *
     * @param string $acceptKey Client accept key
     * @param string $group Group identifier
     */
    public function unsubscribeFromGroup(string $acceptKey, string $group): void
    {
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->groups[$acceptKey])) {
            return;
        }

        unset($this->groups[$acceptKey][$group]);
        if ($this->groups[$acceptKey] === []) {
            unset($this->groups[$acceptKey]);
        }
    }

    /**
     * Removes all page and group subscriptions for a client.
     *
     * @param string $acceptKey Client accept key
     */
    public function unsubscribeFromAll(string $acceptKey): void
    {
        unset($this->pages[$acceptKey]);
        unset($this->groups[$acceptKey]);
    }

    /**
     * Accept keys currently subscribed to a page, optionally filtered by one route param.
     *
     * @param string $page Page identifier to match subscriptions against
     * @param ?string $paramKey Route param key to filter on, or null for no filter
     * @param ?string $paramValue Expected route param value (compared as string), or null for no filter
     * @return list<string> Accept keys subscribed to the page
     */
    public function getAcceptKeysForPage(string $page, ?string $paramKey = null, ?string $paramValue = null): array
    {
        $keys = [];
        foreach ($this->pages as $acceptKey => $subscription) {
            if ($subscription->page !== $page) {
                continue;
            }
            if ($paramKey === null || $paramValue === null) {
                $keys[] = $acceptKey;
                continue;
            }
            if ($subscription->paramEquals($paramKey, $paramValue)) {
                $keys[] = $acceptKey;
            }
        }

        return $keys;
    }

    /**
     * Page subscriptions mirrored here, in browser-config shape.
     *
     * @return array<string, array{page: string, params: array<string, string>}> Subscriptions keyed by accept key
     */
    public function getPageSubscriptions(): array
    {
        $subscriptions = [];
        foreach ($this->pages as $acceptKey => $subscription) {
            if ($acceptKey === '' || $subscription->page === '') {
                continue;
            }

            $subscriptions[$acceptKey] = [
                SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY => $subscription->page,
                SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY => $subscription->stringParams(),
            ];
        }

        return $subscriptions;
    }

    /**
     * @return list<string> Unique accept keys from page and group subscriptions
     */
    public function getSubscribedAcceptKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->pages),
            array_keys($this->groups),
        )));
    }

    /**
     * @return list<string> Accept keys holding any page subscription
     */
    public function pageAcceptKeys(): array
    {
        return array_keys($this->pages);
    }

    /**
     * @param string $group Group identifier
     * @return list<string> Accept keys subscribed to the group
     */
    public function acceptKeysForGroup(string $group): array
    {
        $keys = [];
        foreach ($this->groups as $acceptKey => $groups) {
            if (isset($groups[$group])) {
                $keys[] = $acceptKey;
            }
        }

        return $keys;
    }
}

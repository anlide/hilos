<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Core\Daemon\PageHandoffIdentity;

/**
 * A client's page subscription on the socket master or its serving worker.
 * The socket record keeps the address for release. The worker may retain a
 * confirmed mirror for the start that follows an in-place restart on its IPC.
 *
 * Params arrive from the subscribe signal DTO and survive a worker -> daemon
 * IPC roundtrip, so their values are not statically guaranteed to be strings;
 * the narrowing helpers below own that concern.
 */
final class PageSubscription
{
    /** Worker-local evidence of the identity admitted for this exact delivery. */
    public ?PageHandoffIdentity $admittedIdentity = null;

    /** Whether the original worker kept this mirror through a stop and still owes it a confirmed start. */
    public bool $retainedForRestart = false;

    /** Whether this exact delivery is waiting for its connection source to confirm the admitted identity again. */
    public bool $awaitingIdentityConfirmation = false;

    /** Original record of an ordinary subscription whose serving agent was bound. */
    private ?self $registration = null;

    /** First record of this registration at its serving address. */
    private ?self $servingRegistration = null;

    /**
     * @param string $page Page identifier the client is subscribed to
     * @param array<string, mixed> $params Route params from the subscribe signal
     * @param ?string $agentType Agent type serving this subscription; null until the master binds one
     * @param ?string $agentIndex Instance index of the serving agent, null when it is served unindexed
     * @param ?string $agentNode Node hosting the serving replica, or null for placement routing
     * @param ?string $deliveryId Socket authority for an identity-routed subscription
     * @param ?string $handoffServingNode Historical peer retained for release by the socket master
     */
    public function __construct(
        public readonly string $page,
        public readonly array $params = [],
        public readonly ?string $agentType = null,
        public readonly ?string $agentIndex = null,
        public readonly ?string $agentNode = null,
        public readonly ?string $deliveryId = null,
        public ?string $handoffServingNode = null,
    ) {
    }

    /**
     * Returns a copy of this subscription with $params merged over the current params.
     *
     * The bound address survives the merge: an update is a param change inside the same
     * page, and re-resolving the address there would hand a live subscription to a
     * different instance mid-flight ({@see SubscriptionRegistry::bindPageAgent}).
     *
     * @param array<string, mixed> $params Params to merge on top of the current ones
     * @return self Subscription with merged params
     */
    public function withMergedParams(array $params): self
    {
        $updated = new self(
            $this->page,
            array_merge($this->params, $params),
            $this->agentType,
            $this->agentIndex,
            $this->agentNode,
            $this->deliveryId,
            $this->handoffServingNode,
        );
        $updated->admittedIdentity = $this->admittedIdentity;
        $updated->retainedForRestart = $this->retainedForRestart;
        $updated->awaitingIdentityConfirmation = $this->awaitingIdentityConfirmation;

        return $updated;
    }

    /**
     * Returns a copy of this subscription served by the named agent.
     *
     * @param string $agentType Agent type serving this subscription
     * @param ?string $agentIndex Instance index, or null to serve the subscription unindexed
     * @param ?string $agentNode Node hosting a node-scoped replica
     * @return self Subscription carrying the address
     */
    public function withPageAgent(string $agentType, ?string $agentIndex, ?string $agentNode = null): self
    {
        $bound = new self($this->page, $this->params, $agentType, $agentIndex, $agentNode, $this->deliveryId, $this->handoffServingNode);
        if ($this->deliveryId === null) {
            $bound->registration = $this->registration ?? $this;
            if ($this->agentType === null
                || ($this->agentType === $agentType && $this->agentIndex === $agentIndex && $this->agentNode === $agentNode)
            ) {
                $bound->servingRegistration = $this->servingRegistration ?? $this;
            }
        }

        return $bound;
    }

    /**
     * @param self $other Record to compare
     * @return bool Whether both records come from the same registration, allowing ordinary agent binds
     */
    public function isSameRegistration(self $other): bool
    {
        return ($this->registration ?? $this) === ($other->registration ?? $other);
    }

    /**
     * @param self $other Current record for the connection
     * @return bool Whether the original serving address was held continuously through ordinary binds
     */
    public function isSameServingRegistration(self $other): bool
    {
        return ($this->servingRegistration ?? $this) === ($other->servingRegistration ?? $other);
    }

    /**
     * @param string $acceptKey Connection whose current identity is needed
     * @return bool Whether this record may serve a page answer now
     */
    public function admitsPageAnswer(string $acceptKey): bool
    {
        return !$this->retainedForRestart && !$this->awaitingIdentityConfirmation
            && ($this->admittedIdentity === null || $this->admittedIdentity == PageHandoffIdentity::current($acceptKey));
    }

    /**
     * Whether a route param equals the expected value, compared as a string.
     *
     * Mirrors the legacy (string) comparison: an absent param compares as the
     * empty string, and an array-valued param never matches.
     *
     * @param string $key Route param key
     * @param string $value Expected value
     * @return bool Whether the param matches
     */
    public function paramEquals(string $key, string $value): bool
    {
        $actual = $this->params[$key] ?? null;
        if (is_array($actual)) {
            return false;
        }

        return (string) $actual === $value;
    }

    /**
     * Route params narrowed to scalar string values for browser config reference resolution.
     *
     * @return array<string, string> String-keyed string params
     */
    public function stringParams(): array
    {
        $result = [];
        foreach ($this->params as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_string($value) || is_int($value)) {
                $result[$key] = (string) $value;
            }
        }

        return $result;
    }
}

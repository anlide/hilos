<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * PageSubscription - Worker-local record of a client's page subscription.
 *
 * Value object backing the SignalRouter page subscription mirror: the page a
 * client is subscribed to plus its route params. Replaces a loosely typed
 * [pageKey => string, paramsKey => array] map so reads no longer have to
 * re-check is_string()/is_array() on every access.
 *
 * Params arrive from the subscribe signal DTO and survive a worker -> daemon
 * IPC roundtrip, so their values are not statically guaranteed to be strings;
 * the narrowing helpers below own that concern.
 */
final class PageSubscription
{
    /**
     * @param string $page Page identifier the client is subscribed to
     * @param array<string, mixed> $params Route params from the subscribe signal
     */
    public function __construct(
        public readonly string $page,
        public readonly array $params = [],
    ) {
    }

    /**
     * Returns a copy of this subscription with $params merged over the current params.
     *
     * @param array<string, mixed> $params Params to merge on top of the current ones
     * @return self Subscription with merged params
     */
    public function withMergedParams(array $params): self
    {
        return new self($this->page, array_merge($this->params, $params));
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

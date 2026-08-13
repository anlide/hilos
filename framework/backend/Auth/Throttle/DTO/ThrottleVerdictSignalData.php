<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Page\Exception\ActionRateLimitedException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ThrottleVerdictSignalData - the throttle agent's answer about one deferred action (HIL-420).
 *
 * The slow path's inbound half, delivered to the page agent that is holding the action.
 * {@see requestKey} is the whole address: the agent looks the action up in its pool by that
 * key and resumes the dispatch it stopped, either into onAction or into the refusal
 * {@see ActionRateLimitedException} describes.
 *
 * {@see agentIndex} rides along so an indexed page agent can be addressed by declaring it as
 * the route's index field; a project whose page agent is a singleton declares no index field
 * and the value is simply unread.
 */
final class ThrottleVerdictSignalData extends BaseDTO implements SignalDataInterface
{
    public const string requestKey = 'requestKey';
    public const string allowed = 'allowed';
    public const string retryAfter = 'retryAfter';
    public const string agentIndex = 'agentIndex';

    /**
     * @param string $requestKey Key of the deferred action this verdict answers
     * @param bool $allowed Whether the action may run
     * @param ?int $retryAfter Seconds until the block lifts; null when the action is allowed
     * @param ?string $agentIndex Index of the page agent holding the action, or null when it is a singleton
     */
    public function __construct(
        public readonly string $requestKey,
        public readonly bool $allowed,
        public readonly ?int $retryAfter = null,
        public readonly ?string $agentIndex = null,
    ) {
    }

    /**
     * @return array<string, mixed> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::requestKey => $this->requestKey,
            self::allowed => $this->allowed,
            self::retryAfter => $this->retryAfter,
            self::agentIndex => $this->agentIndex,
        ];
    }

    /**
     * Reads the request key without a fallback: a verdict that names no action cannot be
     * delivered, and an empty key would silently resume nothing.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $retryAfter = $data[self::retryAfter] ?? null;
        $agentIndex = $data[self::agentIndex] ?? null;

        return new static(
            requestKey: (string)$data[self::requestKey],
            allowed: (bool)($data[self::allowed] ?? false),
            retryAfter: $retryAfter === null ? null : (int)$retryAfter,
            agentIndex: $agentIndex === null ? null : (string)$agentIndex,
        );
    }
}

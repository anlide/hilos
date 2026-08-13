<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle\DTO;

use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;

/**
 * ThrottleCheckSignalData - a worker asking the throttle agent to judge one attempt (HIL-420).
 *
 * The slow path's outbound half. It carries the throttle key the agent counts against, and
 * the return address the verdict needs: which pending action this is ({@see requestKey}) and
 * which page agent is holding it ({@see agentType}, {@see agentIndex}). The action itself
 * stays in the asking worker's pool - a deferred action is a live ExecutionContext and a
 * parsed payload, neither of which crosses a process boundary.
 *
 * The connection's accept key rides along so the agent can name it in a log line, and
 * because the wrapper reads it off the payload for delivery accounting.
 */
final class ThrottleCheckSignalData extends BaseDTO implements SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    public const string scope = 'scope';
    public const string identity = 'identity';
    public const string action = 'action';
    public const string acceptKey = 'acceptKey';
    public const string requestKey = 'requestKey';
    public const string agentType = 'agentType';
    public const string agentIndex = 'agentIndex';

    /**
     * @param string $scope Throttle scope, one of {@see ThrottleScope}
     * @param string $identity Client IP or sha256 of the session token
     * @param string $action Throttled action name
     * @param string $acceptKey Accept key of the connection that sent the action
     * @param string $requestKey Key of the deferred action awaiting this verdict
     * @param string $agentType Type of the page agent holding the deferred action
     * @param ?string $agentIndex Index of that agent, or null when it is a singleton
     */
    public function __construct(
        public readonly string $scope,
        public readonly string $identity,
        public readonly string $action,
        public readonly string $acceptKey,
        public readonly string $requestKey,
        public readonly string $agentType,
        public readonly ?string $agentIndex = null,
    ) {
    }

    /**
     * @return ?string Accept key of the connection the attempt came from
     */
    public function getAcceptKey(): ?string
    {
        return $this->acceptKey;
    }

    /**
     * @return array<string, ?string> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::scope => $this->scope,
            self::identity => $this->identity,
            self::action => $this->action,
            self::acceptKey => $this->acceptKey,
            self::requestKey => $this->requestKey,
            self::agentType => $this->agentType,
            self::agentIndex => $this->agentIndex,
        ];
    }

    /**
     * Reads every addressing field without a fallback.
     *
     * A check missing its key or its return address is not a check for an empty identity,
     * it is a frame this DTO cannot describe - and a verdict built on invented values would
     * be counted against the wrong key and delivered to nobody.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $agentIndex = $data[self::agentIndex] ?? null;

        return new static(
            scope: (string)$data[self::scope],
            identity: (string)$data[self::identity],
            action: (string)$data[self::action],
            acceptKey: (string)$data[self::acceptKey],
            requestKey: (string)$data[self::requestKey],
            agentType: (string)$data[self::agentType],
            agentIndex: $agentIndex === null ? null : (string)$agentIndex,
        );
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * OAuthBindSessionSignalData - the OAuth login success payload (OAuthAgent → ChatAgent).
 *
 * How mechanism B's success arm crosses the agent boundary: the framework-owned
 * OAuth agent resolves/creates the account off the master, then signals the
 * session-owning ChatAgent to authenticate the live session to that user, which
 * rides HIL-161's existing session/currentUser fan-out. The agent stays the sole
 * owner of {@see \Demo\Chat\Agents\ChatAgent::authenticateSession()}; the OAuth
 * agent never touches sessions directly.
 *
 * Routing is by signal name (OAUTH_BIND_SESSION → ChatAgent) via the agent's
 * AGENT_SIGNALS declaration.
 */
final class OAuthBindSessionSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates an OAuth bind-session signal payload.
     *
     * @param string $sessionToken Session token to authenticate to the resolved user
     * @param int $userId Resolved durable user id to bind the session to
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly int $userId,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, int|string> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'sessionToken' => $this->sessionToken,
            'userId' => $this->userId,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessionToken: (string)($data['sessionToken'] ?? ''),
            userId: (int)($data['userId'] ?? 0),
        );
    }
}

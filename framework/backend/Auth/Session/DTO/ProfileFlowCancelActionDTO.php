<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileFlowCancelActionDTO - payload for discarding a profile window's flow (HIL-1182).
 *
 * The Discard of the "Discard?" question a window asks when it is closed with a flow behind it.
 * It names WHICH window by its operation key ({@see StepUpOperationKey::CHANGE_EMAIL},
 * CHANGE_PASSWORD, DELETE_ACCOUNT or ADD_SIGN_IN_METHOD), because a session can have several at once; the session itself is the
 * acting connection's, so a window of another browser is simply not in reach.
 *
 * Owned by {@see AbstractSessionsLibraryAgent} through AGENT_ACTIONS: the flow it ends is the
 * session's record, and the windows of every tab of that session close on it.
 */
final class ProfileFlowCancelActionDTO extends ActionPayloadDTO
{
    public const string operation = 'operation';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $operation Operation key of the window being discarded
     */
    public function __construct(
        public readonly string $operation,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_PROFILE_FLOW_CANCEL;
    }

    /**
     * Create from array, unwrapping the optional FIELD_DATA envelope.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Cancel DTO instance
     * @throws InvalidFormatException When the payload names no window
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            operation: self::requireString($inner, self::operation),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Payload with the window's operation key
     */
    public function toArray(): array
    {
        return [
            self::operation => $this->operation,
        ];
    }
}

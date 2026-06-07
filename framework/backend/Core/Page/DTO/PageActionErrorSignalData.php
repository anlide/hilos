<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;

/**
 * PageActionErrorSignalData - Error signal data for page action failures.
 *
 * Sent to the initiating WebSocket connection when AbstractPage::onAction()
 * throws and the page does not override onActionException().
 */
class PageActionErrorSignalData extends BaseDTO implements SignalDataInterface, WebSocketEnvelopeAware
{
    /**
     * @param string $action Action name that failed
     * @param string $reason Human-readable error message
     */
    public function __construct(
        public readonly string $action,
        public readonly string $reason,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reason' => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            action: (string)($data['action'] ?? ''),
            reason: (string)($data['reason'] ?? ''),
        );
    }

    public function getEnvelopeOutcome(): ?string
    {
        return 'fail';
    }

    public function getEnvelopeTime(): ?int
    {
        return null;
    }
}

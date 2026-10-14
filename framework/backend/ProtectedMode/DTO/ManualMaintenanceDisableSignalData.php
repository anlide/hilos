<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Maintenance page → index agent: leave the manual window for this server-resolved browser. */
final class ManualMaintenanceDisableSignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param string $acceptKey Acting browser connection to check and answer
     * @param string $sessionHash Server-resolved session token hash, never returned to the browser
     * @param ?string $requestId Tracked submit correlation, or null for an untracked request
     * @param string $action Browser action to answer
     * @param string $replySignal Page-owned signal carrying the outcome
     * @param ?string $successMessage Sentence to speak only after confirmed exit
     * @throws InvalidFormatException When either half of the browser identity is empty
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $sessionHash,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly string $replySignal,
        public readonly ?string $successMessage,
    ) {
        if ($acceptKey === '' || $sessionHash === '') {
            throw new InvalidFormatException('A browser exit needs both its accept key and session hash');
        }
    }

    /** @return array<string, mixed> Internal handover payload */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'sessionHash' => $this->sessionHash,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'replySignal' => $this->replySignal,
            'successMessage' => $this->successMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data Internal handover payload
     * @return static Browser exit request with its reply address
     * @throws InvalidFormatException When a required field is missing, mistyped or the browser identity is empty
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, 'acceptKey'),
            sessionHash: self::requireString($data, 'sessionHash'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            replySignal: self::requireString($data, 'replySignal'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}

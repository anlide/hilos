<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Maintenance page → index agent: mint a code for this server-resolved browser. */
final class ManualMaintenancePassSignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param string $acceptKey Acting browser connection to check and answer
     * @param string $sessionHash Server-resolved session token hash, never returned to the browser
     * @param ?string $requestId Tracked submit correlation, required for code delivery
     * @param string $action Browser action to answer
     * @param string $replySignal Page-owned signal carrying the outcome
     * @param ?string $successMessage Sentence to speak only after confirmed minting
     * @throws InvalidFormatException When the browser identity or tracked correlation is empty
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $sessionHash,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly string $replySignal,
        public readonly ?string $successMessage,
    ) {
        if ($acceptKey === '' || $sessionHash === '' || $requestId === null || $requestId === '') {
            throw new InvalidFormatException('Code issuance needs a browser identity and a tracked request');
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
     * @return static Browser code request with its reply address
     * @throws InvalidFormatException When a required field is missing, mistyped or the identity or correlation is empty
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

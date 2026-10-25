<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Maintenance page → backup agent: one restore control for this server-resolved browser. */
final class MaintenanceRestoreControlSignalData extends BaseDTO implements HandoverAskInterface
{
    /** Restore control that mints one code. */
    public const string CONTROL_PASS = 'pass';

    /** Restore control that opens the run. */
    public const string CONTROL_OPEN = 'open';

    /** Restore control that closes the run. */
    public const string CONTROL_CLOSE = 'close';

    /**
     * @param string $control Which restore control: pass, open or close
     * @param string $acceptKey Acting browser connection to check and answer
     * @param string $sessionHash Server-resolved session token hash, never returned to the browser
     * @param ?string $requestId Tracked submit correlation, required for this handover
     * @param string $action Browser action that matches the control
     * @param string $replySignal Page-owned signal carrying the outcome
     * @param ?string $successMessage Sentence to speak only after the control is confirmed
     * @throws InvalidFormatException When identity, correlation or reply is empty, or control and action disagree
     */
    public function __construct(
        public readonly string $control,
        public readonly string $acceptKey,
        public readonly string $sessionHash,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly string $replySignal,
        public readonly ?string $successMessage,
    ) {
        if ($acceptKey === '' || $sessionHash === '' || $requestId === null || $requestId === '') {
            throw new InvalidFormatException('Restore control needs a browser identity and a tracked request');
        }
        if ($replySignal === '') {
            throw new InvalidFormatException('Restore control needs a reply signal');
        }
        if ($action !== match ($control) {
            self::CONTROL_PASS => HilosSignalConstants::MAINTENANCE_RESTORE_PASS,
            self::CONTROL_OPEN => HilosSignalConstants::MAINTENANCE_RESTORE_OPEN,
            self::CONTROL_CLOSE => HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE,
            default => throw new InvalidFormatException('Restore control is pass, open or close'),
        }) {
            throw new InvalidFormatException('The browser action does not match this restore control');
        }
    }

    /** @return array<string, mixed> Internal handover payload */
    public function toArray(): array
    {
        return [
            'control' => $this->control,
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
     * @return static Restore control with its reply address
     * @throws InvalidFormatException When a required field is missing, mistyped, empty, or control and action disagree
     */
    public static function fromArray(array $data): static
    {
        return new static(
            control: self::requireString($data, 'control'),
            acceptKey: self::requireString($data, 'acceptKey'),
            sessionHash: self::requireString($data, 'sessionHash'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            replySignal: self::requireString($data, 'replySignal'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}

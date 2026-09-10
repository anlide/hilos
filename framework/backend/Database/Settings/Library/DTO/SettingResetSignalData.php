<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * A settings gatekeeper → the settings library: put this key back to its catalog default (HIL-946).
 *
 * What {@see HilosSignalConstants::HILOS_SETTING_RESET} carries. Both gestures that undo an
 * override arrive under it - the reset of the general settings screen and the reset of one field
 * of a communications channel - because undoing an override is one write however the screen
 * spells it.
 *
 * Whether the key is cataloged at all is judged where the row is: an orphan has no default to go
 * back to, and the settings table says so in the sentence the administrator reads. Asking that
 * question on the screen would answer it in one worker and act on it in another.
 *
 * The return address travels in the frame for the reason it does on every ask of this library:
 * one writer, three gatekeepers, and no name pinned to a screen.
 */
final class SettingResetSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $replySignal Agent-signal name the library reports back under
     * @param string $acceptKey Initiating connection accept key to answer
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     * @param string $key Setting key to return to its catalog default
     */
    public function __construct(
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
        public readonly string $key,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'replySignal' => $this->replySignal,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'successMessage' => $this->successMessage,
            'key' => $this->key,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no key or nobody to answer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
            key: self::requireString($data, 'key'),
        );
    }
}

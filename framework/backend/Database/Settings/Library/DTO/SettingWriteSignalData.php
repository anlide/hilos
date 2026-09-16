<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;

/**
 * A settings gatekeeper → the settings library: put this value under this key (HIL-946).
 *
 * What {@see HilosSignalConstants::HILOS_SETTING_WRITE} carries. One name for every gesture that
 * ends in a value standing under a cataloged key: adding an override, editing one, switching a
 * communications channel on, filling a field of it. They are one write and not four, because the
 * settings table answers all four with the same idempotent {@see SettingsLibraryAgent} call - the
 * four names they have on the browser wire are four buttons, not four kinds of writing.
 *
 * The key is judged where it is written and nowhere else: whether the catalog knows it, and
 * whether a row already stands under it, are questions for the process that owns the collection,
 * at the moment it writes. What the screen keeps is what it can judge without reading a row - an
 * empty key, an unknown channel, a value its descriptor refuses.
 *
 * The return address travels in the frame rather than being pinned to the name, because this one
 * writer has three gatekeepers. A fixed pair of names would make the library know each screen by
 * name, and the next screen that writes a setting would edit its body to be let in.
 */
final class SettingWriteSignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param string $replySignal Agent-signal name the library reports back under
     * @param string $acceptKey Initiating connection accept key to answer
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     * @param string $key Setting key the value stands under
     * @param mixed $value Value to store as the override (never null: a row without a value does not exist)
     */
    public function __construct(
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
        public readonly string $key,
        public readonly mixed $value,
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
            'value' => $this->value,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no key, no value, or nobody to answer
     */
    public static function fromArray(array $data): static
    {
        // An absent or null value would be a reset in disguise, and the reset has a name of its
        // own; the settings table refuses null as a write, so a frame carrying it is malformed.
        $value = $data['value'] ?? null;
        if ($value === null) {
            throw new InvalidFormatException('Payload carries no value under key value');
        }

        return new static(
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
            key: self::requireString($data, 'key'),
            value: $value,
        );
    }
}

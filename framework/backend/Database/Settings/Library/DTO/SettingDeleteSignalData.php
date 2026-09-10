<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * The settings screen → the settings library: drop this orphan row (HIL-946).
 *
 * What {@see HilosSignalConstants::HILOS_SETTING_DELETE} carries. Only an orphan is ever deleted -
 * a cataloged key is reset instead, and the settings table refuses the two being confused - so
 * this ask exists apart from the reset rather than as a flag inside it.
 *
 * Orphanhood is judged where the row is written: a key drops out of the catalog when a project
 * is rebuilt, and a screen that checked first would be checking a catalog its worker read at
 * startup. The refusal comes back as the sentence the administrator reads.
 *
 * The success carries no sentence and never will: the row leaves the table in front of the
 * administrator, and a phrase saying so would repeat what the screen already showed.
 */
final class SettingDeleteSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $replySignal Agent-signal name the library reports back under
     * @param string $acceptKey Initiating connection accept key to answer
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     * @param string $key Orphan setting key to remove
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

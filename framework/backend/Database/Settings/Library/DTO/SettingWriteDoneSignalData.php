<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;

/**
 * The settings library → the gatekeeper that forwarded the ask: it is written, or it is refused
 * (HIL-946).
 *
 * The way back for all four asks of {@see SettingsLibraryAgent}, and one shape for the four
 * because there is one thing to say: the submit that was deferred is now answered, with a
 * sentence or with a reason. The screen deferred its own ack when it handed the work over, so
 * without this frame the administrator's button would spin until the client's timeout.
 *
 * The action name comes back with it because a screen owns several, and the ack is addressed to
 * the one that was pressed. The sentence comes back with it because the library never composed
 * it: the phrase names a channel's label, a field's caption or which of two gestures the press
 * was, none of which the owner of the collection has any business knowing.
 *
 * The reply name this frame travels under is not fixed here. Each screen declares its own, and
 * the library sends under whichever the ask named - which is why one writer can serve three
 * gatekeepers without knowing one of them by name.
 */
final class SettingWriteDoneSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Initiating connection accept key to answer
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     * @param ?string $error Why the write was refused, or null when it went through
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
        public readonly ?string $error,
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
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'successMessage' => $this->successMessage,
            'error' => $this->error,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names nobody to answer or no action to answer on
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
            error: self::optionalString($data, 'error'),
        );
    }
}

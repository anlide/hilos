<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ProtectedModeStubCopy;
use Hilos\Socket\WebSocket\DTO\HandshakeWelcomeSignalData;

/**
 * ProtectedModeStateSignalData - daemon master -> browser payload of the PROTECTED_MODE frame.
 *
 * Told to connections that were already open when the mode turned on or off: a fresh connection
 * learns the same state from {@see HandshakeWelcomeSignalData} instead. The copy fields carry the
 * words this side resolved through {@see ProtectedModeStubCopy}, and which of them a frame carries
 * says who the frame is for: {@see title} and {@see message} word the maintenance surface for a
 * recipient the mode locks out, {@see bannerMessage} words the banner for one it lets in. No frame
 * carries both kinds, and a lift frame carries none of them — nothing renders them, the frontend
 * reloads on it.
 */
final class ProtectedModeStateSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: whether protected mode holds this node right now. */
    public const string active = 'active';

    /** Payload key: the operation the freeze protects, or null when it lifted. */
    public const string operation = 'operation';

    /** Payload key: heading of the maintenance surface. */
    public const string title = 'title';

    /** Payload key: sentence shown under the heading. */
    public const string message = 'message';

    /** Payload key: sentence of the banner an admitted recipient carries over the application. */
    public const string bannerMessage = 'bannerMessage';

    /** Payload key: whether the surface may offer a code field right now. */
    public const string acceptsPass = 'acceptsPass';

    /** Payload key: whether at least one pass is standing, so the field has something to take. */
    public const string passIssued = 'passIssued';

    /**
     * @param bool $active Whether protected mode holds this node right now
     * @param ?string $operation Operation the freeze protects; null on the lift frame
     * @param ?string $title Heading of the maintenance surface; null on the lift frame and when
     *                       the stub registry names none
     * @param ?string $message Sentence under the heading; null on the same two occasions
     * @param bool $acceptsPass Whether the mode is in its verification window and takes a code;
     *                          false on entry and on the lift, where the surface has nothing to
     *                          offer. It rides beside `active` rather than replacing it because
     *                          the verification window keeps the stub up for everyone without a
     *                          pass - a frame saying "not active" would take the surface down
     *                          for exactly the people it must stay up for.
     * @param bool $passIssued Whether at least one pass is standing on the freeze row right now;
     *                         false while the window is open but nothing has been minted, where the
     *                         surface says so instead of offering a field that can take nothing. It
     *                         rides beside `acceptsPass` rather than narrowing it because that flag
     *                         also decides when the client calls the mode over and when it drops a
     *                         presented pass - narrowed, it would reload an admitted verifier out of
     *                         the window the instant nobody held a code
     * @param ?string $bannerMessage Sentence of the banner an admitted recipient carries while the
     *                               mode holds; null on every frame addressed to somebody the mode
     *                               locks out, and on the lift frame. It comes last so that the
     *                               positional calls written before it stay pointed at the same
     *                               parameters they always were
     */
    public function __construct(
        public readonly bool $active,
        public readonly ?string $operation = null,
        public readonly ?string $title = null,
        public readonly ?string $message = null,
        public readonly bool $acceptsPass = false,
        public readonly bool $passIssued = false,
        public readonly ?string $bannerMessage = null,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::active => $this->active,
            self::operation => $this->operation,
            self::title => $this->title,
            self::message => $this->message,
            self::acceptsPass => $this->acceptsPass,
            self::passIssued => $this->passIssued,
            self::bannerMessage => $this->bannerMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $operation = $data[self::operation] ?? null;
        $title = $data[self::title] ?? null;
        $message = $data[self::message] ?? null;
        $bannerMessage = $data[self::bannerMessage] ?? null;

        return new static(
            active: (bool)($data[self::active] ?? false),
            operation: $operation === null ? null : (string)$operation,
            title: $title === null ? null : (string)$title,
            message: $message === null ? null : (string)$message,
            acceptsPass: (bool)($data[self::acceptsPass] ?? false),
            passIssued: (bool)($data[self::passIssued] ?? false),
            bannerMessage: $bannerMessage === null ? null : (string)$bannerMessage,
        );
    }
}

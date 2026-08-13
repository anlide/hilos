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
 * words of the maintenance surface, resolved on this side through {@see ProtectedModeStubCopy};
 * a lift frame leaves all three null, because nothing renders them — the frontend reloads on it.
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

    /** Payload key: whether the surface may offer a code field right now. */
    public const string acceptsPass = 'acceptsPass';

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
     */
    public function __construct(
        public readonly bool $active,
        public readonly ?string $operation = null,
        public readonly ?string $title = null,
        public readonly ?string $message = null,
        public readonly bool $acceptsPass = false,
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

        return new static(
            active: (bool)($data[self::active] ?? false),
            operation: $operation === null ? null : (string)$operation,
            title: $title === null ? null : (string)$title,
            message: $message === null ? null : (string)$message,
            acceptsPass: (bool)($data[self::acceptsPass] ?? false),
        );
    }
}

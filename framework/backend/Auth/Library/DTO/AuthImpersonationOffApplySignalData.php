<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Settings library to session holder: impersonation is switched off, return every takeover before answering the administrator (HIL-1308).
 *
 * The holder sends each session with an administrator behind it back to that administrator, the
 * way the command line's Stop does, and tells them why on the session's toast.
 */
final class AuthImpersonationOffApplySignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param string $replySignal Agent signal name for the original answer
     * @param string $acceptKey Initiating connection
     * @param ?string $requestId Tracked browser submit, if any
     * @param string $action Browser action to answer
     * @param ?string $successMessage Success sentence from the gatekeeper
     */
    public function __construct(
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
    }

    /** @return array<string, mixed> Transport payload */
    public function toArray(): array
    {
        return [
            'replySignal' => $this->replySignal,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'successMessage' => $this->successMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Restored ask
     * @throws InvalidFormatException When a required field is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Settings library to session holder: the second factor is turned off, apply it before answering the administrator (HIL-1303).
 *
 * The holder forgets every trusted browser when the setting asks it to, and lets every sign-in
 * still waiting on a code or on enrolment go back to its start.
 */
final class AuthSecondFactorDisableApplySignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param bool $forgetTrusts Whether every trusted browser is forgotten with it
     * @param string $replySignal Agent signal name for the original answer
     * @param string $acceptKey Initiating connection
     * @param ?string $requestId Tracked browser submit, if any
     * @param string $action Browser action to answer
     * @param ?string $successMessage Success sentence from the gatekeeper
     */
    public function __construct(
        public readonly bool $forgetTrusts,
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
            'forgetTrusts' => $this->forgetTrusts,
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
            forgetTrusts: self::requireBool($data, 'forgetTrusts'),
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}

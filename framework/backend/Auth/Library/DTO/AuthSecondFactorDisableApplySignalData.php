<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Settings library to session holder: the second factor is turned off, apply it before answering the administrator (HIL-1303).
 *
 * The holder lets every sign-in still waiting on a code or on enrolment go back to its start, and,
 * when the switch-off recorded a trust restriction, asks the owners to forget the trusts under that
 * restriction before it answers (HIL-1909).
 */
final class AuthSecondFactorDisableApplySignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param ?int $restrictionId Restriction (S, 0) this switch-off recorded; null keeps the trusted browsers
     * @param string $replySignal Agent signal name for the original answer
     * @param string $acceptKey Initiating connection
     * @param ?string $requestId Tracked browser submit, if any
     * @param string $action Browser action to answer
     * @param ?string $successMessage Success sentence from the gatekeeper
     * @throws InvalidFormatException When the restriction id is not positive
     */
    public function __construct(
        public readonly ?int $restrictionId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($restrictionId !== null && $restrictionId <= 0) {
            throw new InvalidFormatException('Trust restriction id must be positive');
        }
    }

    /** @return array<string, mixed> Transport payload */
    public function toArray(): array
    {
        return [
            'restrictionId' => $this->restrictionId,
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
     * @throws InvalidFormatException When a required field is absent or mistyped, or the restriction id is not positive
     */
    public static function fromArray(array $data): static
    {
        return new static(
            restrictionId: self::optionalInt($data, 'restrictionId'),
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}

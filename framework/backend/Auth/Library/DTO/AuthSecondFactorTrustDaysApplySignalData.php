<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Settings library to session holder: bring the trust restriction the save recorded home before answering the administrator.
 *
 * The holder writes nothing itself: it waits until every owner of a trusted browser has been asked to
 * cut its trusts to the restriction, and answers once by that outcome (HIL-1908).
 */
final class AuthSecondFactorTrustDaysApplySignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param int $restrictionId Trust restriction the holder brings home to the owners
     * @param string $replySignal Agent signal name for the original answer
     * @param string $acceptKey Initiating connection
     * @param ?string $requestId Tracked browser submit, if any
     * @param string $action Browser action to answer
     * @param ?string $successMessage Success sentence from the gatekeeper
     * @throws InvalidFormatException When the restriction id is not positive
     */
    public function __construct(
        public readonly int $restrictionId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($restrictionId <= 0) {
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
            restrictionId: self::requireInt($data, 'restrictionId'),
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Settings library to session holder: apply a shorter trust term before answering the administrator. */
final class AuthSecondFactorTrustDaysApplySignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param int $trustDays New trust term in days
     * @param int $savedAt Epoch seconds of the successful setting write
     * @param string $replySignal Agent signal name for the original answer
     * @param string $acceptKey Initiating connection
     * @param ?string $requestId Tracked browser submit, if any
     * @param string $action Browser action to answer
     * @param ?string $successMessage Success sentence from the gatekeeper
     */
    public function __construct(
        public readonly int $trustDays,
        public readonly int $savedAt,
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
            'trustDays' => $this->trustDays,
            'savedAt' => $this->savedAt,
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
            trustDays: self::requireInt($data, 'trustDays'),
            savedAt: self::requireInt($data, 'savedAt'),
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}

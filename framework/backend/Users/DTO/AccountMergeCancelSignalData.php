<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** User card → sessions holder: request cancellation and answer only its acceptance. */
final class AccountMergeCancelSignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param int $operationId Existing merge operation
     * @param string $replySignal Page's handover answer name
     * @param string $acceptKey Initiating connection
     * @param ?string $requestId Tracked browser request, or null when untracked
     * @param string $action Browser action awaiting the answer
     * @param ?string $successMessage Acceptance text, normally null
     */
    public function __construct(
        public readonly int $operationId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
    }

    /** @return array<string, mixed> Operation address and waiting submit */
    public function toArray(): array
    {
        return [
            'operationId' => $this->operationId,
            'replySignal' => $this->replySignal,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'successMessage' => $this->successMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Cancellation request
     * @throws InvalidFormatException When the operation or waiting submit cannot be read
     */
    public static function fromArray(array $data): static
    {
        $operationId = self::requireInt($data, 'operationId');
        if ($operationId <= 0) {
            throw new InvalidFormatException('Merge operation id must be positive');
        }

        return new static(
            operationId: $operationId,
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}

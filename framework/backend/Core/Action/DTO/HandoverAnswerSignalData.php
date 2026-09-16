<?php

declare(strict_types=1);

namespace Hilos\Core\Action\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Router\SignalDataInterface;

/**
 * A writer → the gatekeeper that forwarded the ask: it is written, or here is why not (HIL-1001).
 *
 * The one way back for every ask of the handover form, because there is one thing to say: the
 * submit that was deferred is now answered, with a sentence or with a reason. The gatekeeper
 * deferred its own ack when it handed the work over, so without this frame the button would spin
 * until the client's timeout.
 *
 * Everything but the refusal is the ask's own, echoed: whom to answer, which press, which action
 * the ack is addressed to and the sentence to speak - the writer never composed any of them. The
 * refusal is the writer's, in three fields: the reason the person reads, and the class and text of
 * a failure the reason stands in for. Whether those two reach the client is decided by the
 * gatekeeper that reads this frame ({@see HandoverGatekeeperTrait}), because only it knows who
 * asked.
 *
 * The name this frame travels under is not fixed here. Each gatekeeper declares its own and puts
 * it on the ask, and the writer sends under whichever the ask named - which is how one writer
 * serves several gatekeepers without knowing one of them by name.
 */
final class HandoverAnswerSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Initiating connection accept key to answer
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     * @param ?string $error Why the write was refused, or null when it went through
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * Answers an ask with its outcome: the writer states only why, the rest comes off the ask.
     *
     * @param HandoverAskInterface $ask The ask being answered
     * @param ?ActionRefusal $refusal Why the write was refused, or null when it went through
     * @return self Answer addressed to whoever the ask names
     */
    public static function to(HandoverAskInterface $ask, ?ActionRefusal $refusal): self
    {
        return new self(
            acceptKey: $ask->acceptKey,
            requestId: $ask->requestId,
            action: $ask->action,
            successMessage: $ask->successMessage,
            error: $refusal?->reason,
            errorType: $refusal?->errorType,
            errorDetail: $refusal?->errorDetail,
        );
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
            'errorType' => $this->errorType,
            'errorDetail' => $this->errorDetail,
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
            errorType: self::optionalString($data, 'errorType'),
            errorDetail: self::optionalString($data, 'errorDetail'),
        );
    }
}

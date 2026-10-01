<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\HilosProfileFlow;

/**
 * Users library → session holder: a profile window of this session reached a step, or its flow is
 * over (HIL-1182).
 *
 * The library runs the step - it checks and spends the codes - and the holder
 * ({@see AbstractSessionsLibraryAgent}) owns the record of the session: it writes the step, tells
 * every tab of the session, and answers the tab that submitted LAST, so that tab moves on the same
 * frame its neighbours move on.
 *
 * A null {@see step} is the flow ending - the address moved, the password was saved - and takes
 * the window's record away. Every other step names the account's address its proof stands on and
 * the moment the code of that proof dies; a frame naming a step without them is refused off the
 * wire, because the step after it could never be let through.
 */
final class ProfileFlowStepSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $sessionToken Session cookie token of the browser that submitted
     * @param int $userId The person the flow is for
     * @param string $operation Operation key of the window
     * @param ?string $step Step reached ({@see HilosProfileFlow}'s STEP_* constants), or null when the flow is over
     * @param ?string $address The account's address the proof stands on, or null when the flow is over
     * @param ?string $target New address of an email change, on its last step alone
     * @param ?int $expiresAt Epoch milliseconds the code of the proof dies at, or null when the flow is over
     * @param string $initiatorAcceptKey Accept key of the connection that submitted
     * @param ?string $requestId Request id of the action to answer, or null when it was untracked
     * @param ?string $action Action name to answer, or null when nothing is waiting on an answer
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly int $userId,
        public readonly string $operation,
        public readonly ?string $step,
        public readonly ?string $address,
        public readonly ?string $target,
        public readonly ?int $expiresAt,
        public readonly string $initiatorAcceptKey,
        public readonly ?string $requestId = null,
        public readonly ?string $action = null,
    ) {
    }

    /**
     * @return array<string, mixed> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            'sessionToken' => $this->sessionToken,
            'userId' => $this->userId,
            'operation' => $this->operation,
            'step' => $this->step,
            'address' => $this->address,
            'target' => $this->target,
            'expiresAt' => $this->expiresAt,
            'initiatorAcceptKey' => $this->initiatorAcceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no session, person, window or connection,
     *     or names a step without the address and the moment its proof stands on
     */
    public static function fromArray(array $data): static
    {
        $step = self::optionalString($data, 'step');
        $address = self::optionalString($data, 'address');
        $expiresAt = self::optionalInt($data, 'expiresAt');
        if ($step !== null && ($address === null || $expiresAt === null)) {
            throw new InvalidFormatException('A profile flow step names the address and the moment its proof stands on');
        }

        return new static(
            sessionToken: self::requireString($data, 'sessionToken'),
            userId: self::requireInt($data, 'userId'),
            operation: self::requireString($data, 'operation'),
            step: $step,
            address: $address,
            target: self::optionalString($data, 'target'),
            expiresAt: $expiresAt,
            initiatorAcceptKey: self::requireString($data, 'initiatorAcceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::optionalString($data, 'action'),
        );
    }
}

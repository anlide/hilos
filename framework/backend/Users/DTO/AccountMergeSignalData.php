<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\Users\AbstractHilosUserPage;

/**
 * Hilos user page → sessions library: fold one account into another (HIL-411).
 *
 * {@see AbstractHilosUserPage} owns the ADMIN gate and forwards the write to
 * {@see AbstractSessionsLibraryAgent}, which owns the sessions closed by a merge. The password
 * fate is a backed-enum value on the transport and null when the browser did not need or make a
 * choice.
 *
 * Everything after the merge fields describes the waiting submit ({@see HandoverAskInterface}):
 * the page and request to answer, the browser action the ack belongs to, and its success text.
 * The success text starts null because only the library knows the counts after the write.
 */
final class AccountMergeSignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param int $survivorUserId Survivor user id that absorbs the loser
     * @param int $loserUserId Loser user id folded into the survivor
     * @param ?string $passwordFate Password-fate backed value, or null when unnamed
     * @param string $replySignal Agent-signal name the library reports back under
     * @param string $acceptKey Initiating connection accept key
     * @param ?string $requestId Client-minted tracked request id, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Initial success sentence, null until the library has counts
     */
    public function __construct(
        public readonly int $survivorUserId,
        public readonly int $loserUserId,
        public readonly ?string $passwordFate,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'survivorUserId' => $this->survivorUserId,
            'loserUserId' => $this->loserUserId,
            'passwordFate' => $this->passwordFate,
            'replySignal' => $this->replySignal,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'successMessage' => $this->successMessage,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload cannot name the merge or its waiting submit
     */
    public static function fromArray(array $data): static
    {
        return new static(
            survivorUserId: self::requireInt($data, 'survivorUserId'),
            loserUserId: self::requireInt($data, 'loserUserId'),
            passwordFate: self::optionalString($data, 'passwordFate'),
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Moderation\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Remove the captured rule only while its kind and confirmation text still match. */
final class ModerationRuleDeleteSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string kind = 'kind';
    public const string id = 'id';
    public const string expectedRule = 'expectedRule';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /** Longest client correlation, in characters. */
    public const int MAX_REQUEST_ID_LENGTH = 128;

    /**
     * @param int $id Captured rule primary ID
     * @param string $kind Published moderation catalog key
     * @param string $expectedRule Exact confirmation text, without normalization
     * @param string $replySignal Gatekeeper reply signal
     * @param string $acceptKey Initiating connection
     * @param ?string $requestId Client correlation, null when untracked
     * @param string $action Initiating action name
     * @param ?string $successMessage Gatekeeper success sentence, or null
     * @throws InvalidFormatException When the rule ID or handover fields are invalid
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $expectedRule,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($id <= 0) {
            throw new InvalidFormatException('Invalid rule id: ' . $id);
        }
        if (trim($replySignal) === '' || trim($acceptKey) === '' || trim($action) === '') {
            throw new InvalidFormatException('Provide the handover reply, connection and action.');
        }
        if ($requestId !== null && (!mb_check_encoding($requestId, 'UTF-8') ||
            mb_strlen($requestId, 'UTF-8') > self::MAX_REQUEST_ID_LENGTH)) {
            throw new InvalidFormatException('The moderation request id must contain at most 128 characters.');
        }
    }

    /** @return array<string, mixed> Complete transport payload, including explicit nulls */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::kind => $this->kind,
            self::expectedRule => $this->expectedRule,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data Complete transport payload
     * @return static Validated internal ask
     * @throws InvalidFormatException When a required key is missing, mistyped or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: self::requireInt($data, self::id),
            kind: self::requireString($data, self::kind),
            expectedRule: self::requireString($data, self::expectedRule),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::requireNullableString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::requireNullableString($data, self::successMessage),
        );
    }
}

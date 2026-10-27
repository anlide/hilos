<?php

declare(strict_types=1);

namespace Hilos\Moderation\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Add raw policy text to one published moderation kind. */
final class ModerationRuleAddSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string kind = 'kind';
    public const string rule = 'rule';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /** Longest client correlation, in characters. */
    public const int MAX_REQUEST_ID_LENGTH = 128;

    /**
     * @param string $kind Published moderation catalog key
     * @param string $rule Raw rule text validated by the ORM
     * @param string $replySignal Gatekeeper reply signal
     * @param string $acceptKey Initiating connection
     * @param ?string $requestId Client correlation, null when untracked
     * @param string $action Initiating action name
     * @param ?string $successMessage Gatekeeper success sentence, or null
     * @throws InvalidFormatException When the handover fields are invalid
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $rule,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
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
            self::kind => $this->kind,
            self::rule => $this->rule,
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
            kind: self::requireString($data, self::kind),
            rule: self::requireString($data, self::rule),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::requireNullableString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::requireNullableString($data, self::successMessage),
        );
    }
}

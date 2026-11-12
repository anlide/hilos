<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\ValueTooLongException;
use JsonException;

/**
 * What the erasure census expects from one participant for one account of the circle: a named
 * owner to carry its part, or a wait the coordinator holds while no owner is there to carry it.
 * SCAFFOLD: no live caller until the erasure coordinator opens its operation (HIL-1952).
 */
final readonly class AccountErasureExpectation
{
    private const string EXPECT_PREFIX = 'expect';
    private const string WAIT_PREFIX = 'wait';
    private const string ASSIGNMENT_KEY_SEPARATOR = ':';
    private const string PAYLOAD_PARTICIPANT = 'participant';

    /**
     * @param string $participantKey Participant the expectation belongs to, a channel-qualified one for deliveries
     * @param int $userId Account of the erasure circle
     * @param ?AccountOperationOwnerAddress $owner Expected owner, or null for a wait
     */
    public function __construct(
        public string $participantKey,
        public int $userId,
        public ?AccountOperationOwnerAddress $owner,
    ) {
    }

    /**
     * @param string $assignmentKey Stored assignment key of any kind
     * @return bool Whether it is an expected owner's key, `expect:…`
     */
    public static function isExpectKey(string $assignmentKey): bool
    {
        return str_starts_with($assignmentKey, self::EXPECT_PREFIX . self::ASSIGNMENT_KEY_SEPARATOR);
    }

    /**
     * @param string $assignmentKey Stored assignment key of any kind
     * @return bool Whether it is a wait's key, `wait:…`
     */
    public static function isWaitKey(string $assignmentKey): bool
    {
        return str_starts_with($assignmentKey, self::WAIT_PREFIX . self::ASSIGNMENT_KEY_SEPARATOR);
    }

    /**
     * The participant is read from the payload and never from the key: a delivery participant and
     * an owner's agent id both carry the separator, so the key cannot be split back.
     *
     * @param AccountOperationAssignmentPayload $payload Stored payload of an `expect:` or `wait:` assignment
     * @return string Participant the payload names
     * @throws JsonException When the stored payload cannot be decoded
     * @throws InvalidFormatException When the payload names no participant
     */
    public static function participantOf(AccountOperationAssignmentPayload $payload): string
    {
        $fields = json_decode($payload->toJson(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($fields) || !is_string($fields[self::PAYLOAD_PARTICIPANT] ?? null)) {
            throw new InvalidFormatException('An erasure expectation payload must name its participant.');
        }

        return $fields[self::PAYLOAD_PARTICIPANT];
    }

    /** @return bool Whether no owner is there and the coordinator waits instead */
    public function isWait(): bool
    {
        return $this->owner === null;
    }

    /** @return string Assignment key: `expect:<participant>:<owner agent id>:<user id>` or `wait:<participant>:<user id>` */
    public function assignmentKey(): string
    {
        if ($this->owner === null) {
            return implode(self::ASSIGNMENT_KEY_SEPARATOR, [self::WAIT_PREFIX, $this->participantKey, (string)$this->userId]);
        }

        return implode(
            self::ASSIGNMENT_KEY_SEPARATOR,
            [self::EXPECT_PREFIX, $this->participantKey, $this->owner->agentId(), (string)$this->userId],
        );
    }

    /**
     * @return AccountOperationOwnerAddress Address the assignment is written to: the owner, or the coordinator for a wait
     * @throws EmptyValueException When the coordinator's agent type is empty
     * @throws ValueTooLongException When the coordinator's agent type exceeds the address limit
     */
    public function assignmentOwner(): AccountOperationOwnerAddress
    {
        return $this->owner ?? new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY);
    }

    /**
     * @return AccountOperationAssignmentPayload Exactly `{"participant":"<participant key>"}`
     * @throws JsonException When the participant key is not valid UTF-8
     * @throws InvalidJsonException When the encoded payload is not valid JSON
     * @throws InvalidFormatException When the encoded payload is not a JSON object
     */
    public function payload(): AccountOperationAssignmentPayload
    {
        return AccountOperationAssignmentPayload::fromJson(json_encode(
            [self::PAYLOAD_PARTICIPANT => $this->participantKey],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;
use stdClass;

/**
 * The capture the person's agent leaves in its participant record before the ways in go, and its
 * reading back by the owners that delete later (HIL-1940).
 *
 * The payload is exactly `{"addresses":[…],"project":{…}}`: the identifier of every way in the
 * person had, each once, in ascending string order, and the keys the project asked to keep beside
 * them. `project` stays a JSON object even when the project keeps nothing.
 * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
 */
final readonly class AccountErasurePersonalCaptureResult
{
    private const string ADDRESSES = 'addresses';
    private const string PROJECT = 'project';

    /**
     * @param list<string> $addresses Identifier of every way in the person had, each once, in ascending order
     * @param array<string, mixed> $project Keys the project kept beside the addresses
     */
    private function __construct(public array $addresses, public array $project)
    {
    }

    /**
     * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
     *
     * @param list<string> $addresses Identifiers of the person's ways in, repeats allowed
     * @param array<string, mixed> $project Keys the project keeps beside them
     * @return AccountOperationResultPayload Exactly `{"addresses":[…],"project":{…}}`, the addresses unique and sorted
     * @throws JsonException When an address or a project value is not valid UTF-8
     * @throws InvalidJsonException When the encoded payload is not valid JSON
     * @throws InvalidFormatException Never for the encoded object; declared by the payload boundary
     */
    public static function payloadOf(array $addresses, array $project): AccountOperationResultPayload
    {
        $unique = array_unique($addresses);
        sort($unique, SORT_STRING);

        return AccountOperationResultPayload::fromJson(json_encode(
            [self::ADDRESSES => $unique, self::PROJECT => (object)$project],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
     *
     * @param AccountOperationResultPayload $payload Saved result of a personal capture
     * @return self The addresses and the project's keys the payload names
     * @throws JsonException When the saved payload cannot be decoded
     * @throws InvalidFormatException When the payload is not `{"addresses":[…],"project":{…}}` with string addresses
     */
    public static function fromPayload(AccountOperationResultPayload $payload): self
    {
        $fields = json_decode($payload->toJson(), flags: JSON_THROW_ON_ERROR);
        if (!$fields instanceof stdClass
            || array_keys(get_object_vars($fields)) !== [self::ADDRESSES, self::PROJECT]
            || !is_array($fields->{self::ADDRESSES})
            || !array_is_list($fields->{self::ADDRESSES})
            || array_filter($fields->{self::ADDRESSES}, static fn (mixed $address): bool => !is_string($address)) !== []
            || !$fields->{self::PROJECT} instanceof stdClass) {
            throw new InvalidFormatException('An account erasure capture must be {"addresses":[…],"project":{…}}.');
        }
        // The object read above tells `{}` from `[]`; the project's own values are handed over as arrays.
        return new self($fields->{self::ADDRESSES}, json_decode($payload->toJson(), true, flags: JSON_THROW_ON_ERROR)[self::PROJECT]);
    }

    /**
     * The person's capture in an erasure, at whatever stage it was assigned.
     *
     * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
     *
     * @param int $operationId Erasure whose circle the person belongs to
     * @param int $userId Account whose capture is read
     * @return ?self The capture, or null while the person's agent has not recorded one
     * @throws HilosException When the storage reader or item wrapper refuses a lookup
     * @throws EmptyValueException Never for the person's agent type; declared by the capture key
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the capture key
     * @throws JsonException When the saved capture cannot be decoded
     * @throws InvalidFormatException When the saved capture is not `{"addresses":[…],"project":{…}}`
     */
    public static function of(int $operationId, int $userId): ?self
    {
        $key = AccountErasureCapture::personalKey($userId);
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operationId) as $assignment) {
            if ($assignment->assignmentKey !== $key) {
                continue;
            }
            $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
            if ($result !== null) {
                return self::fromPayload($result->payload);
            }
        }

        return null;
    }
}

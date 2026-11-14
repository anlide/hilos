<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\MalformedInput;
use Hilos\Core\Exception\NonArrayPayloadException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\HilosException;
use Hilos\Constants\WorkerConstants;
use Hilos\Socket\Worker\Exception\UnknownWorkerMessageTypeException;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\DaemonWorkerSignalDTO;
use Hilos\Socket\Worker\DTO\ProtectedModeReadyDTO;
use Hilos\Socket\Worker\DTO\ProtectedModeRefusedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentRestartRequestDTO;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydratedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReReadMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbInterestReadyMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncClearedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerGroupJoinDTO;
use Hilos\Socket\Worker\DTO\WorkerGroupLeaveAllDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessConnectionsMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeDisableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeEnableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeCircleDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModePassDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeProgressDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeRefreezeDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeSettingsDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeVerifyDTO;
use Hilos\Socket\Worker\DTO\WorkerLogWriteLevelDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSnapshotMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtStalenessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Socket\Worker\DTO\WorkerUploadConsumedDTO;
use Hilos\Socket\Worker\DTO\WorkerUploadSettingsDTO;
use Hilos\Socket\Worker\DTO\WorkerDeliverySettingsDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncStreamDTO;
use Hilos\Utils\Logger;
use Hilos\Socket\Worker\DTO\WorkerTestCronFireDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFiredDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleResultDTO;
use Hilos\Socket\Worker\DTO\WorkerTestOrmForgetDTO;
use Hilos\Socket\Worker\DTO\WorkerTestOrmForgottenDTO;

/**
 * WorkerDTO - Abstract base class for worker DTOs.
 *
 * Provides common functionality for worker communication DTOs.
 */
abstract class WorkerDTO extends BaseDTO
{
    // Field name constants
    public const string TYPE = 'type';

    private const string PAGE_RESPONSE_JSON_WHITESPACE = " \t\r\n";

    private const string PAGE_RESPONSE_JSON_STRING_BOUNDARIES = '"\\';

    /**
     * Get message type.
     *
     * @return string Message type
     */
    abstract public function getType(): string;

    /**
     * Factory method to create WorkerDTO from JSON string.
     *
     * Parses JSON, determines message type, and creates appropriate DTO instance.
     * Typed page responses keep their applied filter subtrees from the original JSON;
     * the other fields and message types retain the shared associative decoding.
     *
     * Every way a frame can fail here is a way the input could not be parsed, so each
     * refusal is one that carries {@see MalformedInput}: the reader of the log asks the
     * failure what it is and gets the same answer whether the string never decoded, held
     * the wrong shape, named no type or named one this node does not know. The generic
     * invalid-argument exception used to answer for all four and could not be marked —
     * the same class reports a caller passing nonsense in five other places, where the
     * fault is the code's and not the wire's.
     *
     * @param string $json JSON string
     * @return WorkerDTO Worker DTO instance
     * @throws InvalidJsonException When the frame does not decode as JSON
     * @throws NonArrayPayloadException When the frame decodes into something other than an array
     * @throws InvalidFormatException When the frame names no message type
     * @throws UnknownWorkerMessageTypeException When the frame names a type the registry does not know
     * @throws HilosException When an agent frame's inner signal payload refuses to be restored
     */
    public static function factoryWorkerDTO(string $json): WorkerDTO
    {
        Logger::debug('Parsing worker DTO from JSON: ' . $json);
        $data = self::decodePayload($json);

        $type = $data[self::TYPE] ?? null;
        if (!is_string($type) || $type === '') {
            throw new InvalidFormatException('Message type is missing');
        }

        if ($type === WorkerAgentMessageDTO::MESSAGE_TYPE) {
            $data = self::restorePageResponseFilters($data, $json);
        }

        return match ($type) {
            WorkerRegisterDTO::MESSAGE_TYPE => WorkerRegisterDTO::fromArray($data),
            WorkerLogWriteLevelDTO::MESSAGE_TYPE => WorkerLogWriteLevelDTO::fromArray($data),
            WorkerProtectedModeSettingsDTO::MESSAGE_TYPE => WorkerProtectedModeSettingsDTO::fromArray($data),
            WorkerUploadSettingsDTO::MESSAGE_TYPE => WorkerUploadSettingsDTO::fromArray($data),
            WorkerDeliverySettingsDTO::MESSAGE_TYPE => WorkerDeliverySettingsDTO::fromArray($data),
            WorkerUploadConsumedDTO::MESSAGE_TYPE => WorkerUploadConsumedDTO::fromArray($data),
            WorkerAgentStartedDTO::MESSAGE_TYPE => WorkerAgentStartedDTO::fromArray($data),
            WorkerAgentStartFailedDTO::MESSAGE_TYPE => WorkerAgentStartFailedDTO::fromArray($data),
            WorkerAgentStoppedDTO::MESSAGE_TYPE => WorkerAgentStoppedDTO::fromArray($data),
            WorkerAgentMessageDTO::MESSAGE_TYPE => WorkerAgentMessageDTO::fromArray($data),
            WorkerConstants::MESSAGE_DAEMON_AGENT_MESSAGE => DaemonAgentMessageDTO::fromArray($data),
            DaemonWorkerSignalDTO::MESSAGE_TYPE => DaemonWorkerSignalDTO::fromArray($data),
            WorkerRegisteredDTO::MESSAGE_TYPE => WorkerRegisteredDTO::fromArray($data),
            AgentStartDTO::MESSAGE_TYPE => AgentStartDTO::fromArray($data),
            AgentStopDTO::MESSAGE_TYPE => AgentStopDTO::fromArray($data),
            ProtectedModeReadyDTO::MESSAGE_TYPE => ProtectedModeReadyDTO::fromArray($data),
            ProtectedModeRefusedDTO::MESSAGE_TYPE => ProtectedModeRefusedDTO::fromArray($data),
            WorkerDbSyncCreatedMessageDTO::MESSAGE_TYPE => WorkerDbSyncCreatedMessageDTO::fromArray($data),
            WorkerDbSyncUpdatedMessageDTO::MESSAGE_TYPE => WorkerDbSyncUpdatedMessageDTO::fromArray($data),
            WorkerDbSyncDeletedMessageDTO::MESSAGE_TYPE => WorkerDbSyncDeletedMessageDTO::fromArray($data),
            WorkerDbSyncClearedMessageDTO::MESSAGE_TYPE => WorkerDbSyncClearedMessageDTO::fromArray($data),
            WorkerDbReHydrateMessageDTO::MESSAGE_TYPE => WorkerDbReHydrateMessageDTO::fromArray($data),
            WorkerDbReReadMessageDTO::MESSAGE_TYPE => WorkerDbReReadMessageDTO::fromArray($data),
            WorkerPageAccessReassessMessageDTO::MESSAGE_TYPE => WorkerPageAccessReassessMessageDTO::fromArray($data),
            WorkerPageAccessReassessConnectionsMessageDTO::MESSAGE_TYPE
                => WorkerPageAccessReassessConnectionsMessageDTO::fromArray($data),
            WorkerTestOrmForgetDTO::MESSAGE_TYPE => WorkerTestOrmForgetDTO::fromArray($data),
            WorkerTestOrmForgottenDTO::MESSAGE_TYPE => WorkerTestOrmForgottenDTO::fromArray($data),
            WorkerTestCronFireDTO::MESSAGE_TYPE => WorkerTestCronFireDTO::fromArray($data),
            WorkerTestCronFiredDTO::MESSAGE_TYPE => WorkerTestCronFiredDTO::fromArray($data),
            WorkerTestCronScheduleDTO::MESSAGE_TYPE => WorkerTestCronScheduleDTO::fromArray($data),
            WorkerTestCronScheduleResultDTO::MESSAGE_TYPE => WorkerTestCronScheduleResultDTO::fromArray($data),
            WorkerDbReHydratedDTO::MESSAGE_TYPE => WorkerDbReHydratedDTO::fromArray($data),
            DbReHydrateCompleteDTO::MESSAGE_TYPE => DbReHydrateCompleteDTO::fromArray($data),
            WorkerRtSyncStreamDTO::MESSAGE_TYPE => WorkerRtSyncStreamDTO::fromArray($data),
            WorkerRtSourceRegisteredDTO::MESSAGE_TYPE => WorkerRtSourceRegisteredDTO::fromArray($data),
            WorkerRtSourceReleasedDTO::MESSAGE_TYPE => WorkerRtSourceReleasedDTO::fromArray($data),
            WorkerSourceInterestDTO::MESSAGE_TYPE => WorkerSourceInterestDTO::fromArray($data),
            WorkerGroupJoinDTO::MESSAGE_TYPE => WorkerGroupJoinDTO::fromArray($data),
            WorkerGroupLeaveAllDTO::MESSAGE_TYPE => WorkerGroupLeaveAllDTO::fromArray($data),
            WorkerRtSnapshotMessageDTO::MESSAGE_TYPE => WorkerRtSnapshotMessageDTO::fromArray($data),
            WorkerDbInterestReadyMessageDTO::MESSAGE_TYPE => WorkerDbInterestReadyMessageDTO::fromArray($data),
            WorkerRtStalenessMessageDTO::MESSAGE_TYPE => WorkerRtStalenessMessageDTO::fromArray($data),
            WorkerAgentRestartRequestDTO::MESSAGE_TYPE => WorkerAgentRestartRequestDTO::fromArray($data),
            WorkerProtectedModeEnableDTO::MESSAGE_TYPE => WorkerProtectedModeEnableDTO::fromArray($data),
            WorkerProtectedModeDisableDTO::MESSAGE_TYPE => WorkerProtectedModeDisableDTO::fromArray($data),
            WorkerProtectedModeVerifyDTO::MESSAGE_TYPE => WorkerProtectedModeVerifyDTO::fromArray($data),
            WorkerProtectedModeProgressDTO::MESSAGE_TYPE => WorkerProtectedModeProgressDTO::fromArray($data),
            WorkerProtectedModePassDTO::MESSAGE_TYPE => WorkerProtectedModePassDTO::fromArray($data),
            WorkerProtectedModeCircleDTO::MESSAGE_TYPE => WorkerProtectedModeCircleDTO::fromArray($data),
            WorkerProtectedModeRefreezeDTO::MESSAGE_TYPE => WorkerProtectedModeRefreezeDTO::fromArray($data),
            default => throw new UnknownWorkerMessageTypeException($type),
        };
    }

    /**
     * Preserves object/list shape only inside existing typed page-response window filters.
     *
     * Only page responses with windows inspect the already validated JSON a second time.
     * Object-mode decoding of the whole frame would reject otherwise valid NUL-prefixed
     * keys in unrelated sections. Those sections keep the shared associative decoding.
     * Envelope selection retains byte ranges in the immutable frame, never raw subtrees.
     * The pass runs only when some filter holds a nested container: a flat filter loses
     * nothing to the shared decoder, and {@see PagePayload::toArray()} restores its root.
     *
     * @param array<string, mixed> $data Worker frame decoded by the shared reader
     * @param string $json Original, already validated JSON frame
     * @return array<string, mixed> Worker frame with the original nested filter shapes
     */
    private static function restorePageResponseFilters(array $data, string $json): array
    {
        $signal = $data[WorkerAgentMessageDTO::SIGNAL] ?? null;
        if (!is_array($signal)
            || ($signal['signalName'] ?? null) !== SignalTypeConstants::PAGE_RESPONSE
            || ($signal[SignalPayloadConstants::FIELD_DATA_TYPE] ?? null) !== WebSocketSignalData::class
            || ($signal[SignalPayloadConstants::FIELD_DATA][SignalPayloadConstants::FIELD_DATA_TYPE] ?? null) !== PageResponseSignalData::class
        ) {
            return $data;
        }

        $windows = $signal[SignalPayloadConstants::FIELD_DATA][SignalPayloadConstants::FIELD_DATA]
            [PageResponseSignalData::payload][PagePayload::windows] ?? null;
        if (!is_array($windows) || $windows === []) {
            return $data;
        }
        // Only a nested container comes back from associative decoding in another shape: the scalars of a
        // flat filter survive it, and its root object is restored by PagePayload::toArray().
        if (!array_any($windows, static fn (mixed $window): bool => is_array($window)
            && is_array($window[TableWindowDescriptorDTO::FILTER] ?? null)
            && array_any($window[TableWindowDescriptorDTO::FILTER], static fn (mixed $value): bool => is_array($value)))) {
            return $data;
        }

        $windowsRange = [0, strlen($json)];
        foreach ([
            WorkerAgentMessageDTO::SIGNAL, SignalPayloadConstants::FIELD_DATA,
            SignalPayloadConstants::FIELD_DATA, PageResponseSignalData::payload, PagePayload::windows,
        ] as $key) {
            $windowsRange = self::pageResponseJsonMembers($json, ...$windowsRange)[$key] ?? null;
            if ($windowsRange === null || $json[$windowsRange[0]] !== '{') {
                return $data;
            }
        }

        foreach (self::pageResponseJsonMembers($json, ...$windowsRange) as $tableKey => $windowRange) {
            $filterRange = self::pageResponseJsonMembers($json, ...$windowRange)[TableWindowDescriptorDTO::FILTER] ?? null;
            if ($filterRange !== null && $json[$filterRange[0]] === '{' && is_array($windows[$tableKey] ?? null)) {
                $offset = $filterRange[0];
                $windows[$tableKey][TableWindowDescriptorDTO::FILTER] = (array) self::pageResponseFilterValue($json, $offset, $filterRange[1]);
            }
        }
        $signal[SignalPayloadConstants::FIELD_DATA][SignalPayloadConstants::FIELD_DATA]
            [PageResponseSignalData::payload][PagePayload::windows] = $windows;
        $data[WorkerAgentMessageDTO::SIGNAL] = $signal;

        return $data;
    }

    /**
     * Consumes one filter value in a single forward traversal of the immutable frame.
     * Only decoded children and O(depth) cursors survive recursion, never raw container copies.
     * Only list-shaped object maps need a cast; associative maps retain NUL-prefixed keys.
     *
     * @param string $json Original worker frame, already validated by the native decoder
     * @param int $offset Value start, advanced just past the consumed value
     * @param int $end Exclusive upper bound of the selected filter in the frame
     * @return mixed Filter value with its original JSON container shapes
     */
    private static function pageResponseFilterValue(string $json, int &$offset, int $end): mixed
    {
        $offset += strspn($json, self::PAGE_RESPONSE_JSON_WHITESPACE, $offset, $end - $offset);
        if ($json[$offset] !== '{' && $json[$offset] !== '[') {
            return self::pageResponseJsonScalarValue($json, $offset, $end);
        }

        $isObject = $json[$offset] === '{';
        $offset++;
        $filter = [];
        while ($offset < $end) {
            $offset += strspn($json, self::PAGE_RESPONSE_JSON_WHITESPACE, $offset, $end - $offset);
            if ($json[$offset] === '}' || $json[$offset] === ']') {
                $offset++;
                break;
            }
            if ($isObject) {
                $key = self::pageResponseJsonScalarValue($json, $offset, $end);
                $offset += strspn($json, self::PAGE_RESPONSE_JSON_WHITESPACE, $offset, $end - $offset);
                $offset++;
                $filter[$key] = self::pageResponseFilterValue($json, $offset, $end);
            } else {
                $filter[] = self::pageResponseFilterValue($json, $offset, $end);
            }
            $offset += strspn($json, self::PAGE_RESPONSE_JSON_WHITESPACE, $offset, $end - $offset);
            if ($json[$offset] === ',') {
                $offset++;
            }
        }

        return $isObject && array_is_list($filter) ? (object) $filter : $filter;
    }

    /**
     * Reads one validated scalar or object key with native JSON scalar semantics.
     * The only raw slice lives for this decode; no container is materialized as a string.
     *
     * @param string $json Original, already validated worker frame
     * @param int $offset First non-whitespace byte, advanced just past the scalar
     * @param int $end Exclusive upper bound of the selected filter in the frame
     * @return mixed Decoded scalar or string key
     */
    private static function pageResponseJsonScalarValue(string $json, int &$offset, int $end): mixed
    {
        $start = $offset;
        if ($json[$offset] === '"') {
            $offset++;
            while ($offset < $end) {
                $offset += strcspn($json, self::PAGE_RESPONSE_JSON_STRING_BOUNDARIES, $offset, $end - $offset);
                if ($json[$offset] === '"') {
                    $offset++;
                    break;
                }
                $offset += 2;
            }
        } else {
            $offset += strcspn($json, ',]}' . self::PAGE_RESPONSE_JSON_WHITESPACE, $offset, $end - $offset);
        }

        return json_decode(substr($json, $start, $offset - $start), true);
    }

    /**
     * Selects child byte ranges only along the fixed typed envelope/window path.
     * The native decoder has already checked syntax and nesting. This boundary scan skips
     * quoted/escaped bytes without copying containers; filter recursion uses its own cursor.
     *
     * @param string $json Original, already validated worker frame
     * @param int $start Inclusive start of the selected value, possibly preceded by whitespace
     * @param int $end Exclusive end of the selected value in the frame
     * @return array<string|int, array{int, int}> Half-open child ranges; empty for a scalar or empty container
     */
    private static function pageResponseJsonMembers(string $json, int $start, int $end): array
    {
        $start += strspn($json, self::PAGE_RESPONSE_JSON_WHITESPACE, $start, $end - $start);
        if ($json[$start] !== '{' && $json[$start] !== '[') {
            return [];
        }

        $members = [];
        $start++;
        $colon = null;
        $depth = 0;
        for ($offset = $start; $offset < $end; $offset++) {
            $offset += strcspn($json, '"{}[],:', $offset, $end - $offset);
            $byte = $json[$offset];
            if ($byte === '"') {
                $offset++;
                while ($offset < $end) {
                    $offset += strcspn($json, self::PAGE_RESPONSE_JSON_STRING_BOUNDARIES, $offset, $end - $offset);
                    if ($json[$offset] === '"') {
                        break;
                    }
                    $offset += 2;
                }
                continue;
            }
            if ($byte === '{' || $byte === '[') {
                $depth++;
            } elseif (($byte === '}' || $byte === ']') && $depth > 0) {
                $depth--;
            } elseif ($depth === 0 && $byte === ':') {
                $colon = $offset;
            } elseif ($depth === 0 && ($byte === ',' || $byte === '}' || $byte === ']')) {
                $valueStart = $colon === null ? $start : $colon + 1;
                $valueStart += strspn($json, self::PAGE_RESPONSE_JSON_WHITESPACE, $valueStart, $offset - $valueStart);
                if ($valueStart < $offset) {
                    $key = $colon === null ? count($members) : json_decode(substr($json, $start, $colon - $start), true);
                    $members[$key] = [$valueStart, $offset];
                }
                if ($byte !== ',') {
                    break;
                }
                $start = $offset + 1;
                $colon = null;
            }
        }

        return $members;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Moderation\DTO;

use Hilos\API\Exception\AsyncHttpStatusException;
use Hilos\Core\Exception\LogicException;
use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\Exception\LLMResponseException;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\Moderation\ModerationFailureLog;
use Throwable;

/**
 * A bounded diagnostic snapshot, independent of transport DTOs and of the attempted request.
 */
final readonly class ModerationFailure
{
    public const string MALFORMED_ANSWER = 'malformed_answer';
    public const string MODEL_FAILURE = 'model_failure';
    public const string CHECK_UNAVAILABLE = 'check_unavailable';

    public const string STAGE_PREPARE = 'prepare';
    public const string STAGE_START = 'start';
    public const string STAGE_POLL = 'poll';
    public const string STAGE_CONSUME = 'consume';
    public const string STAGE_PARSE = 'parse';
    public const string STAGE_ANSWER = 'answer';

    public const string event = 'event';
    public const string outcome = 'outcome';
    public const string stage = 'stage';
    public const string kind = 'kind';
    public const string requestId = 'requestId';
    public const string profile = 'profile';
    public const string provider = 'provider';
    public const string model = 'model';
    public const string reason = 'reason';
    public const string errorClass = 'errorClass';
    public const string causeClass = 'causeClass';
    public const string httpStatus = 'httpStatus';
    public const string raw = 'raw';
    public const string rawTruncated = 'rawTruncated';
    public const string summary = 'summary';
    public const string suppressed = 'suppressed';
    public const string firstRequestId = 'firstRequestId';
    public const string lastRequestId = 'lastRequestId';
    public const string windowSec = 'windowSec';

    private const string EVENT_NAME = 'moderation_check_failure';
    private const string DEADLINE_REASON = 'deadline_exceeded';
    private const string REDACTED = '[redacted]';
    private const string HASH_ALGORITHM = 'sha256';
    private const string UTF8_ENCODING = 'UTF-8';

    /** Bounds even an unexpectedly large identifier without retaining its original value. */
    private const int IDENTIFIER_MAX_BYTES = 512;

    /**
     * @param string $outcome One of the three diagnostic outcomes
     * @param string $stage Stage which failed
     * @param string $kind Check kind
     * @param string $requestId Attempt correlation
     * @param ?string $profile Requested or resolved profile key
     * @param ?string $provider Resolved provider enum value
     * @param ?string $model Resolved model
     * @param string $reason Bounded safe diagnostic text
     * @param ?string $errorClass Failure type
     * @param ?string $causeClass Innermost previous failure type
     * @param ?int $httpStatus Structured HTTP status, when present
     * @param ?string $raw Bounded parser input, only for malformed answers
     * @param bool $rawTruncated Whether the redacted parser input exceeded its byte budget
     * @param string $groupKey Digest of the full grouping identity, without variable messages or request ids
     */
    private function __construct(
        public string $outcome,
        public string $stage,
        public string $kind,
        public string $requestId,
        public ?string $profile,
        public ?string $provider,
        public ?string $model,
        public string $reason,
        public ?string $errorClass,
        public ?string $causeClass,
        public ?int $httpStatus,
        public ?string $raw,
        public bool $rawTruncated,
        private string $groupKey,
    ) {
    }

    /**
     * Copies only diagnostic scalars; neither the exception nor the profile survives the call.
     * A null failure denotes the moderator's own in-flight deadline.
     *
     * @param string $outcome One of MALFORMED_ANSWER, MODEL_FAILURE or CHECK_UNAVAILABLE
     * @param string $stage One of the STAGE_* constants
     * @param string $kind Check kind
     * @param string $requestId Attempt correlation, never the connection key
     * @param ?Throwable $failure Caught failure, or null for the in-flight deadline
     * @param ?string $requestedProfile Known requested key before resolution, otherwise null
     * @param ?LlmProfile $profile Effective profile of this attempt, when already resolved
     * @param ?string $raw Parser input, required only for MALFORMED_ANSWER
     * @return self Bounded immutable diagnostic snapshot
     * @throws LogicException When an outcome, stage, raw input or deadline combination violates the diagnostic contract
     */
    public static function capture(
        string $outcome,
        string $stage,
        string $kind,
        string $requestId,
        ?Throwable $failure,
        ?string $requestedProfile = null,
        ?LlmProfile $profile = null,
        ?string $raw = null,
    ): self {
        if (!in_array($outcome, [self::MALFORMED_ANSWER, self::MODEL_FAILURE, self::CHECK_UNAVAILABLE], true)
            || !in_array($stage, [
                self::STAGE_PREPARE, self::STAGE_START, self::STAGE_POLL,
                self::STAGE_CONSUME, self::STAGE_PARSE, self::STAGE_ANSWER,
            ], true)
            || ($outcome === self::MALFORMED_ANSWER && $raw === null)
            || ($failure === null && ($outcome !== self::MODEL_FAILURE || $stage !== self::STAGE_POLL))
        ) {
            throw new LogicException('Invalid moderation diagnostic snapshot');
        }

        $cause = $failure?->getPrevious();
        $httpStatus = $failure instanceof AsyncHttpStatusException ? $failure->statusCode : null;
        while ($cause !== null) {
            if ($cause instanceof AsyncHttpStatusException) {
                $httpStatus = $cause->statusCode;
            }
            if ($cause->getPrevious() === null) {
                break;
            }
            $cause = $cause->getPrevious();
        }
        $errorClass = $failure === null ? null : get_class($failure);
        $causeClass = $cause === null ? null : get_class($cause);
        $profileKey = $profile?->key ?? $requestedProfile;
        $provider = $profile?->provider->value;
        $model = $profile?->model;
        $secret = $profile?->apiKey;
        $redactedRaw = $outcome === self::MALFORMED_ANSWER ? self::redact($raw, $secret) : null;
        $rawPrefix = $redactedRaw === null
            ? null
            : self::substituteInvalidUtf8(substr($redactedRaw, 0, ModerationFailureLog::RAW_MAX_BYTES));

        return new self(
            $outcome,
            $stage,
            self::clip($kind, self::IDENTIFIER_MAX_BYTES),
            self::clip($requestId, self::IDENTIFIER_MAX_BYTES),
            $profileKey === null ? null : self::clip($profileKey, self::IDENTIFIER_MAX_BYTES),
            $provider,
            $model === null ? null : self::clip($model, self::IDENTIFIER_MAX_BYTES),
            self::clip(self::redact(self::safeReason($failure), $secret), ModerationFailureLog::REASON_MAX_BYTES),
            $errorClass,
            $causeClass,
            $httpStatus,
            $rawPrefix === null ? null : mb_strcut($rawPrefix, 0, ModerationFailureLog::RAW_MAX_BYTES, self::UTF8_ENCODING),
            $redactedRaw !== null && (
                strlen($redactedRaw) > ModerationFailureLog::RAW_MAX_BYTES || strlen($rawPrefix) > ModerationFailureLog::RAW_MAX_BYTES
            ),
            hash(self::HASH_ALGORITHM, serialize([
                $outcome, $stage, $kind, $profileKey, $provider, $model,
                $errorClass, $failure?->getFile(), $failure?->getLine(), $causeClass, $httpStatus,
                $failure === null ? self::DEADLINE_REASON : null,
            ])),
        );
    }

    /** @return string Stable identity without request ids, messages, credentials or raw answers */
    public function groupKey(): string
    {
        return $this->groupKey;
    }

    /**
     * @param bool $summary Whether this closes a window
     * @param int $suppressed Additional events beyond the detailed entries
     * @param ?string $firstRequestId First request of the closed group, or null for a detailed entry
     * @return array<string, string|int|bool|null> JSON-boundary record without a second timestamp
     */
    public function toArray(bool $summary = false, int $suppressed = 0, ?string $firstRequestId = null): array
    {
        return [
            self::event => self::EVENT_NAME,
            self::outcome => $this->outcome,
            self::stage => $this->stage,
            self::kind => $this->kind,
            self::requestId => $this->requestId,
            self::profile => $this->profile,
            self::provider => $this->provider,
            self::model => $this->model,
            self::reason => $this->reason,
            self::errorClass => $this->errorClass,
            self::causeClass => $this->causeClass,
            self::httpStatus => $this->httpStatus,
            self::raw => $this->raw,
            self::rawTruncated => $this->rawTruncated,
            self::summary => $summary,
            self::suppressed => $suppressed,
            self::firstRequestId => $firstRequestId ?? $this->requestId,
            self::lastRequestId => $this->requestId,
            self::windowSec => (int)ModerationFailureLog::WINDOW_SECONDS,
        ];
    }

    /**
     * @param ?Throwable $failure Caught exception, or the moderator's own deadline
     * @return string Diagnostic reason without a provider response envelope
     */
    private static function safeReason(?Throwable $failure): string
    {
        if ($failure === null) {
            return self::DEADLINE_REASON;
        }
        $reason = $failure->getMessage();
        if ($failure instanceof LLMResponseException) {
            foreach ([LLMApiConstants::LOG_OPENAI_RESPONSE_INVALID, LLMApiConstants::LOG_OLLAMA_RESPONSE_INVALID] as $prefix) {
                if (str_starts_with($reason, $prefix)) {
                    return rtrim($prefix, ': ');
                }
            }
        }

        return $reason;
    }

    /**
     * @param string $text Diagnostic text
     * @param ?string $secret Already-known credential, never looked up for logging
     * @return string Redacted text before truncation
     */
    private static function redact(string $text, ?string $secret): string
    {
        return $secret === null || $secret === '' ? $text : str_replace($secret, self::REDACTED, $text);
    }

    /**
     * @param string $text Redacted text
     * @param int $maxBytes Storage budget in bytes
     * @return string Valid UTF-8 snapshot within the byte budget, including replacement characters
     */
    private static function clip(string $text, int $maxBytes): string
    {
        return mb_strcut(self::substituteInvalidUtf8(substr($text, 0, $maxBytes)), 0, $maxBytes, self::UTF8_ENCODING);
    }

    /**
     * @param string $text Byte-bounded prefix of a diagnostic field
     * @return string The same substitution used at the JSON boundary, without changing mbstring global configuration
     */
    private static function substituteInvalidUtf8(string $text): string
    {
        return json_decode(json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE));
    }
}

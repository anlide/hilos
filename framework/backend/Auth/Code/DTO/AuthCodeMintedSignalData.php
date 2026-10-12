<?php

declare(strict_types=1);

namespace Hilos\Auth\Code\DTO;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;

/** Users library → code agent: a self-contained delivery order or refusal (HIL-1411). */
final class AuthCodeMintedSignalData extends BaseDTO implements SignalDataInterface
{
    public const string request = 'request';
    public const string probeToken = 'probeToken';
    public const string code = 'code';
    public const string reason = 'reason';
    public const string resendAt = 'resendAt';
    public const string expiresAt = 'expiresAt';
    public const string registration = 'registration';

    /**
     * @param AuthCodeSendSignalData $request Complete code request
     * @param ?string $probeToken Channel probe handle reused by delivery
     * @param ?string $code Plaintext code to send, or null on refusal
     * @param ?string $reason Refusal reason, or null when a code was issued
     * @param ?int $resendAt Next allowed send in epoch milliseconds
     * @param ?int $expiresAt Live code expiry in epoch milliseconds
     * @param bool $registration Whether this request holds a free number for registration
     * @throws InvalidFormatException When code and reason do not describe exactly one permitted outcome
     */
    public function __construct(
        public readonly AuthCodeSendSignalData $request,
        public readonly ?string $probeToken,
        public readonly ?string $code,
        public readonly ?string $reason,
        public readonly ?int $resendAt,
        public readonly ?int $expiresAt,
        public readonly bool $registration,
    ) {
        if (($code === null) === ($reason === null)) {
            throw new InvalidFormatException('A minted code order requires exactly one of code and reason');
        }
        if ($reason !== null && !in_array($reason, [
            HilosCodeSendAttempt::REASON_RATE_LIMITED,
            HilosCodeSendAttempt::REASON_CAP_REACHED,
            HilosCodeSendAttempt::REASON_SEND_FAILED,
            AuthFlowOutcome::CODE_TERMS_UNPUBLISHED,
            AuthFlowOutcome::CODE_CONSENT_REQUIRED,
            AuthFlowOutcome::CODE_CONSENT_REVISED,
        ], true)) {
            throw new InvalidFormatException('Unknown code mint refusal reason');
        }
    }

    /** @return array<string, mixed> Payload for transport */
    public function toArray(): array
    {
        return [
            self::request => $this->request->toArray(),
            self::probeToken => $this->probeToken,
            self::code => $this->code,
            self::reason => $this->reason,
            self::resendAt => $this->resendAt,
            self::expiresAt => $this->expiresAt,
            self::registration => $this->registration,
        ];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Parsed signal payload
     * @throws InvalidFormatException When a field is missing, mistyped or inconsistent
     * @throws ValidationException When the nested accepted revision map is malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            request: AuthCodeSendSignalData::fromArray(self::requireArray($data, self::request)),
            probeToken: self::optionalString($data, self::probeToken),
            code: self::optionalString($data, self::code),
            reason: self::optionalString($data, self::reason),
            resendAt: self::optionalInt($data, self::resendAt),
            expiresAt: self::optionalInt($data, self::expiresAt),
            registration: self::requireBool($data, self::registration),
        );
    }
}

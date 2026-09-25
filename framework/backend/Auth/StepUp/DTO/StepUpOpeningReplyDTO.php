<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * Opening answer for a protected operation's optional confirmation step (HIL-495).
 */
final class StepUpOpeningReplyDTO extends ActionReplyDTO
{
    public const string required = 'required';
    public const string purpose = 'purpose';
    public const string method = 'method';
    public const string destination = 'destination';
    public const string signedChallenge = 'signedChallenge';
    public const string publicKeyOptions = 'publicKeyOptions';

    /**
     * @param bool $required Whether the operation must show the confirmation step
     * @param string $purpose Phrase completing the confirmation copy
     * @param ?string $method Selected confirmation method, or null when no step is required
     * @param ?string $destination Email address or phone number receiving a code, or null
     * @param ?string $signedChallenge Signed WebAuthn challenge token, or null
     * @param ?array<string, mixed> $publicKeyOptions WebAuthn request options, or null
     */
    public function __construct(
        public readonly bool $required,
        public readonly string $purpose,
        public readonly ?string $method = null,
        public readonly ?string $destination = null,
        public readonly ?string $signedChallenge = null,
        public readonly ?array $publicKeyOptions = null,
    ) {
    }

    /**
     * @return array<string, mixed> Opening answer with absent optional members omitted
     */
    public function toArray(): array
    {
        $data = [self::required => $this->required, self::purpose => $this->purpose];
        if ($this->method !== null) {
            $data[self::method] = $this->method;
        }
        if ($this->destination !== null) {
            $data[self::destination] = $this->destination;
        }
        if ($this->signedChallenge !== null) {
            $data[self::signedChallenge] = $this->signedChallenge;
        }
        if ($this->publicKeyOptions !== null) {
            $data[self::publicKeyOptions] = $this->publicKeyOptions;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data Opening answer payload
     * @return static Restored answer
     * @throws InvalidFormatException When a required or present optional field is mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            required: self::requireBool($data, self::required),
            purpose: self::requireString($data, self::purpose),
            method: self::optionalString($data, self::method),
            destination: self::optionalString($data, self::destination),
            signedChallenge: self::optionalString($data, self::signedChallenge),
            publicKeyOptions: self::optionalArray($data, self::publicKeyOptions),
        );
    }
}

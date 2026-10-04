<?php

declare(strict_types=1);

namespace Hilos\Auth\Code\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/** Outcome and timing of a profile code request. */
final class CodeSendReplyDTO extends ActionReplyDTO
{
    public const string sent = 'sent';
    public const string resendAt = 'resendAt';
    public const string expiresAt = 'expiresAt';

    /**
     * @param bool $sent Whether this request issued a new code
     * @param int $resendAt Server moment another send is allowed, in epoch ms
     * @param ?int $expiresAt Server moment the live code dies, or null when none remains
     */
    public function __construct(
        public readonly bool $sent,
        public readonly int $resendAt,
        public readonly ?int $expiresAt,
    ) {
    }

    /**
     * @return array<string, bool|int|null> Reply payload
     */
    public function toArray(): array
    {
        return [
            self::sent => $this->sent,
            self::resendAt => $this->resendAt,
            self::expiresAt => $this->expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $data Reply payload
     * @return static Restored reply
     * @throws InvalidFormatException When a required field is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sent: self::requireBool($data, self::sent),
            resendAt: self::requireInt($data, self::resendAt),
            expiresAt: self::optionalInt($data, self::expiresAt),
        );
    }
}

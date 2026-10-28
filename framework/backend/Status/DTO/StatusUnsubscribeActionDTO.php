<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Guest request to end a Status email subscription. */
final class StatusUnsubscribeActionDTO extends ActionPayloadDTO
{
    public const string TOKEN = 'token';

    public const array SECRET_FIELDS = [self::TOKEN];

    /**
     * @param string $token Token from the subscription letter
     * @throws InvalidFormatException When the token is not exactly 64 hexadecimal characters
     */
    public function __construct(public readonly string $token)
    {
        if (preg_match('/\A[0-9a-fA-F]{64}\z/', $token) !== 1) {
            throw new InvalidFormatException('Invalid subscription link');
        }
    }

    /**
     * @return string Guest action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::STATUS_UNSUBSCRIBE;
    }

    /**
     * @param array<string, mixed> $data Guest action payload
     * @return static Validated link payload
     * @throws InvalidFormatException When token is missing, mistyped or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::TOKEN));
    }

    /**
     * @return array<string, mixed> Link token payload
     */
    public function toArray(): array
    {
        return [self::TOKEN => $this->token];
    }
}

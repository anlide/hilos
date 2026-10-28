<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Throttle\ThrottleEmailIdentityInterface;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Guest request to receive a Status subscription confirmation letter. */
final class StatusSubscribeActionDTO extends ActionPayloadDTO implements ThrottleEmailIdentityInterface
{
    public const string EMAIL = 'email';

    public const array SECRET_FIELDS = [self::EMAIL];

    private const int MAX_EMAIL_CHARACTERS = 254;

    public readonly string $email;

    /**
     * @param string $email Submitted email address
     * @throws InvalidFormatException When the address is malformed
     * @throws ValueTooLongException When the normalized address exceeds the length limit
     */
    public function __construct(string $email)
    {
        $this->email = IdentifierDetector::normalize($email, IdentifierDetection::KIND_EMAIL);
        if (mb_strlen($this->email) > self::MAX_EMAIL_CHARACTERS) {
            throw new ValueTooLongException('Email address is too long');
        }
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidFormatException('Enter a valid email address');
        }
    }

    /**
     * @return string Status subscription action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::STATUS_SUBSCRIBE;
    }

    /**
     * @return string Validated, normalized address for the shared email budget
     */
    public function throttleEmail(): string
    {
        return $this->email;
    }

    /**
     * @param array<string, mixed> $data Guest action payload
     * @return static Validated subscription request
     * @throws InvalidFormatException When email is missing, mistyped or malformed
     * @throws ValueTooLongException When the normalized address exceeds the length limit
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::EMAIL));
    }

    /**
     * @return array<string, mixed> Normalized address payload
     */
    public function toArray(): array
    {
        return [self::EMAIL => $this->email];
    }
}

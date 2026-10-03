<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Project checker → users library: verdict for one pending profile photo. */
final class ProfilePhotoVerdictSignalData extends BaseDTO implements SignalDataInterface
{
    public const string acceptKey = 'acceptKey';
    public const string clientUploadId = 'clientUploadId';
    public const string allow = 'allow';
    public const string reason = 'reason';

    /**
     * @param string $acceptKey Connection that submitted the photo
     * @param string $clientUploadId Pending upload id
     * @param bool $allow Whether the photo passed
     * @param string $reason Model reason or refusal code
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $clientUploadId,
        public readonly bool $allow,
        public readonly string $reason,
    ) {
    }

    /** @return array{acceptKey: string, clientUploadId: string, allow: bool, reason: string} Transport payload */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::clientUploadId => $this->clientUploadId,
            self::allow => $this->allow,
            self::reason => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Restored verdict
     * @throws InvalidFormatException When any field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::acceptKey),
            self::requireString($data, self::clientUploadId),
            self::requireBool($data, self::allow),
            self::requireString($data, self::reason),
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Moderation\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Moderator → the verdict recipient of a kind: the verdict on one check (HIL-1625).
 *
 * Sent as {@see HilosSignalConstants::HILOS_MODERATION_VERDICT}. The connection and the kind name
 * the check, and the request id names the attempt: a recipient whose check holds another request
 * id by now has nothing to apply it to.
 */
final class ModerationVerdictSignalData extends BaseDTO implements SignalDataInterface
{
    public const string acceptKey = 'acceptKey';
    public const string kind = 'kind';
    public const string requestId = 'requestId';
    public const string allow = 'allow';
    public const string reason = 'reason';

    /**
     * @param string $acceptKey Connection awaiting the verdict
     * @param string $kind Moderation kind key of the check
     * @param string $requestId Request id of the attempt the verdict answers
     * @param bool $allow Whether the content passed
     * @param string $reason Reason code of the verdict
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $kind,
        public readonly string $requestId,
        public readonly bool $allow,
        public readonly string $reason,
    ) {
    }

    /** @return array{acceptKey: string, kind: string, requestId: string, allow: bool, reason: string} Transport payload */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::kind => $this->kind,
            self::requestId => $this->requestId,
            self::allow => $this->allow,
            self::reason => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Restored verdict
     * @throws InvalidFormatException When a field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::acceptKey),
            self::requireString($data, self::kind),
            self::requireString($data, self::requestId),
            self::requireBool($data, self::allow),
            self::requireString($data, self::reason),
        );
    }
}

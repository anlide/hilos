<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/** Read-only preview; opening a letter never deletes or spends a subscription. */
final class StatusUnsubscribePreviewReplyDTO extends ActionReplyDTO
{
    public const string maskedEmail = 'maskedEmail';

    /** @param string $maskedEmail Masked recipient, never the complete address */
    public function __construct(public readonly string $maskedEmail)
    {
    }

    /** @return array<string, string> Preview's approved field */
    public function toArray(): array
    {
        return [self::maskedEmail => $this->maskedEmail];
    }

    /**
     * @param array<string, mixed> $data Serialized preview
     * @return static Restored preview
     * @throws InvalidFormatException When the mask is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::maskedEmail));
    }
}

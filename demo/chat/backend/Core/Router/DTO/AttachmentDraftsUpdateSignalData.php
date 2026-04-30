<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Server → client: full attachment draft list for the current connection.
 */
final class AttachmentDraftsUpdateSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param list<array<string, mixed>> $attachmentDrafts Draft rows
     */
    public function __construct(
        public readonly array $attachmentDrafts,
    ) {
    }

    /**
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return ['attachmentDrafts' => $this->attachmentDrafts];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $drafts = $data['attachmentDrafts'] ?? [];

        return new static(attachmentDrafts: is_array($drafts) ? array_values($drafts) : []);
    }
}

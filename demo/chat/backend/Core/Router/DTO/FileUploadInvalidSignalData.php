<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

final class FileUploadInvalidSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $reason,
    ) {
    }

    public function toArray(): array
    {
        return ['reason' => $this->reason];
    }

    public static function fromArray(array $data): static
    {
        return new static(reason: (string)($data['reason'] ?? ''));
    }
}

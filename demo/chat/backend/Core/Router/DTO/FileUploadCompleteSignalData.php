<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

final class FileUploadCompleteSignalData extends BaseDTO implements SignalDataInterface
{
    public function toArray(): array
    {
        return [];
    }

    public static function fromArray(array $data): static
    {
        return new static();
    }
}

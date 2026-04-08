<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

final class FileUploadRejectedSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            code: (string)($data['code'] ?? ''),
            message: (string)($data['message'] ?? ''),
        );
    }
}

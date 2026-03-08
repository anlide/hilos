<?php

declare(strict_types=1);

namespace Hilos\Guardian\DTO;

use Hilos\BaseDTO;

final class CapabilityResult extends BaseDTO
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $data = [],
        public readonly ?string $error = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'data' => $this->data,
            'error' => $this->error,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            ok: (bool) ($data['ok'] ?? false),
            data: is_array($data['data'] ?? null) ? $data['data'] : [],
            error: isset($data['error']) ? (string) $data['error'] : null,
        );
    }
}

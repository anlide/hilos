<?php

declare(strict_types=1);

namespace Hilos\Guardian\DTO;

use Hilos\BaseDTO;

/**
 * Capability result DTO.
 *
 * Wraps result of a guardian capability check (ok, data, optional error).
 */
final class CapabilityResult extends BaseDTO
{
    /**
     * Creates capability result instance.
     *
     * @param bool $ok Whether capability check passed
     * @param array<string, mixed> $data Result data (default empty)
     * @param ?string $error Error message if check failed
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $data = [],
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data with ok, data, error keys
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'data' => $this->data,
            'error' => $this->error,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (ok, data, error keys)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            ok: (bool) ($data['ok'] ?? false),
            data: is_array($data['data'] ?? null) ? $data['data'] : [],
            error: isset($data['error']) ? (string) $data['error'] : null,
        );
    }
}

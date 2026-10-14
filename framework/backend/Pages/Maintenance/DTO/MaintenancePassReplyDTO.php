<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/** Clear code carried only by the originating tracked action's success. */
final class MaintenancePassReplyDTO extends ActionReplyDTO
{
    /**
     * @param string $pass Clear code whose hash has landed
     * @throws InvalidFormatException When the code is empty
     */
    public function __construct(public readonly string $pass)
    {
        if ($pass === '') {
            throw new InvalidFormatException('A code reply requires a nonempty pass');
        }
    }

    /** @return array<string, mixed> Domain reply for the originating browser */
    public function toArray(): array
    {
        return ['pass' => $this->pass];
    }

    /**
     * @param array<string, mixed> $data Domain reply payload
     * @return static Validated code reply
     * @throws InvalidFormatException When the code is missing, mistyped or empty
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'pass'));
    }
}

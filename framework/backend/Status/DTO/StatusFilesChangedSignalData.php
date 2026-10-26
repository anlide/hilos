<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/** Successful public file publication hint; carries no status or private facts. */
final class StatusFilesChangedSignalData extends BaseDTO implements SignalDataInterface
{
    /** @return array<string, mixed> Empty IPC payload; the browser encoder materializes an object */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data Empty transport payload
     * @return static Stateless publication hint
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * {@see AnalyticsJournalAgent} → {@see AnalyticsWriterAgent}: this node has a ready file (HIL-1155).
 */
final class AnalyticsJournalReadySignalData extends BaseDTO implements SignalDataInterface
{
    public const string nodeId = 'nodeId';

    /**
     * @param ?string $nodeId Node whose journal has a ready file, null outside a cluster
     */
    public function __construct(public readonly ?string $nodeId)
    {
    }

    /**
     * @return array<string, mixed> Notice as it goes to the writer
     */
    public function toArray(): array
    {
        return [self::nodeId => $this->nodeId];
    }

    /**
     * @param array<string, mixed> $data Wire form of the notice
     * @return static Restored notice
     * @throws InvalidFormatException When the node id has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(self::optionalString($data, self::nodeId));
    }
}

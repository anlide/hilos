<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerPageAccessReassessConnectionsMessageDTO - re-decision announcement by connection (HIL-652).
 *
 * Travels both ways over the worker link exactly as its twin
 * {@see WorkerPageAccessReassessMessageDTO} does: the worker that ended a session emits it to
 * its own daemon, and the daemon fans the same frame back out to every worker of the node,
 * each of which sweeps its own subscription mirror
 * ({@see PageAccessReassessment::sweepThisWorkerConnections()}).
 *
 * It is a frame of its own rather than a criterion field on the twin because the two name
 * different things, and a worker holding one merged shape would have to establish which
 * question it had been asked before it could answer it.
 */
class WorkerPageAccessReassessConnectionsMessageDTO extends WorkerDTO
{
    /** @var string Frame key carrying the accept keys whose pages are to be re-judged */
    public const string FIELD_ACCEPT_KEYS = 'acceptKeys';

    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PAGE_ACCESS_REASSESS_CONNECTIONS;

    /**
     * @param list<string> $acceptKeys Accept keys whose open pages are to be re-judged
     */
    public function __construct(
        public readonly array $acceptKeys,
    ) {
    }

    /**
     * Returns message type.
     *
     * @return string Message type identifier.
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array.
     */
    public function toArray(): array
    {
        return [
            self::TYPE => $this->getType(),
            self::FIELD_ACCEPT_KEYS => $this->acceptKeys,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (acceptKeys).
     * @return static DTO instance
     * @throws InvalidFormatException When the frame carries no key list
     */
    public static function fromArray(array $data): static
    {
        $acceptKeys = [];
        foreach (self::requireArray($data, self::FIELD_ACCEPT_KEYS) as $acceptKey) {
            if (is_string($acceptKey) && $acceptKey !== '') {
                $acceptKeys[] = $acceptKey;
            }
        }

        return new static(
            acceptKeys: $acceptKeys,
        );
    }
}

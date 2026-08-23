<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerPageAccessReassessMessageDTO - access re-decision announcement frame (HIL-644).
 *
 * Travels both ways over the worker link: the worker that wrote a person's rights emits it to
 * its own daemon, and the daemon fans the same frame back out to every worker of the node.
 * Each of them then sweeps its own subscription mirror
 * ({@see PageAccessReassessment::sweepThisWorker()}) - the master resolves nobody, because it
 * has no browser context to ask who is behind a connection.
 *
 * It is deliberately not an agent message: its addressee is "every worker of this node", which
 * only the master can address, and no agent is named anywhere on the path.
 */
class WorkerPageAccessReassessMessageDTO extends WorkerDTO
{
    /** @var string Frame key carrying the user whose rights changed */
    public const string FIELD_USER_ID = 'userId';

    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PAGE_ACCESS_REASSESS_USER;

    /**
     * @param int $userId Durable user id whose rights just changed
     */
    public function __construct(
        public readonly int $userId,
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
            self::FIELD_USER_ID => $this->userId,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (userId).
     * @return static DTO instance.
     * @throws InvalidFormatException When the frame names no user
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::FIELD_USER_ID),
        );
    }
}

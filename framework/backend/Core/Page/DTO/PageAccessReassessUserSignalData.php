<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\SignalDataInterface;

/**
 * PageAccessReassessUserSignalData - the access re-decision announcement's payload (HIL-644).
 *
 * It carries a person and not a page, because the announcing worker knows neither: the pages
 * of one person are spread across every worker of the node, and which of them belong to whom
 * is answered where identity is resolved. Each receiving worker turns this one field into as
 * many re-decision frames as its own mirror holds
 * ({@see PageAccessReassessment::sweepThisWorker()}).
 */
final class PageAccessReassessUserSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the durable user id whose rights changed. */
    public const string userId = 'userId';

    /**
     * @param int $userId Durable user id whose rights just changed
     */
    public function __construct(
        public readonly int $userId,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no user
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
        );
    }
}

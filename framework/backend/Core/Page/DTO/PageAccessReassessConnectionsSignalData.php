<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\SignalDataInterface;

/**
 * PageAccessReassessConnectionsSignalData - the by-connection announcement's payload (HIL-652).
 *
 * It carries accept keys where its twin {@see PageAccessReassessUserSignalData} carries a
 * person, and that difference is the whole reason it exists: a downgrade REMOVES the identity
 * the person criterion matches on, while an accept key names the same socket before and after
 * the write. Each receiving worker intersects the list with its own subscription mirror
 * ({@see PageAccessReassessment::sweepThisWorkerConnections()}); a key no worker holds is
 * simply matched by nobody.
 */
final class PageAccessReassessConnectionsSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the accept keys whose open pages are to be re-judged. */
    public const string acceptKeys = 'acceptKeys';

    /**
     * @param list<string> $acceptKeys Accept keys whose open pages are to be re-judged
     */
    public function __construct(
        public readonly array $acceptKeys,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::acceptKeys => $this->acceptKeys,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no key list
     */
    public static function fromArray(array $data): static
    {
        $acceptKeys = [];
        foreach (self::requireArray($data, self::acceptKeys) as $acceptKey) {
            if (is_string($acceptKey) && $acceptKey !== '') {
                $acceptKeys[] = $acceptKey;
            }
        }

        return new static(
            acceptKeys: $acceptKeys,
        );
    }
}

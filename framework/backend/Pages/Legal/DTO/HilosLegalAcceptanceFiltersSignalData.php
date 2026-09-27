<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Complete filter vocabulary, independent of the acceptance window and catalog validity. */
final class HilosLegalAcceptanceFiltersSignalData extends BaseDTO implements SignalDataInterface
{
    public const string documents = 'documents';
    public const string document = 'document';
    public const string declared = 'declared';
    public const string revisions = 'revisions';
    public const string revisionId = 'revisionId';

    /**
     * @param list<array{document: string, declared: ?bool, revisions: list<array{revisionId: string, declared: ?bool}>}> $documents Wire entries
     */
    public function __construct(public readonly array $documents)
    {
    }

    /** @return array<string, mixed> Filter vocabulary on the page's subscription signal */
    public function toArray(): array
    {
        return [self::documents => $this->documents];
    }

    /**
     * @param array<string, mixed> $data Serialized filter vocabulary
     * @return static Parsed filter vocabulary
     * @throws InvalidFormatException When a required entry is missing or malformed
     */
    public static function fromArray(array $data): static
    {
        $documents = [];
        foreach (self::requireArray($data, self::documents) as $entry) {
            if (!is_array($entry)) {
                throw new InvalidFormatException('Legal acceptance filter document must be an object');
            }
            $revisions = [];
            foreach (self::requireArray($entry, self::revisions) as $revision) {
                if (!is_array($revision)) {
                    throw new InvalidFormatException('Legal acceptance filter revision must be an object');
                }
                $revisions[] = [
                    self::revisionId => self::requireString($revision, self::revisionId),
                    self::declared => self::optionalBool($revision, self::declared),
                ];
            }
            $documents[] = [
                self::document => self::requireString($entry, self::document),
                self::declared => self::optionalBool($entry, self::declared),
                self::revisions => $revisions,
            ];
        }

        return new static($documents);
    }
}

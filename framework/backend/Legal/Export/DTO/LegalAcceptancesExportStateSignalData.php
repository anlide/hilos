<?php

declare(strict_types=1);

namespace Hilos\Legal\Export\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * One administrator's file of acceptance records (HIL-1234); moments are server epoch milliseconds.
 *
 * The node is the same in the page response of the acceptances page and in the signal that follows it;
 * null says the administrator has no export.
 */
final class LegalAcceptancesExportStateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string legalAcceptancesExport = 'legalAcceptancesExport';
    public const string state = 'state';
    public const string document = 'document';
    public const string revisionId = 'revisionId';
    public const string search = 'search';
    public const string requestedAt = 'requestedAt';
    public const string finishedAt = 'finishedAt';
    public const string expiresAt = 'expiresAt';
    public const string sizeBytes = 'sizeBytes';
    public const string records = 'records';

    /**
     * @param ?array{
     *     state: string,
     *     document: ?string,
     *     revisionId: ?string,
     *     search: ?string,
     *     requestedAt: int,
     *     finishedAt: ?int,
     *     expiresAt: ?int,
     *     sizeBytes: ?int,
     *     records: ?int,
     * } $legalAcceptancesExport Export state
     */
    public function __construct(public readonly ?array $legalAcceptancesExport)
    {
    }

    /**
     * @return array<string, mixed> Wire form without any storage name
     */
    public function toArray(): array
    {
        return [self::legalAcceptancesExport => $this->legalAcceptancesExport];
    }

    /**
     * @param array<string, mixed> $data Wire form
     * @return static Restored state
     * @throws InvalidFormatException When a state field has the wrong type or a required field is missing
     */
    public static function fromArray(array $data): static
    {
        $node = self::optionalArray($data, self::legalAcceptancesExport);

        return new static($node === null ? null : [
            self::state => self::requireString($node, self::state),
            self::document => self::optionalString($node, self::document),
            self::revisionId => self::optionalString($node, self::revisionId),
            self::search => self::optionalString($node, self::search),
            self::requestedAt => self::requireInt($node, self::requestedAt),
            self::finishedAt => self::optionalInt($node, self::finishedAt),
            self::expiresAt => self::optionalInt($node, self::expiresAt),
            self::sizeBytes => self::optionalInt($node, self::sizeBytes),
            self::records => self::optionalInt($node, self::records),
        ]);
    }
}

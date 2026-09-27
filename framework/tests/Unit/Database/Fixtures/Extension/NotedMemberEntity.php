<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Fixtures\Extension;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Item\VerifierCircleMember;
use Hilos\Database\PhpType;

/**
 * The Entity of a test project's chain over the framework's verifier circle: the framework's row
 * plus one column of the project's, `note`, NULL in the table and nullified on restore.
 *
 * The metadata is composed from the base's and never restated (inheritance.md, *Metadata And
 * Verdicts*): the three lists the new column joins start from the parent's, and `_table`,
 * `_primary`, `_indexes`, `_setVia`, `_setRoot` and `_piiNotPersonal` are inherited as they are.
 * The chain is what FrameworkExtensionMountTest mounts and the framework's extension integration
 * test writes through, and the green case the start guard's own test (HIL-1191) takes.
 */
final class NotedMemberEntity extends VerifierCircleMember
{
    public const string note = 'note';

    public const array _columns = [...parent::_columns, self::note];

    public const array _types = [...parent::_types, self::note => PhpType::STRING->value];

    public const array _pii = [...parent::_pii, self::note => AnonymizationStrategy::NULLIFY];

    public ?string $note = null;
}

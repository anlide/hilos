<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Unit tests for the one gate an exception passes to become text a client may read:
 * the ValidationException family speaks for itself, everything else is replaced by the
 * placeholder, and the class name is offered short for an administrator to quote.
 */
final class ActionFailureReasonTest extends TestCase
{
    public function testARuleRefusalTravelsInItsOwnWords(): void
    {
        $refusal = new ValidationException('Value must be an integer of 0 or more');

        $this->assertTrue(ActionFailureReason::isPersonFacing($refusal));
        $this->assertSame('Value must be an integer of 0 or more', ActionFailureReason::forClient($refusal));
    }

    public function testATableActionRefusalTravelsToo(): void
    {
        $refusal = new TableActionException('This user is already a moderator');

        $this->assertTrue(ActionFailureReason::isPersonFacing($refusal));
        $this->assertSame('This user is already a moderator', ActionFailureReason::forClient($refusal));
    }

    public function testAStorageFailureIsReplacedByThePlaceholder(): void
    {
        $failure = new DatabaseException('SQLSTATE[42S02]: Base table or view not found: hilos_setting');

        $this->assertFalse(ActionFailureReason::isPersonFacing($failure));
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, ActionFailureReason::forClient($failure));
    }

    public function testAFrameworkSentenceOutsideTheFamilyIsReplacedToo(): void
    {
        // The case the gate was narrowed for: it reads as a sentence and is a framework
        // exception, so the old gate let it through for being neither a database error nor
        // a foreign class - and it describes a broken catalog, not anything the caller did.
        $failure = new SettingInvalidValueException("Setting 'logs.write_level' value is not a valid integer");

        $this->assertFalse(ActionFailureReason::isPersonFacing($failure));
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, ActionFailureReason::forClient($failure));
    }

    public function testAnEngineFaultIsReplacedAsWell(): void
    {
        $this->assertSame(
            SignalConstants::ACTION_FAILED_REASON,
            ActionFailureReason::forClient(new TypeError('Argument #1 must be of type int, string given')),
        );
    }

    public function testTheTypeIsNamedWithoutItsNamespace(): void
    {
        $this->assertSame('DatabaseException', ActionFailureReason::typeOf(new DatabaseException('any')));
        $this->assertSame('TypeError', ActionFailureReason::typeOf(new TypeError('any')));
    }
}

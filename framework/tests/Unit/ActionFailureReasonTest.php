<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Exception\PersonFacingFailure;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\Exception\ActionImpersonationException;
use Hilos\Core\Page\Exception\ActionRateLimitedException;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\PageException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

/**
 * Unit tests for the one gate an exception passes to become text a client may read:
 * marked failures speak for themselves, unmarked failures are replaced by the
 * placeholder, and the class name is offered short for an administrator to quote.
 */
final class ActionFailureReasonTest extends TestCase
{
    /**
     * @return iterable<string, array{PageException, string, string, int, ?int}> Guard refusals and their unchanged metadata
     */
    public static function publicRefusals(): iterable
    {
        yield 'rate limited' => [
            new ActionRateLimitedException(37),
            'Too many attempts. Please wait a moment and try again.',
            'rate_limited',
            429,
            37,
        ];
        yield 'view mode' => [
            new ActionViewModeException(),
            'View mode: you can look around, but not change anything.',
            'view_mode',
            403,
            null,
        ];
        yield 'impersonation view only' => [
            new ActionImpersonationException(ActionImpersonationException::CODE_VIEW_ONLY),
            "View only: nothing can be changed in someone else's account.",
            'impersonation_view_only',
            403,
            null,
        ];
        yield 'impersonation account access' => [
            new ActionImpersonationException(ActionImpersonationException::CODE_ACCOUNT_ACCESS),
            "This is not available while you work in someone else's account",
            'impersonated',
            403,
            null,
        ];
    }

    /**
     * @param PageException $refusal Guard refusal
     * @param string $message Exact public sentence
     * @param string $errorCode Existing machine code
     * @param int $httpCode Existing status semantics
     * @param ?int $retryAfter Existing retry hint, or null
     */
    #[DataProvider('publicRefusals')]
    public function testGuardRefusalsCarryTheirExactPublicSentences(
        PageException $refusal,
        string $message,
        string $errorCode,
        int $httpCode,
        ?int $retryAfter,
    ): void {
        $this->assertInstanceOf(PersonFacingFailure::class, $refusal);
        $this->assertTrue(ActionFailureReason::isPersonFacing($refusal));
        $this->assertSame($message, $refusal->getMessage());
        $this->assertSame($message, ActionFailureReason::forClient($refusal));
        $this->assertSame($errorCode, $refusal->errorCode);
        $this->assertSame($httpCode, $refusal->getCode());
        if ($refusal instanceof ActionRateLimitedException) {
            $this->assertSame($retryAfter, $refusal->retryAfter);
        }
    }

    /** The marker declares public text independently of the exception's base class. */
    public function testAMarkedFailureOutsideValidationSpeaksForItself(): void
    {
        $refusal = new class ('This operation is closed for today') extends RuntimeException implements PersonFacingFailure {
        };

        $this->assertTrue(ActionFailureReason::isPersonFacing($refusal));
        $this->assertSame('This operation is closed for today', ActionFailureReason::forClient($refusal));
    }

    /** Explicit messages and previous exceptions keep their existing constructor positions. */
    public function testExplicitMessagesAndExceptionChainsArePreserved(): void
    {
        $previous = new RuntimeException('Internal cause');
        $refusals = [
            new ActionRateLimitedException(37, 'Please try later', ActionRateLimitedException::ERROR_CODE, $previous),
            new ActionViewModeException('Please try later', ActionViewModeException::ERROR_CODE, $previous),
            new ActionImpersonationException(ActionImpersonationException::CODE_VIEW_ONLY, 'Please try later', $previous),
            new ActionImpersonationException(ActionImpersonationException::CODE_ACCOUNT_ACCESS, 'Please try later', $previous),
        ];

        foreach ($refusals as $refusal) {
            $this->assertSame('Please try later', ActionFailureReason::forClient($refusal));
            $this->assertSame($previous, $refusal->getPrevious());
        }
    }

    /** A public machine code never grants an unmarked exception permission to disclose its text. */
    public function testAnAssignedErrorCodeDoesNotPublishDiagnosticText(): void
    {
        foreach (['rate_limited', 'view_mode', 'impersonation_view_only', 'impersonated'] as $errorCode) {
            $failures = [
                new class ('Diagnostic: /private/config.php') extends PageException {
                    public string $errorCode;
                },
                new class ('Diagnostic: /private/config.php') extends RuntimeException {
                    public string $errorCode;
                },
                new class ('Diagnostic: /private/config.php') extends DatabaseException {
                    public string $errorCode;
                },
            ];

            foreach ($failures as $failure) {
                $failure->errorCode = $errorCode;

                $this->assertFalse(ActionFailureReason::isPersonFacing($failure));
                $this->assertSame(SignalConstants::ACTION_FAILED_REASON, ActionFailureReason::forClient($failure));
            }
        }
    }

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

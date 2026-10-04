<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Router\DTO;

use Hilos\Auth\Code\DTO\CodeSendReplyDTO;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\RelayedActionReplyDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for a reply sent on by the agent that holds the socket (HIL-1186).
 *
 * The project agent answers the actions the sessions library deferred, and it used to rebuild
 * every such reply as a sign-in outcome on the way. A profile window's code-send reply is not
 * one: it carries no success flag, the rebuild threw, the worker swallowed the frame, and the
 * window waited on an answer that never came. The relay now carries whatever it was given.
 */
final class RelayedActionReplyDTOTest extends TestCase
{
    private const int RESEND_AT = 1_760_000_060_000;
    private const int EXPIRES_AT = 1_760_000_900_000;
    private const string STEP = 'code';
    private const string INTENT = 'register';

    public function testACodeSendReplyTheSignInOutcomeRefusesIsRelayedUnchanged(): void
    {
        $wire = new CodeSendReplyDTO(false, self::RESEND_AT, null)->toArray();

        try {
            AuthFlowOutcome::fromArray($wire);
            self::fail('A code-send reply carries no success flag, so the sign-in outcome must refuse it');
        } catch (InvalidFormatException) {
            // The rebuild the relay no longer does.
        }

        self::assertSame($wire, RelayedActionReplyDTO::fromArray($wire)->toArray());
    }

    public function testASignInOutcomeIsRelayedUnchanged(): void
    {
        $wire = AuthFlowOutcome::moveTo(self::STEP, self::INTENT, self::RESEND_AT, self::EXPIRES_AT)->toArray();

        self::assertSame($wire, new RelayedActionReplyDTO($wire)->toArray());
    }
}

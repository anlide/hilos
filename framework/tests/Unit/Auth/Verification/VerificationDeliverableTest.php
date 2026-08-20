<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Verification;

use Hilos\Auth\Verification\VerificationDeliverable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what an issued challenge hands its transport (HIL-606).
 *
 * Locks the two shapes apart, because every deliverer branches on the link rather than
 * on the verification type: a lone code carries none, and a magic-link letter carries
 * both halves. A code that arrived with a link, or a link that lost its code, would each
 * mail a way in that does not work.
 */
final class VerificationDeliverableTest extends TestCase
{
    public function testACodeCarriesNoLink(): void
    {
        $deliverable = VerificationDeliverable::code('123456');

        self::assertSame('123456', $deliverable->code);
        self::assertNull($deliverable->link);
    }

    public function testAMagicLinkCarriesBothHalvesOfTheLetter(): void
    {
        $deliverable = VerificationDeliverable::magicLink('https://app.example/auth/magic?t=abc', '135790');

        self::assertSame('https://app.example/auth/magic?t=abc', $deliverable->link);
        self::assertSame('135790', $deliverable->code);
    }

    public function testTheUrlIsNeverMistakenForTheCode(): void
    {
        $deliverable = VerificationDeliverable::magicLink('https://app.example/auth/magic?t=abc', '135790');

        self::assertNotSame($deliverable->link, $deliverable->code);
    }
}

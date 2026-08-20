<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth;

use Hilos\Auth\Verification\NotificationVerificationDeliverer;
use Hilos\Auth\Verification\VerificationDeliverable;
use Hilos\Auth\Verification\VerificationDeliverer;
use Hilos\Database\Verification\VerificationType;
use PHPUnit\Framework\TestCase;

/**
 * Tests the routing verification deliverer dispatches by channel (HIL-285).
 *
 * {@see NotificationVerificationDeliverer} sends the SMS types to its SMS deliverer and every
 * other type to its mail deliverer, so a project gets phone codes texted and email codes mailed
 * with no wiring.
 */
final class NotificationVerificationDelivererTest extends TestCase
{
    public function testSmsTypesRouteToTheSmsDeliverer(): void
    {
        $mail = new RecordingVerificationDeliverer();
        $sms = new RecordingVerificationDeliverer();
        $router = new NotificationVerificationDeliverer($mail, $sms);

        $router->deliver('+15551234567', VerificationType::SMS_LOGIN, VerificationDeliverable::code('111'));
        $router->deliver('+15551234567', VerificationType::SMS_ADD, VerificationDeliverable::code('222'));

        self::assertSame([VerificationType::SMS_LOGIN, VerificationType::SMS_ADD], $sms->types);
        self::assertSame([], $mail->types);
    }

    public function testEmailTypesRouteToTheMailDeliverer(): void
    {
        $mail = new RecordingVerificationDeliverer();
        $sms = new RecordingVerificationDeliverer();
        $router = new NotificationVerificationDeliverer($mail, $sms);

        $router->deliver('user@example.com', VerificationType::REGISTER_CONFIRM, VerificationDeliverable::code('333'));
        $router->deliver(
            'user@example.com',
            VerificationType::MAGIC_LINK,
            VerificationDeliverable::magicLink('https://app.example/auth/magic?t=token', '135790'),
        );

        self::assertSame([VerificationType::REGISTER_CONFIRM, VerificationType::MAGIC_LINK], $mail->types);
        self::assertSame([], $sms->types);
    }
}

/**
 * Verification deliverer double that records the types it was asked to deliver.
 */
final class RecordingVerificationDeliverer implements VerificationDeliverer
{
    /** @var list<string> Verification types delivered through this double. */
    public array $types = [];

    public function deliver(string $identifier, string $type, VerificationDeliverable $deliverable): void
    {
        $this->types[] = $type;
    }
}

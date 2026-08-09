<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail;

use Hilos\Core\Exception\ValidationException;
use Hilos\Mail\DTO\MailSendSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Tests the raw-send DTO array round-trip required for agent-signal transport (HIL-197).
 */
final class MailSendSignalDataTest extends TestCase
{
    public function testTemplateVariantRoundTripsThroughArray(): void
    {
        $original = new MailSendSignalData(
            to: 'user@example.com',
            shardKey: 3,
            templateKey: 'auth.register_confirm',
            params: ['code' => '123456', 'nested' => ['x' => 1]],
            locale: 'en',
        );

        $restored = MailSendSignalData::fromArray($original->toArray());

        $this->assertEquals($original, $restored);
    }

    public function testInlineVariantRoundTripsThroughArray(): void
    {
        $original = new MailSendSignalData(
            to: 'user@example.com',
            shardKey: 1,
            subject: 'Hi',
            text: 'plain',
            html: '<b>rich</b>',
        );

        $restored = MailSendSignalData::fromArray($original->toArray());

        $this->assertEquals($original, $restored);
    }

    public function testFromArrayCoercesTypesAndDefaults(): void
    {
        $restored = MailSendSignalData::fromArray([
            MailSendSignalData::to => 'user@example.com',
            MailSendSignalData::shardKey => '7',
            MailSendSignalData::templateKey => 'auth.register_confirm',
        ]);

        $this->assertSame('user@example.com', $restored->to);
        $this->assertSame(7, $restored->shardKey);
        $this->assertNull($restored->subject);
        $this->assertSame('auth.register_confirm', $restored->templateKey);
        $this->assertSame([], $restored->params);
        $this->assertNull($restored->locale);
    }

    public function testAPayloadWithNeitherTemplateNorInlineContentIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        MailSendSignalData::fromArray([
            MailSendSignalData::to => 'user@example.com',
            MailSendSignalData::shardKey => '7',
        ]);
    }

    public function testAnInlinePayloadCarryingOnlyASubjectIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        new MailSendSignalData(to: 'user@example.com', shardKey: 1, subject: 'Hi');
    }
}

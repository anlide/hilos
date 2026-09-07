<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail;

use Hilos\Core\Exception\InvalidFormatException;
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

    public function testTheProgressTicketSurvivesTheRoundTripAndIsOptional(): void
    {
        $watched = new MailSendSignalData(
            to: 'user@example.com',
            shardKey: 1,
            subject: 'Hi',
            text: 'plain',
            progressTicket: 'a1b2c3d4e5f60718',
        );

        // The ticket is the only thing this subsystem learns about who ordered the letter
        // (HIL-826), so it has to survive the boundary; a letter nobody is watching carries
        // none, which is most of them.
        self::assertEquals($watched, MailSendSignalData::fromArray($watched->toArray()));
        self::assertNull(MailSendSignalData::fromArray(
            new MailSendSignalData(to: 'user@example.com', shardKey: 1, subject: 'Hi', text: 'plain')->toArray(),
        )->progressTicket);
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

    public function testFromArrayCarriesTheFieldsATemplateSendLeavesOutAsNull(): void
    {
        $restored = MailSendSignalData::fromArray([
            MailSendSignalData::to => 'user@example.com',
            MailSendSignalData::shardKey => 7,
            MailSendSignalData::templateKey => 'auth.register_confirm',
            MailSendSignalData::params => [],
        ]);

        $this->assertSame('user@example.com', $restored->to);
        $this->assertSame(7, $restored->shardKey);
        $this->assertNull($restored->subject);
        $this->assertSame('auth.register_confirm', $restored->templateKey);
        $this->assertSame([], $restored->params);
        $this->assertNull($restored->locale);
    }

    public function testAPayloadThatLostTheShardKeyIsRefusedInsteadOfPooledAtZero(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(MailSendSignalData::shardKey);

        MailSendSignalData::fromArray([
            MailSendSignalData::to => 'user@example.com',
            MailSendSignalData::templateKey => 'auth.register_confirm',
            MailSendSignalData::params => [],
        ]);
    }

    public function testAShardKeyWrittenAsTextIsRefusedRatherThanRead(): void
    {
        $this->expectException(InvalidFormatException::class);

        MailSendSignalData::fromArray([
            MailSendSignalData::to => 'user@example.com',
            MailSendSignalData::shardKey => '7',
            MailSendSignalData::templateKey => 'auth.register_confirm',
            MailSendSignalData::params => [],
        ]);
    }

    public function testAPayloadWithNeitherTemplateNorInlineContentIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        MailSendSignalData::fromArray([
            MailSendSignalData::to => 'user@example.com',
            MailSendSignalData::shardKey => 7,
            MailSendSignalData::params => [],
        ]);
    }

    public function testABlankRecipientIsRefusedBeforeItReachesTheEnvelope(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('recipient address');

        new MailSendSignalData(to: '', shardKey: 1, subject: 'Hi', text: 'plain');
    }

    public function testAnInlinePayloadCarryingOnlyASubjectIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        new MailSendSignalData(to: 'user@example.com', shardKey: 1, subject: 'Hi');
    }
}

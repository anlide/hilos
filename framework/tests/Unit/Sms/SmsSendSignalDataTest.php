<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Sms;

use Hilos\Sms\DTO\SmsSendSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Tests the raw-send handoff DTO roundtrips through its array form (HIL-285).
 *
 * {@see SmsSendSignalData} crosses the worker->agent process boundary as a plain array, so
 * {@see SmsSendSignalData::fromArray()} must rebuild exactly what {@see SmsSendSignalData::toArray()}
 * produced - both the inline-text and the templated shapes.
 */
final class SmsSendSignalDataTest extends TestCase
{
    public function testTemplatedPayloadRoundtrips(): void
    {
        $original = new SmsSendSignalData(
            to: '+15551234567',
            shardKey: 3,
            templateKey: 'auth.sms_login',
            params: ['code' => '123456'],
            locale: 'en',
        );

        $restored = SmsSendSignalData::fromArray($original->toArray());

        self::assertSame('+15551234567', $restored->to);
        self::assertSame(3, $restored->shardKey);
        self::assertNull($restored->text);
        self::assertSame('auth.sms_login', $restored->templateKey);
        self::assertSame(['code' => '123456'], $restored->params);
        self::assertSame('en', $restored->locale);
    }

    public function testInlineTextPayloadRoundtrips(): void
    {
        $original = new SmsSendSignalData(
            to: '+441234567890',
            shardKey: 1,
            text: 'Your code is 999',
        );

        $restored = SmsSendSignalData::fromArray($original->toArray());

        self::assertSame('+441234567890', $restored->to);
        self::assertSame(1, $restored->shardKey);
        self::assertSame('Your code is 999', $restored->text);
        self::assertNull($restored->templateKey);
        self::assertSame([], $restored->params);
        self::assertNull($restored->locale);
    }

    public function testFromArrayCoercesLooseTypes(): void
    {
        $restored = SmsSendSignalData::fromArray([
            SmsSendSignalData::to => '+10000000000',
            SmsSendSignalData::shardKey => '7',
            SmsSendSignalData::params => 'not-an-array',
        ]);

        self::assertSame('+10000000000', $restored->to);
        self::assertSame(7, $restored->shardKey);
        self::assertSame([], $restored->params);
    }
}

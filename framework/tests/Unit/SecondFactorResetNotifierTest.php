<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\SecondFactor\SecondFactorNotificationType;
use Hilos\Auth\SecondFactor\SecondFactorResetNotifier;
use Hilos\Hilos;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\NotificationDraft;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the cancel link a second-factor removal repeats (HIL-1302).
 *
 * The first notice and the reminder are asked with one token. What is pinned is that both
 * carry that token's link in the body and in data.url, using the notifier's own link builder
 * rather than a second copy of it.
 */
final class SecondFactorResetNotifierTest extends TestCase
{
    /** Token both notices are asked with. */
    private const string TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /** Person both notices are addressed to. */
    private const int USER_ID = 7;

    /** Moment both notices name (Unix seconds). */
    private const int EFFECTIVE_AT_SEC = 1800000000;

    /** @var ?HilosNotifier Notifier the suite had, put back so this file does not decide the next one */
    private ?HilosNotifier $previousNotify = null;

    protected function setUp(): void
    {
        $this->previousNotify = Hilos::$notify;
    }

    protected function tearDown(): void
    {
        Hilos::$notify = $this->previousNotify;

        parent::tearDown();
    }

    /**
     * The reminder carries the same cancel link as the notice that opened the removal.
     */
    public function testTheReminderRepeatsTheFirstNoticeLink(): void
    {
        $capture = new SecondFactorResetNotifierCapture();
        Hilos::$notify = $capture;

        SecondFactorResetNotifier::requested(self::USER_ID, self::TOKEN, self::EFFECTIVE_AT_SEC);
        SecondFactorResetNotifier::reminder(self::USER_ID, self::TOKEN, self::EFFECTIVE_AT_SEC);

        self::assertCount(2, $capture->drafts);
        $requested = $capture->drafts[0];
        $reminder = $capture->drafts[1];
        $url = SecondFactorResetNotifier::cancelUrl(self::TOKEN);
        self::assertSame(SecondFactorNotificationType::RESET_REQUESTED, $requested->type);
        self::assertSame(SecondFactorNotificationType::RESET_REMINDER, $reminder->type);
        self::assertIsArray($requested->data);
        self::assertIsArray($reminder->data);
        self::assertSame($url, $requested->data['url']);
        self::assertSame($url, $reminder->data['url']);
        self::assertStringContainsString($url, (string)$requested->body);
        self::assertStringContainsString($url, (string)$reminder->body);
        self::assertStringContainsString(self::TOKEN, $url);
    }
}

/**
 * Keeps the drafts a removal's notifier emits, instead of queueing them.
 */
final class SecondFactorResetNotifierCapture extends HilosNotifier
{
    /** @var list<NotificationDraft> Drafts emitted, in order */
    public array $drafts = [];

    /**
     * @param NotificationDraft $draft The notification a removal asked to send
     */
    public function emit(NotificationDraft $draft): void
    {
        $this->drafts[] = $draft;
    }
}

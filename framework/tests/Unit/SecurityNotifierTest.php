<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\SecurityNotice\SecurityNotificationType;
use Hilos\Auth\SecurityNotice\SecurityNotifier;
use Hilos\Hilos;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Notification\NotificationTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the security notices of an account (HIL-294).
 *
 * What is pinned is each notice's type, importance, title and the absence of a link, the sign-in
 * text with and without the device and the address, and that no channel setting mutes any of them.
 */
final class SecurityNotifierTest extends TestCase
{
    /** Person every notice is addressed to. */
    private const int USER_ID = 7;

    /** Device name a session carries. */
    private const string DEVICE = 'Firefox on Linux';

    /** Address a session carries. */
    private const string ADDRESS = '203.0.113.9';

    /** @var ?HilosNotifier Notifier the suite had, put back so this file does not decide the next one */
    private ?HilosNotifier $previousNotify = null;

    /** Notifier that keeps the drafts of the case. */
    private SecurityNotifierCapture $capture;

    protected function setUp(): void
    {
        $this->previousNotify = Hilos::$notify;
        $this->capture = new SecurityNotifierCapture();
        Hilos::$notify = $this->capture;
    }

    protected function tearDown(): void
    {
        Hilos::$notify = $this->previousNotify;

        parent::tearDown();
    }

    /**
     * A password changed in the profile is a warning without a link.
     */
    public function testAChangedPasswordIsAWarning(): void
    {
        SecurityNotifier::passwordChanged(self::USER_ID);

        $draft = $this->assertOnlyDraft(SecurityNotificationType::PASSWORD_CHANGED, NotificationSeverity::WARNING, 'Your password was changed');
        self::assertSame(
            'The password of your account was changed. If this was not you, reset your password from the sign-in screen'
                . ' (Forgot password?) right away - that signs out every other session.',
            $draft->body,
        );
    }

    /**
     * A password reset through recovery is a warning without a link.
     */
    public function testAResetPasswordIsAWarning(): void
    {
        SecurityNotifier::passwordReset(self::USER_ID);

        $draft = $this->assertOnlyDraft(SecurityNotificationType::PASSWORD_RESET, NotificationSeverity::WARNING, 'Your password was reset');
        self::assertSame(
            'The password of your account was reset through password recovery, and every other session was signed out.'
                . ' If this was not you, reset it again from the sign-in screen (Forgot password?)'
                . ' and change the password of your email account too.',
            $draft->body,
        );
    }

    /**
     * A sign-in is information, and names the device and the address the session has.
     */
    public function testASignInNamesTheDeviceAndTheAddress(): void
    {
        SecurityNotifier::signedIn(self::USER_ID, self::DEVICE, self::ADDRESS);

        $draft = $this->assertOnlyDraft(SecurityNotificationType::SIGNED_IN, NotificationSeverity::INFO, 'New sign-in to your account');
        self::assertSame(
            'Your account was signed in on ' . self::DEVICE . ' from ' . self::ADDRESS . '. If this was not you, reset your password'
                . ' from the sign-in screen (Forgot password?) - that signs out every other session. You can also end that session'
                . ' in your profile under Sessions.',
            $draft->body,
        );
    }

    /**
     * A device or an address the session does not have leaves its part out whole.
     */
    public function testASignInWithoutDeviceAndAddressLeavesThemOut(): void
    {
        SecurityNotifier::signedIn(self::USER_ID, null, '');

        $draft = $this->assertOnlyDraft(SecurityNotificationType::SIGNED_IN, NotificationSeverity::INFO, 'New sign-in to your account');
        self::assertSame(
            'Your account was signed in. If this was not you, reset your password from the sign-in screen (Forgot password?)'
                . ' - that signs out every other session. You can also end that session in your profile under Sessions.',
            $draft->body,
        );
    }

    /**
     * A device alone is named without the address part.
     */
    public function testASignInWithADeviceOnlyNamesTheDevice(): void
    {
        SecurityNotifier::signedIn(self::USER_ID, self::DEVICE, null);

        $draft = $this->assertOnlyDraft(SecurityNotificationType::SIGNED_IN, NotificationSeverity::INFO, 'New sign-in to your account');
        self::assertStringStartsWith('Your account was signed in on ' . self::DEVICE . '. If this was not you,', (string)$draft->body);
    }

    /**
     * A block is a warning without a link.
     */
    public function testABlockIsAWarning(): void
    {
        SecurityNotifier::accountBlocked(self::USER_ID);

        $draft = $this->assertOnlyDraft(SecurityNotificationType::ACCOUNT_BLOCKED, NotificationSeverity::WARNING, 'Your account was blocked');
        self::assertSame(
            'An administrator blocked your account. You cannot sign in until the block is lifted.'
                . " If you think this is a mistake, contact the service's support.",
            $draft->body,
        );
    }

    /**
     * A lifted block is information without a link.
     */
    public function testALiftedBlockIsInformation(): void
    {
        SecurityNotifier::accountUnblocked(self::USER_ID);

        $draft = $this->assertOnlyDraft(SecurityNotificationType::ACCOUNT_UNBLOCKED, NotificationSeverity::INFO, 'Your account was unblocked');
        self::assertSame('An administrator lifted the block on your account. You can sign in again.', $draft->body);
    }

    /**
     * No channel setting mutes any of the five.
     */
    public function testAllFiveTypesAreMandatory(): void
    {
        foreach (
            [
                SecurityNotificationType::PASSWORD_CHANGED,
                SecurityNotificationType::PASSWORD_RESET,
                SecurityNotificationType::SIGNED_IN,
                SecurityNotificationType::ACCOUNT_BLOCKED,
                SecurityNotificationType::ACCOUNT_UNBLOCKED,
            ] as $type
        ) {
            self::assertTrue(NotificationTypeRegistry::isMandatory($type), $type);
        }
    }

    /**
     * Asserts the case emitted exactly one draft, to the person, with no link and no channel choice.
     *
     * @param string $type Expected notification type
     * @param string $severity Expected importance
     * @param string $title Expected title
     * @return NotificationDraft The one draft
     */
    private function assertOnlyDraft(string $type, string $severity, string $title): NotificationDraft
    {
        self::assertCount(1, $this->capture->drafts);
        $draft = $this->capture->drafts[0];
        self::assertSame(self::USER_ID, $draft->userId);
        self::assertSame($type, $draft->type);
        self::assertSame($severity, $draft->severity);
        self::assertSame($title, $draft->title);
        self::assertNull($draft->data);
        self::assertNull($draft->channels);

        return $draft;
    }
}

/**
 * Keeps the drafts the security notifier emits, instead of queueing them.
 */
final class SecurityNotifierCapture extends HilosNotifier
{
    /** @var list<NotificationDraft> Drafts emitted, in order */
    public array $drafts = [];

    /**
     * @param NotificationDraft $draft The notification a security event asked to send
     */
    public function emit(NotificationDraft $draft): void
    {
        $this->drafts[] = $draft;
    }
}

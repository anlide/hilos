<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use DateTimeImmutable;
use Hilos\Auth\SecondFactor\SecondFactorLockNotifier;
use Hilos\Auth\SecondFactor\SecondFactorNotificationType;
use Hilos\Auth\SecondFactor\SecondFactorResetNotifier;
use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real user Views, including offline browser memory; no session table is created by this harness. */
#[IntegrationTest]
final class ServerTextTimeZoneIntegrationTest extends FrameworkRollbackIntegrationTestCase
{
    /** Fixed rows are inserted before the first ORM read, inside the managed rollback scope. */
    protected function setUpInTransaction(): void
    {
        Database::sqlRun(
            'INSERT INTO hilos_user (id, name, time_zone, last_browser_time_zone) VALUES '
            . "(1, 'Own zone', 'Europe/Warsaw', 'Asia/Tokyo'), "
            . "(2, 'Offline browser', NULL, 'Asia/Tokyo'), "
            . "(3, 'Project fallback', NULL, NULL), "
            . "(4, 'Own alias', 'Europe/Kiev', 'Asia/Tokyo'), "
            . "(5, 'Browser alias', NULL, 'US/Eastern'), "
            . "(6, 'Invalid own zone', '+02:00', 'Asia/Tokyo'), "
            . "(7, 'Invalid browser zone', NULL, 'CEST'), "
            . "(8, 'Both invalid', ' Europe/Warsaw', 'Not/AZone'), "
            . "(9, 'Own UTC', 'UTC', 'Asia/Tokyo'), "
            . "(10, 'Empty own zone', '', 'Asia/Tokyo'), "
            . "(11, 'Padded browser zone', NULL, 'Asia/Tokyo ')",
        );
    }

    /** @return iterable<string, array{string, int, ?string, string}> Three modes over all four fallback situations */
    public static function policyMatrix(): iterable
    {
        foreach (['recipient', 'project', 'utc'] as $mode) {
            yield $mode . ' own' => [$mode, 1, 'America/New_York', match ($mode) {
                'recipient' => 'Europe/Warsaw',
                'project' => 'America/New_York',
                'utc' => 'UTC',
            }];
            yield $mode . ' last browser' => [$mode, 2, 'America/New_York', match ($mode) {
                'recipient' => 'Asia/Tokyo',
                'project' => 'America/New_York',
                'utc' => 'UTC',
            }];
            yield $mode . ' project' => [$mode, 3, 'America/New_York', $mode === 'utc' ? 'UTC' : 'America/New_York'];
            yield $mode . ' default UTC' => [$mode, 3, null, 'UTC'];
        }
    }

    /**
     * @param string $mode Exact env policy
     * @param int $userId Required recipient
     * @param ?string $project Project zone or absent key
     * @param string $expected Selected zone
     */
    #[DataProvider('policyMatrix')]
    public function testPolicyOverRealUserRows(string $mode, int $userId, ?string $project, string $expected): void
    {
        $this->withEnvironment($mode, $project, function () use ($userId, $expected): void {
            $this->assertSame($expected, TimeHelper::serverTextTimeZone($userId, 'Pacific/Auckland')->getName());
            $this->assertNull(Hilos::$rt);
            $this->assertDatedNotifications(
                $userId,
                new DateTimeImmutable('2026-01-15T12:00:00Z')->getTimestamp(),
                match ($expected) {
                    'Europe/Warsaw' => '2026-01-15 13:00 +01:00 (Europe/Warsaw)',
                    'Asia/Tokyo' => '2026-01-15 21:00 +09:00 (Asia/Tokyo)',
                    'America/New_York' => '2026-01-15 07:00 -05:00 (America/New_York)',
                    'UTC' => '2026-01-15 12:00 +00:00 (UTC)',
                },
            );
        });
    }

    /** @return iterable<string, array{int, ?string, string}> Aliases and skipped unusable saved links */
    public static function savedZones(): iterable
    {
        yield 'own alias' => [4, 'America/New_York', 'Europe/Kiev'];
        yield 'browser alias' => [5, 'America/New_York', 'US/Eastern'];
        yield 'invalid own' => [6, 'America/New_York', 'Asia/Tokyo'];
        yield 'invalid browser' => [7, 'America/New_York', 'America/New_York'];
        yield 'both invalid' => [8, 'America/New_York', 'America/New_York'];
        yield 'all invalid' => [8, '+02:00', 'UTC'];
        yield 'own UTC' => [9, 'America/New_York', 'UTC'];
        yield 'empty own' => [10, 'America/New_York', 'Asia/Tokyo'];
        yield 'padded browser' => [11, 'America/New_York', 'America/New_York'];
    }

    /**
     * @param int $userId Recipient with a saved candidate
     * @param ?string $project Configured project value
     * @param string $expected First usable candidate
     */
    #[DataProvider('savedZones')]
    public function testSavedNamesAreExactAndBrowserArgumentCannotReplaceThem(int $userId, ?string $project, string $expected): void
    {
        $this->withEnvironment('recipient', $project, function () use ($userId, $expected): void {
            $this->assertSame($expected, TimeHelper::serverTextTimeZone($userId, 'Pacific/Auckland')->getName());
        });
    }

    public function testMissingRequiredRecipientRefusesInsteadOfUsingTheBrowserOrProject(): void
    {
        $this->withEnvironment('recipient', 'America/New_York', function (): void {
            $this->assertArrayNotHasKey(1578, Hilos::$db->users);
            $this->expectException(ItemNotFoundException::class);
            TimeHelper::serverTextTimeZone(1578, 'Asia/Tokyo');
        });
    }

    public function testWarsawOffsetsBelongToTheFormattedMoment(): void
    {
        $this->withEnvironment('recipient', 'America/New_York', function (): void {
            $moments = [
                ['2026-10-25T00:30:00Z', '2026-10-25 02:30 +02:00'],
                ['2026-10-25T01:30:00Z', '2026-10-25 02:30 +01:00'],
                ['2026-01-15T12:00:00Z', '2026-01-15 13:00 +01:00'],
                ['2026-07-15T12:00:00Z', '2026-07-15 14:00 +02:00'],
            ];
            $before = time();
            foreach ($moments as [$utc, $expected]) {
                $moment = new DateTimeImmutable($utc);
                $local = $moment->setTimezone(TimeHelper::serverTextTimeZone(1));

                $this->assertDatedNotifications(1, $moment->getTimestamp(), $expected . ' (Europe/Warsaw)');
                $this->assertSame($expected, $local->format('Y-m-d H:i P'));
                $this->assertSame($moment->getTimestamp(), $local->getTimestamp());
                $this->assertSame($utc, $moment->format('Y-m-d\TH:i:s\Z'));
            }
            $this->assertGreaterThanOrEqual($before, time());
        });
    }

    public function testAbsentNotifierNeedsNoEnvironmentDatabaseOrRuntime(): void
    {
        $previousNotify = Hilos::$notify;
        $previousEnv = Hilos::$env;
        $previousDb = Hilos::$db;
        $previousRt = Hilos::$rt;
        try {
            Hilos::$notify = null;
            Hilos::$env = null;
            Hilos::$db = null;
            Hilos::$rt = null;

            SecondFactorLockNotifier::locked(1578, 3, 1800000000);
            SecondFactorResetNotifier::requested(1578, 'cancel-token', 1800000000);
            SecondFactorResetNotifier::reminder(1578, 'cancel-token', 1800000000);
            SecondFactorResetNotifier::canceled(1578);
            SecondFactorResetNotifier::completed(1578);

            $this->assertNull(Hilos::$notify);
            $this->assertNull(Hilos::$env);
            $this->assertNull(Hilos::$db);
            $this->assertNull(Hilos::$rt);
        } finally {
            Hilos::$notify = $previousNotify;
            Hilos::$env = $previousEnv;
            Hilos::$db = $previousDb;
            Hilos::$rt = $previousRt;
        }
    }

    public function testCanceledAndCompletedDoNotInventAMomentOrReadTimeConfiguration(): void
    {
        $previousNotify = Hilos::$notify;
        $previousEnv = Hilos::$env;
        $previousDb = Hilos::$db;
        $capture = new ServerTextTimeZoneNotifierCapture();
        try {
            Hilos::$notify = $capture;
            Hilos::$env = null;
            Hilos::$db = null;

            SecondFactorResetNotifier::canceled(1);
            SecondFactorResetNotifier::completed(1);

            $this->assertCount(2, $capture->drafts);
            [$canceled, $completed] = $capture->drafts;
            $this->assertSame(SecondFactorNotificationType::RESET_CANCELED, $canceled->type);
            $this->assertSame('Removal of two-step verification canceled', $canceled->title);
            $this->assertSame(
                'The request to remove two-step verification from your account was canceled. Nothing changes.',
                $canceled->body,
            );
            $this->assertSame(SecondFactorNotificationType::RESET_COMPLETED, $completed->type);
            $this->assertSame('Two-step verification removed', $completed->title);
            $this->assertSame(
                'Two-step verification was removed from your account, as requested. You can turn it on again in your profile.',
                $completed->body,
            );
            foreach ($capture->drafts as $draft) {
                $this->assertSame(1, $draft->userId);
                $this->assertSame(NotificationSeverity::WARNING, $draft->severity);
                $this->assertNull($draft->data);
            }
        } finally {
            Hilos::$notify = $previousNotify;
            Hilos::$env = $previousEnv;
            Hilos::$db = $previousDb;
        }
    }

    /**
     * @param int $userId Real recipient View key
     * @param int $momentSec Fixed absolute end of a lock or removal
     * @param string $expectedTime Independently specified local time, offset and zone
     */
    private function assertDatedNotifications(int $userId, int $momentSec, string $expectedTime): void
    {
        $previousNotify = Hilos::$notify;
        $previousCancelUrl = getenv(EnvConstants::HILOS_SECOND_FACTOR_CANCEL_URL->name);
        $capture = new ServerTextTimeZoneNotifierCapture();
        try {
            Hilos::$notify = $capture;
            putenv(EnvConstants::HILOS_SECOND_FACTOR_CANCEL_URL->name . '=https://example.test/cancel-second-factor');
            $token = 'cancel-token';
            $url = 'https://example.test/cancel-second-factor?token=cancel-token';

            SecondFactorLockNotifier::locked($userId, 3, $momentSec);
            SecondFactorResetNotifier::requested($userId, $token, $momentSec);
            SecondFactorResetNotifier::reminder($userId, $token, $momentSec);

            $this->assertCount(3, $capture->drafts);
            [$locked, $requested, $reminder] = $capture->drafts;
            $this->assertSame(SecondFactorNotificationType::APP_CODES_LOCKED, $locked->type);
            $this->assertSame('Two-step verification codes locked', $locked->title);
            $this->assertSame(
                'Someone entered 3 wrong codes from your authenticator app. App codes are not accepted until ' . $expectedTime
                    . '; backup codes still work. If this was not you, change your password and sign out your other sessions in your profile.',
                $locked->body,
            );
            $this->assertNull($locked->data);
            $this->assertSame(SecondFactorNotificationType::RESET_REQUESTED, $requested->type);
            $this->assertSame('Removal of two-step verification requested', $requested->title);
            $this->assertSame(
                'Someone asked to remove two-step verification from your account. It will be removed on '
                    . $expectedTime . '. If this was not you, cancel it: ' . $url,
                $requested->body,
            );
            $this->assertSame(SecondFactorNotificationType::RESET_REMINDER, $reminder->type);
            $this->assertSame('Two-step verification will be removed', $reminder->title);
            $this->assertSame(
                'Two-step verification on your account will be removed on ' . $expectedTime . '. If this was not you, cancel it: ' . $url,
                $reminder->body,
            );
            $this->assertSame(['url' => $url], $requested->data);
            $this->assertSame(['url' => $url], $reminder->data);
            foreach ($capture->drafts as $draft) {
                $this->assertSame($userId, $draft->userId);
                $this->assertSame(NotificationSeverity::WARNING, $draft->severity);
            }
        } finally {
            Hilos::$notify = $previousNotify;
            putenv(EnvConstants::HILOS_SECOND_FACTOR_CANCEL_URL->name . ($previousCancelUrl === false ? '' : '=' . $previousCancelUrl));
        }
    }

    /**
     * Restores the active env around the test body, including failures and missing-user refusals.
     *
     * @param string $mode Exact mode value
     * @param ?string $project Project value, null removes the key and uses the real catalog default
     * @param callable(): void $assertions Assertions against the real user View
     */
    private function withEnvironment(string $mode, ?string $project, callable $assertions): void
    {
        $previousEnv = Hilos::$env;
        $previousMode = getenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name);
        $previousProject = getenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        $previousTimezone = date_default_timezone_get();
        try {
            Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
            putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . '=' . $mode);
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . ($project === null ? '' : '=' . $project));
            $assertions();
        } finally {
            Hilos::$env = $previousEnv;
            putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . ($previousMode === false ? '' : '=' . $previousMode));
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . ($previousProject === false ? '' : '=' . $previousProject));
            $this->assertSame($previousTimezone, date_default_timezone_get());
        }
    }
}

/** Keeps the production notifiers' drafts without queueing a signal or choosing a channel. */
final class ServerTextTimeZoneNotifierCapture extends HilosNotifier
{
    /** @var list<NotificationDraft> Emitted drafts in call order */
    public array $drafts = [];

    /** @param NotificationDraft $draft Production announcement */
    public function emit(NotificationDraft $draft): void
    {
        $this->drafts[] = $draft;
    }
}

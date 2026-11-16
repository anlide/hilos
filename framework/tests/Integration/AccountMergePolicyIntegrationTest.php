<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Auth\SecondFactor\SecondFactorTrustRestrictionSweep;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictSignalData;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/** Policy reads remain restrictive while owners defer material application until their own release. */
final class AccountMergePolicyIntegrationTest extends AccountMergeGuardIntegrationTestCase
{
    private const int DAY_MS = TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;

    /**
     * @param int $days Saved term; zero removes the trusts
     */
    #[DataProvider('terms')]
    public function testStartupServiceSweepAndAwaitKeepDebtUntilEachOwnersRelease(int $days): void
    {
        $survivor = self::person();
        $loser = self::person();
        $operation = $this->open($survivor, $loser);
        $this->reserve($operation, $survivor);
        $this->reserve($operation, $loser);
        $now = TimeHelper::nowMs();
        $sessions = [];
        foreach ([$survivor, $loser] as $userId) {
            $sessions[$userId] = self::trust($userId, $now - 10 * self::DAY_MS, $now + 20 * self::DAY_MS);
        }
        $savedAt = $now - 9 * self::DAY_MS;
        Database::sql('INSERT INTO `hilos_second_factor_trust_restriction` (`saved_at`, `term_days`) VALUES (?, ?)', [$savedAt, $days]);
        $restrictionId = Database::lastInsertId();
        $restrictions = Database::sql('SELECT * FROM `hilos_second_factor_trust_restriction`')->rows();
        $before = self::trustRows();
        foreach ([$survivor, $loser] as $userId) {
            $this->asHolder(static function () use ($userId, $sessions): void {
                self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessions[$userId], $userId, 30));
                self::assertTrue(Hilos::$db->secondFactorTrusts->getObjectCollection()->owes($userId));
            });
            $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->onStart());
            self::assertSame($before, self::trustRows(), 'Starting a reserved owner must defer material policy writes');
            $answer = $this->answer($userId, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT,
                new UserSecondFactorTrustRestrictSignalData($userId, $restrictionId, self::REPLY));
            self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $answer->error);
            self::assertSame($before, self::trustRows());
        }
        $sweep = new SecondFactorTrustRestrictionSweep(new MergeAccessTestSessionsHolder());
        self::assertSame(2, $this->awaitPass($sweep, $restrictionId));
        self::assertSame($before, self::trustRows());
        self::assertSame($restrictions, Database::sql('SELECT * FROM `hilos_second_factor_trust_restriction`')->rows());

        $this->release($operation, $survivor);
        $this->asPerson($survivor, static fn (AbstractUserAgent $agent) => $agent->onStart());
        $this->asHolder(static function () use ($survivor, $loser): void {
            self::assertFalse(Hilos::$db->secondFactorTrusts->getObjectCollection()->owes($survivor));
            self::assertTrue(Hilos::$db->secondFactorTrusts->getObjectCollection()->owes($loser));
        });
        self::assertSame(1, $this->awaitPass($sweep, $restrictionId), 'A refused flight must leave room for the next explicit pass');
        $this->release($operation, $loser);
        self::assertSame(0, $this->awaitPass($sweep, $restrictionId), 'Await pays the remaining debt without a timer or peer reservation');
        $rows = self::trustRows();
        if ($days === 0) {
            self::assertSame([], $rows);
        } else {
            self::assertCount(2, $rows);
            foreach ($rows as $row) {
                self::assertSame($savedAt + $days * self::DAY_MS, (int)$row['trusted_until']);
            }
        }
        self::assertSame($restrictions, Database::sql('SELECT * FROM `hilos_second_factor_trust_restriction`')->rows());
    }

    /** A folded account with no reservation still accepts service policy work. */
    public function testUnreservedFoldedOwnerKeepsTheServicePath(): void
    {
        $survivor = self::person();
        $folded = self::person();
        $now = TimeHelper::nowMs();
        self::trust($folded, $now - self::DAY_MS, $now + self::DAY_MS);
        Database::sql('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [$folded, $survivor, $now]);
        Database::sql('INSERT INTO `hilos_second_factor_trust_restriction` (`saved_at`, `term_days`) VALUES (?, ?)', [$now, 0]);
        $restrictionId = Database::lastInsertId();
        $answer = $this->answer($folded, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT,
            new UserSecondFactorTrustRestrictSignalData($folded, $restrictionId, self::REPLY));
        self::assertNull($answer->error);
        self::assertSame([], self::trustRows());
    }

    /** @return iterable<string, array{int}> Both material restriction shapes */
    public static function terms(): iterable
    {
        yield 'switch off' => [0];
        yield 'shorten term' => [7];
    }

    /**
     * @param SecondFactorTrustRestrictionSweep $sweep Existing mechanism retaining flight and DB debt
     * @param int $restrictionId Saved policy
     * @return int Number of trusts still owing after this explicit pass
     */
    private function awaitPass(SecondFactorTrustRestrictionSweep $sweep, int $restrictionId): int
    {
        self::drain();
        $settled = [];
        $this->asHolder(static function () use ($sweep, $restrictionId, &$settled): void {
            $sweep->await($restrictionId, static function (int $owing) use (&$settled): void {
                $settled[] = $owing;
            });
        });
        $frames = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($queued->signalName->getName() === HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT) {
                $frames[] = $queued;
            }
        }
        foreach ($frames as $frame) {
            $this->deliverToPerson($frame);
            while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($queued->signalName->getName() === HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT_DONE) {
                    self::assertInstanceOf(AgentSignalData::class, $queued->data);
                    $done = $queued->data->data;
                    self::assertInstanceOf(UserSecondFactorTrustRestrictDoneSignalData::class, $done);
                    $this->asHolder(static fn () => $sweep->answered($done));
                }
            }
        }
        self::assertCount(1, $settled, 'The pass must release every refused flight and answer its waiter');

        return $settled[0];
    }

    /**
     * @param int $userId Owner of the trust
     * @param int $grantedAt Old proof moment
     * @param int $until Original trust end
     * @return int Session id for the trust read gate
     */
    private static function trust(int $userId, int $grantedAt, int $until): int
    {
        $token = "merge-policy-{$userId}";
        self::seedSession($token, $userId, $grantedAt, null);
        $sessionId = (int)Database::sql('SELECT `id` FROM `hilos_session` WHERE `token` = ?', [$token])->rows()[0]['id'];
        Database::sql('INSERT INTO `hilos_second_factor_trust` (`session_id`, `user_id`, `trusted_until`, `created_at`, `granted_at`)'
            . ' VALUES (?, ?, ?, ?, ?)', [$sessionId, $userId, $until, $grantedAt, $grantedAt]);

        return $sessionId;
    }

    /** @return list<array<string, mixed>> Independently persisted trust rows */
    private static function trustRows(): array
    {
        return Database::sql('SELECT * FROM `hilos_second_factor_trust` ORDER BY `id`')->rows();
    }
}

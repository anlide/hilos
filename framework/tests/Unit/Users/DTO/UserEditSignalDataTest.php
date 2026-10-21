<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\DatabaseException;
use Hilos\Users\DTO\UserAdminCommandDoneSignalData;
use Hilos\Users\DTO\UserAdminCommandSignalData;
use Hilos\Users\DTO\UserAdminWriteDoneSignalData;
use Hilos\Users\DTO\UserAdminWriteSignalData;
use Hilos\Users\DTO\UserBlockWriteDoneSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use Hilos\Users\DTO\UserThemePickWriteSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteSignalData;
use PHPUnit\Framework\TestCase;

/** The frames between a coordinator and the person's agent survive the wire whole (HIL-1404). */
final class UserEditSignalDataTest extends TestCase
{
    public function testARenameAskAndItsAnswerRoundTrip(): void
    {
        $ask = new UserRenameSignalData(
            userId: 7,
            name: 'Ada',
            renamedByUserId: 3,
            replySignal: HilosSignalConstants::HILOS_USER_RENAME_DONE,
            acceptKey: 'key-1',
            requestId: 'req-1',
            action: 'hilos_user_update',
            successMessage: 'Saved',
            answerSignal: HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
        );
        $done = UserRenameDoneSignalData::to($ask, 11, null);

        $restored = UserRenameDoneSignalData::fromArray(json_decode(json_encode($done->toArray()), true));

        self::assertEquals($done, $restored);
        self::assertSame(11, $restored->renameId);
        self::assertNull($restored->error);
        self::assertSame($ask->toArray(), $restored->ask->toArray());
    }

    public function testAnOwnRenameCarriesNoAuthorNameAndNoAnswerName(): void
    {
        $ask = new UserRenameSignalData(7, 'Ada', 7, HilosSignalConstants::HILOS_USER_RENAME_DONE, 'key-1', null, 'rename', null, null);

        $restored = UserRenameSignalData::fromArray($ask->toArray());

        self::assertNull($restored->answerSignal);
        self::assertNull($restored->requestId);
        self::assertSame(7, $restored->renamedByUserId);
    }

    public function testARefusedRenameCarriesTheRefusalBesideTheAsk(): void
    {
        $ask = new UserRenameSignalData(7, 'Ada', null, HilosSignalConstants::HILOS_USER_RENAME_DONE, 'key-1', null, 'rename', null, null);
        $done = UserRenameDoneSignalData::to($ask, null, ActionRefusal::fromThrowable(new DatabaseException('disk full')));

        $restored = UserRenameDoneSignalData::fromArray($done->toArray());

        self::assertNull($restored->renameId);
        self::assertNotNull($restored->error);
        self::assertSame('DatabaseException', $restored->errorType);
        self::assertSame('disk full', $restored->errorDetail);
    }

    public function testAnAdminWriteAskAndItsAnswerRoundTrip(): void
    {
        $ask = new UserAdminWriteSignalData(
            userId: 7,
            admin: false,
            replySignal: HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE,
            acceptKey: 'key-1',
            requestId: 'req-1',
            action: 'hilos_user_admin_set',
            successMessage: null,
            answerSignal: HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE,
        );
        $done = UserAdminWriteDoneSignalData::to($ask, ActionRefusal::said('No such user: 7'));

        $restored = UserAdminWriteDoneSignalData::fromArray(json_decode(json_encode($done->toArray()), true));

        self::assertEquals($done, $restored);
        self::assertSame('No such user: 7', $restored->error);
        self::assertNull($restored->errorDetail);
    }

    public function testACommandRequestAndItsAnswerRoundTrip(): void
    {
        $request = new UserAdminCommandSignalData(
            userId: 7,
            admin: true,
            replySignal: HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE,
            correlationId: 'corr-1',
            command: CliCommands::ADMIN_CREATE,
            sessionToken: 'token-1',
            expired: true,
        );
        $done = UserAdminCommandDoneSignalData::to($request, null);

        $restored = UserAdminCommandDoneSignalData::fromArray(json_decode(json_encode($done->toArray()), true));

        self::assertEquals($done, $restored);
        self::assertSame('token-1', $restored->request->sessionToken);
        self::assertTrue($restored->request->expired);
    }

    public function testABlockWriteAskAndItsAnswerRoundTrip(): void
    {
        $ask = new UserBlockWriteSignalData(
            userId: 7,
            block: true,
            by: 3,
            replySignal: HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE,
            acceptKey: 'key-1',
            requestId: null,
            action: 'hilos_user_block_set',
            successMessage: null,
            answerSignal: HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE,
        );
        $done = UserBlockWriteDoneSignalData::to($ask, null);

        $restored = UserBlockWriteDoneSignalData::fromArray(json_decode(json_encode($done->toArray()), true));

        self::assertEquals($done, $restored);
        self::assertSame(3, $restored->ask->by);
    }

    public function testAnAnswerWithoutItsAskIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        UserBlockWriteDoneSignalData::fromArray([UserBlockWriteDoneSignalData::error => null]);
    }

    public function testAThemePickAskAndItsAnswerRoundTrip(): void
    {
        $ask = new UserThemePickWriteSignalData(7, 'dark', 'theme_pick_done', 'key-1', 'req-1', 'theme_pick', 'Saved');
        $done = UserThemePickWriteDoneSignalData::to($ask, null);

        $restored = UserThemePickWriteDoneSignalData::fromArray(json_decode(json_encode($done->toArray()), true));

        self::assertEquals($done, $restored);
        self::assertSame($ask->toArray(), $restored->ask->toArray());
        self::assertNull($restored->error);
        self::assertFalse($restored->ask->adoptOnly);
    }

    public function testAnExplicitThemeResetSurvivesTheAskAndNestedAnswer(): void
    {
        $ask = new UserThemePickWriteSignalData(7, null, 'theme_pick_done', 'key-1', 'req-1', 'theme_pick', null);
        $done = UserThemePickWriteDoneSignalData::to($ask, null);

        self::assertNull(UserThemePickWriteSignalData::fromArray($ask->toArray())->themePick);
        self::assertEquals($done, UserThemePickWriteDoneSignalData::fromJson($done->toJson()));
        self::assertArrayHasKey(UserThemePickWriteSignalData::themePick, $done->ask->toArray());
    }

    public function testThemeAdoptionCannotAskForAReset(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserThemePickWriteSignalData(7, null, 'theme_pick_done', 'key-1', null, 'theme_pick', null, true);
    }

    public function testThemePickAskCannotOmitTheNullableChoice(): void
    {
        $payload = new UserThemePickWriteSignalData(7, null, 'theme_pick_done', 'key-1', null, 'theme_pick', null)->toArray();
        unset($payload[UserThemePickWriteSignalData::themePick]);
        $this->expectException(InvalidFormatException::class);

        UserThemePickWriteSignalData::fromArray($payload);
    }

    /** The adoption flag rides inside the ask and its answer in either state. */
    public function testThemeAdoptionFlagRoundTrips(): void
    {
        foreach ([true, false] as $adoptOnly) {
            $ask = new UserThemePickWriteSignalData(7, 'dark', 'theme_pick_done', 'key-1', 'req-1', 'theme_pick', null, $adoptOnly);
            $done = UserThemePickWriteDoneSignalData::to($ask, null);

            self::assertSame($adoptOnly, UserThemePickWriteSignalData::fromArray($ask->toArray())->adoptOnly);
            self::assertSame($adoptOnly, UserThemePickWriteDoneSignalData::fromArray($done->toArray())->ask->adoptOnly);
        }
    }

    /** A frame predating conditional adoption still asks for an explicit write. */
    public function testAnOldThemePickFrameDefaultsToExplicitWriting(): void
    {
        $ask = new UserThemePickWriteSignalData(7, 'dark', 'theme_pick_done', 'key-1', null, 'theme_pick', null);
        $payload = $ask->toArray();
        unset($payload[UserThemePickWriteSignalData::adoptOnly]);

        self::assertFalse($ask->adoptOnly);
        self::assertFalse(UserThemePickWriteSignalData::fromArray($payload)->adoptOnly);
    }

    /** A present null flag is malformed rather than an older frame. */
    public function testAThemePickFrameRefusesANullAdoptionFlag(): void
    {
        $ask = new UserThemePickWriteSignalData(7, 'dark', 'theme_pick_done', 'key-1', null, 'theme_pick', null);
        $payload = $ask->toArray();
        $payload[UserThemePickWriteSignalData::adoptOnly] = null;

        $this->expectException(InvalidFormatException::class);
        UserThemePickWriteSignalData::fromArray($payload);
    }

    public function testAThemePickForNoPersonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserThemePickWriteSignalData(0, 'dark', 'theme_pick_done', 'key-1', null, 'theme_pick', null);
    }

    public function testAnUnknownThemePickIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserThemePickWriteSignalData(7, 'auto', 'theme_pick_done', 'key-1', null, 'theme_pick', null);
    }

    public function testAnEmptyThemePickIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserThemePickWriteSignalData(7, '', 'theme_pick_done', 'key-1', null, 'theme_pick', null);
    }

    public function testAnAskForNoPersonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserAdminCommandSignalData(0, true, HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE, 'corr-1', CliCommands::ADMIN_GRANT, null, false);
    }

    public function testATimeZoneWriteAskAndItsAnswerRoundTrip(): void
    {
        $ask = new UserTimeZoneWriteSignalData(
            userId: 7,
            timeZone: 'Europe/Warsaw',
            replySignal: 'time_zone_write_done',
            acceptKey: 'key-1',
            requestId: 'req-1',
            action: 'user_time_zone_set',
            successMessage: 'Saved',
        );
        $expectedAsk = [
            UserTimeZoneWriteSignalData::userId => 7,
            UserTimeZoneWriteSignalData::timeZone => 'Europe/Warsaw',
            UserTimeZoneWriteSignalData::replySignal => 'time_zone_write_done',
            UserTimeZoneWriteSignalData::acceptKey => 'key-1',
            UserTimeZoneWriteSignalData::requestId => 'req-1',
            UserTimeZoneWriteSignalData::action => 'user_time_zone_set',
            UserTimeZoneWriteSignalData::successMessage => 'Saved',
        ];
        $expectedDone = [
            UserTimeZoneWriteDoneSignalData::ask => $expectedAsk,
            UserTimeZoneWriteDoneSignalData::error => null,
            UserTimeZoneWriteDoneSignalData::errorType => null,
            UserTimeZoneWriteDoneSignalData::errorDetail => null,
        ];
        $done = UserTimeZoneWriteDoneSignalData::to($ask, null);

        $restored = UserTimeZoneWriteDoneSignalData::fromJson($done->toJson());

        self::assertSame($expectedAsk, $ask->toArray());
        self::assertSame($expectedDone, $done->toArray());
        self::assertEquals($done, $restored);
        self::assertSame($expectedDone, $restored->toArray());
        self::assertSame($expectedAsk, $restored->ask->toArray());
        self::assertNull($restored->error);
        self::assertNull($restored->errorType);
        self::assertNull($restored->errorDetail);
    }

    public function testAnExplicitNullTimeZoneSurvivesAskAndNestedAnswerJson(): void
    {
        $ask = new UserTimeZoneWriteSignalData(7, null, 'time_zone_write_done', 'key-1', null, 'user_time_zone_set', null);
        $done = UserTimeZoneWriteDoneSignalData::to($ask, null);
        $expectedAsk = [
            UserTimeZoneWriteSignalData::userId => 7,
            UserTimeZoneWriteSignalData::timeZone => null,
            UserTimeZoneWriteSignalData::replySignal => 'time_zone_write_done',
            UserTimeZoneWriteSignalData::acceptKey => 'key-1',
            UserTimeZoneWriteSignalData::requestId => null,
            UserTimeZoneWriteSignalData::action => 'user_time_zone_set',
            UserTimeZoneWriteSignalData::successMessage => null,
        ];
        $expectedDone = [
            UserTimeZoneWriteDoneSignalData::ask => $expectedAsk,
            UserTimeZoneWriteDoneSignalData::error => null,
            UserTimeZoneWriteDoneSignalData::errorType => null,
            UserTimeZoneWriteDoneSignalData::errorDetail => null,
        ];

        $restoredAsk = UserTimeZoneWriteSignalData::fromJson($ask->toJson());
        $restoredDone = UserTimeZoneWriteDoneSignalData::fromJson($done->toJson());

        self::assertSame($expectedAsk, $restoredAsk->toArray());
        self::assertSame($expectedDone, $restoredDone->toArray());
        self::assertArrayHasKey(UserTimeZoneWriteSignalData::timeZone, $restoredAsk->toArray());
        self::assertNull($restoredAsk->timeZone);
        self::assertArrayHasKey(UserTimeZoneWriteSignalData::timeZone, $restoredDone->ask->toArray());
        self::assertNull($restoredDone->ask->timeZone);
        self::assertNull($restoredDone->ask->requestId);
        self::assertNull($restoredDone->ask->successMessage);
    }

    public function testATimeZoneWriteAnswerSeparatesPublicRefusalFromTechnicalDetails(): void
    {
        $ask = new UserTimeZoneWriteSignalData(7, 'Europe/Warsaw', 'time_zone_write_done', 'key-1', null, 'user_time_zone_set', null);
        $done = UserTimeZoneWriteDoneSignalData::to($ask, ActionRefusal::fromThrowable(new DatabaseException('disk full')));

        $restored = UserTimeZoneWriteDoneSignalData::fromArray($done->toArray());

        self::assertSame(SignalConstants::ACTION_FAILED_REASON, $restored->error);
        self::assertSame('DatabaseException', $restored->errorType);
        self::assertSame('disk full', $restored->errorDetail);
        self::assertNotSame($restored->error, $restored->errorDetail);
        self::assertSame($ask->toArray(), $restored->ask->toArray());
    }

    public function testATimeZoneWriteAskForNoPersonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserTimeZoneWriteSignalData(0, 'Europe/Warsaw', 'done', 'key-1', null, 'set', null);
    }

    public function testATimeZoneWriteAskForANegativePersonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserTimeZoneWriteSignalData(-1, 'Europe/Warsaw', 'done', 'key-1', null, 'set', null);
    }

    public function testATimeZoneWriteAskCannotOmitItsNullableChoice(): void
    {
        $ask = new UserTimeZoneWriteSignalData(7, null, 'done', 'key-1', null, 'set', null);
        $payload = $ask->toArray();
        unset($payload[UserTimeZoneWriteSignalData::timeZone]);
        $this->expectException(InvalidFormatException::class);

        UserTimeZoneWriteSignalData::fromArray($payload);
    }

    public function testATimeZoneWriteAskRefusesANonStringChoice(): void
    {
        $ask = new UserTimeZoneWriteSignalData(7, null, 'done', 'key-1', null, 'set', null);
        $payload = $ask->toArray();
        $payload[UserTimeZoneWriteSignalData::timeZone] = ['Europe/Warsaw'];
        $this->expectException(InvalidFormatException::class);

        UserTimeZoneWriteSignalData::fromArray($payload);
    }

    public function testAnUnknownTimeZoneStringPassesTheDtoShapeCheck(): void
    {
        $ask = new UserTimeZoneWriteSignalData(7, 'Definitely/Not_A_Zone', 'done', 'key-1', null, 'set', null);

        self::assertSame('Definitely/Not_A_Zone', $ask->timeZone);
    }
}

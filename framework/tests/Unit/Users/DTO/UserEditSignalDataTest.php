<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
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
}

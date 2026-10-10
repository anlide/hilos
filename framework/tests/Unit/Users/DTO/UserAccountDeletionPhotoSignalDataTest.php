<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\DatabaseException;
use Hilos\Users\DTO\UserAccountDeletionCancelDoneSignalData;
use Hilos\Users\DTO\UserAccountDeletionCancelSignalData;
use Hilos\Users\DTO\UserPhotoPutDoneSignalData;
use Hilos\Users\DTO\UserPhotoPutSignalData;
use Hilos\Users\DTO\UserPhotoRemoveDoneSignalData;
use Hilos\Users\DTO\UserPhotoRemoveSignalData;
use PHPUnit\Framework\TestCase;

/**
 * The frames that cancel a deletion and set or remove a profile photo survive the wire whole (HIL-1409).
 *
 * Each ask comes back nested in its answer. A person and a file are named by a positive id, and a
 * refusal cancels nothing and names no file.
 */
final class UserAccountDeletionPhotoSignalDataTest extends TestCase
{
    public function testADeletionCancelAskAndItsAnswerRoundTrip(): void
    {
        $ask = new UserAccountDeletionCancelSignalData(
            userId: 7,
            by: 3,
            replySignal: HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE,
            acceptKey: 'key-1',
            requestId: 'req-1',
            action: HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL,
            successMessage: null,
            answerSignal: HilosSignalConstants::HILOS_ACCOUNT_DELETION_STATE,
        );
        $done = new UserAccountDeletionCancelDoneSignalData($ask, true, null, null, null);

        $restored = UserAccountDeletionCancelDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame(3, $restored->ask->by);
        self::assertSame(HilosSignalConstants::HILOS_ACCOUNT_DELETION_STATE, $restored->ask->answerSignal);
        self::assertTrue($restored->canceled);
        self::assertNull($restored->error);
    }

    public function testADeletionCancelRefusalReportsNothingCanceled(): void
    {
        $ask = new UserAccountDeletionCancelSignalData(
            7,
            null,
            HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE,
            'key-1',
            null,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL,
            null,
            null,
        );
        $done = UserAccountDeletionCancelDoneSignalData::refused($ask, ActionRefusal::fromThrowable(new DatabaseException('disk full')));

        $restored = UserAccountDeletionCancelDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertFalse($restored->canceled);
        self::assertSame('DatabaseException', $restored->errorType);
        self::assertSame('disk full', $restored->errorDetail);
        self::assertNull($restored->ask->by);
        self::assertNull($restored->ask->answerSignal);
    }

    public function testAPhotoPutAskAndItsAnswerRoundTrip(): void
    {
        $ask = $this->photoPut(11);
        $done = new UserPhotoPutDoneSignalData($ask, 4, null, null, null);

        $restored = UserPhotoPutDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame(11, $restored->ask->fileId);
        self::assertSame(4, $restored->formerFileId);
        self::assertNull($restored->ask->requestId);
        self::assertNull($restored->error);
    }

    public function testAPhotoPutWithNoFormerPhotoRoundTrips(): void
    {
        $done = new UserPhotoPutDoneSignalData($this->photoPut(11), null, null, null, null);

        $restored = UserPhotoPutDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertNull($restored->formerFileId);
    }

    public function testAPhotoPutRefusalNamesNoFormerFile(): void
    {
        $done = UserPhotoPutDoneSignalData::refused(
            $this->photoPut(11),
            ActionRefusal::said('This account was merged into another one'),
        );

        $restored = UserPhotoPutDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertNull($restored->formerFileId);
        self::assertSame('This account was merged into another one', $restored->error);
        self::assertNull($restored->errorDetail);
    }

    public function testAPhotoRemoveAskAndItsAnswerRoundTrip(): void
    {
        $ask = $this->photoRemove();
        $done = new UserPhotoRemoveDoneSignalData($ask, 9, null, null, null);

        $restored = UserPhotoRemoveDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame(9, $restored->fileId);
        self::assertSame(HilosSignalConstants::PROFILE_PHOTO_REMOVE, $restored->ask->action);
        self::assertNull($restored->error);
    }

    public function testAPhotoRemoveRefusalNamesNoFile(): void
    {
        $done = UserPhotoRemoveDoneSignalData::refused(
            $this->photoRemove(),
            ActionRefusal::fromThrowable(new DatabaseException('disk full')),
        );

        $restored = UserPhotoRemoveDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertNull($restored->fileId);
        self::assertSame('disk full', $restored->errorDetail);
    }

    public function testADeletionCancelForNoPersonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserAccountDeletionCancelSignalData(
            0,
            null,
            HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE,
            'key-1',
            null,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL,
            null,
            null,
        );
    }

    public function testAPhotoPutForNoPersonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->photoPut(11, 0);
    }

    public function testAPhotoOfNoFileIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->photoPut(0);
    }

    public function testAPhotoRemoveForNoPersonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserPhotoRemoveSignalData(
            0,
            HilosSignalConstants::HILOS_USER_PHOTO_REMOVE_DONE,
            'key-1',
            null,
            HilosSignalConstants::PROFILE_PHOTO_REMOVE,
            null,
        );
    }

    public function testAnAnswerWithoutItsAskIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        UserAccountDeletionCancelDoneSignalData::fromArray([
            UserAccountDeletionCancelDoneSignalData::canceled => false,
        ]);
    }

    /**
     * @param int $fileId Registry file the photo is set from
     * @param int $userId Owner of that file
     * @return UserPhotoPutSignalData Photo set ask
     * @throws InvalidFormatException When the person id or the file id is not positive
     */
    private function photoPut(int $fileId, int $userId = 7): UserPhotoPutSignalData
    {
        return new UserPhotoPutSignalData(
            $userId,
            $fileId,
            HilosSignalConstants::HILOS_USER_PHOTO_PUT_DONE,
            'key-1',
            null,
            HilosSignalConstants::PROFILE_PHOTO_SET,
            null,
        );
    }

    /**
     * @return UserPhotoRemoveSignalData Photo removal ask
     * @throws InvalidFormatException When the person id is not positive
     */
    private function photoRemove(): UserPhotoRemoveSignalData
    {
        return new UserPhotoRemoveSignalData(
            7,
            HilosSignalConstants::HILOS_USER_PHOTO_REMOVE_DONE,
            'key-1',
            null,
            HilosSignalConstants::PROFILE_PHOTO_REMOVE,
            null,
        );
    }

    /**
     * @param array<string, mixed> $payload Payload as the sender serialized it
     * @return array<string, mixed> The same payload after a JSON trip
     */
    private static function overTheWire(array $payload): array
    {
        return json_decode(json_encode($payload), true);
    }
}

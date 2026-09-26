<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Upload;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\Upload\DTO\UploadCancelActionDTO;
use Hilos\Files\Upload\DTO\UploadInitActionDTO;
use Hilos\Files\Upload\DTO\UploadStateSignalData;
use Hilos\Files\Upload\UploadFailureCode;
use Hilos\Files\Upload\UploadFrame;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Item\HilosUpload;
use PHPUnit\Framework\TestCase;

/**
 * Tests the two upload actions and the state frame on the wire (HIL-135).
 */
final class UploadDtoTest extends TestCase
{
    public function testTheDeclarationRoundTripsThroughArray(): void
    {
        $original = new UploadInitActionDTO('avatar', 'u-1', 'photo.png', 'image/png', 1024);

        $this->assertEquals($original, UploadInitActionDTO::fromArray($original->toArray()));
    }

    public function testTheDeclarationDropsAnyPathInFrontOfTheName(): void
    {
        $this->assertSame('photo.png', $this->declare(filename: 'C:\\Users\\me\\photo.png')->filename);
        $this->assertSame('photo.png', $this->declare(filename: '/home/me/ photo.png ')->filename);
        $this->assertSame('photo.png', $this->declare(filename: 'a/b\\photo.png')->filename);
    }

    public function testTheDeclarationRefusesAnIdOutsideTheAlphabet(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->declare(clientUploadId: 'a|b');
    }

    public function testTheDeclarationRefusesAnIdLongerThanASignatureCarries(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->declare(clientUploadId: str_repeat('a', UploadFrame::MAX_ID_LENGTH + 1));
    }

    public function testTheDeclarationRefusesANameThatIsOnlyAPath(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->declare(filename: 'folder/   ');
    }

    public function testTheDeclarationRefusesANameLongerThanTheLimit(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->declare(filename: str_repeat('я', UploadInitActionDTO::MAX_FILENAME_LENGTH + 1));
    }

    public function testTheDeclarationCountsTheNameInCharacters(): void
    {
        $name = str_repeat('я', UploadInitActionDTO::MAX_FILENAME_LENGTH);

        $this->assertSame($name, $this->declare(filename: $name)->filename);
    }

    public function testTheDeclarationRefusesANegativeSize(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->declare(size: -1);
    }

    public function testTheDeclarationRefusesAMissingField(): void
    {
        $this->expectException(InvalidFormatException::class);

        UploadInitActionDTO::fromArray(['target' => 'avatar', 'clientUploadId' => 'u1', 'filename' => 'a', 'size' => 1]);
    }

    public function testTheCancelRoundTripsAndRefusesABadId(): void
    {
        $original = new UploadCancelActionDTO('u-1');
        $this->assertEquals($original, UploadCancelActionDTO::fromArray($original->toArray()));

        $this->expectException(InvalidFormatException::class);
        UploadCancelActionDTO::fromArray(['clientUploadId' => '']);
    }

    public function testTheStateFrameCarriesTheWholeRow(): void
    {
        $frame = UploadStateSignalData::fromUpload(new HilosUpload(StateHilosUpload::fromRow([
            StateHilosUpload::acceptKey => 'accept-key',
            StateHilosUpload::clientUploadId => 'u1',
            StateHilosUpload::target => 'avatar',
            StateHilosUpload::userId => 7,
            StateHilosUpload::filename => 'a.png',
            StateHilosUpload::mimeType => 'image/png',
            StateHilosUpload::declaredSize => 10,
            StateHilosUpload::receivedBytes => 12,
            StateHilosUpload::tmpIndex => null,
            StateHilosUpload::phase => UploadPhase::FAILED->value,
            StateHilosUpload::detectedMimeType => null,
            StateHilosUpload::contentHash => null,
            StateHilosUpload::errorCode => UploadFailureCode::SIZE_OVERFLOW,
            StateHilosUpload::errorMessage => 'Uploaded data exceeds declared size',
            StateHilosUpload::updatedAt => 1,
        ])));

        $this->assertSame([
            'clientUploadId' => 'u1',
            'phase' => 'failed',
            'receivedBytes' => 12,
            'declaredSize' => 10,
            'errorCode' => 'size_overflow',
            'errorMessage' => 'Uploaded data exceeds declared size',
        ], $frame->toArray());
        $this->assertEquals($frame, UploadStateSignalData::fromArray($frame->toArray()));
    }

    public function testTheGoneFrameIsTheIdAndNothingElse(): void
    {
        $frame = UploadStateSignalData::gone('u1');

        $this->assertSame([
            'clientUploadId' => 'u1',
            'phase' => null,
            'receivedBytes' => null,
            'declaredSize' => null,
            'errorCode' => null,
            'errorMessage' => null,
        ], $frame->toArray());
        $this->assertEquals($frame, UploadStateSignalData::fromArray($frame->toArray()));
    }

    /**
     * @param string $clientUploadId Upload id to declare
     * @param string $filename File name to declare
     * @param int $size Size to declare
     * @return UploadInitActionDTO The declaration as read from the wire
     */
    private function declare(string $clientUploadId = 'u1', string $filename = 'a.png', int $size = 1): UploadInitActionDTO
    {
        return UploadInitActionDTO::fromArray([
            'target' => 'avatar',
            'clientUploadId' => $clientUploadId,
            'filename' => $filename,
            'mimeType' => 'image/png',
            'size' => $size,
        ]);
    }
}
